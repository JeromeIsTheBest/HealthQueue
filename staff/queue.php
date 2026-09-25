<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

// Waiting longer than this is highlighted.
const LONG_WAIT_MINUTES = 30;
const PRIORITY_OPTIONS = ['Senior', 'PWD', 'Pregnant'];
// The waiting line is ordered by COALESCE(Position, QueueNumber * 10).
const QUEUE_ORDER_SQL = "CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN 0 ELSE 1 END, CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN TIMESTAMP(a.AppointmentDate, a.AppointmentTime) END, COALESCE(q.Position, q.QueueNumber * 10), q.QueueNumber";

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$clinicId = (int) $user['ClinicID'];
$physicianFilter = filter_var($_GET['physician'] ?? null, FILTER_VALIDATE_INT) ?: null;
$walkInValues = ['first_name' => '', 'last_name' => '', 'contact_number' => '', 'email' => '', 'physician_id' => '', 'concern' => '', 'priority' => ''];

/** Today's waiting entries for the clinic (optionally one physician), in line order. */
function waitingLine(PDO $pdo, int $clinicId, ?int $physicianId): array
{
    $sql = "SELECT q.QueueID, COALESCE(q.Position, q.QueueNumber * 10) AS Pos FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID
            WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status = 'Waiting' AND (a.AppointmentTime IS NULL OR TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW()) AND NOT EXISTS (SELECT 1 FROM Queue activeQ WHERE activeQ.ClinicID = q.ClinicID AND activeQ.Status IN ('Calling', 'Serving'))";
    $params = [$clinicId];
    if ($physicianId) {
        $sql .= ' AND q.PhysicianID = ?';
        $params[] = $physicianId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY ' . QUEUE_ORDER_SQL);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Marks a waiting entry as Calling and notifies the patient. */
function callQueueEntry(PDO $pdo, int $queueId, int $clinicId, int $staffId): ?string
{
    $targetStmt = $pdo->prepare("SELECT PhysicianID FROM Queue WHERE QueueID = ? AND ClinicID = ? AND Status = 'Waiting'");
    $targetStmt->execute([$queueId, $clinicId]);
    $targetPhysicianId = $targetStmt->fetchColumn();
    if ($targetPhysicianId === false) return null;

    $busyStmt = $pdo->prepare("SELECT COUNT(*) FROM Queue WHERE ClinicID = ? AND Status IN ('Calling', 'Serving')");
    $busyStmt->execute([$clinicId]);
    if ((int) $busyStmt->fetchColumn() > 0) return null;

    $stmt = $pdo->prepare("UPDATE Queue SET Status = 'Calling', CalledAt = NOW() WHERE QueueID = ? AND ClinicID = ? AND Status = 'Waiting'");
    $stmt->execute([$queueId, $clinicId]);
    if (!$stmt->rowCount()) return null;
    $row = $pdo->prepare('SELECT q.QueueNumber, q.ScheduledNumber, q.RegularNumber, a.PatientID, a.AppointmentID FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID WHERE q.QueueID = ?');
    $row->execute([$queueId]);
    $entry = $row->fetch();
    notifyPatient($pdo, (int) $entry['PatientID'], "You're being called now - please proceed to the counter.", (int) $entry['AppointmentID']);
    $ticketNumber = $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']);
    logActivity($pdo, $staffId, $clinicId, 'Updated queue status', "Queue {$ticketNumber} set to Calling");
    return $ticketNumber;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';
        $queueId = filter_input(INPUT_POST, 'queue_id', FILTER_VALIDATE_INT);

        if ($formType === 'register_walkin') {
            foreach ($walkInValues as $field => $value) $walkInValues[$field] = trim((string) ($_POST[$field] ?? ''));

            if ($walkInValues['first_name'] === '' || $walkInValues['last_name'] === '') $errors[] = 'First and last name are required.';
            if ($walkInValues['contact_number'] === '') $errors[] = 'Contact number is required.';
            if ($walkInValues['email'] !== '' && !filter_var($walkInValues['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address, or leave it blank.';
            if ($walkInValues['priority'] !== '' && !in_array($walkInValues['priority'], PRIORITY_OPTIONS, true)) $errors[] = 'Please choose a valid priority.';

            if (!$errors) {
                try {
                    $pdo->beginTransaction();

                    $patientRoleStmt = $pdo->query("SELECT RoleID FROM Roles WHERE RoleName = 'Patient'");
                    $patientRoleId = (int) $patientRoleStmt->fetchColumn();

                    $patientId = null;
                    if ($walkInValues['email'] !== '') {
                        $existingStmt = $pdo->prepare('SELECT UserID, RoleID FROM Users WHERE Email = ?');
                        $existingStmt->execute([$walkInValues['email']]);
                        $existing = $existingStmt->fetch();
                        if ($existing) {
                            if ((int) $existing['RoleID'] !== $patientRoleId) {
                                $errors[] = 'That email already belongs to a non-patient account.';
                            } else {
                                $patientId = (int) $existing['UserID'];
                            }
                        }
                    }

                    if (!$errors && !$patientId) {
                        $email = $walkInValues['email'] !== '' ? $walkInValues['email'] : ('walkin.' . bin2hex(random_bytes(5)) . '@healthqueue.local');
                        $insertPatient = $pdo->prepare(
                            'INSERT INTO Users (RoleID, FirstName, LastName, Email, ContactNumber, PasswordHash, Status) VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $insertPatient->execute([
                            $patientRoleId, $walkInValues['first_name'], $walkInValues['last_name'], $email,
                            $walkInValues['contact_number'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Active',
                        ]);
                        $patientId = (int) $pdo->lastInsertId();
                        assignUserIdNumber($pdo, $patientId);
                    }

                    if (!$errors) {
                        $physicianId = $walkInValues['physician_id'] !== '' ? (int) $walkInValues['physician_id'] : null;
                        $insertAppt = $pdo->prepare(
                            "INSERT INTO Appointments (PatientID, ClinicID, PhysicianID, AppointmentDate, AppointmentTime, Concern, Status)
                             VALUES (?, ?, ?, CURDATE(), NULL, ?, 'Confirmed')"
                        );
                        $insertAppt->execute([$patientId, $clinicId, $physicianId, $walkInValues['concern'] ?: null]);
                        $appointmentId = (int) $pdo->lastInsertId();

                        $queueNumStmt = $pdo->prepare('SELECT COALESCE(MAX(QueueNumber), 0) + 1 FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE()');
                        $queueNumStmt->execute([$clinicId]);
                        $queueNumber = (int) $queueNumStmt->fetchColumn();

                        $regularNumStmt = $pdo->prepare('SELECT COALESCE(MAX(RegularNumber), 0) + 1 FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND RegularNumber IS NOT NULL');
                        $regularNumStmt->execute([$clinicId]);
                        $regularNumber = (int) $regularNumStmt->fetchColumn();
                        // Priority walk-ins go just ahead of the first non-priority patient still waiting.
                        $position = $queueNumber * 10;
                        if ($walkInValues['priority'] !== '') {
                            $firstRegular = $pdo->prepare(
                                "SELECT MIN(COALESCE(q.Position, q.QueueNumber * 10)) FROM Queue q
                                 WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status = 'Waiting' AND q.Priority IS NULL"
                            );
                            $firstRegular->execute([$clinicId]);
                            $firstPos = $firstRegular->fetchColumn();
                            if ($firstPos !== null) $position = (int) $firstPos - 1;
                        }

                        $insertQueue = $pdo->prepare(
                            "INSERT INTO Queue (ClinicID, AppointmentID, PhysicianID, QueueNumber, RegularNumber, Position, Priority, Status, CreatedByStaffID) VALUES (?, ?, ?, ?, ?, ?, ?, 'Waiting', ?)"
                        );
                        $insertQueue->execute([$clinicId, $appointmentId, $physicianId, $queueNumber, $regularNumber, $position, $walkInValues['priority'] ?: null, $user['UserID']]);

                        $pdo->commit();
                        logActivity($pdo, $user['UserID'], $clinicId, 'Registered walk-in patient', "{$walkInValues['first_name']} {$walkInValues['last_name']}, queue #{$queueNumber}");
                        $flash = "Walk-in registered as queue #{$queueNumber}" . ($walkInValues['priority'] ? " (priority: {$walkInValues['priority']})." : '.');
                        $walkInValues = array_map(static fn() => '', $walkInValues);
                    }

                    if ($errors && $pdo->inTransaction()) $pdo->rollBack();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Walk-in registration failed: ' . $e->getMessage());
                    $errors[] = 'We could not register this walk-in. Please try again.';
                }
            }
        } elseif ($formType === 'call_next') {
            try {
                $filterPhysician = filter_input(INPUT_POST, 'physician_filter', FILTER_VALIDATE_INT) ?: null;
                $line = waitingLine($pdo, $clinicId, $filterPhysician);
                $called = $line ? callQueueEntry($pdo, (int) $line[0]['QueueID'], $clinicId, (int) $user['UserID']) : null;
                if ($called) {
                    $flash = "Calling queue {$called}.";
                } else {
                    $errors[] = 'No one is waiting in this line.';
                }
            } catch (PDOException $e) {
                error_log('Call next failed: ' . $e->getMessage());
                $errors[] = 'We could not call the next patient.';
            }
        } elseif ($formType === 'recall' && $queueId) {
            try {
                $row = $pdo->prepare(
                    "SELECT q.QueueNumber, a.PatientID, a.AppointmentID FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID
                     WHERE q.QueueID = ? AND q.ClinicID = ? AND q.Status = 'Calling'"
                );
                $row->execute([$queueId, $clinicId]);
                if ($entry = $row->fetch()) {
                    notifyPatient($pdo, (int) $entry['PatientID'], "Reminder: it's your turn (queue #{$entry['QueueNumber']}) — please proceed to the counter.", (int) $entry['AppointmentID']);
                    logActivity($pdo, $user['UserID'], $clinicId, 'Recalled patient', "Queue #{$entry['QueueNumber']}");
                    $flash = "Recalled queue #{$entry['QueueNumber']}.";
                } else {
                    $errors[] = 'That patient is no longer being called.';
                }
            } catch (PDOException $e) {
                error_log('Recall failed: ' . $e->getMessage());
                $errors[] = 'We could not recall this patient.';
            }
        } elseif (in_array($formType, ['move_up', 'move_down', 'move_last'], true) && $queueId) {
            try {
                // Reorder within the line currently shown (all, or one physician's).
                $filterPhysician = filter_input(INPUT_POST, 'physician_filter', FILTER_VALIDATE_INT) ?: null;
                $line = waitingLine($pdo, $clinicId, $filterPhysician);
                $ids = array_map(static fn($r) => (int) $r['QueueID'], $line);
                $index = array_search($queueId, $ids, true);
                $setPos = $pdo->prepare('UPDATE Queue SET Position = ? WHERE QueueID = ? AND ClinicID = ?');

                if ($index === false) {
                    $errors[] = 'That patient is no longer waiting.';
                } elseif ($formType === 'move_last') {
                    $allLine = waitingLine($pdo, $clinicId, null);
                    $setPos->execute([(int) end($allLine)['Pos'] + 10, $queueId, $clinicId]);
                    $flash = 'Moved to the end of the line.';
                } else {
                    $swapWith = $formType === 'move_up' ? $index - 1 : $index + 1;
                    if (isset($line[$swapWith])) {
                        $a = $line[$index];
                        $b = $line[$swapWith];
                        // Equal positions can't be swapped meaningfully, so nudge.
                        $posA = (int) $b['Pos'];
                        $posB = (int) $a['Pos'] === (int) $b['Pos'] ? (int) $a['Pos'] + ($formType === 'move_up' ? 1 : -1) : (int) $a['Pos'];
                        $setPos->execute([$posA, $a['QueueID'], $clinicId]);
                        $setPos->execute([$posB, $b['QueueID'], $clinicId]);
                    }
                }
            } catch (PDOException $e) {
                error_log('Queue reorder failed: ' . $e->getMessage());
                $errors[] = 'We could not reorder the queue.';
            }
        } elseif (in_array($formType, ['call_patient', 'mark_serving', 'skip_forfeit', 'remove_from_queue'], true)) {
            $transitions = [
                'call_patient'      => ['from' => ['Waiting'], 'to' => 'Calling', 'stamp' => 'CalledAt'],
                'mark_serving'      => ['from' => ['Calling'], 'to' => 'Serving', 'stamp' => 'ServedAt'],
                'skip_forfeit'      => ['from' => ['Calling', 'Serving'], 'to' => 'Forfeited_Late', 'stamp' => null],
                'remove_from_queue' => ['from' => null, 'to' => 'Removed', 'stamp' => null],
            ];
            $t = $transitions[$formType];

            if (!$queueId) {
                $errors[] = 'Invalid queue entry.';
            } elseif ($formType === 'remove_from_queue' && trim((string) ($_POST['removal_reason'] ?? '')) === '') {
                $errors[] = 'Please provide a reason for removing this booking from the queue.';
            } else {
                try {
                    if ($formType === 'call_patient') {
                        $called = callQueueEntry($pdo, $queueId, $clinicId, (int) $user['UserID']);
                        if ($called) {
                            $flash = "Calling queue {$called}.";
                        } else {
                            $errors[] = 'That queue entry could not be updated (it may have already changed).';
                        }
                    } elseif ($formType === 'remove_from_queue') {
                        $reason = mb_substr(trim((string) $_POST['removal_reason']), 0, 255);
                        $pdo->beginTransaction();
                        $lookup = $pdo->prepare("SELECT q.AppointmentID, a.PatientID, a.Status FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID WHERE q.QueueID = ? AND q.ClinicID = ? AND q.Status IN ('Waiting', 'Calling', 'Serving') FOR UPDATE");
                        $lookup->execute([$queueId, $clinicId]);
                        $target = $lookup->fetch();
                        if (!$target || !in_array($target['Status'], ['Pending', 'Confirmed'], true)) {
                            $pdo->rollBack();
                            $errors[] = 'That booking could not be removed because it is no longer active.';
                        } else {
                            $pdo->prepare("UPDATE Queue SET Status = 'Removed' WHERE QueueID = ? AND ClinicID = ? AND Status IN ('Waiting', 'Calling', 'Serving')")->execute([$queueId, $clinicId]);
                            $pdo->prepare("UPDATE Appointments SET Status = 'Cancelled', CancellationReason = ? WHERE AppointmentID = ? AND ClinicID = ? AND Status IN ('Pending', 'Confirmed')")->execute([$reason, $target['AppointmentID'], $clinicId]);
                            $pdo->commit();
                            logActivity($pdo, $user['UserID'], $clinicId, 'Removed patient booking from queue', "Appointment #{$target['AppointmentID']}: {$reason}");
                            notifyPatient($pdo, (int) $target['PatientID'], 'Your appointment and queue booking was cancelled by the clinic. Reason: ' . $reason, (int) $target['AppointmentID']);
                            $flash = 'Booking removed. The patient was notified.';
                        }
                    } else {
                        $sql = 'UPDATE Queue SET Status = ?' . ($t['stamp'] ? ", {$t['stamp']} = NOW()" : '') . ' WHERE QueueID = ? AND ClinicID = ?';
                        $params = [$t['to'], $queueId, $clinicId];
                        if ($t['from']) {
                            $sql .= ' AND Status IN (' . implode(',', array_fill(0, count($t['from']), '?')) . ')';
                            array_push($params, ...$t['from']);
                        }
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        if ($stmt->rowCount()) {
                            logActivity($pdo, $user['UserID'], $clinicId, 'Updated queue status', "Queue entry {$queueId} set to {$t['to']}");
                            $flash = ['mark_serving' => 'Marked as serving.', 'skip_forfeit' => 'Marked as a no-show.', 'remove_from_queue' => 'Removed from the queue.'][$formType];
                        } else {
                            $errors[] = 'That queue entry could not be updated (it may have already changed).';
                        }
                    }
                } catch (PDOException $e) {
                    error_log('Queue transition failed: ' . $e->getMessage());
                    $errors[] = 'We could not update the queue.';
                }
            }
        } elseif ($formType === 'confirm_complete') {
            $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
            if (!$appointmentId) {
                $errors[] = 'Invalid appointment.';
            } else {
                try {
                    // Only allowed once the physician has actually finalized
                    // their notes -- staff confirm the visit is over, they
                    // don't write the notes.
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
                        $flash = 'Visit marked as done.';
                    }
                } catch (PDOException $e) {
                    error_log('Confirm consultation complete failed: ' . $e->getMessage());
                    $errors[] = 'We could not confirm this consultation right now.';
                }
            }
        } elseif ($formType === 'reschedule_from_queue') {
            $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
            $newDate = trim((string) ($_POST['appointment_date'] ?? ''));
            $newTime = trim((string) ($_POST['appointment_time'] ?? ''));

            if (!$queueId || !$appointmentId || $newDate === '' || $newDate < date('Y-m-d') || $newTime === '') {
                $errors[] = 'Please choose a valid future date and time.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE Queue SET Status = ? WHERE QueueID = ? AND ClinicID = ?')->execute(['Removed', $queueId, $clinicId]);
                    $pdo->prepare('UPDATE Appointments SET AppointmentDate = ?, AppointmentTime = ? WHERE AppointmentID = ? AND ClinicID = ?')
                        ->execute([$newDate, $newTime, $appointmentId, $clinicId]);
                    $pdo->commit();
                    logActivity($pdo, $user['UserID'], $clinicId, 'Rescheduled from queue', "Appointment #{$appointmentId} to {$newDate} {$newTime}");
                    $flash = 'Appointment rescheduled and removed from today\'s queue.';
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Reschedule from queue failed: ' . $e->getMessage());
                    $errors[] = 'We could not reschedule this appointment.';
                }
            }
        }
    }
}

$entries = [];
$physicians = [];
$tabCounts = ['all' => 0];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $stmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, q.Priority, q.PhysicianID, q.AppointmentID,
                    TIMESTAMPDIFF(MINUTE, q.CreatedAt, NOW()) AS WaitingMinutes,
                    TIMESTAMPDIFF(MINUTE, COALESCE(q.ServedAt, q.CalledAt), NOW()) AS ActiveMinutes,
                    a.AppointmentDate, a.AppointmentTime, a.Concern, a.BookingFeePaid,
                    pat.FirstName, pat.LastName,
                    phy.LastName AS PhyLastName,
                    (SELECT COUNT(*) FROM ConsultationVersions v
                     JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                     WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized') AS FinalizedCount
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE()
             ORDER BY " . QUEUE_ORDER_SQL
        );
        $stmt->execute([$clinicId]);
        $entries = $stmt->fetchAll();

        $physStmt = $pdo->prepare(
            "SELECT UserID, FirstName, LastName FROM Users
             WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
             ORDER BY LastName"
        );
        $physStmt->execute([$clinicId]);
        $physicians = $physStmt->fetchAll();

        foreach ($entries as $entry) {
            if (!in_array($entry['Status'], ['Waiting', 'Calling', 'Serving'], true)) continue;
            $tabCounts['all']++;
            if ($entry['PhysicianID']) $tabCounts[(int) $entry['PhysicianID']] = ($tabCounts[(int) $entry['PhysicianID']] ?? 0) + 1;
        }
    } catch (PDOException $e) {
        error_log('Queue list load failed: ' . $e->getMessage());
        $dataError = 'The queue is temporarily unavailable.';
    }
}

