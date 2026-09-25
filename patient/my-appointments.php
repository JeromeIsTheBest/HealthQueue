<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/availability.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

// Tab key => [label, Appointments.Status it shows (null = everything)].
// Keys are kept stable because the Wallet page links to ?tab=<key>.
$tabs = [
    'all'      => ['All', null],
    'pending'  => ['Pending', 'Pending'],
    'approved' => ['Approved', 'Confirmed'],
    'rejected' => ['Declined', 'Cancelled'],
    'done'     => ['Completed', 'Completed'],
];
$activeTab = $_GET['tab'] ?? 'all';
if (!isset($tabs[$activeTab])) {
    $activeTab = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

        if (!$appointmentId) {
            $errors[] = 'Invalid appointment.';
        } elseif ($formType === 'cancel_appointment') {
            $cancellationReason = trim((string) ($_POST['cancellation_reason'] ?? ''));
            if ($cancellationReason === '') {
                $errors[] = 'Please choose a reason for cancelling.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $lookupStmt = $pdo->prepare(
                        "SELECT a.BookingFeePaid, c.ClinicName, c.BaseConsultationFee
                         FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID
                         WHERE a.AppointmentID = ? AND a.PatientID = ? AND a.Status IN ('Pending', 'Confirmed')
                         FOR UPDATE"
                    );
                    $lookupStmt->execute([$appointmentId, $user['UserID']]);
                    $target = $lookupStmt->fetch();

                    if (!$target) {
                        $pdo->rollBack();
                        $errors[] = 'That appointment could not be cancelled.';
                    } else {
                        $pdo->prepare("UPDATE Appointments SET Status = 'Cancelled', CancellationReason = ? WHERE AppointmentID = ? AND PatientID = ?")
                            ->execute([$cancellationReason, $appointmentId, $user['UserID']]);

                        if ($target['BookingFeePaid']) {
                            walletRefund(
                                $pdo,
                                $user['UserID'],
                                round((float) $target['BaseConsultationFee'], 2),
                                $appointmentId,
                                'Refund for cancelled booking at ' . $target['ClinicName']
                            );
                        }

                        $pdo->commit();
                        $flash = $target['BookingFeePaid']
                            ? 'Appointment cancelled. Your booking fee was refunded to your wallet.'
                            : 'Appointment cancelled.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Patient cancel failed: ' . $e->getMessage());
                    $errors[] = 'We could not cancel this appointment.';
                }
            }
        } elseif ($formType === 'reschedule_appointment') {
            $newDate = trim((string) ($_POST['appointment_date'] ?? ''));
            $newTime = trim((string) ($_POST['appointment_time'] ?? ''));
            $current = $pdo->prepare("SELECT ClinicID, PhysicianID FROM Appointments WHERE AppointmentID = ? AND PatientID = ? AND Status = 'Pending'");
            $current->execute([$appointmentId, $user['UserID']]);
            $currentAppt = $current->fetch();
            if ($newDate === '' || $newDate < date('Y-m-d') || $newTime === '') {
                $errors[] = 'Please choose a valid future date and time.';
            } elseif ($currentAppt && lockClinicBooking($pdo, (int) $currentAppt['ClinicID']) && !isBookable($pdo, (int) $currentAppt['ClinicID'], $currentAppt['PhysicianID'] ? (int) $currentAppt['PhysicianID'] : null, $newDate, $newTime, $appointmentId)) {
                // Same rule as new bookings: only inside a physician's published hours.
                $errors[] = 'That date and time is outside the physician\'s available hours (or already full). Please choose another slot.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE Appointments SET AppointmentDate = ?, AppointmentTime = ? WHERE AppointmentID = ? AND PatientID = ? AND Status = 'Pending'");
                    $stmt->execute([$newDate, $newTime, $appointmentId, $user['UserID']]);
                    if ($stmt->rowCount()) {
                        $flash = 'Appointment rescheduled.';
                    } else {
                        $errors[] = 'Only pending requests can be rescheduled from here.';
                    }
                } catch (PDOException $e) {
                    error_log('Patient reschedule failed: ' . $e->getMessage());
                    $errors[] = 'We could not reschedule this appointment.';
                }
            }
        }
    }
}

const MINUTES_PER_PATIENT = 15;

$appointments = [];
$tabCounts = array_fill_keys(array_keys($tabs), 0);
$activeQueue = null;
$dataError = null;

