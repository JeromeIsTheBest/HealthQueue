<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$clinics = [];
$physicians = [];
$errors = [];
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$values = ['clinic_id' => (string) ($_GET['clinic'] ?? ''), 'physician_id' => '', 'appointment_date' => '', 'appointment_time' => '', 'concern' => ''];

if ($pdo) {
    try {
        $clinics = $pdo->query("SELECT ClinicID, ClinicName, Address FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName")->fetchAll();
        $physicians = $pdo->query("SELECT u.UserID, u.ClinicID, u.FirstName, u.LastName, c.ClinicName FROM Users u JOIN Roles r ON r.RoleID = u.RoleID JOIN Clinic c ON c.ClinicID = u.ClinicID WHERE r.RoleName = 'Physician' AND u.Status = 'Active' AND u.DeletedAt IS NULL AND c.archived = 0 AND c.Status = 'Active' ORDER BY c.ClinicName, u.LastName")->fetchAll();
    } catch (PDOException $e) {
        error_log('Clinic lookup failed: ' . $e->getMessage());
        $errors[] = 'Clinics are temporarily unavailable. Please try again later.';
    }
} else {
    $errors[] = 'Booking is temporarily unavailable. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) $errors[] = 'Your session expired. Please try again.';
    foreach ($values as $field => $value) $values[$field] = trim((string) ($_POST[$field] ?? ''));

    $clinicIds = array_map(static fn(array $clinic): string => (string) $clinic['ClinicID'], $clinics);
    if (!in_array($values['clinic_id'], $clinicIds, true)) $errors[] = 'Please select a clinic.';
    if ($values['appointment_date'] === '' || $values['appointment_date'] < date('Y-m-d')) $errors[] = 'Please choose a future appointment date.';
    if ($values['physician_id'] !== '') {
        $validPhysician = false;
        foreach ($physicians as $physician) {
            if ((string) $physician['UserID'] === $values['physician_id'] && (string) $physician['ClinicID'] === $values['clinic_id']) {
                $validPhysician = true;
                break;
            }
        }
        if (!$validPhysician) $errors[] = 'Please choose a physician from the selected clinic.';
    }

    // A patient may have one active appointment request at a time.
    if (!$errors && $pdo) {
        $activeBooking = $pdo->prepare("SELECT AppointmentID FROM Appointments WHERE PatientID = ? AND Status IN ('Pending', 'Confirmed') LIMIT 1");
        $activeBooking->execute([$user['UserID']]);
        if ($activeBooking->fetchColumn()) {
            $errors[] = 'You already have an active booking. You can book again after it is completed or cancelled.';
        }
    }

    // Hold a per-clinic lock from the capacity check until the insert, so two
    // patients can't both take the last spot (MySQL frees it when the request ends).
    $clinicLocked = false;
    if (!$errors && $pdo && !($clinicLocked = lockClinicBooking($pdo, (int) $values['clinic_id']))) {
        $errors[] = 'The clinic is busy right now. Please try again in a moment.';
    }

    // Only dates/times inside a physician's published hours (with capacity left) can be booked.
    if (!$errors && $pdo && $values['appointment_time'] !== '' && !isBookable($pdo, (int) $values['clinic_id'], $values['physician_id'] !== '' ? (int) $values['physician_id'] : null, $values['appointment_date'], $values['appointment_time'])) {
        $errors[] = $values['physician_id'] !== ''
            ? 'That physician has no open slot at that date and time (it may have just filled up). Please pick one of the available times.'
            : 'No physician at this clinic has an open slot at that date and time. Please pick one of the available times.';
    }

    if (!$errors && $pdo) {
        try {
            $stmt = $pdo->prepare('INSERT INTO Appointments (PatientID, ClinicID, PhysicianID, AppointmentDate, AppointmentTime, Concern) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$user['UserID'], (int) $values['clinic_id'], $values['physician_id'] !== '' ? (int) $values['physician_id'] : null, $values['appointment_date'], $values['appointment_time'] !== '' ? $values['appointment_time'] : null, $values['concern'] ?: null]);
            $newAppointmentId = (int) $pdo->lastInsertId();

            if ($isAjax) {
                $clinicRow = array_values(array_filter($clinics, static fn(array $c): bool => (string) $c['ClinicID'] === $values['clinic_id']))[0] ?? null;
                $physRow = array_values(array_filter($physicians, static fn(array $p): bool => (string) $p['UserID'] === $values['physician_id']))[0] ?? null;
                $feeStmt = $pdo->prepare('SELECT BaseConsultationFee FROM Clinic WHERE ClinicID = ?');
                $feeStmt->execute([(int) $values['clinic_id']]);
                $fee = (float) $feeStmt->fetchColumn();

                $walletStmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ?');
                $walletStmt->execute([$user['UserID']]);
                $walletBalance = (float) $walletStmt->fetchColumn();

                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => true,
                    'appointment_id' => $newAppointmentId,
                    'clinic_name' => $clinicRow['ClinicName'] ?? '',
                    'physician_label' => $physRow ? ('Dr. ' . $physRow['FirstName'] . ' ' . $physRow['LastName']) : 'No physician preference',
                    'appointment_date' => date('M j, Y', strtotime($values['appointment_date'])),
                    'appointment_time' => $values['appointment_time'] !== '' ? date('g:i A', strtotime($values['appointment_time'])) : 'Regular queue',
                    'fee' => number_format($fee, 2),
                    'wallet_balance' => number_format($walletBalance, 2),
                    'can_pay_with_wallet' => $walletBalance >= $fee,
                    'fee_short' => number_format($fee),
                    'concern' => $values['concern'] !== '' ? mb_strimwidth($values['concern'], 0, 40, '…') : 'General consultation',
                    'appointment_when' => date('D, M j, Y', strtotime($values['appointment_date'])) . ($values['appointment_time'] !== '' ? ' at ' . date('g:i A', strtotime($values['appointment_time'])) : ' - Regular queue'),
                    'hold_seconds' => UNPAID_HOLD_MINUTES * 60,
                ]);
                exit;
            }

            // The clinic isn't notified yet -- that happens once the
            // simulated booking fee is paid in checkout.php, which is also
            // what makes the request visible in staff's queue.
            header('Location: ' . HQ_BASE_URL . '/patient/checkout.php?appointment_id=' . $newAppointmentId);
            exit;
        } catch (PDOException $e) {
            error_log('Appointment request failed: ' . $e->getMessage());
            $errors[] = 'We could not submit your appointment request. Please try again.';
        }
    }
    if ($clinicLocked) unlockClinicBooking($pdo, (int) $values['clinic_id']);

    if ($errors && $isAjax) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
}

