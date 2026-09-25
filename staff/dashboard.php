<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/clinic-hours.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

// Same rough per-patient estimate the patient pages use.
const MINUTES_PER_PATIENT = 15;

$user = currentUser();
$pdo = getDbConnection();
$clinicId = (int) $user['ClinicID'];
$flash = null;

// Flash handed back by staff/appointments.php after Approve/Decline.
if (!empty($_SESSION['dashboard_flash'])) {
    $flash = $_SESSION['dashboard_flash'];
    unset($_SESSION['dashboard_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $flash = ['error', 'Your session expired. Please try again.'];
    } elseif (!$pdo || !$clinicId) {
        $flash = ['error', 'The database is temporarily unavailable.'];
    } elseif ($formType === 'call_next') {
        try {
            // Next in line = lowest waiting queue number today.
            $next = $pdo->prepare(
                "SELECT q.QueueID, q.QueueNumber, a.PatientID, a.AppointmentID FROM Queue q
                 JOIN Appointments a ON a.AppointmentID = q.AppointmentID
                 WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status = 'Waiting' AND (a.AppointmentTime IS NULL OR TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW()) AND NOT EXISTS (SELECT 1 FROM Queue activeQ WHERE activeQ.ClinicID = q.ClinicID AND activeQ.Status IN ('Calling', 'Serving'))
                 ORDER BY CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN 0 ELSE 1 END, CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN TIMESTAMP(a.AppointmentDate, a.AppointmentTime) END, q.QueueNumber LIMIT 1"
            );
            $next->execute([$clinicId]);
            $entry = $next->fetch();
            if (!$entry) {
                $flash = ['error', 'No one is waiting in the queue right now.'];
            } else {
                $pdo->prepare("UPDATE Queue SET Status = 'Calling', CalledAt = NOW() WHERE QueueID = ? AND Status = 'Waiting'")->execute([$entry['QueueID']]);
                notifyPatient($pdo, (int) $entry['PatientID'], "You're being called now — please proceed to the counter.", (int) $entry['AppointmentID']);
                logActivity($pdo, $user['UserID'], $clinicId, 'Updated queue status', "Queue #{$entry['QueueNumber']} set to Calling");
                $flash = ['success', "Calling queue #{$entry['QueueNumber']}."];
            }
        } catch (PDOException $e) {
            error_log('Call next failed: ' . $e->getMessage());
            $flash = ['error', 'We could not call the next patient.'];
        }
    }
}

$clinic = null;
$now = null;
$stats = ['pending' => 0, 'pending_new' => 0, 'today' => 0, 'today_done' => 0, 'in_queue' => 0, 'avg_wait' => null, 'avg_wait_yesterday' => null];
$nowServing = null;
$upNext = [];
$pendingRequests = [];
$physicians = [];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $now = clinicNow($pdo);
        $clinicStmt = $pdo->prepare('SELECT ClinicName, OpenDays, OpenTime, CloseTime FROM Clinic WHERE ClinicID = ?');
        $clinicStmt->execute([$clinicId]);
        $clinic = $clinicStmt->fetch() ?: null;

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS Total, COALESCE(SUM(DATE(CreatedAt) = CURDATE()), 0) AS NewToday
             FROM Appointments WHERE ClinicID = ? AND Status = 'Pending' AND BookingFeePaid = 1"
        );
        $stmt->execute([$clinicId]);
        $row = $stmt->fetch();
        $stats['pending'] = (int) $row['Total'];
        $stats['pending_new'] = (int) $row['NewToday'];

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS Total, COALESCE(SUM(Status = 'Completed'), 0) AS Done
             FROM Appointments WHERE ClinicID = ? AND AppointmentDate = CURDATE() AND Status IN ('Confirmed', 'Completed')"
        );
        $stmt->execute([$clinicId]);
        $row = $stmt->fetch();
        $stats['today'] = (int) $row['Total'];
        $stats['today_done'] = (int) $row['Done'];

        // Average time from joining the queue to being called, today vs yesterday.
        $waitStmt = $pdo->prepare(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, CreatedAt, CalledAt)) FROM Queue
             WHERE ClinicID = ? AND CalledAt IS NOT NULL AND DATE(CreatedAt) = CURDATE() - INTERVAL ? DAY"
        );
        $waitStmt->execute([$clinicId, 0]);
        $stats['avg_wait'] = ($v = $waitStmt->fetchColumn()) !== null ? (int) round((float) $v) : null;
        $waitStmt->execute([$clinicId, 1]);
        $stats['avg_wait_yesterday'] = ($v = $waitStmt->fetchColumn()) !== null ? (int) round((float) $v) : null;

        $queueStmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, a.AppointmentTime, a.BookingFeePaid,
                    pat.FirstName, pat.LastName, phy.LastName AS PhyLastName
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY FIELD(q.Status, 'Serving', 'Calling', 'Waiting'), q.QueueNumber"
        );
        $queueStmt->execute([$clinicId]);
        $active = $queueStmt->fetchAll();
        $stats['in_queue'] = count($active);
        foreach ($active as $entry) {
            if ($entry['Status'] !== 'Waiting' && !$nowServing) {
                $nowServing = $entry;
            } elseif ($entry['Status'] === 'Waiting') {
                $upNext[] = $entry;
            }
        }

        $reqStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.PhysicianID, pat.FirstName, pat.LastName
             FROM Appointments a JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.ClinicID = ? AND a.Status = 'Pending' AND a.BookingFeePaid = 1
             ORDER BY a.AppointmentDate, a.AppointmentTime LIMIT 3"
        );
        $reqStmt->execute([$clinicId]);
        $pendingRequests = $reqStmt->fetchAll();

        $physStmt = $pdo->prepare(
            "SELECT u.UserID, u.FirstName, u.LastName, u.AvailabilityStatus,
                    (SELECT COUNT(*) FROM Queue q WHERE q.PhysicianID = u.UserID AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')) AS InQueue
             FROM Users u
             WHERE u.ClinicID = ? AND u.Status = 'Active' AND u.RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician')
             ORDER BY u.LastName"
        );
        $physStmt->execute([$clinicId]);
        $physicians = $physStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Staff dashboard failed: ' . $e->getMessage());
        $dataError = 'Some dashboard information is temporarily unavailable.';
    }
}