if ($pdo) {
    try {
        $countStmt = $pdo->prepare('SELECT Status, COUNT(*) FROM Appointments WHERE PatientID = ? GROUP BY Status');
        $countStmt->execute([$user['UserID']]);
        foreach ($countStmt->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $count) {
            foreach ($tabs as $key => [, $tabStatus]) {
                if ($tabStatus === $status) $tabCounts[$key] = (int) $count;
            }
            $tabCounts['all'] += (int) $count;
        }

        $statusFilter = $tabs[$activeTab][1];
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.Status, a.BookingFeePaid, a.CancellationReason, a.DeclineReason,
                    TIMESTAMPDIFF(MINUTE, a.CreatedAt, NOW()) AS MinutesSinceRequest,
                    c.ClinicName, c.Address, c.BaseConsultationFee,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName,
                    (SELECT COALESCE(SUM(t.Amount), 0) FROM WalletTransactions t
                     WHERE t.RelatedAppointmentID = a.AppointmentID AND t.Type = 'Refund') AS RefundAmount,
                    (SELECT q.QueueNumber FROM Queue q
                     WHERE q.AppointmentID = a.AppointmentID AND DATE(q.CreatedAt) = CURDATE()
                       AND q.Status IN ('Waiting', 'Calling', 'Serving')
                     ORDER BY q.CreatedAt DESC LIMIT 1) AS TodayQueueNumber
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.PatientID = ?" . ($statusFilter ? ' AND a.Status = ?' : '') . "
             ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC"
        );
        $stmt->execute($statusFilter ? [$user['UserID'], $statusFilter] : [$user['UserID']]);
        $appointments = $stmt->fetchAll();

        // Today's live queue entry (if any) is pinned above the list.
        $queueStmt = $pdo->prepare(
            "SELECT q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, q.ClinicID, c.ClinicName, c.Address,
                    a.AppointmentID, a.AppointmentTime, a.Concern,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Clinic c ON c.ClinicID = q.ClinicID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE a.PatientID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY q.CreatedAt DESC LIMIT 1"
        );
        $queueStmt->execute([$user['UserID']]);
        $activeQueue = $queueStmt->fetch() ?: null;

        if ($activeQueue) {
            $posStmt = $pdo->prepare(
                "SELECT COUNT(*) AS Total, COALESCE(SUM(Status = 'Waiting'), 0) AS Ahead
                 FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND COALESCE(Position, QueueNumber * 10) < (SELECT COALESCE(me.Position, me.QueueNumber * 10) FROM Queue me WHERE me.ClinicID = Queue.ClinicID AND DATE(me.CreatedAt) = CURDATE() AND me.QueueNumber = ? ORDER BY me.QueueID DESC LIMIT 1) AND Status <> 'Removed'"
            );
            $posStmt->execute([$activeQueue['ClinicID'], $activeQueue['QueueNumber']]);
            $pos = $posStmt->fetch();
            $total = (int) $pos['Total'];
            $activeQueue['Ahead'] = $activeQueue['Status'] === 'Waiting' ? (int) $pos['Ahead'] : 0;
            $activeQueue['WaitMinutes'] = $activeQueue['Ahead'] * MINUTES_PER_PATIENT;
            // How far the line has moved towards this patient.
            $activeQueue['Progress'] = $activeQueue['Status'] !== 'Waiting' || $total === 0
                ? 100 : (int) round(($total - $activeQueue['Ahead']) / $total * 100);
        }
    } catch (PDOException $e) {
        error_log('My appointments load failed: ' . $e->getMessage());
        $dataError = 'Your appointments are temporarily unavailable.';
    }
}

$statusPills = [
    'Pending'   => ['Pending', 'ma-pill-pending'],
    'Confirmed' => ['Approved', 'ma-pill-approved'],
    'Completed' => ['Completed', 'ma-pill-completed'],
];