$pageTitle = 'Book an Appointment — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container"><section class="booking-layout"><div class="booking-copy"><a href="<?= HQ_BASE_URL ?>/patient/dashboard.php" class="back-link">&larr; Back to dashboard</a><span class="eyebrow">Book care</span><h1>Request an appointment</h1><p>Select a clinic and your preferred schedule. The clinic will review and confirm your request.</p></div><form class="booking-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><h2>Appointment details</h2><?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><div class="form-stack"><label>Clinic<select name="clinic_id" required><option value="">Select a clinic</option><?php foreach ($clinics as $clinic): ?><option value="<?= (int) $clinic['ClinicID'] ?>" <?= $values['clinic_id'] === (string) $clinic['ClinicID'] ? 'selected' : '' ?>><?= htmlspecialchars($clinic['ClinicName']) ?> &mdash; <?= htmlspecialchars($clinic['Address']) ?></option><?php endforeach; ?></select></label><label>Preferred physician <span class="optional">(optional)</span><select name="physician_id"><option value="">No preference</option><?php foreach ($physicians as $physician): ?><option value="<?= (int) $physician['UserID'] ?>" <?= $values['physician_id'] === (string) $physician['UserID'] ? 'selected' : '' ?>>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?> &mdash; <?= htmlspecialchars($physician['ClinicName']) ?></option><?php endforeach; ?></select></label><div class="form-stack form-cols-2"><label>Preferred date<input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($values['appointment_date']) ?>" required></label><label>Preferred time <span class="optional">(optional)</span><input type="time" name="appointment_time" value="<?= htmlspecialchars($values['appointment_time']) ?>"></label><p class="form-message warning">No time selected? Join the regular queue for that day; this is not a scheduled appointment time.</p></div><label>Reason for visit <span class="optional">(optional)</span><textarea name="concern" rows="4" placeholder="Briefly describe what you need help with."><?= htmlspecialchars($values['concern']) ?></textarea></label></div><button class="btn btn-primary btn-block" type="submit">Submit appointment request</button></form></section></div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
