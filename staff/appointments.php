<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/wallet.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$clinicId = (int) $user['ClinicID'];

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
        } elseif ($formType === 'accept_request') {
            $physicianId = filter_input(INPUT_POST, 'physician_id', FILTER_VALIDATE_INT) ?: null;
            try {
                // Scoped to this staff member's own clinic so one clinic's
                // staff can never touch another clinic's appointment requests.
                $checkStmt = $pdo->prepare("SELECT PhysicianID, PatientID, AppointmentDate, AppointmentTime FROM Appointments WHERE AppointmentID = ? AND ClinicID = ? AND Status = 'Pending'");
                $checkStmt->execute([$appointmentId, $clinicId]);
                $existing = $checkStmt->fetch();

                if (!$existing) {
                    $errors[] = 'That request could not be found.';
                } elseif (!$physicianId && !$existing['PhysicianID']) {
                    $errors[] = 'Please assign a physician before accepting this request.';
                } else {
                    if ($physicianId) {
                        $physCheck = $pdo->prepare(
                            "SELECT UserID FROM Users WHERE UserID = ? AND ClinicID = ?
                             AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician')"
                        );
                        $physCheck->execute([$physicianId, $clinicId]);
                        if (!$physCheck->fetch()) {
                            $errors[] = 'Please choose a physician from your clinic.';
                        }
                    }
                    if (!$errors) {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("UPDATE Appointments SET Status = 'Confirmed', PhysicianID = COALESCE(?, PhysicianID) WHERE AppointmentID = ? AND ClinicID = ?");
                        $stmt->execute([$physicianId, $appointmentId, $clinicId]);

                        // Accepting a request is the moment it joins the day's
                        // queue -- walk-ins get a queue number at registration,
                        // so an accepted online request needs the same step.
                        $queueNumStmt = $pdo->prepare('SELECT COALESCE(MAX(QueueNumber), 0) + 1 FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = ?');
                        $queueNumStmt->execute([$clinicId, $existing['AppointmentDate']]);
                        $queueNumber = (int) $queueNumStmt->fetchColumn();
                        $scheduledNumber = null;
                        $regularNumber = null;
                        if ($existing['AppointmentTime'] !== null) {
                            $scheduledNumStmt = $pdo->prepare(
                                "SELECT COALESCE(MAX(q.ScheduledNumber), 0) + 1
                                 FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID
                                 WHERE q.ClinicID = ? AND DATE(a.AppointmentDate) = ? AND q.ScheduledNumber IS NOT NULL"
                            );
                            $scheduledNumStmt->execute([$clinicId, $existing['AppointmentDate']]);
                            $scheduledNumber = (int) $scheduledNumStmt->fetchColumn();
                        } else {
                            $regularNumStmt = $pdo->prepare(
                                "SELECT COALESCE(MAX(RegularNumber), 0) + 1 FROM Queue
                                 WHERE ClinicID = ? AND DATE(CreatedAt) = ? AND RegularNumber IS NOT NULL"
                            );
                            $regularNumStmt->execute([$clinicId, $existing['AppointmentDate']]);
                            $regularNumber = (int) $regularNumStmt->fetchColumn();
                        }

                        $ticketToken = $scheduledNumber !== null ? 'S-' . $scheduledNumber : '#' . $regularNumber;
                        $insertQueue = $pdo->prepare(
                            "INSERT INTO Queue (ClinicID, AppointmentID, PhysicianID, QueueNumber, ScheduledNumber, RegularNumber, Status, CreatedByStaffID, CreatedAt) VALUES (?, ?, ?, ?, ?, ?, 'Waiting', ?, GREATEST(NOW(), TIMESTAMP(?, COALESCE(?, '00:00:00'))))"
                        );
                        $insertQueue->execute([$clinicId, $appointmentId, $physicianId ?: $existing['PhysicianID'], $queueNumber, $scheduledNumber, $regularNumber, $user['UserID'], $existing['AppointmentDate'], $existing['AppointmentTime']]);

                        $pdo->commit();
                        logActivity($pdo, $user['UserID'], $clinicId, 'Accepted appointment request', "Appointment #{$appointmentId}, queue {$ticketToken}");
                        notifyPatient($pdo, (int) $existing['PatientID'], 'Your appointment on ' . $existing['AppointmentDate'] . ' has been confirmed. Your queue number is ' . $ticketToken . '.', $appointmentId);
                        $flash = "Appointment request accepted as queue {$ticketToken}.";
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Accept request failed: ' . $e->getMessage());
                $errors[] = 'We could not accept this request.';
            }
        } elseif ($formType === 'reject_request') {
            // The reason is required and is shown to the patient.
            $declineReason = trim((string) ($_POST['decline_reason'] ?? ''));
            if ($declineReason === 'Other') $declineReason = trim((string) ($_POST['decline_reason_other'] ?? ''));
            $declineReason = mb_substr($declineReason, 0, 255);
            if ($declineReason === '') {
                $errors[] = 'Please provide a reason for declining this appointment.';
            } else {
            try {
                $pdo->beginTransaction();
                $lookup = $pdo->prepare(
                    "SELECT a.PatientID, a.BookingFeePaid, c.ClinicName, c.BaseConsultationFee
                     FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID
                     WHERE a.AppointmentID = ? AND a.ClinicID = ? AND a.Status = 'Pending' FOR UPDATE"
                );
                $lookup->execute([$appointmentId, $clinicId]);
                $target = $lookup->fetch();

                if (!$target) {
                    $pdo->rollBack();
                    $errors[] = 'That request could not be found.';
                } else {
                    $pdo->prepare("UPDATE Appointments SET Status = 'Cancelled', DeclineReason = ? WHERE AppointmentID = ? AND ClinicID = ?")
                        ->execute([$declineReason !== '' ? $declineReason : 'Declined by the clinic', $appointmentId, $clinicId]);
                    // A paid booking fee goes back to the patient's wallet.
                    $refund = $target['BookingFeePaid'] ? round((float) $target['BaseConsultationFee'], 2) : 0.0;
                    if ($refund > 0) {
                        walletRefund($pdo, (int) $target['PatientID'], $refund, $appointmentId, 'Refund for declined request at ' . $target['ClinicName']);
                    }
                    $pdo->commit();

                    logActivity($pdo, $user['UserID'], $clinicId, 'Declined appointment request', "Appointment #{$appointmentId}" . ($declineReason !== '' ? " ({$declineReason})" : ''));
                    notifyPatient(
                        $pdo,
                        (int) $target['PatientID'],
                        'Your appointment request was declined by the clinic' . ($declineReason !== '' ? ": {$declineReason}." : '.')
                            . ($refund > 0 ? ' PHP ' . number_format($refund, 2) . ' was refunded to your wallet.' : ''),
                        $appointmentId
                    );
                    $flash = 'Request declined' . ($refund > 0 ? ' and ₱' . number_format($refund) . ' refunded to the patient.' : '.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Reject request failed: ' . $e->getMessage());
                $errors[] = 'We could not decline this request.';
            }
            }
        } elseif ($formType === 'reschedule_request') {
            $newDate = trim((string) ($_POST['appointment_date'] ?? ''));
            $newTime = trim((string) ($_POST['appointment_time'] ?? ''));
            if ($newDate === '' || $newDate < date('Y-m-d') || $newTime === '') {
                $errors[] = 'Please choose a valid future date and time.';
            } else {
                try {
                    $patientStmt = $pdo->prepare('SELECT PatientID FROM Appointments WHERE AppointmentID = ? AND ClinicID = ?');
                    $patientStmt->execute([$appointmentId, $clinicId]);
                    $patientId = (int) $patientStmt->fetchColumn();

                    $stmt = $pdo->prepare('UPDATE Appointments SET AppointmentDate = ?, AppointmentTime = ? WHERE AppointmentID = ? AND ClinicID = ?');
                    $stmt->execute([$newDate, $newTime, $appointmentId, $clinicId]);
                    if ($stmt->rowCount()) {
                        logActivity($pdo, $user['UserID'], $clinicId, 'Rescheduled appointment', "Appointment #{$appointmentId} to {$newDate} {$newTime}");
                        if ($patientId) notifyPatient($pdo, $patientId, 'Your appointment was rescheduled to ' . $newDate . ' ' . $newTime . '.', $appointmentId);
                        $flash = 'Appointment rescheduled.';
                    } else {
                        $errors[] = 'That appointment could not be found.';
                    }
                } catch (PDOException $e) {
                    error_log('Reschedule failed: ' . $e->getMessage());
                    $errors[] = 'We could not reschedule this appointment.';
                }
            }
        } elseif ($formType === 'confirm_complete') {
            try {
                // Scoped to this staff member's own clinic, and only allowed
                // once the physician has actually finalized their notes --
                // staff confirm the visit is over, they don't write the notes.
                $checkStmt = $pdo->prepare(
                    "SELECT a.PatientID, a.Status,
                            (SELECT COUNT(*) FROM ConsultationVersions v
                             JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                             WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized') AS FinalizedCount
                     FROM Appointments a
                     WHERE a.AppointmentID = ? AND a.ClinicID = ?"
                );
                $checkStmt->execute([$appointmentId, $clinicId]);
                $target = $checkStmt->fetch();

                if (!$target || $target['Status'] !== 'Confirmed') {
                    $errors[] = 'That consultation could not be found or is no longer awaiting confirmation.';
                } elseif ((int) $target['FinalizedCount'] === 0) {
                    $errors[] = 'The physician has not finalized their notes for this visit yet.';
                } else {
                    $pdo->prepare("UPDATE Appointments SET Status = 'Completed' WHERE AppointmentID = ? AND ClinicID = ?")
                        ->execute([$appointmentId, $clinicId]);
                    $pdo->prepare("UPDATE Queue SET Status = 'Completed' WHERE AppointmentID = ? AND Status IN ('Waiting', 'Calling', 'Serving')")
                        ->execute([$appointmentId]);
                    logActivity($pdo, $user['UserID'], $clinicId, 'Confirmed consultation complete', "Appointment #{$appointmentId}");
                    notifyPatient($pdo, (int) $target['PatientID'], 'Your consultation record is ready to view.', $appointmentId);
                    $flash = 'Consultation confirmed as complete.';
                }
            } catch (PDOException $e) {
                error_log('Confirm consultation complete failed: ' . $e->getMessage());
                $errors[] = 'We could not confirm this consultation right now.';
            }
        }
    }

    // Approve/Decline buttons on the staff dashboard post here and go back.
    if (($_POST['return_to'] ?? '') === 'dashboard') {
        $_SESSION['dashboard_flash'] = $errors ? ['error', implode(' ', $errors)] : ['success', $flash];
        header('Location: ' . HQ_BASE_URL . '/staff/dashboard.php');
        exit;
    }
}

$tabs = ['requests' => 'Requests', 'today' => 'Today', 'confirm' => 'To confirm', 'all' => 'All'];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'requests';
$search = trim((string) ($_GET['q'] ?? ''));
$dateFilter = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : '';
$declineReasons = ['Fully booked', 'Physician unavailable', 'Other'];

$requests = [];
$todayList = [];
$pendingConfirmations = [];
$allList = [];
$physicians = [];
$physicianSchedule = [];
$tabCounts = array_fill_keys(array_keys($tabs), 0);
$today = null;
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $today = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
        $listDate = $dateFilter ?: $today;

        // Shared search/date filters for the Requests and All lists.
        $filterSql = '';
        $filterParams = [];
        if ($search !== '') {
            $filterSql .= " AND CONCAT(pat.FirstName, ' ', pat.LastName) LIKE ?";
            $filterParams[] = '%' . $search . '%';
        }
        if ($dateFilter !== '') {
            $filterSql .= ' AND a.AppointmentDate = ?';
            $filterParams[] = $dateFilter;
        }

        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.PhysicianID, a.BookingFeePaid,
                    TIMESTAMPDIFF(MINUTE, a.CreatedAt, NOW()) AS MinutesAgo,
                    pat.UserID AS PatientUserID, pat.FirstName, pat.LastName, pat.Email, pat.ContactNumber,
                    c.BaseConsultationFee,
                    (SELECT COUNT(*) FROM Appointments h WHERE h.PatientID = a.PatientID AND h.ClinicID = a.ClinicID) AS VisitCount
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             WHERE a.ClinicID = ? AND a.Status = 'Pending' AND a.BookingFeePaid = 1{$filterSql}
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        $stmt->execute(array_merge([$clinicId], $filterParams));
        $requests = $stmt->fetchAll();

        $physStmt = $pdo->prepare(
            "SELECT UserID, FirstName, LastName, AvailabilityStatus FROM Users
             WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
             ORDER BY LastName"
        );
        $physStmt->execute([$clinicId]);
        $physicians = $physStmt->fetchAll();

        // Each physician's date-based availability, used to warn when a
        // request is assigned to someone who has no hours (or a day off) then.
        foreach ($physicians as $physician) {
            $physicianSchedule[(int) $physician['UserID']] = [
                'name'    => 'Dr. ' . $physician['LastName'],
                'status'  => $physician['AvailabilityStatus'],
                'working' => [],
                'off'     => [],
            ];
        }
        $schedStmt = $pdo->prepare(
            "SELECT pa.PhysicianID, pa.AvailDate, MAX(pa.IsDayOff) AS DayOff, SUM(pa.IsDayOff = 0) AS Blocks
             FROM PhysicianDateAvailability pa JOIN Users u ON u.UserID = pa.PhysicianID
             WHERE u.ClinicID = ? AND pa.AvailDate >= CURDATE()
             GROUP BY pa.PhysicianID, pa.AvailDate"
        );
        $schedStmt->execute([$clinicId]);
        foreach ($schedStmt->fetchAll() as $row) {
            $pid = (int) $row['PhysicianID'];
            if (!isset($physicianSchedule[$pid])) continue;
            if ((int) $row['DayOff']) $physicianSchedule[$pid]['off'][] = $row['AvailDate'];
            elseif ((int) $row['Blocks']) $physicianSchedule[$pid]['working'][] = $row['AvailDate'];
        }

        $todayStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentTime, a.Concern, a.Status, a.BookingFeePaid,
                    pat.FirstName, pat.LastName, phy.LastName AS PhyLastName,
                    (SELECT q.QueueNumber FROM Queue q WHERE q.AppointmentID = a.AppointmentID ORDER BY q.CreatedAt DESC LIMIT 1) AS QueueNumber,
                    (SELECT q.ScheduledNumber FROM Queue q WHERE q.AppointmentID = a.AppointmentID ORDER BY q.CreatedAt DESC LIMIT 1) AS ScheduledNumber,
                    (SELECT q.RegularNumber FROM Queue q WHERE q.AppointmentID = a.AppointmentID ORDER BY q.CreatedAt DESC LIMIT 1) AS RegularNumber,
                    (SELECT q.Status FROM Queue q WHERE q.AppointmentID = a.AppointmentID ORDER BY q.CreatedAt DESC LIMIT 1) AS QueueStatus
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.ClinicID = ? AND a.AppointmentDate = ? AND a.Status IN ('Confirmed', 'Completed')
             ORDER BY a.AppointmentTime"
        );
        $todayStmt->execute([$clinicId, $listDate]);
        $todayList = $todayStmt->fetchAll();

        $confirmStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime,
                    pat.FirstName, pat.LastName, phy.LastName AS PhyLastName,
                    (SELECT v.UpdatedAt FROM ConsultationVersions v
                     JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                     WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized'
                     ORDER BY v.RevisionNumber DESC LIMIT 1) AS FinalizedAt
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.ClinicID = ? AND a.Status = 'Confirmed'
               AND EXISTS (
                   SELECT 1 FROM ConsultationVersions v
                   JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                   WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized'
               )
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        $confirmStmt->execute([$clinicId]);
        $pendingConfirmations = $confirmStmt->fetchAll();

        $allStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.Status, a.BookingFeePaid, a.CancellationReason, a.DeclineReason,
                    pat.FirstName, pat.LastName, phy.LastName AS PhyLastName
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.ClinicID = ?{$filterSql}
             ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC
             LIMIT 100"
        );
        $allStmt->execute(array_merge([$clinicId], $filterParams));
        $allList = $allStmt->fetchAll();

        $tabCounts = ['requests' => count($requests), 'today' => count($todayList), 'confirm' => count($pendingConfirmations), 'all' => count($allList)];
    } catch (PDOException $e) {
        error_log('Appointments list load failed: ' . $e->getMessage());
        $dataError = 'Appointments are temporarily unavailable.';
    }
}

