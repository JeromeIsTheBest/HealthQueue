<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/clinic-hours.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

const MINUTES_PER_PATIENT = 15;
// Patients this close to the front get the "almost your turn" heads-up.
const ALMOST_TURN_AHEAD = 2;

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $queueId = filter_input(INPUT_POST, 'queue_id', FILTER_VALIDATE_INT);
    $formType = $_POST['form_type'] ?? '';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo || !$queueId) {
        $errors[] = 'We could not update your queue entry.';
    } else {
        try {
            // Scoped to this patient's own active entry for today.
            $lookup = $pdo->prepare(
                "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.ClinicID, q.Status, a.AppointmentID, a.BookingFeePaid, c.ClinicName, c.BaseConsultationFee
                 FROM Queue q
                 JOIN Appointments a ON a.AppointmentID = q.AppointmentID
                 JOIN Clinic c ON c.ClinicID = q.ClinicID
                 WHERE q.QueueID = ? AND a.PatientID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling')"
            );
            $lookup->execute([$queueId, $user['UserID']]);
            $target = $lookup->fetch();
            $patientName = $user['FirstName'] . ' ' . $user['LastName'];

            if (!$target) {
                $errors[] = 'That queue entry is no longer active.';
            } elseif ($formType === 'running_late') {
                // One heads-up per entry every 10 minutes, so the front desk isn't spammed.
                $lastKey = 'late_notice_' . $target['QueueID'];
                if (($_SESSION[$lastKey] ?? 0) > time() - 600) {
                    $flash = 'The clinic already knows you are running late.';
                } else {
                    notifyClinic($pdo, (int) $target['ClinicID'], "{$patientName} (queue #{$target['QueueNumber']}) is running late.", (int) $target['AppointmentID']);
                    logActivity($pdo, $user['UserID'], (int) $target['ClinicID'], 'Reported running late', "Queue #{$target['QueueNumber']}");
                    $_SESSION[$lastKey] = time();
                    $flash = 'We let the clinic know you are running late.';
                }
            } elseif ($formType === 'leave_queue') {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE Queue SET Status = 'Removed' WHERE QueueID = ?")->execute([$target['QueueID']]);
                $pdo->prepare("UPDATE Appointments SET Status = 'Cancelled', CancellationReason = 'Left the queue' WHERE AppointmentID = ?")
                    ->execute([$target['AppointmentID']]);
                if ($target['BookingFeePaid']) {
                    walletRefund($pdo, $user['UserID'], round((float) $target['BaseConsultationFee'], 2), (int) $target['AppointmentID'], 'Refund for leaving the queue at ' . $target['ClinicName']);
                }
                $pdo->commit();
                notifyClinic($pdo, (int) $target['ClinicID'], "{$patientName} (queue #{$target['QueueNumber']}) left the queue.", (int) $target['AppointmentID']);
                logActivity($pdo, $user['UserID'], (int) $target['ClinicID'], 'Left the queue', "Queue #{$target['QueueNumber']}");
                $flash = $target['BookingFeePaid'] ? 'You left the queue. Your booking fee was refunded to your wallet.' : 'You left the queue.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Patient queue action failed: ' . $e->getMessage());
            $errors[] = 'We could not update your queue entry right now.';
        }
    }
}

