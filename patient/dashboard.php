<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/announcements.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$appointments = [];
$clinics = [];
$records = [];
$unpaidCount = 0;
$queueEntries = [];
$announcements = [];
$minutesPerPatient = 15;
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Status, c.ClinicName, c.Address FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID WHERE a.PatientID = ? AND a.AppointmentDate >= CURDATE() ORDER BY a.AppointmentDate, a.AppointmentTime LIMIT 5");
        $stmt->execute([$user['UserID']]);
        $appointments = $stmt->fetchAll();

        $clinics = $pdo->query("SELECT ClinicID, ClinicName, Address, BaseConsultationFee, PhotoUrl FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName LIMIT 6")->fetchAll();

        $unpaidStmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE PatientID = ? AND Status = 'Pending' AND BookingFeePaid = 0");
        $unpaidStmt->execute([$user['UserID']]);
        $unpaidCount = (int) $unpaidStmt->fetchColumn();

        $recordsStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, c.ClinicName, u.FirstName, u.LastName
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users u ON u.UserID = a.PhysicianID
             WHERE a.PatientID = ? AND a.Status = 'Completed'
             ORDER BY a.AppointmentDate DESC LIMIT 5"
        );
        $recordsStmt->execute([$user['UserID']]);
        $records = $recordsStmt->fetchAll();

        $queueStmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, q.ClinicID, c.ClinicName, c.Address,
                    a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Clinic c ON c.ClinicID = q.ClinicID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE a.PatientID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY q.CreatedAt DESC"
        );
        $queueStmt->execute([$user['UserID']]);
        $queueEntries = $queueStmt->fetchAll();

        foreach ($queueEntries as &$queueEntry) {
            $posStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND Status = 'Waiting' AND COALESCE(Position, QueueNumber * 10) < (SELECT COALESCE(me.Position, me.QueueNumber * 10) FROM Queue me WHERE me.ClinicID = Queue.ClinicID AND DATE(me.CreatedAt) = CURDATE() AND me.QueueNumber = ? ORDER BY me.QueueID DESC LIMIT 1)"
            );
            $posStmt->execute([$queueEntry['ClinicID'], $queueEntry['QueueNumber']]);
            $queueEntry['AheadCount'] = (int) $posStmt->fetchColumn();
            $queueEntry['EstimatedWaitMinutes'] = $queueEntry['Status'] === 'Waiting' ? $queueEntry['AheadCount'] * $minutesPerPatient : 0;
        }
        unset($queueEntry);

        $annStmt = $pdo->prepare(
            "SELECT a.AnnouncementID, a.Title, a.Category, a.Message, a.PhotoPath, a.AffectsDate, a.CreatedAt, c.ClinicName
             FROM Announcements a LEFT JOIN Clinic c ON c.ClinicID = a.ClinicID
             WHERE a.Audience IN ('Everyone', 'Patients')
               AND NOT EXISTS (SELECT 1 FROM AnnouncementDismissals d WHERE d.UserID = ? AND d.AnnouncementID = a.AnnouncementID)
             ORDER BY a.CreatedAt DESC LIMIT 3"
        );
        $annStmt->execute([$user['UserID']]);
        $announcements = $annStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Patient dashboard failed: ' . $e->getMessage());
        $dataError = 'Your appointment information is temporarily unavailable.';
    }
} else {
    $dataError = 'Your appointment information is temporarily unavailable.';
}
$pageTitle = 'Patient Dashboard — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container">
  <div class="plain-page-header">
    <h1>Good day, <?= htmlspecialchars($user['FirstName']) ?></h1>
    <button type="button" class="btn btn-primary" data-modal-open="bookNowModal">Book Now</button>
  </div>
  <?php if (isset($_GET['dismissed'])): ?><p class="form-message success" role="status">Announcement deleted.</p><?php endif; ?>
  <?php if (isset($_GET['booked'])): ?><p class="form-message success" role="status">Your appointment request was submitted. The clinic will confirm it soon.</p><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>
  <?php if ($unpaidCount > 0): ?><p class="form-message error" role="alert">You have <?= $unpaidCount ?> request(s) awaiting payment. <a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php">Complete payment to send them to the clinic &rarr;</a></p><?php endif; ?>

  <section class="portal-section">
    <div class="dashboard-top-grid">
      <div>
        <?php foreach ($queueEntries as $entry):
          $physicianLabel = $entry['PhyFirstName'] ? 'Dr. ' . $entry['PhyFirstName'] . ' ' . $entry['PhyLastName'] : 'No physician preference';
          $whenLabel = date('l, F j, Y', strtotime($entry['AppointmentDate'])) . ' at ' . date('g:i A', strtotime($entry['AppointmentTime']));
        ?>
          <div class="queue-hero-card" role="button" tabindex="0" data-queue-detail
               data-number="<?= $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']) ?>"
               data-clinic="<?= htmlspecialchars($entry['ClinicName'], ENT_QUOTES) ?>"
               data-address="<?= htmlspecialchars($entry['Address'], ENT_QUOTES) ?>"
               data-status="<?= htmlspecialchars($entry['Status'], ENT_QUOTES) ?>"
               data-when="<?= htmlspecialchars($whenLabel, ENT_QUOTES) ?>"
               data-physician="<?= htmlspecialchars($physicianLabel, ENT_QUOTES) ?>"
               data-concern="<?= htmlspecialchars($entry['Concern'] ?: 'Not specified', ENT_QUOTES) ?>">
            <div class="queue-hero-top">
              <span class="queue-hero-label">Queue</span>
              <span class="queue-hero-live">Live</span>
            </div>
            <div class="queue-hero-number"><?= $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']) ?></div>
            <div class="queue-hero-meta">
              <?php if ($entry['Status'] === 'Waiting'): ?>
                <?= (int) $entry['AheadCount'] ?> patient(s) ahead &middot; ~<?= (int) $entry['EstimatedWaitMinutes'] ?> mins
              <?php elseif ($entry['Status'] === 'Calling'): ?>
                You're being called now — please proceed to the counter.
              <?php else: ?>
                You're currently being served.
              <?php endif; ?>
            </div>
            <div class="queue-hero-clinic"><?= htmlspecialchars($entry['ClinicName']) ?></div>
            <a href="<?= HQ_BASE_URL ?>/patient/queue-status.php" class="text-link" onclick="event.stopPropagation();">Live status <span>→</span></a>
          </div>
        <?php endforeach; ?>

        <?php if (!$queueEntries): ?>
          <div class="queue-empty-card">
            <span class="queue-hero-label">Queue</span>
            <h3>No queue for today</h3>
            <p>Once a clinic checks you in for a walk-in visit or a confirmed appointment today, your live position will appear here.</p>
          </div>
        <?php endif; ?>

        <div class="dash-announcements">
          <div class="dash-announcements-head">
            <span class="queue-hero-label">Announcements</span>
            <a href="<?= HQ_BASE_URL ?>/patient/announcements.php" class="text-link">View all <span>&rarr;</span></a>
          </div>
          <?php if ($announcements): ?>
            <ul>
              <?php foreach ($announcements as $note): ?>
                <li>
                  <a href="<?= HQ_BASE_URL ?>/patient/announcements.php?open=<?= (int) $note['AnnouncementID'] ?>" data-dash-announcement
                     data-id="<?= (int) $note['AnnouncementID'] ?>"
                     data-title="<?= htmlspecialchars($note['Title'], ENT_QUOTES) ?>"
                     data-source="<?= htmlspecialchars($note['ClinicName'] ?: 'HealthQueue', ENT_QUOTES) ?>"
                     data-category="<?= htmlspecialchars($note['Category'], ENT_QUOTES) ?>"
                     data-posted="<?= htmlspecialchars(date('l, F j, Y · g:i A', strtotime($note['CreatedAt'])), ENT_QUOTES) ?>"
                     data-affects="<?= $note['AffectsDate'] ? htmlspecialchars(date('l, F j, Y', strtotime($note['AffectsDate'])), ENT_QUOTES) : '' ?>"
                     data-photo="<?= htmlspecialchars((string) announcementPhotoUrl($note['PhotoPath']), ENT_QUOTES) ?>"
                     data-message="<?= htmlspecialchars($note['Message'], ENT_QUOTES) ?>">
                    <strong><?= htmlspecialchars($note['Title']) ?></strong>
                    <p><?= htmlspecialchars(mb_strimwidth(preg_replace('/\s+/', ' ', $note['Message']), 0, 90, '…')) ?></p>
                    <span><?= htmlspecialchars(date('M j, Y · g:i A', strtotime($note['CreatedAt']))) ?></span>
                  </a>
                  <form method="post" action="<?= HQ_BASE_URL ?>/patient/announcements.php" class="dash-ann-delete" onsubmit="return confirm('Delete this announcement? It will be removed from your list only.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="form_type" value="dismiss_announcement">
                    <input type="hidden" name="return_to" value="dashboard">
                    <input type="hidden" name="announcement_id" value="<?= (int) $note['AnnouncementID'] ?>">
                    <button type="submit" aria-label="Delete announcement" title="Delete"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="dash-announcements-empty">No announcements right now.</p>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <div class="portal-heading"><div><span class="section-kicker">My schedule</span><h2>Upcoming appointments</h2></div><a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php" class="text-link">View All <span>→</span></a></div>
        <?php if ($appointments): ?><div class="appointment-list"><?php foreach ($appointments as $appointment): ?><article class="appointment-card"><div class="appointment-date"><strong><?= htmlspecialchars(date('d', strtotime($appointment['AppointmentDate']))) ?></strong><span><?= htmlspecialchars(date('M', strtotime($appointment['AppointmentDate']))) ?></span></div><div class="appointment-info"><h3><?= htmlspecialchars($appointment['ClinicName']) ?></h3><p><?= htmlspecialchars(date('l, F j', strtotime($appointment['AppointmentDate']))) ?> · <?= htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentTime']))) ?></p><span><?= htmlspecialchars($appointment['Address']) ?></span></div><span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span></article><?php endforeach; ?></div><?php else: ?><div class="empty-state"><div class="empty-icon">+</div><h3>No upcoming appointments</h3><p>Choose a partner clinic and request a schedule that works for you.</p><a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="btn btn-primary btn-sm">Find a clinic</a></div><?php endif; ?>
      </div>
    </div>
  </section>
  <section class="portal-section"><div class="portal-heading"><div><span class="section-kicker">Records</span><h2>Consultation records</h2></div><a href="<?= HQ_BASE_URL ?>/patient/medical-records.php" class="text-link">View All <span>→</span></a></div>
  <?php if ($records): ?><div class="compact-list"><?php foreach ($records as $record): ?><article><div><strong><?= htmlspecialchars($record['ClinicName']) ?></strong><span>Dr. <?= htmlspecialchars($record['FirstName'] . ' ' . $record['LastName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($record['AppointmentDate']))) ?></span></div><div style="display:flex;gap:14px;align-items:center;"><button type="button" class="text-link" data-view-record="<?= (int) $record['AppointmentID'] ?>">View record <span>&rarr;</span></button><button type="button" class="text-link" data-view-record="<?= (int) $record['AppointmentID'] ?>" data-scroll-to="ai-summary">AI summary <span>&rarr;</span></button></div></article><?php endforeach; ?></div><?php else: ?><p class="admin-empty">No completed consultations yet. Records appear here once a visit is complete.</p><?php endif; ?></section>
  <section class="portal-section clinics-band"><div class="portal-heading"><div><span class="section-kicker">Care near you</span><h2>Browse Nearby Clinics</h2></div><a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="text-link">View all clinics <span>→</span></a></div><div class="portal-clinics"><?php foreach ($clinics as $clinic): ?><article class="portal-clinic-card"><?php if (!empty($clinic['PhotoUrl'])): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" class="clinic-photo-block"><?php else: ?><div class="clinic-photo-block"></div><?php endif; ?><h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3><p><?= htmlspecialchars($clinic['Address']) ?></p><div><span>From PHP <?= htmlspecialchars(number_format((float) $clinic['BaseConsultationFee'], 2)) ?></span><a href="<?= HQ_BASE_URL ?>/patient/clinic-detail.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>">View</a></div></article><?php endforeach; ?></div></section>
</div></main>

<?php require __DIR__ . '/../includes/booking-modal.php'; ?>

<div class="modal-overlay" id="dashAnnouncementModal">
  <div class="modal-box an-modal" role="dialog" aria-modal="true" aria-labelledby="dashAnnTitle">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div class="an-modal-head">
      <div>
        <span class="an-modal-source" id="dashAnnSource"></span>
        <span class="an-pill an-pill-slate" id="dashAnnCategory"></span>
      </div>
    </div>
    <img class="an-modal-photo" id="dashAnnPhoto" alt="" hidden>
    <h2 id="dashAnnTitle"></h2>
    <p class="an-modal-posted" id="dashAnnPosted"></p>
    <p class="an-modal-affects" id="dashAnnAffects" hidden></p>
    <div class="an-modal-message" id="dashAnnMessage"></div>
    <form method="post" action="<?= HQ_BASE_URL ?>/patient/announcements.php" class="an-modal-actions" onsubmit="return confirm('Delete this announcement? It will be removed from your list only.');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="dismiss_announcement">
      <input type="hidden" name="return_to" value="dashboard">
      <input type="hidden" name="announcement_id" id="dashAnnId" value="">
      <button type="submit" class="btn btn-outline ma-danger">Delete</button>
      <button type="button" class="btn btn-primary" data-modal-close>Close</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="queueDetailModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Queue #<span id="queueDetailNumber"></span></h2>
    <p class="modal-subtitle" id="queueDetailClinic"></p>
    <div class="dev-note" style="margin-bottom:18px;">
      <strong>Status:</strong> <span id="queueDetailStatus"></span><br>
      <strong>Appointment:</strong> <span id="queueDetailWhen"></span><br>
      <strong>Physician:</strong> <span id="queueDetailPhysician"></span><br>
      <strong>Reason for visit:</strong> <span id="queueDetailConcern"></span>
    </div>
    <a href="<?= HQ_BASE_URL ?>/patient/queue-status.php" class="btn btn-primary btn-block">View Live Status</a>
  </div>
</div>

<div class="modal-overlay" id="viewRecordModal">
  <div class="modal-box modal-box-wide" style="max-height:85vh;">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div id="viewRecordContent"><p class="admin-empty">Loading…</p></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Dashboard announcements open in a modal (the link is a no-JS fallback).
  var annModal = document.getElementById('dashAnnouncementModal');
  var annPills = { 'Closure': 'an-pill-red', 'Schedule change': 'an-pill-amber', 'Event': 'an-pill-green', 'Health advisory': 'an-pill-blue' };
  document.querySelectorAll('[data-dash-announcement]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var d = link.dataset;
      document.getElementById('dashAnnSource').textContent = d.source;
      var pill = document.getElementById('dashAnnCategory');
      pill.textContent = d.category;
      pill.className = 'an-pill ' + (annPills[d.category] || 'an-pill-slate');
      var photo = document.getElementById('dashAnnPhoto');
      photo.hidden = !d.photo;
      if (d.photo) photo.src = d.photo;
      document.getElementById('dashAnnTitle').textContent = d.title;
      document.getElementById('dashAnnPosted').textContent = 'Posted ' + d.posted;
      var affects = document.getElementById('dashAnnAffects');
      affects.hidden = !d.affects;
      affects.textContent = 'Affects appointments on ' + d.affects;
      document.getElementById('dashAnnMessage').textContent = d.message;
      document.getElementById('dashAnnId').value = d.id;
      window.hqOpenModal(annModal);
    });
  });

  var queueDetailModal = document.getElementById('queueDetailModal');
  function openQueueDetail(card) {
    if (!queueDetailModal) return;
    document.getElementById('queueDetailNumber').textContent = card.getAttribute('data-number');
    document.getElementById('queueDetailClinic').textContent = card.getAttribute('data-clinic') + ' — ' + card.getAttribute('data-address');
    document.getElementById('queueDetailStatus').textContent = card.getAttribute('data-status');
    document.getElementById('queueDetailWhen').textContent = card.getAttribute('data-when');
    document.getElementById('queueDetailPhysician').textContent = card.getAttribute('data-physician');
    document.getElementById('queueDetailConcern').textContent = card.getAttribute('data-concern');
    window.hqOpenModal(queueDetailModal);
  }
  document.querySelectorAll('[data-queue-detail]').forEach(function (card) {
    card.addEventListener('click', function () { openQueueDetail(card); });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openQueueDetail(card); }
    });
  });

  var viewRecordModal = document.getElementById('viewRecordModal');
  var viewRecordContent = document.getElementById('viewRecordContent');

  function loadRecord(appointmentId, scrollTo) {
    if (!viewRecordModal || !viewRecordContent) return;
    viewRecordContent.innerHTML = '<p class="admin-empty">Loading…</p>';
    window.hqOpenModal(viewRecordModal);
    fetch('<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=' + encodeURIComponent(appointmentId), { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) {
        viewRecordContent.innerHTML = html;
        if (scrollTo) {
          var target = viewRecordContent.querySelector('#' + scrollTo);
          if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function () {
        viewRecordContent.innerHTML = '<p class="form-message error" role="alert">We could not load this record. Please try again.</p>';
      });
  }

  document.querySelectorAll('[data-view-record]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      loadRecord(trigger.getAttribute('data-view-record'), trigger.getAttribute('data-scroll-to'));
    });
  });

  if (viewRecordContent) {
    viewRecordContent.addEventListener('submit', function (e) {
      if (!e.target.matches('.consultation-feedback-form')) return;
      e.preventDefault();
      var form = e.target;
      var formData = new FormData(form);
      fetch(form.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.text(); })
        .then(function (html) { viewRecordContent.innerHTML = html; })
        .catch(function () {
          var err = document.createElement('p');
          err.className = 'form-message error';
          err.textContent = 'We could not save your feedback. Please try again.';
          form.prepend(err);
        });
    });
  }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