// Split today's entries for the selected physician tab.
$visible = array_filter($entries, static fn($e) => !$physicianFilter || (int) $e['PhysicianID'] === $physicianFilter);
$active = array_values(array_filter($visible, static fn($e) => in_array($e['Status'], ['Calling', 'Serving'], true)));
$waiting = array_values(array_filter($visible, static fn($e) => $e['Status'] === 'Waiting'));
$canCallNext = $pdo ? (bool) waitingLine($pdo, $clinicId, $physicianFilter) : false;
$finished = array_values(array_filter($visible, static fn($e) => in_array($e['Status'], ['Completed', 'Forfeited_Late', 'Removed'], true)));
$finishedCounts = ['Completed' => 0, 'Forfeited_Late' => 0, 'Removed' => 0];
foreach ($finished as $entry) $finishedCounts[$entry['Status']]++;

$walkInHasData = (bool) array_filter($walkInValues);
$ticketLabel = static fn(array $e): string => $e['ScheduledNumber'] !== null ? 'S-' . (int) $e['ScheduledNumber'] : '#' . (int) ($e['RegularNumber'] ?? $e['QueueNumber']);
$csrf = htmlspecialchars(csrfToken());
$filterInput = $physicianFilter ? '<input type="hidden" name="physician_filter" value="' . $physicianFilter . '">' : '';
$sourceLabel = static fn(array $e): string => !$e['AppointmentTime'] ? 'Regular queue' : ($e['BookingFeePaid'] ? 'Appointment ' . date('g:i A', strtotime($e['AppointmentTime'])) : 'Walk-in');
$concern = static fn(array $e): string => mb_strimwidth(trim((string) $e['Concern']) ?: 'General', 0, 30, '…');
$minutesLabel = static function (int $minutes): string {
    return $minutes < 60 ? $minutes . ' min' : intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
};