$hour = $now ? (int) $now->format('G') : (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$hours = $clinic && $now ? clinicOpenStatus($clinic, $now) : null;
$hoursText = $hours ? ($hours['open'] ? 'Clinic open until ' . clinicFormatTime($clinic['CloseTime']) : $hours['label']) : '';

$availableCount = count(array_filter($physicians, static fn($p) => $p['AvailabilityStatus'] === 'Available'));
$breakCount = count(array_filter($physicians, static fn($p) => $p['AvailabilityStatus'] === 'On Break'));
$availabilityDots = ['Available' => 'sd-dot-green', 'On Break' => 'sd-dot-amber', 'Unavailable' => 'sd-dot-slate'];
$waitDelta = ($stats['avg_wait'] !== null && $stats['avg_wait_yesterday'] !== null) ? $stats['avg_wait_yesterday'] - $stats['avg_wait'] : null;

$pageTitle = 'Staff Dashboard — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container sd-page">
  <section class="sd-hero">
    <div>
      <h1><?= $greeting ?>, <?= htmlspecialchars($user['FirstName']) ?></h1>
      <p><?= $now ? htmlspecialchars($now->format('l, M j')) : '' ?><?= $hoursText ? ' · ' . htmlspecialchars($hoursText) : '' ?></p>
    </div>
    <div class="sd-hero-actions">
      <a href="<?= HQ_BASE_URL ?>/staff/queue.php#walk-in" class="btn sd-btn-ghost">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
        Register walk-in
      </a>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="form_type" value="call_next">
        <button type="submit" class="btn sd-btn-call"<?= ($upNext && !$nowServing) ? '' : ' disabled title="A visit is in progress or no one is waiting"' ?>>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
          Call next patient
        </button>
      </form>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message <?= $flash[0] === 'error' ? 'error' : 'success' ?>" role="status"><?= htmlspecialchars($flash[1]) ?></p><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="sd-stats">
    <a href="<?= HQ_BASE_URL ?>/staff/appointments.php">
      <span>Pending requests</span><strong><?= $stats['pending'] ?></strong>
      <small class="<?= $stats['pending_new'] ? 'sd-amber' : '' ?>"><?= $stats['pending_new'] ?> new today</small>
    </a>
    <a href="<?= HQ_BASE_URL ?>/staff/appointments.php">
      <span>Today's appointments</span><strong><?= $stats['today'] ?></strong>
      <small><?= $stats['today_done'] ?> done · <?= $stats['today'] - $stats['today_done'] ?> left</small>
    </a>
    <a href="<?= HQ_BASE_URL ?>/staff/queue.php">
      <span>In queue</span><strong><?= $stats['in_queue'] ?></strong>
      <small><?= $nowServing ? 'Serving #' . (int) $nowServing['QueueNumber'] : 'No one being served' ?></small>
    </a>
    <div>
      <span>Average wait</span><strong><?= $stats['avg_wait'] !== null ? $stats['avg_wait'] . '<em> min</em>' : '—' ?></strong>
      <small class="<?= $waitDelta !== null ? ($waitDelta >= 0 ? 'sd-green' : 'sd-red') : '' ?>">
        <?php if ($stats['avg_wait'] === null): ?>No calls yet today<?php elseif ($waitDelta === null): ?>Joining the queue → being called<?php elseif ($waitDelta === 0): ?>Same as yesterday<?php else: ?><?= abs($waitDelta) ?> min <?= $waitDelta > 0 ? 'faster' : 'slower' ?> than yesterday<?php endif; ?>
      </small>
    </div>
  </section>

  <div class="sd-grid">
    <section class="sd-card">
      <div class="sd-card-head">
        <h2>Queue</h2>
        <a href="<?= HQ_BASE_URL ?>/staff/queue.php" class="text-link">Open queue management</a>
      </div>
      <?php if ($nowServing): ?>
        <div class="sd-serving">
          <div><span>Now <?= $nowServing['Status'] === 'Calling' ? 'calling' : 'serving' ?></span><strong><?= $nowServing['ScheduledNumber'] !== null ? 'S-' . (int) $nowServing['ScheduledNumber'] : '#' . (int) ($nowServing['RegularNumber'] ?? $nowServing['QueueNumber']) ?></strong></div>
          <div>
            <b><?= htmlspecialchars($nowServing['FirstName'] . ' ' . $nowServing['LastName']) ?></b>
            <p><?= $nowServing['PhyLastName'] ? 'Dr. ' . htmlspecialchars($nowServing['PhyLastName']) : 'No physician assigned' ?></p>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($upNext): ?>
        <ul class="sd-queue">
          <?php foreach (array_slice($upNext, 0, 5) as $i => $entry): ?>
            <li>
              <span class="sd-num"><?= $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']) ?></span>
              <span class="sd-name"><?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?> <em>· <?= $entry['BookingFeePaid'] ? htmlspecialchars(date('g:i A', strtotime($entry['AppointmentTime']))) : 'walk-in' ?></em></span>
              <span class="sd-wait">~<?= ($i + 1) * MINUTES_PER_PATIENT ?> min</span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($upNext) > 5): ?><p class="sd-more">+<?= count($upNext) - 5 ?> more waiting</p><?php endif; ?>
      <?php elseif (!$nowServing): ?>
        <p class="sd-empty">No one in the queue yet today.</p>
      <?php else: ?>
        <p class="sd-empty">No one else is waiting.</p>
      <?php endif; ?>
    </section>

    <div class="sd-col">
      <section class="sd-card">
        <div class="sd-card-head">
          <h2>Pending requests</h2>
          <a href="<?= HQ_BASE_URL ?>/staff/appointments.php" class="text-link">View all <?= $stats['pending'] ?></a>
        </div>
        <?php if ($pendingRequests): ?>
          <ul class="sd-requests">
            <?php foreach ($pendingRequests as $req): ?>
              <li>
                <div>
                  <b><?= htmlspecialchars($req['FirstName'] . ' ' . $req['LastName']) ?></b>
                  <p><?= htmlspecialchars(date('M j, g:i A', strtotime($req['AppointmentDate'] . ' ' . $req['AppointmentTime']))) ?> · <?= htmlspecialchars(mb_strimwidth(trim((string) $req['Concern']) ?: 'General', 0, 28, '…')) ?></p>
                </div>
                <form method="post" action="<?= HQ_BASE_URL ?>/staff/appointments.php" class="sd-req-actions">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                  <input type="hidden" name="appointment_id" value="<?= (int) $req['AppointmentID'] ?>">
                  <input type="hidden" name="return_to" value="dashboard">
                  <button type="submit" name="form_type" value="reject_request" class="sd-decline" onclick="return confirm('Decline this appointment request?');">Decline</button>
                  <?php if ($req['PhysicianID']): ?>
                    <button type="submit" name="form_type" value="accept_request" class="btn btn-primary btn-sm">Approve</button>
                  <?php else: ?>
                    <a href="<?= HQ_BASE_URL ?>/staff/appointments.php" class="btn btn-primary btn-sm" title="Choose a physician first">Assign</a>
                  <?php endif; ?>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="sd-empty">No requests waiting for approval.</p>
        <?php endif; ?>
      </section>

      <section class="sd-card">
        <div class="sd-card-head">
          <h2>Physicians</h2>
          <span class="sd-muted"><?= $availableCount ?> available · <?= $breakCount ?> on break</span>
        </div>
        <p class="sd-note">Physicians update their own availability from their dashboard.</p>
        <?php if ($physicians): ?>
          <ul class="sd-physicians">
            <?php foreach ($physicians as $phy): ?>
              <li>
                <span class="sd-dot <?= $availabilityDots[$phy['AvailabilityStatus']] ?? 'sd-dot-slate' ?>"></span>
                <span class="sd-name">Dr. <?= htmlspecialchars($phy['LastName']) ?> <em>· <?= (int) $phy['InQueue'] ?> in queue</em></span>
                <span class="sd-status <?= $availabilityDots[$phy['AvailabilityStatus']] ?? 'sd-dot-slate' ?>"><?= htmlspecialchars($phy['AvailabilityStatus']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="sd-empty">No physicians are assigned to this clinic yet.</p>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