$initials = static fn(array $row): string => strtoupper(mb_substr($row['FirstName'], 0, 1) . mb_substr($row['LastName'], 0, 1));
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
$concernLabel = static fn(?string $concern): string => mb_strimwidth(trim((string) $concern) ?: 'General', 0, 32, '…');
$statusPill = static function (array $row): array {
    if ($row['Status'] === 'Cancelled') {
        return !empty($row['DeclineReason']) || empty($row['CancellationReason']) ? ['Declined', 'ma-pill-declined'] : ['Cancelled', 'ma-pill-declined'];
    }
    return ['Pending' => ['Pending', 'ma-pill-pending'], 'Confirmed' => ['Approved', 'ma-pill-approved'], 'Completed' => ['Completed', 'ma-pill-completed']][$row['Status']] ?? [$row['Status'], 'ma-pill-completed'];
};
$tabUrl = static function (string $tab) use ($search, $dateFilter): string {
    return '?' . http_build_query(array_filter(['tab' => $tab, 'q' => $search, 'date' => $dateFilter], 'strlen'));
};

$pageTitle = 'Appointments — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container sa-page">
  <section class="sa-hero">
    <span class="an-eyebrow">Front desk</span>
    <h1>Appointments</h1>
    <p>Review requests, manage today's schedule, and confirm finished visits.</p>
    <form method="get" class="sa-filters">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
      <label class="sa-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search patient name" aria-label="Search patient name">
      </label>
      <input type="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>" aria-label="Filter by date" onchange="this.form.submit()">
      <button type="submit" class="btn sa-filter-btn">Search</button>
      <?php if ($search !== '' || $dateFilter !== ''): ?><a href="?tab=<?= htmlspecialchars($activeTab) ?>" class="sa-clear">Clear</a><?php endif; ?>
    </form>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <nav class="sa-tabs" aria-label="Appointment lists">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="<?= htmlspecialchars($tabUrl($key)) ?>" class="sa-tab<?= $activeTab === $key ? ' is-active' : '' ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>>
        <?= $key === 'today' && $dateFilter && $dateFilter !== $today ? htmlspecialchars(date('M j', strtotime($dateFilter))) : $label ?>
        <?php if ($key !== 'all'): ?><span class="sa-count<?= $key === 'confirm' && $tabCounts[$key] ? ' is-amber' : '' ?>"><?= $tabCounts[$key] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($activeTab === 'requests'): ?>
    <?php if ($requests): ?>
      <div class="sa-list">
        <?php foreach ($requests as $req): ?>
          <?php
            $id = (int) $req['AppointmentID'];
            $refund = $req['BookingFeePaid'] ? (float) $req['BaseConsultationFee'] : 0;
            $weekday = (int) date('N', strtotime($req['AppointmentDate']));
          ?>
          <article class="sa-item" data-request data-date="<?= htmlspecialchars($req['AppointmentDate']) ?>" data-weekday="<?= $weekday ?>" data-date-label="<?= htmlspecialchars(date('M j', strtotime($req['AppointmentDate']))) ?>" data-is-today="<?= $req['AppointmentDate'] === $today ? '1' : '0' ?>">
            <div class="sa-head">
              <span class="sa-avatar"><?= htmlspecialchars($initials($req)) ?></span>
              <div>
                <strong><?= htmlspecialchars($req['FirstName'] . ' ' . $req['LastName']) ?></strong>
                <p><?= htmlspecialchars(date('M j, g:i A', strtotime($req['AppointmentDate'] . ' ' . $req['AppointmentTime']))) ?> · <?= htmlspecialchars($concernLabel($req['Concern'])) ?> · requested <?= htmlspecialchars($timeAgo((int) $req['MinutesAgo'])) ?></p>
              </div>
            </div>

            <form method="post" class="sa-approve-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
              <input type="hidden" name="appointment_id" value="<?= $id ?>">
              <select name="physician_id" class="sa-physician" aria-label="Assign physician">
                <option value="">Choose a physician…</option>
                <?php foreach ($physicians as $physician): ?>
                  <option value="<?= (int) $physician['UserID'] ?>" <?= (int) $req['PhysicianID'] === (int) $physician['UserID'] ? 'selected' : '' ?>>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="sa-actions">
                <button type="button" class="btn btn-outline btn-sm" data-open-decline>Decline</button>
                <button type="submit" name="form_type" value="accept_request" class="btn btn-outline btn-sm sa-approve">Approve</button>
                <button type="button" class="sa-link" data-toggle="details-<?= $id ?>">Details</button>
                <button type="button" class="sa-link" data-toggle="resched-<?= $id ?>">Reschedule</button>
              </div>
              <p class="sa-warning" hidden></p>
            </form>

            <div class="sa-details" id="details-<?= $id ?>" hidden>
              <span><b>Email</b> <?= htmlspecialchars($req['Email']) ?></span>
              <span><b>Contact</b> <?= htmlspecialchars($req['ContactNumber'] ?: 'Not provided') ?></span>
              <span><b>Visits here</b> <?= (int) $req['VisitCount'] ?></span>
            </div>

            <form method="post" class="sa-resched" id="resched-<?= $id ?>" hidden>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
              <input type="hidden" name="form_type" value="reschedule_request">
              <input type="hidden" name="appointment_id" value="<?= $id ?>">
              <input type="date" name="appointment_date" min="<?= htmlspecialchars($today) ?>" value="<?= htmlspecialchars($req['AppointmentDate']) ?>" required>
              <input type="time" name="appointment_time" value="<?= htmlspecialchars($req['AppointmentTime']) ?>" required>
              <button type="submit" class="btn btn-outline btn-sm">Save new schedule</button>
            </form>

            <form method="post" class="sa-decline" hidden>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
              <input type="hidden" name="form_type" value="reject_request">
              <input type="hidden" name="appointment_id" value="<?= $id ?>">
              <p class="sa-decline-label">Reason for declining <span>(sent to patient)</span></p>
              <div class="sa-chips">
                <?php foreach ($declineReasons as $reason): ?>
                  <label class="sa-chip"><input type="radio" name="decline_reason" value="<?= htmlspecialchars($reason) ?>" required><span><?= htmlspecialchars($reason) ?></span></label>
                <?php endforeach; ?>
              </div>
              <input type="text" name="decline_reason_other" class="sa-other" maxlength="200" placeholder="Tell the patient why" hidden>
              <?php if ($refund > 0): ?>
                <p class="sa-refund">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M16 13h2M2 10h20"/></svg>
                  ₱<?= number_format($refund) ?> will be refunded to the patient's wallet.
                </p>
              <?php endif; ?>
              <div class="sa-actions">
                <button type="button" class="btn btn-outline btn-sm" data-cancel-decline>Cancel</button>
                <button type="submit" class="btn btn-sm sa-confirm-decline">Confirm decline</button>
              </div>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>No requests waiting</h3><p><?= $search !== '' || $dateFilter !== '' ? 'No requests match your search.' : 'New appointment requests for your clinic will appear here.' ?></p></div>
    <?php endif; ?>

  <?php elseif ($activeTab === 'today'): ?>
    <?php if ($todayList): ?>
      <div class="sa-list">
        <?php foreach ($todayList as $row): ?>
          <?php [$pillLabel, $pillClass] = $statusPill($row); ?>
          <article class="sa-item sa-row">
            <span class="sa-time"><?= htmlspecialchars(date('g:i A', strtotime($row['AppointmentTime']))) ?></span>
            <div class="sa-row-main">
              <strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></strong>
              <p><?= htmlspecialchars($concernLabel($row['Concern'])) ?> · <?= $row['PhyLastName'] ? 'Dr. ' . htmlspecialchars($row['PhyLastName']) : 'No physician' ?><?= $row['BookingFeePaid'] ? '' : ' · walk-in' ?><?= $row['QueueNumber'] ? ' - queue ' . ($row['ScheduledNumber'] !== null ? 'S-' . (int) $row['ScheduledNumber'] : '#' . (int) ($row['RegularNumber'] ?? $row['QueueNumber'])) . ' (' . htmlspecialchars(str_replace('_', ' ', $row['QueueStatus'])) . ')' : '' ?></p>
            </div>
            <span class="ma-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>Nothing scheduled</h3><p>No approved appointments on <?= htmlspecialchars(date('M j, Y', strtotime($dateFilter ?: ($today ?? 'now')))) ?>.</p></div>
    <?php endif; ?>

  <?php elseif ($activeTab === 'confirm'): ?>
    <?php if ($pendingConfirmations): ?>
      <div class="sa-list">
        <?php foreach ($pendingConfirmations as $item): ?>
          <article class="sa-item sa-row">
            <span class="sa-avatar"><?= htmlspecialchars($initials($item)) ?></span>
            <div class="sa-row-main">
              <strong><?= htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']) ?></strong>
              <p><?= $item['PhyLastName'] ? 'Dr. ' . htmlspecialchars($item['PhyLastName']) : 'No physician' ?> · <?= htmlspecialchars(date('M j, Y', strtotime($item['AppointmentDate']))) ?><?= $item['FinalizedAt'] ? ' · notes finalized ' . htmlspecialchars(date('M j, g:i A', strtotime($item['FinalizedAt']))) : '' ?></p>
            </div>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="confirm_complete"><input type="hidden" name="appointment_id" value="<?= (int) $item['AppointmentID'] ?>"><button class="btn btn-primary btn-sm">Confirm complete</button></form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>Nothing to confirm</h3><p>Once a physician finalizes a visit's notes, it will appear here for you to confirm.</p></div>
    <?php endif; ?>

  <?php else: ?>
    <?php if ($allList): ?>
      <div class="sa-list">
        <?php foreach ($allList as $row): ?>
          <?php [$pillLabel, $pillClass] = $statusPill($row); $ts = strtotime($row['AppointmentDate']); ?>
          <article class="sa-item sa-row">
            <div class="ma-date"><span><?= strtoupper(date('M', $ts)) ?></span><strong><?= date('j', $ts) ?></strong></div>
            <div class="sa-row-main">
              <strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></strong>
              <p><?= htmlspecialchars(date('g:i A', strtotime($row['AppointmentTime']))) ?> · <?= htmlspecialchars($concernLabel($row['Concern'])) ?> · <?= $row['PhyLastName'] ? 'Dr. ' . htmlspecialchars($row['PhyLastName']) : 'No physician' ?><?= $row['DeclineReason'] ? ' · ' . htmlspecialchars($row['DeclineReason']) : '' ?></p>
            </div>
            <span class="ma-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>No appointments found</h3><p>Try a different name or date.</p></div>
    <?php endif; ?>
  <?php endif; ?>