// Rendered once so the full page and the periodic auto-refresh share markup.
ob_start();
?>
  <h3 class="qm-section-title">Now serving</h3>
  <?php if ($active): ?>
    <?php foreach ($active as $entry): ?>
      <?php $isServing = $entry['Status'] === 'Serving'; ?>
      <section class="qm-serving">
        <div class="qm-serving-top">
          <div class="qm-serving-num"><span><?= $isServing ? 'Serving' : 'Calling' ?></span><strong><?= htmlspecialchars($ticketLabel($entry)) ?></strong></div>
          <div class="qm-serving-info">
            <h2><?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?><?php if ($entry['Priority']): ?> <span class="qm-priority">Priority · <?= htmlspecialchars($entry['Priority']) ?></span><?php endif; ?></h2>
            <p><?= htmlspecialchars($concern($entry)) ?> · <?= htmlspecialchars(lcfirst($sourceLabel($entry))) ?><?= $entry['PhyLastName'] ? ' · Dr. ' . htmlspecialchars($entry['PhyLastName']) : '' ?> · <?= $isServing ? 'in consultation ' : 'called ' ?><?= $minutesLabel((int) $entry['ActiveMinutes']) ?><?= $isServing ? '' : ' ago' ?></p>
          </div>
        </div>
        <div class="qm-serving-actions">
          <?php if (!$isServing): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="recall"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
              <button class="btn btn-outline btn-sm"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>Recall</button></form>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="mark_serving"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
              <button class="btn btn-outline btn-sm">Patient arrived</button></form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Mark #<?= (int) $entry['QueueNumber'] ?> as a no-show?');"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="skip_forfeit"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
            <button class="btn btn-outline btn-sm qm-danger">No-show</button></form>
          <?php if ($isServing): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="confirm_complete"><input type="hidden" name="appointment_id" value="<?= (int) $entry['AppointmentID'] ?>">
              <button class="btn btn-outline btn-sm"<?= (int) $entry['FinalizedCount'] > 0 ? '' : ' disabled title="Waiting for the physician to finalize their notes"' ?>>Mark done</button></form>
          <?php endif; ?>
          <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="call_next"><?= $filterInput ?>
            <button class="btn btn-outline btn-sm"<?= $canCallNext ? '' : ' disabled title="A visit is in progress or no eligible patient is waiting"' ?>>Call next &rarr;</button></form>
        </div>
        <?php if ($isServing && (int) $entry['FinalizedCount'] === 0): ?><p class="qm-hint">"Mark done" unlocks once the physician finalizes their notes.</p><?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <section class="qm-serving qm-serving-empty">
      <p>No one is being served right now.</p>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="call_next"><?= $filterInput ?>
        <button class="btn btn-primary btn-sm"<?= $canCallNext ? '' : ' disabled title="A visit is in progress or no eligible patient is waiting"' ?>>Call next &rarr;</button></form>
    </section>
  <?php endif; ?>

  <h3 class="qm-section-title">Waiting · <?= count($waiting) ?></h3>
  <?php if ($waiting): ?>
    <div class="qm-list">
      <?php foreach ($waiting as $i => $entry): ?>
        <?php $mins = (int) $entry['WaitingMinutes']; $qid = (int) $entry['QueueID']; ?>
        <article class="qm-row">
          <span class="qm-num"><?= htmlspecialchars($ticketLabel($entry)) ?></span>
          <div class="qm-row-main">
            <strong><?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?><?php if ($entry['Priority']): ?> <span class="qm-priority">Priority · <?= htmlspecialchars($entry['Priority']) ?></span><?php endif; ?></strong>
            <p><?= htmlspecialchars($sourceLabel($entry)) ?> · <?= htmlspecialchars($concern($entry)) ?><?= !$physicianFilter && $entry['PhyLastName'] ? ' · Dr. ' . htmlspecialchars($entry['PhyLastName']) : '' ?></p>
          </div>
          <span class="qm-wait<?= $mins >= LONG_WAIT_MINUTES ? ' is-long' : '' ?>">waiting <?= $minutesLabel($mins) ?></span>
          <div class="qm-icons">
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="move_up"><input type="hidden" name="queue_id" value="<?= $qid ?>"><?= $filterInput ?>
              <button class="qm-icon" aria-label="Move up" title="Move up"<?= $i === 0 ? ' disabled' : '' ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg></button></form>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="move_down"><input type="hidden" name="queue_id" value="<?= $qid ?>"><?= $filterInput ?>
              <button class="qm-icon" aria-label="Move down" title="Move down"<?= $i === count($waiting) - 1 ? ' disabled' : '' ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button></form>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="move_last"><input type="hidden" name="queue_id" value="<?= $qid ?>"><?= $filterInput ?>
              <button class="qm-icon" aria-label="Send to end of line" title="Send to end of line"<?= $i === count($waiting) - 1 ? ' disabled' : '' ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 5 8 7-8 7V5zM18 5v14"/></svg></button></form>
            <form method="post" onsubmit="return confirm('Remove this booking from the queue and cancel the appointment?');"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="remove_from_queue"><input type="hidden" name="queue_id" value="<?= $qid ?>">
              <input type="text" name="removal_reason" maxlength="255" required aria-label="Reason for removal" placeholder="Reason required" class="qm-remove-reason">
              <button class="qm-icon qm-icon-danger" aria-label="Remove from queue" title="Remove from queue"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="qm-empty">No one is waiting. Register a walk-in or approve a request to add patients.</p>
  <?php endif; ?>

  <details class="qm-finished">
    <summary>
      <span>Finished today</span>
      <span class="qm-finished-pills">
        <span class="ma-pill ma-pill-approved"><?= $finishedCounts['Completed'] ?> done</span>
        <span class="ma-pill ma-pill-declined"><?= $finishedCounts['Forfeited_Late'] ?> no-show</span>
        <span class="ma-pill ma-pill-completed"><?= $finishedCounts['Removed'] ?> removed</span>
      </span>
    </summary>
    <?php if ($finished): ?>
      <ul>
        <?php foreach ($finished as $entry): ?>
          <li><span class="qm-num"><?= htmlspecialchars($ticketLabel($entry)) ?></span><?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?><em><?= ['Completed' => 'Done', 'Forfeited_Late' => 'No-show', 'Removed' => 'Removed'][$entry['Status']] ?></em></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="qm-empty">Nothing finished yet today.</p>
    <?php endif; ?>
  </details>