$entries = [];
$nextVisit = null;
$partnerClinics = [];
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, q.ClinicID, c.ClinicName, a.Concern,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Clinic c ON c.ClinicID = q.ClinicID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE a.PatientID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY q.CreatedAt DESC"
        );
        $stmt->execute([$user['UserID']]);
        $entries = $stmt->fetchAll();

        $aheadStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND Status = 'Waiting' AND COALESCE(Position, QueueNumber * 10) < (SELECT COALESCE(me.Position, me.QueueNumber * 10) FROM Queue me WHERE me.ClinicID = Queue.ClinicID AND DATE(me.CreatedAt) = CURDATE() AND me.QueueNumber = ? ORDER BY me.QueueID DESC LIMIT 1)"
        );
        $servingStmt = $pdo->prepare(
            "SELECT MAX(QueueNumber) FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND Status IN ('Calling', 'Serving')"
        );
        foreach ($entries as &$entry) {
            $aheadStmt->execute([$entry['ClinicID'], $entry['QueueNumber']]);
            $entry['Ahead'] = $entry['Status'] === 'Waiting' ? (int) $aheadStmt->fetchColumn() : 0;
            $entry['WaitMinutes'] = $entry['Ahead'] * MINUTES_PER_PATIENT;
            $servingStmt->execute([$entry['ClinicID']]);
            $entry['NowServing'] = $servingStmt->fetchColumn() ?: null;
        }
        unset($entry);

        if (!$entries) {
            $nextStmt = $pdo->prepare(
                "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, c.ClinicName
                 FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID
                 WHERE a.PatientID = ? AND a.Status IN ('Pending', 'Confirmed')
                   AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) >= NOW() - INTERVAL 1 HOUR
                 ORDER BY a.AppointmentDate, a.AppointmentTime LIMIT 1"
            );
            $nextStmt->execute([$user['UserID']]);
            $nextVisit = $nextStmt->fetch() ?: null;

            $now = clinicNow($pdo);
            $partnerClinics = $pdo->query(
                "SELECT c.ClinicID, c.ClinicName, c.OpenDays, c.OpenTime, c.CloseTime,
                        (SELECT MAX(q.QueueNumber) FROM Queue q WHERE q.ClinicID = c.ClinicID AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Calling', 'Serving')) AS NowServing,
                        (SELECT COUNT(*) FROM Queue q WHERE q.ClinicID = c.ClinicID AND DATE(q.CreatedAt) = CURDATE() AND q.Status = 'Waiting') AS Waiting
                 FROM Clinic c WHERE c.archived = 0 AND c.Status = 'Active'
                 ORDER BY c.ClinicName"
            )->fetchAll();
            foreach ($partnerClinics as &$clinic) {
                $clinic['Hours'] = clinicOpenStatus($clinic, $now);
            }
            unset($clinic);
        }
    } catch (PDOException $e) {
        error_log('Queue status load failed: ' . $e->getMessage());
        $dataError = 'Queue status is temporarily unavailable.';
    }
}

$steps = ['Checked in', 'Waiting', 'Almost your turn', 'Being served', 'Done'];

// Rendered once so the full page and the periodic auto-refresh (fetch with
// X-Requested-With) share the same markup.
ob_start();
if ($entries):
    foreach ($entries as $entry):
        if ($entry['Status'] === 'Serving') {
            $stage = 3;
            $banner = ['lq-banner-success', "You're being seen now."];
        } elseif ($entry['Status'] === 'Calling') {
            $stage = 3;
            $banner = ['lq-banner-success', "It's your turn! Please proceed to the counter."];
        } elseif ($entry['Ahead'] <= ALMOST_TURN_AHEAD) {
            $stage = 2;
            $banner = ['lq-banner-warning', 'Almost your turn. Please head to the waiting area.'];
        } else {
            $stage = 1;
            $banner = ['lq-banner-info', "You're in line. We'll let you know when it's almost your turn."];
        }
        $concern = trim((string) $entry['Concern']);
        $meta = ($concern !== '' ? (mb_strlen($concern) > 40 ? mb_substr($concern, 0, 40) . '…' : $concern) : 'General consultation')
            . ($entry['PhyLastName'] ? ' · Dr. ' . $entry['PhyLastName'] : '');
