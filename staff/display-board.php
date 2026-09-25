<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

// Full-screen "now serving" board for a waiting-room TV. Opened in its own
// tab from Queue Management; refreshes itself every 10 seconds. Shows queue
// numbers only (no patient names) for privacy.
$user = currentUser();
$pdo = getDbConnection();
$clinicId = (int) $user['ClinicID'];
$clinicName = 'HealthQueue';
$lanes = [];

if ($pdo && $clinicId) {
    try {
        $nameStmt = $pdo->prepare('SELECT ClinicName FROM Clinic WHERE ClinicID = ?');
        $nameStmt->execute([$clinicId]);
        $clinicName = (string) $nameStmt->fetchColumn() ?: $clinicName;

        $stmt = $pdo->prepare(
            "SELECT q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, q.PhysicianID, phy.LastName AS PhyLastName
             FROM Queue q LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY COALESCE(q.Position, q.QueueNumber * 10), q.QueueNumber"
        );
        $stmt->execute([$clinicId]);
        // One lane per physician (plus "General" for unassigned patients).
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['PhysicianID'] ?: 0;
            $lanes[$key] ??= ['name' => $row['PhyLastName'] ? 'Dr. ' . $row['PhyLastName'] : 'General', 'current' => [], 'next' => []];
            if ($row['Status'] === 'Waiting') {
                $lanes[$key]['next'][] = $row['ScheduledNumber'] !== null ? 'S-' . (int) $row['ScheduledNumber'] : (int) ($row['RegularNumber'] ?? $row['QueueNumber']);
            } else {
                $lanes[$key]['current'][] = ['number' => $row['ScheduledNumber'] !== null ? 'S-' . (int) $row['ScheduledNumber'] : (int) ($row['RegularNumber'] ?? $row['QueueNumber']), 'calling' => $row['Status'] === 'Calling'];
            }
        }
    } catch (PDOException $e) {
        error_log('Display board failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="10">
<title>Now Serving — <?= htmlspecialchars($clinicName) ?></title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; padding: 4vh 4vw; background: #0b1a33; color: #fff; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
  header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4vh; }
  header h1 { margin: 0; font-size: clamp(24px, 3.4vw, 54px); }
  header time { color: #94a3b8; font-size: clamp(18px, 2.2vw, 36px); font-variant-numeric: tabular-nums; }
  .lanes { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 3vw; }
  .lane { padding: 3vh 2.5vw; border: 1px solid rgba(255,255,255,.12); border-radius: 28px; background: rgba(255,255,255,.04); }
  .lane h2 { margin: 0 0 2vh; color: #bae6fd; font-size: clamp(20px, 2.2vw, 38px); }
  .label { color: #94a3b8; font-size: clamp(14px, 1.4vw, 24px); text-transform: uppercase; letter-spacing: .08em; }
  .current { font-size: clamp(64px, 10vw, 180px); font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
  .current.calling { color: #fbbf24; animation: pulse 1.6s ease-in-out infinite; }
  .next { display: flex; flex-wrap: wrap; gap: 1vw; margin-top: 1vh; }
  .next span { padding: .4em .8em; border-radius: 14px; background: rgba(255,255,255,.08); font-size: clamp(20px, 2.4vw, 40px); font-weight: 700; }
  .empty { color: #64748b; font-size: clamp(18px, 2vw, 32px); }
  @keyframes pulse { 50% { opacity: .55; } }
  @media (prefers-reduced-motion: reduce) { .current.calling { animation: none; } }
</style>
</head>
<body>
  <header>
    <h1><?= htmlspecialchars($clinicName) ?> · Now Serving</h1>
    <time id="clock"></time>
  </header>
  <?php if ($lanes): ?>
    <div class="lanes">
      <?php foreach ($lanes as $lane): ?>
        <section class="lane">
          <h2><?= htmlspecialchars($lane['name']) ?></h2>
          <div class="label">Now serving</div>
          <?php if ($lane['current']): ?>
            <?php foreach ($lane['current'] as $current): ?>
              <div class="current<?= $current['calling'] ? ' calling' : '' ?>">#<?= $current['number'] ?></div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="current">—</div>
          <?php endif; ?>
          <div class="label" style="margin-top:3vh;">Next</div>
          <?php if ($lane['next']): ?>
            <div class="next"><?php foreach (array_slice($lane['next'], 0, 5) as $number): ?><span>#<?= $number ?></span><?php endforeach; ?></div>
          <?php else: ?>
            <div class="empty">No one waiting</div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="empty">The queue is empty. Please check in at the front desk.</p>
  <?php endif; ?>
<script>
  (function tick() {
    document.getElementById('clock').textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    setTimeout(tick, 15000);
  })();
</script>
</body>
</html>