<?php
$queueHtml = ob_get_clean();

// Periodic auto-refresh (bottom of page) fetches this URL with an AJAX
// header and swaps in just this fragment.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    echo $queueHtml;
    exit;
}

$pageTitle = 'Queue Management — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container qm-page">
  <section class="sd-hero qm-hero" id="walk-in">
    <div>
      <span class="an-eyebrow">Front desk</span>
      <h1>Queue Management</h1>
      <p>Today's walk-in and confirmed patients, in line order.</p>
    </div>
    <div class="sd-hero-actions">
      <a href="<?= HQ_BASE_URL ?>/staff/display-board.php" class="btn sd-btn-ghost" target="_blank" rel="noopener">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="m17 2-5 5-5-5"/></svg>
        Display board
      </a>
      <button type="button" class="btn sd-btn-call" data-modal-open="registerWalkinModal">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
        Register walk-in
      </button>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors && !$walkInHasData): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <nav class="sa-tabs" aria-label="Filter by physician">
    <a href="?" class="sa-tab<?= !$physicianFilter ? ' is-active' : '' ?>">All <span class="sa-count"><?= $tabCounts['all'] ?></span></a>
    <?php foreach ($physicians as $physician): ?>
      <?php $pid = (int) $physician['UserID']; ?>
      <a href="?physician=<?= $pid ?>" class="sa-tab<?= $physicianFilter === $pid ? ' is-active' : '' ?>">Dr. <?= htmlspecialchars($physician['LastName']) ?> <span class="sa-count"><?= $tabCounts[$pid] ?? 0 ?></span></a>
    <?php endforeach; ?>
  </nav>

  <div id="queueListContainer"><?= $queueHtml ?></div>
