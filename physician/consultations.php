<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

const LONG_WAIT_MINUTES = 30;

$user = currentUser();
$pdo = getDbConnection();
$physicianId = (int) $user['UserID'];
$errors = [];
$flash = '';
if (!empty($_SESSION['consult_flash'])) {
    $flash = $_SESSION['consult_flash'];
    unset($_SESSION['consult_flash']);
}

// "Start consultation": the patient moves to Serving and the workspace opens.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'start_consultation') {
    $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($pdo && $appointmentId) {
        try {
            $check = $pdo->prepare("SELECT a.AppointmentID, a.ClinicID, a.PatientID FROM Appointments a WHERE a.AppointmentID = ? AND a.PhysicianID = ? AND a.Status = 'Confirmed'");
            $check->execute([$appointmentId, $physicianId]);
            if ($appt = $check->fetch()) {
                $pdo->prepare(
                    "UPDATE Queue SET Status = 'Serving', CalledAt = COALESCE(CalledAt, NOW()), ServedAt = NOW()
                     WHERE AppointmentID = ? AND DATE(CreatedAt) = CURDATE() AND Status IN ('Waiting', 'Calling')"
                )->execute([$appointmentId]);
                $exists = $pdo->prepare('SELECT 1 FROM Consultations WHERE AppointmentID = ?');
                $exists->execute([$appointmentId]);
                if (!$exists->fetchColumn()) {
                    $pdo->prepare('INSERT INTO Consultations (ClinicID, AppointmentID, PatientID, DoctorID) VALUES (?, ?, ?, ?)')
                        ->execute([$appt['ClinicID'], $appointmentId, $appt['PatientID'], $physicianId]);
                }
                logActivity($pdo, $physicianId, (int) $appt['ClinicID'], 'Started consultation', "Appointment #{$appointmentId}");
                header('Location: ' . HQ_BASE_URL . '/physician/consultation.php?appointment_id=' . $appointmentId);
                exit;
            }
            $errors[] = 'That appointment is no longer available.';
        } catch (PDOException $e) {
            error_log('Start consultation failed: ' . $e->getMessage());
            $errors[] = 'We could not start this consultation.';
        }
    }
}

$inProgress = [];
$upNext = [];
$awaiting = [];
$scheduled = [];
$stats = ['waiting' => 0, 'seen' => 0];
$clinicName = '';
$dataError = null;

if ($pdo) {
    try {
        $clinicStmt = $pdo->prepare('SELECT ClinicName FROM Clinic WHERE ClinicID = ?');
        $clinicStmt->execute([(int) $user['ClinicID']]);
        $clinicName = (string) $clinicStmt->fetchColumn();

        // Every confirmed appointment of mine, with today's queue entry and note state.
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.BookingFeePaid,
                    pat.FirstName, pat.LastName,
                    q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status AS QueueStatus,
                    TIMESTAMPDIFF(MINUTE, q.CreatedAt, NOW()) AS WaitingMinutes,
                    TIMESTAMPDIFF(SECOND, q.ServedAt, NOW()) AS ServingSeconds,
                    (SELECT MAX(v.UpdatedAt) FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID = v.ConsultationID
                     WHERE c.AppointmentID = a.AppointmentID AND v.Status = 'Finalized') AS FinalizedAt,
                    (SELECT COUNT(*) FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID = v.ConsultationID
                     WHERE c.AppointmentID = a.AppointmentID AND v.Status = 'Draft') AS DraftCount,
                    (SELECT c.AudioFilePath FROM Consultations c WHERE c.AppointmentID = a.AppointmentID) AS AudioFilePath
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Queue q ON q.QueueID = (
                 SELECT q2.QueueID FROM Queue q2 WHERE q2.AppointmentID = a.AppointmentID AND DATE(q2.CreatedAt) = CURDATE()
                 ORDER BY q2.CreatedAt DESC LIMIT 1
             )
             WHERE a.PhysicianID = ? AND a.Status = 'Confirmed'
             ORDER BY COALESCE(q.Position, q.QueueNumber * 10), a.AppointmentDate, a.AppointmentTime"
        );
        $stmt->execute([$physicianId]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['FinalizedAt']) {
                $awaiting[] = $row;
            } elseif ($row['QueueStatus'] === 'Serving') {
                $inProgress[] = $row;
            } elseif (in_array($row['QueueStatus'], ['Waiting', 'Calling'], true)) {
                $upNext[] = $row;
            } else {
                $scheduled[] = $row;
            }
        }
        $stats['waiting'] = count($upNext);

        $seenStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM Queue WHERE PhysicianID = ? AND DATE(CreatedAt) = CURDATE() AND Status = 'Completed'"
        );
        $seenStmt->execute([$physicianId]);
        $stats['seen'] = (int) $seenStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Consultations list load failed: ' . $e->getMessage());
        $dataError = 'Your consultations list is temporarily unavailable.';
    }
}