?>
    <section class="lq-card">
      <div class="lq-head">
        <div class="lq-head-main">
          <span class="lq-clinic-mark"><?= htmlspecialchars(strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($entry['ClinicName'])), 0, 2))))) ?></span>
          <div>
          <h2><?= htmlspecialchars($entry['ClinicName']) ?></h2>
          <p><?= htmlspecialchars($meta) ?></p>
          </div>
        </div>
        <span class="lq-live"><span class="lq-live-dot"></span>Live · updated <span data-updated-seconds>0</span>s ago</span>
      </div>

      <div class="lq-stats">
        <div class="lq-stat lq-stat-mine"><span>Your number</span><strong><?= $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']) ?></strong><em><?= htmlspecialchars($entry['Status'] === 'Waiting' ? 'In line' : ($entry['Status'] === 'Calling' ? 'Called' : 'Being seen')) ?></em></div>
        <div class="lq-stat"><span>Now serving</span><strong><?= $entry['NowServing'] ? '#' . (int) $entry['NowServing'] : '—' ?></strong></div>
        <div class="lq-stat"><span>Ahead of you</span><strong><?= (int) $entry['Ahead'] ?></strong></div>
        <div class="lq-stat"><span>Est. wait</span><strong><?= $entry['Status'] === 'Waiting' ? '~' . (int) $entry['WaitMinutes'] . '<small> min</small>' : 'Now' ?></strong></div>
      </div>

      <div class="lq-banner <?= $banner[0] ?>" role="status">
        <span class="lq-banner-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span>
        <?= htmlspecialchars($banner[1]) ?>
      </div>

      <ol class="lq-steps">
        <?php foreach ($steps as $i => $label): ?>
          <li class="<?= $i < $stage ? 'is-done' : ($i === $stage ? 'is-current' : '') ?>"><span></span><?= $label ?></li>
        <?php endforeach; ?>
      </ol>

      <?php if (in_array($entry['Status'], ['Waiting', 'Calling'], true)): ?>
        <div class="lq-actions">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="form_type" value="running_late">
            <input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
            <button type="submit" class="btn btn-outline btn-sm">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
              I'm running late
            </button>
          </form>
          <form method="post" onsubmit="return confirm('Leave the queue? You will lose your place in line. Any booking fee you paid will be refunded to your wallet.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="form_type" value="leave_queue">
            <input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
            <button type="submit" class="btn btn-outline btn-sm ma-danger">Leave queue</button>
          </form>
        </div>
      <?php endif; ?>
    </section>
<?php
    endforeach;
    ?><p class="lq-footnote">Wait times are a rough estimate (~<?= MINUTES_PER_PATIENT ?> min per patient ahead of you), not a guarantee. This page refreshes on its own.</p><?php
else:
?>
    <section class="lq-card lq-idle">
      <div class="lq-idle-top">
        <span class="lq-idle-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/></svg></span>
        <h2>You're not in a queue right now</h2>
        <?php if ($nextVisit): ?>
          <p>Your next visit is <?= htmlspecialchars(date('M j', strtotime($nextVisit['AppointmentDate'])) . ', ' . date('g:i A', strtotime($nextVisit['AppointmentTime']))) ?> at <?= htmlspecialchars($nextVisit['ClinicName']) ?>.</p>
          <a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php?tab=all#appt-<?= (int) $nextVisit['AppointmentID'] ?>" class="btn btn-outline btn-sm">View appointment</a>
        <?php else: ?>
          <p>Book a visit and your queue number will show up here on the day.</p>
          <a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="btn btn-outline btn-sm">Find a clinic</a>
        <?php endif; ?>
      </div>
      <?php if ($partnerClinics): ?>
        <div class="lq-partners">
          <div class="lq-partners-title">Live at partner clinics</div>
          <?php foreach ($partnerClinics as $clinic): ?>
            <div class="lq-partner">
              <span class="lq-partner-name"><span class="lq-dot <?= $clinic['Hours']['open'] ? 'is-open' : 'is-closed' ?>"></span><?= htmlspecialchars($clinic['ClinicName']) ?></span>
              <span>
                <?php if (!$clinic['Hours']['open']): ?>
                  <?= htmlspecialchars($clinic['Hours']['label']) ?>
                <?php else: ?>
                  <?= $clinic['NowServing'] ? 'Serving #' . (int) $clinic['NowServing'] : 'No one being served' ?> ·
                  <span class="lq-chip <?= (int) $clinic['Waiting'] <= 5 ? 'lq-low' : 'lq-high' ?>"><?= (int) $clinic['Waiting'] ?> waiting</span>
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
<?php
endif;
$queueHtml = ob_get_clean();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    echo $queueHtml;
    exit;
}

$pageTitle = 'Live Queue Status — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container lq-page">
  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <div id="liveQueue"><?= $queueHtml ?></div>
</div></main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Refresh the queue card every 15s and tick the "Updated Xs ago" label.
  var container = document.getElementById('liveQueue');
  var lastUpdate = Date.now();

  setInterval(function () {
    var seconds = Math.floor((Date.now() - lastUpdate) / 1000);
    container.querySelectorAll('[data-updated-seconds]').forEach(function (el) { el.textContent = seconds; });
  }, 1000);

  setInterval(function () {
    fetch('<?= HQ_BASE_URL ?>/patient/queue-status.php', { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) { container.innerHTML = html; lastUpdate = Date.now(); })
      .catch(function () { /* keep the last view; try again next tick */ });
  }, 15000);
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