</div></main>

<div class="modal-overlay" id="registerWalkinModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Register Walk-in Patient</h2>
    <?php if ($errors && $walkInHasData): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="registration-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="form_type" value="register_walkin">
      <div class="form-stack form-cols-2">
        <label>First name<input type="text" name="first_name" value="<?= htmlspecialchars($walkInValues['first_name']) ?>" required></label>
        <label>Last name<input type="text" name="last_name" value="<?= htmlspecialchars($walkInValues['last_name']) ?>" required></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Contact number<input type="text" name="contact_number" value="<?= htmlspecialchars($walkInValues['contact_number']) ?>" required></label>
        <label>Email <span class="optional">(optional)</span><input type="email" name="email" value="<?= htmlspecialchars($walkInValues['email']) ?>"></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Physician <span class="optional">(optional)</span>
          <select name="physician_id">
            <option value="">No preference</option>
            <?php foreach ($physicians as $physician): ?>
              <option value="<?= (int) $physician['UserID'] ?>" <?= $walkInValues['physician_id'] === (string) $physician['UserID'] || (!$walkInHasData && $physicianFilter === (int) $physician['UserID']) ? 'selected' : '' ?>>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Priority <span class="optional">(goes ahead of the line)</span>
          <select name="priority">
            <option value="">None</option>
            <?php foreach (PRIORITY_OPTIONS as $option): ?>
              <option <?= $walkInValues['priority'] === $option ? 'selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form-stack">
        <label>Reason for visit <span class="optional">(optional)</span><textarea name="concern" rows="3"><?= htmlspecialchars($walkInValues['concern']) ?></textarea></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Add to Queue</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Reopen after a failed submit, or open straight away when arriving from
  // the dashboard's "Register walk-in" link (#walk-in).
  if (<?= $walkInHasData ? 'true' : 'false' ?> || window.location.hash === '#walk-in') {
    window.hqOpenModal(document.getElementById('registerWalkinModal'));
  }

  // Auto-refresh the queue so staff see new walk-ins and changes made at
  // other desks. Skipped while the "Finished today" list is open.
  var container = document.getElementById('queueListContainer');
  setInterval(function () {
    var finished = container.querySelector('.qm-finished');
    if (finished && finished.open) return;
    fetch(window.location.href, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) { container.innerHTML = html; })
      .catch(function () { /* try again next tick */ });
  }, 20000);
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