$timeAgo = static function (int $minutes): string {
    if ($minutes < 1) return 'just now';
    if ($minutes < 60) return $minutes . ' min ago';
    if ($minutes < 1440) {
        $h = intdiv($minutes, 60);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = intdiv($minutes, 1440);
    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
};

$serviceLabel = static function (?string $concern): string {
    $concern = trim(preg_replace('/\s+/', ' ', (string) $concern));
    if ($concern === '') return 'General consultation';
    return mb_strlen($concern) > 40 ? mb_substr($concern, 0, 40) . '…' : $concern;
};

$emptyStates = [
    'all'      => 'You have no appointments yet',
    'pending'  => 'No requests waiting for approval',
    'approved' => 'No approved appointments',
    'rejected' => 'No declined or cancelled requests',
    'done'     => 'No completed visits yet',
];

$pageTitle = 'My Appointments — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container ma-page">
  <?php if (isset($_GET['paid'])): ?><p class="form-message success" role="status">Payment received — your request has been sent to the clinic.</p><?php endif; ?>
  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <?php if ($activeQueue): ?>
    <?php
      $queuePill = ['Waiting' => 'Approved · today', 'Calling' => 'Now calling you', 'Serving' => 'In consultation'][$activeQueue['Status']] ?? 'Today';
      $queueMeta = $serviceLabel($activeQueue['Concern'])
          . ($activeQueue['PhyFirstName'] ? ' · Dr. ' . $activeQueue['PhyLastName'] : '')
          . ' · ' . date('g:i A', strtotime($activeQueue['AppointmentTime']));
      if ($activeQueue['Status'] !== 'Waiting') {
          $aheadText = $queuePill;
      } elseif ($activeQueue['Ahead'] === 0) {
          $aheadText = "You're next";
      } else {
          $aheadText = $activeQueue['Ahead'] . ' ahead of you';
      }
    ?>
    <section class="ma-live">
      <div class="ma-live-top">
        <div>
          <span class="ma-pill ma-pill-approved"><?= htmlspecialchars($queuePill) ?></span>
          <h2><?= htmlspecialchars($activeQueue['ClinicName']) ?></h2>
          <p><?= htmlspecialchars($queueMeta) ?></p>
        </div>
        <div class="ma-live-number"><span>Your number</span><strong><?= $activeQueue['ScheduledNumber'] !== null ? 'S-' . (int) $activeQueue['ScheduledNumber'] : '#' . (int) ($activeQueue['RegularNumber'] ?? $activeQueue['QueueNumber']) ?></strong></div>
      </div>
      <div class="ma-live-progress-row">
        <span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <?= htmlspecialchars($aheadText) ?>
        </span>
        <span class="ma-live-wait"><?= $activeQueue['Status'] === 'Waiting' ? '~' . (int) $activeQueue['WaitMinutes'] . ' min wait' : 'Please proceed' ?></span>
      </div>
      <div class="ma-live-bar"><span style="width:<?= (int) $activeQueue['Progress'] ?>%;"></span></div>
      <div class="ma-live-actions">
        <a href="<?= HQ_BASE_URL ?>/patient/queue-status.php" class="btn btn-primary btn-sm">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
          View live queue
        </a>
        <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= urlencode($activeQueue['Address']) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
          Directions
        </a>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
          <input type="hidden" name="form_type" value="cancel_appointment">
          <input type="hidden" name="appointment_id" value="<?= (int) $activeQueue['AppointmentID'] ?>">
          <input type="hidden" name="cancellation_reason">
          <button type="button" class="btn btn-outline btn-sm ma-danger" data-confirm-modal="cancelConfirmModal">Cancel</button>
        </form>
      </div>
    </section>
  <?php endif; ?>

  <nav class="ma-tabs" aria-label="Filter appointments">
    <?php foreach ($tabs as $key => [$label]): ?>
      <a href="?tab=<?= $key ?>" class="ma-tab<?= $activeTab === $key ? ' is-active' : '' ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>><?= $label ?> <span><?= $tabCounts[$key] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($appointments): ?>
    <div class="ma-list">
      <?php foreach ($appointments as $appt): ?>
        <?php
          $id = (int) $appt['AppointmentID'];
          $ts = strtotime($appt['AppointmentDate']);
          $time = date('g:i A', strtotime($appt['AppointmentTime']));
          $doctor = $appt['PhyFirstName'] ? 'Dr. ' . $appt['PhyLastName'] : null;
          $meta = [$serviceLabel($appt['Concern'])];
          switch ($appt['Status']) {
              case 'Pending':
                  $meta[] = $time;
                  $meta[] = $appt['BookingFeePaid'] ? 'requested ' . $timeAgo((int) $appt['MinutesSinceRequest']) : 'awaiting payment';
                  break;
              case 'Confirmed':
                  $meta[] = $time;
                  $meta[] = $appt['TodayQueueNumber'] ? 'queue #' . (int) $appt['TodayQueueNumber'] : ($doctor ?: 'physician to be assigned');
                  break;
              case 'Cancelled':
                  $meta[] = $appt['DeclineReason'] ?: ($appt['CancellationReason'] ?: 'Declined by the clinic');
                  break;
              case 'Completed':
                  if ($doctor) $meta[] = $doctor;
                  break;
          }
          // Staff rejections leave CancellationReason empty; patient
          // cancellations always record one.
          $isDeclined = $appt['Status'] === 'Cancelled' && ($appt['DeclineReason'] || !$appt['CancellationReason']);
          if ($appt['Status'] === 'Cancelled') {
              [$pillLabel, $pillClass] = [$isDeclined ? 'Declined' : 'Cancelled', 'ma-pill-declined'];
          } else {
              [$pillLabel, $pillClass] = $statusPills[$appt['Status']] ?? [$appt['Status'], ''];
          }
          $cancelForm = '<form method="post"><input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">'
              . '<input type="hidden" name="form_type" value="cancel_appointment"><input type="hidden" name="appointment_id" value="' . $id . '">'
              . '<input type="hidden" name="cancellation_reason">';
        ?>
        <article class="ma-row" id="appt-<?= $id ?>">
          <div class="ma-date"><span><?= strtoupper(date('M', $ts)) ?></span><strong><?= date('j', $ts) ?></strong></div>
          <div class="ma-info">
            <strong><?= htmlspecialchars($appt['ClinicName']) ?></strong>
            <p><?= htmlspecialchars(implode(' · ', $meta)) ?></p>
            <div class="ma-links">
              <?php if ($appt['Status'] === 'Pending'): ?>
                <?php if (!$appt['BookingFeePaid']): ?>
                  <a href="<?= HQ_BASE_URL ?>/patient/checkout.php?appointment_id=<?= $id ?>" class="ma-link-strong">Complete payment</a>
                <?php endif; ?>
                <button type="button" class="view-concern-btn" data-target="resched-<?= $id ?>">Reschedule</button>
                <?= $cancelForm ?><button type="button" data-confirm-modal="cancelConfirmModal">Cancel request</button></form>
              <?php elseif ($appt['Status'] === 'Confirmed'): ?>
                <?php if ($appt['TodayQueueNumber']): ?><a href="<?= HQ_BASE_URL ?>/patient/queue-status.php">View queue</a><?php endif; ?>
                <?= $cancelForm ?><button type="button" data-confirm-modal="cancelConfirmModal">Cancel</button></form>
              <?php elseif ($appt['Status'] === 'Cancelled' && (float) $appt['RefundAmount'] > 0): ?>
                <span class="ma-refund">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M16 13h2M2 10h20"/></svg>
                  ₱<?= number_format((float) $appt['RefundAmount']) ?> refunded to wallet
                </span>
              <?php elseif ($appt['Status'] === 'Completed'): ?>
                <button type="button" data-view-record="<?= $id ?>">View medical record</button>
              <?php endif; ?>
            </div>
            <?php if ($appt['Status'] === 'Pending'): ?>
              <form method="post" id="resched-<?= $id ?>" class="ma-resched" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="form_type" value="reschedule_appointment">
                <input type="hidden" name="appointment_id" value="<?= $id ?>">
                <input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($appt['AppointmentDate']) ?>" required>
                <input type="time" name="appointment_time" value="<?= htmlspecialchars($appt['AppointmentTime']) ?>" required>
                <button type="submit" class="btn btn-outline btn-sm">Save new schedule</button>
              </form>
            <?php endif; ?>
          </div>
          <span class="ma-pill <?= $pillClass ?>"><?= htmlspecialchars($pillLabel) ?></span>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="ma-empty">
      <h3><?= htmlspecialchars($emptyStates[$activeTab]) ?></h3>
      <p>Find a clinic and book your next visit.</p>
      <a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="btn btn-outline btn-sm">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M10 9h4M12 7v4"/></svg>
        Find a clinic
      </a>
    </div>
  <?php endif; ?>
</div></main>

<div class="modal-overlay" id="viewRecordModal">
  <div class="modal-box modal-box-wide" style="max-height:85vh;">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div id="viewRecordContent"><p class="admin-empty">Loading…</p></div>
  </div>
</div>

<div class="modal-overlay" id="cancelConfirmModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Cancel this appointment request?</h2>
    <p class="modal-subtitle">This can't be undone. Any booking fee you've already paid will be refunded to your wallet.</p>
    <div id="cancelReasonError" class="form-message error" role="alert" style="display:none;">Please choose a reason for cancelling.</div>
    <div class="form-stack" style="text-align:left;">
      <label>Reason for cancellation
        <select data-confirm-field="cancellation_reason" id="cancelReasonSelect">
          <option value="">Select a reason</option>
          <option value="Schedule conflict">Schedule conflict</option>
          <option value="Found another clinic or physician">Found another clinic or physician</option>
          <option value="No longer needed">No longer needed</option>
          <option value="Booked by mistake">Booked by mistake</option>
          <option value="Financial reasons">Financial reasons</option>
          <option value="Others" data-other="true">Others</option>
        </select>
      </label>
      <label id="cancelReasonOtherWrap" style="display:none;">Please specify
        <textarea data-confirm-field-for="cancellation_reason" id="cancelReasonOtherInput" rows="2" placeholder="Tell us more..."></textarea>
      </label>
    </div>
    <div style="display:flex;gap:10px;margin-top:8px;">
      <button type="button" class="btn btn-outline btn-block" data-modal-close>Keep Appointment</button>
      <button type="button" class="btn btn-primary btn-block" id="cancelConfirmSubmitBtn" data-confirm-submit>Yes, Cancel</button>
    </div>
  </div>
</div>

<script>
(function () {
  document.querySelectorAll('.view-concern-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var panel = document.getElementById(btn.getAttribute('data-target'));
      if (!panel) return;
      var showing = panel.style.display !== 'none';
      panel.style.display = showing ? 'none' : (panel.tagName === 'FORM' ? 'flex' : 'block');
    });
  });

  var reasonSelect = document.getElementById('cancelReasonSelect');
  var reasonOtherWrap = document.getElementById('cancelReasonOtherWrap');
  var reasonOtherInput = document.getElementById('cancelReasonOtherInput');
  var reasonError = document.getElementById('cancelReasonError');
  var reasonSubmitBtn = document.getElementById('cancelConfirmSubmitBtn');

  function isOtherSelected() {
    var opt = reasonSelect ? reasonSelect.options[reasonSelect.selectedIndex] : null;
    return !!(opt && opt.getAttribute('data-other') === 'true');
  }

  if (reasonSelect && reasonOtherWrap) {
    reasonSelect.addEventListener('change', function () {
      reasonOtherWrap.style.display = isOtherSelected() ? 'block' : 'none';
      if (reasonError) reasonError.style.display = 'none';
    });
  }

  // Reset the reason fields every time the modal is opened for a
  // (possibly different) appointment, so a stale choice never carries over.
  document.querySelectorAll('[data-confirm-modal="cancelConfirmModal"]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      if (reasonSelect) reasonSelect.value = '';
      if (reasonOtherInput) reasonOtherInput.value = '';
      if (reasonOtherWrap) reasonOtherWrap.style.display = 'none';
      if (reasonError) reasonError.style.display = 'none';
    });
  });

  // Registered before the generic confirm-modal handler assigns its own
  // onclick, so this runs first and can block the submit with
  // stopImmediatePropagation() when no reason has been chosen.
  if (reasonSubmitBtn) {
    reasonSubmitBtn.addEventListener('click', function (e) {
      var hasReason = reasonSelect && reasonSelect.value !== '';
      var hasOtherText = !isOtherSelected() || (reasonOtherInput && reasonOtherInput.value.trim() !== '');
      if (!hasReason || !hasOtherText) {
        e.stopImmediatePropagation();
        if (reasonError) reasonError.style.display = 'block';
      }
    });
  }

  // "View medical record" on completed visits opens the consultation record
  // (rendered by consultation.php) in a modal, same as Medical Records.
  var viewRecordModal = document.getElementById('viewRecordModal');
  var viewRecordContent = document.getElementById('viewRecordContent');
  document.querySelectorAll('[data-view-record]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      viewRecordContent.innerHTML = '<p class="admin-empty">Loading…</p>';
      window.hqOpenModal(viewRecordModal);
      fetch('<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=' + encodeURIComponent(trigger.getAttribute('data-view-record')), { headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.text(); })
        .then(function (html) { viewRecordContent.innerHTML = html; })
        .catch(function () {
          viewRecordContent.innerHTML = '<p class="form-message error" role="alert">We could not load this record. Please try again.</p>';
        });
    });
  });
  viewRecordContent.addEventListener('submit', function (e) {
    if (!e.target.matches('.consultation-feedback-form')) return;
    e.preventDefault();
    var form = e.target;
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) { viewRecordContent.innerHTML = html; })
      .catch(function () {
        var err = document.createElement('p');
        err.className = 'form-message error';
        err.textContent = 'We could not save your feedback. Please try again.';
        form.prepend(err);
      });
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