</div></main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Physician schedules: { id: { name, status, days: [ISO weekdays] } }.
  var schedules = <?= json_encode($physicianSchedule, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  document.querySelectorAll('[data-request]').forEach(function (item) {
    var select = item.querySelector('.sa-physician');
    var warning = item.querySelector('.sa-warning');
    var approveForm = item.querySelector('.sa-approve-form');
    var declineForm = item.querySelector('.sa-decline');
    var otherInput = declineForm.querySelector('.sa-other');

    // Warn when the chosen physician doesn't work that weekday (per their
    // weekly schedule) or, for today's requests, is marked unavailable.
    function checkPhysician() {
      var info = schedules[select.value];
      var message = '';
      if (info) {
        var date = item.getAttribute('data-date');
        var label = item.getAttribute('data-date-label');
        if (info.off.indexOf(date) !== -1) {
          message = info.name + ' has a day off on ' + label + '. Assign another physician or decline.';
        } else if (info.working.indexOf(date) === -1) {
          message = info.name + ' has no hours set on ' + label + '. Assign another physician or decline.';
        } else if (item.getAttribute('data-is-today') === '1' && info.status !== 'Available') {
          message = info.name + ' is marked "' + info.status + '" today. Assign another physician or decline.';
        }
      }
      warning.hidden = !message;
      warning.textContent = message ? '⚠ ' + message : '';
    }
    select.addEventListener('change', checkPhysician);
    checkPhysician();

    item.querySelector('[data-open-decline]').addEventListener('click', function () {
      approveForm.hidden = true;
      declineForm.hidden = false;
      item.classList.add('is-declining');
    });
    item.querySelector('[data-cancel-decline]').addEventListener('click', function () {
      declineForm.hidden = true;
      approveForm.hidden = false;
      item.classList.remove('is-declining');
    });
    declineForm.querySelectorAll('input[name="decline_reason"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        var isOther = radio.value === 'Other' && radio.checked;
        otherInput.hidden = !isOther;
        otherInput.required = isOther;
        if (isOther) otherInput.focus();
      });
    });

    item.querySelectorAll('[data-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var panel = document.getElementById(btn.getAttribute('data-toggle'));
        if (panel) panel.hidden = !panel.hidden;
      });
    });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
