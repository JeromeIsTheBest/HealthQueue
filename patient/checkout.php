<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/payment-panel.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];

$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

if (!$appointmentId || !$pdo) {
    header('Location: ' . HQ_BASE_URL . '/patient/dashboard.php');
    exit;
}

// Scoped to this patient's own UserID -- one patient can never pay for or
// even see another patient's booking through this page.
$stmt = $pdo->prepare(
    "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.BookingFeePaid, a.PhysicianID, a.Status,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), a.CreatedAt + INTERVAL " . UNPAID_HOLD_MINUTES . " MINUTE)) AS HoldSeconds,
            c.ClinicID, c.ClinicName, c.BaseConsultationFee,
            phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
     FROM Appointments a
     JOIN Clinic c ON c.ClinicID = a.ClinicID
     LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
     WHERE a.AppointmentID = ? AND a.PatientID = ?"
);
$stmt->execute([$appointmentId, $user['UserID']]);
$appointment = $stmt->fetch();

if (!$appointment) {
    header('Location: ' . HQ_BASE_URL . '/patient/dashboard.php');
    exit;
}

if ($appointment['BookingFeePaid']) {
    header('Location: ' . HQ_BASE_URL . '/patient/my-appointments.php?paid=1');
    exit;
}

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

// One handler for both modes of payment -- which one applies is decided by
// the "payment_mode" field (wallet or card), not by a different form_type
// per button, so the UI only ever needs a single Pay Now action.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'submit_payment') {
    // Wallet really deducts; GCash, Maya and card are simulated (demo) methods.
    $paymentMode = isset(PAYMENT_METHODS[$_POST['payment_mode'] ?? '']) ? $_POST['payment_mode'] : 'card';

    $clinicLocked = false;
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($appointment['Status'] !== 'Pending') {
        $errors[] = 'This request can no longer be paid for.';
    } elseif (!($clinicLocked = lockClinicBooking($pdo, (int) $appointment['ClinicID']))) {
        $errors[] = 'The clinic is busy right now. Please try again in a moment.';
    } elseif ($appointment['AppointmentTime'] !== null && !isBookable($pdo, (int) $appointment['ClinicID'], $appointment['PhysicianID'] ? (int) $appointment['PhysicianID'] : null, $appointment['AppointmentDate'], $appointment['AppointmentTime'], (int) $appointmentId)) {
        // The unpaid hold expired and the slot was taken (or it's no longer open).
        $errors[] = 'Sorry, this time slot was taken while your request was unpaid. Please reschedule it from My Appointments, then pay.';
    } else {
        try {
            $pdo->beginTransaction();
            $fee = round((float) $appointment['BaseConsultationFee'], 2);

            if ($paymentMode === 'wallet') {
                $paid = walletDeduct($pdo, $user['UserID'], $fee, $appointmentId, 'Booking fee for ' . $appointment['ClinicName']);
                if (!$paid) {
                    $pdo->rollBack();
                    $errors[] = 'Your wallet balance is not enough to cover this fee.';
                }
            } else {
                $paid = true;
            }

            if ($paid ?? false) {
                $pdo->prepare("UPDATE Appointments SET BookingFeePaid = 1, BookingFeePaidAt = NOW(), BookingPaymentMethod = ? WHERE AppointmentID = ? AND PatientID = ?")
                    ->execute([$paymentMode, $appointmentId, $user['UserID']]);
                $pdo->commit();

                notifyClinic(
                    $pdo,
                    (int) $appointment['ClinicID'],
                    'New appointment request from ' . $user['FirstName'] . ' ' . $user['LastName'] . ' for ' . $appointment['AppointmentDate'] . '.',
                    $appointmentId
                );

                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true]);
                    exit;
                }

                header('Location: ' . HQ_BASE_URL . '/patient/my-appointments.php?paid=1');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Booking payment failed: ' . $e->getMessage());
            $errors[] = 'We could not process your payment right now. Please try again.';
        }
    }
    if ($clinicLocked) unlockClinicBooking($pdo, (int) $appointment['ClinicID']);

    if ($errors && $isAjax) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }
}

$walletBalance = 0.0;
try {
    $walletStmt = $pdo->prepare('SELECT WalletBalance FROM Users WHERE UserID = ?');
    $walletStmt->execute([$user['UserID']]);
    $walletBalance = (float) $walletStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Wallet balance load failed: ' . $e->getMessage());
}

$pageTitle = 'Checkout — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container pay-page">
  <a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php?tab=pending" class="back-link">&larr; Back to My Appointments</a>

  <?php renderPaySteps(); ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <?php $fee = (float) $appointment['BaseConsultationFee']; ?>
  <form method="post" class="pay-layout">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
    <input type="hidden" name="form_type" value="submit_payment">
    <input type="hidden" name="appointment_id" value="<?= (int) $appointmentId ?>">

    <?php renderPaymentMethods('payment_mode', $walletBalance, $fee, HQ_BASE_URL . '/patient/wallet.php'); ?>

    <aside class="pay-summary">
      <p class="pay-hold" data-hold-seconds="<?= (int) $appointment['HoldSeconds'] ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> <span>Slot held for <b data-hold-left>15:00</b></span></p>
      <h3><?= htmlspecialchars($appointment['ClinicName']) ?></h3>
      <p class="pay-sub"><?= $appointment['PhyFirstName'] ? 'Dr. ' . htmlspecialchars($appointment['PhyFirstName'] . ' ' . $appointment['PhyLastName']) : 'No physician preference' ?> · <?= htmlspecialchars(trim((string) $appointment['Concern']) !== '' ? mb_strimwidth($appointment['Concern'], 0, 40, '…') : 'General consultation') ?></p>
      <p class="pay-sub"><?= htmlspecialchars(date('D, M j, Y', strtotime($appointment['AppointmentDate'])) . ' · ' . date('g:i A', strtotime($appointment['AppointmentTime']))) ?></p>
      <dl class="pay-rows">
        <div><dt>Booking fee</dt><dd>₱<?= number_format($fee, 2) ?></dd></div>
        <div class="pay-total"><dt>Pay now</dt><dd>₱<?= number_format($fee, 2) ?></dd></div>
      </dl>
      <button type="submit" class="btn btn-outline btn-block pay-btn">Pay ₱<?= number_format($fee, 2) ?></button>
      <p class="pay-refund"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg> <span>If the clinic declines, <b>₱<?= number_format($fee) ?></b> goes back to your wallet.</span></p>
      <a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php?tab=pending#appt-<?= (int) $appointmentId ?>" class="pay-change">Change schedule</a>
    </aside>
  </form>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