$source = static fn(array $r): string => $r['BookingFeePaid'] ? 'Appointment ' . date('g:i A', strtotime($r['AppointmentTime'])) : 'Walk-in';
$concern = static fn(array $r): string => trim((string) $r['Concern']) !== '' ? '"' . mb_strimwidth(trim($r['Concern']), 0, 60, '…') . '"' : 'No stated concern';
$waitLabel = static fn(int $m): string => $m < 60 ? "waiting {$m} min" : 'waiting ' . intdiv($m, 60) . ' h ' . ($m % 60) . ' min';
$csrf = htmlspecialchars(csrfToken());

$pageTitle = 'Consultations — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container cs-page">
  <section class="an-hero">
    <div>
      <span class="an-eyebrow">Active visits</span>
      <h1>Consultations</h1>
      <p><?= htmlspecialchars(trim(($clinicName ? $clinicName . ' · ' : '') . ($pdo ? date('l, M j', strtotime((string) $pdo->query('SELECT CURDATE()')->fetchColumn())) : ''))) ?></p>
    </div>
    <div class="an-hero-stats">
      <div><strong><?= $stats['waiting'] ?></strong><span>Waiting</span></div>
      <div class="cs-stat-green"><strong><?= $stats['seen'] ?></strong><span>Seen today</span></div>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <h3 class="qm-section-title">In progress</h3>
  <?php if ($inProgress): ?>
    <?php foreach ($inProgress as $row): ?>
      <?php $secs = max(0, (int) $row['ServingSeconds']); ?>
      <article class="cs-row cs-active">
        <span class="qm-num"><?= $row['ScheduledNumber'] !== null ? 'S-' . (int) $row['ScheduledNumber'] : '#' . (int) ($row['RegularNumber'] ?? $row['QueueNumber']) ?></span>
        <div class="cs-main">
          <strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?> <em>· <?= htmlspecialchars($source($row)) ?></em></strong>
          <p><?= htmlspecialchars($concern($row)) ?></p>
        </div>
        <span class="cs-pill"><?= (int) $row['DraftCount'] ? 'Draft saved · ' : 'In consultation · ' ?><?= sprintf('%02d:%02d', intdiv($secs, 60), $secs % 60) ?></span>
        <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $row['AppointmentID'] ?>" class="btn btn-outline btn-sm">Continue</a>
      </article>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="cs-empty">No one is with you right now.</p>
  <?php endif; ?>

  <h3 class="qm-section-title">Up next · <?= count($upNext) ?></h3>
  <?php if ($upNext): ?>
    <div class="sa-list">
      <?php foreach ($upNext as $i => $row): ?>
        <?php $mins = (int) $row['WaitingMinutes']; ?>
        <article class="cs-row">
          <span class="qm-num"><?= $row['ScheduledNumber'] !== null ? 'S-' . (int) $row['ScheduledNumber'] : '#' . (int) ($row['RegularNumber'] ?? $row['QueueNumber']) ?></span>
          <div class="cs-main">
            <strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?> <em>· <?= htmlspecialchars($source($row)) ?><?= $row['QueueStatus'] === 'Calling' ? ' · being called' : '' ?></em></strong>
            <p><?= htmlspecialchars($concern($row)) ?></p>
          </div>
          <span class="qm-wait<?= $mins >= LONG_WAIT_MINUTES ? ' is-long' : '' ?>"><?= $waitLabel($mins) ?></span>
          <?php if ($i === 0 && !$inProgress): ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="form_type" value="start_consultation">
              <input type="hidden" name="appointment_id" value="<?= (int) $row['AppointmentID'] ?>">
              <button type="submit" class="btn btn-outline btn-sm">Start consultation</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="cs-empty">No patients waiting in your queue.</p>
  <?php endif; ?>

  <h3 class="qm-section-title">Awaiting staff confirmation · <?= count($awaiting) ?></h3>
  <?php if ($awaiting): ?>
    <div class="sa-list">
      <?php foreach ($awaiting as $row): ?>
        <article class="cs-row">
          <div class="cs-main">
            <strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?> <em>· finalized <?= htmlspecialchars(date('g:i A', strtotime($row['FinalizedAt']))) ?><?= date('Y-m-d', strtotime($row['FinalizedAt'])) !== date('Y-m-d') ? ' ' . htmlspecialchars(date('M j', strtotime($row['FinalizedAt']))) : '' ?></em></strong>
          </div>
          <span class="ma-pill ma-pill-pending">Awaiting confirmation</span>
          <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $row['AppointmentID'] ?>" class="btn btn-outline btn-sm">View</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="cs-empty">Nothing waiting on the front desk.</p>
  <?php endif; ?>

  <?php if ($scheduled): ?>
    <details class="qm-finished cs-scheduled">
      <summary><span>Other confirmed appointments</span><span class="ma-pill ma-pill-completed"><?= count($scheduled) ?></span></summary>
      <ul>
        <?php foreach ($scheduled as $row): ?>
          <li>
            <?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?>
            <em><?= htmlspecialchars(date('M j, g:i A', strtotime($row['AppointmentDate'] . ' ' . $row['AppointmentTime']))) ?></em>
            <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $row['AppointmentID'] ?>" class="text-link">Open</a>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
