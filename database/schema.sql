-- HealthQueue Database Schema
-- Import via phpMyAdmin or: mysql -u root healthqueue_db < schema.sql
-- (Create the database first: CREATE DATABASE healthqueue_db;)

CREATE DATABASE IF NOT EXISTS healthqueue_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE healthqueue_db;

-- ---------------------------------------------------------------
-- Clinics (Data Dictionary Table 4)
-- Starting point: only what the landing page's Featured Clinics
-- section needs. Additional columns/tables (Users, Roles, Appointments,
-- QueueTokens, etc.) are added as those modules are built.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Clinic (
    ClinicID            INT AUTO_INCREMENT PRIMARY KEY,
    ClinicName          VARCHAR(150)   NOT NULL,
    Address             VARCHAR(255)   NOT NULL,
    ContactNumber       VARCHAR(20)    NOT NULL,
    BaseConsultationFee DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    PhotoUrl             VARCHAR(255)  NULL,
    Description          TEXT          NULL,
    -- Comma-separated list shown as tags/filters on the patient clinic browser.
    Specialties          VARCHAR(255)  NULL,
    -- Opening hours: ISO weekdays (1 = Monday ... 7 = Sunday), comma-separated.
    OpenDays             VARCHAR(20)   NOT NULL DEFAULT '1,2,3,4,5,6',
    OpenTime             TIME          NOT NULL DEFAULT '08:00:00',
    CloseTime            TIME          NOT NULL DEFAULT '17:00:00',
    RegistrationDate    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DeletedAt           DATETIME       NULL,
    Status               VARCHAR(20)   NOT NULL DEFAULT 'Active',
    archived             TINYINT       NOT NULL DEFAULT 0
);

-- Clinic registration inquiries submitted from the public landing page.
-- An administrator can review these before creating an active Clinic record.
CREATE TABLE IF NOT EXISTS ClinicRegistrationInquiry (
    InquiryID       INT AUTO_INCREMENT PRIMARY KEY,
    ClinicName      VARCHAR(150) NOT NULL,
    ContactName     VARCHAR(150) NOT NULL,
    Email           VARCHAR(150) NOT NULL,
    Phone           VARCHAR(30)  NOT NULL,
    City            VARCHAR(150) NOT NULL,
    PhysiciansCount INT NULL,
    Status          VARCHAR(20)  NOT NULL DEFAULT 'Pending',
    SubmittedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------
-- Roles (Data Dictionary Table 5)
-- Patients self-register through auth/register.php; Staff, Physician
-- and Admin accounts are created later by a System Administrator, per
-- the manuscript's onboarding workflow (Figure 12 / Network Topology).
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Roles (
    RoleID   INT AUTO_INCREMENT PRIMARY KEY,
    RoleName VARCHAR(50) NOT NULL UNIQUE
);

INSERT IGNORE INTO Roles (RoleName) VALUES
    ('Patient'), ('Staff'), ('Physician'), ('Admin');

-- ---------------------------------------------------------------
-- Users (Data Dictionary Table 8)
-- Deviation from the strict data dictionary: ClinicID is nullable here.
-- Patients and platform Admins are not scoped to a single clinic (a
-- patient books across many clinics), while Staff/Physician accounts
-- -- created by an Admin once a clinic is onboarded -- do carry a
-- ClinicID. ContactNumber is added for SMS notifications (storyboard
-- Figure 24's sign-up form) though it isn't in the printed dictionary.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Users (
    UserID        INT AUTO_INCREMENT PRIMARY KEY,
    -- Public 10-digit ID shown to users: RR YY NNNNNN = role code
    -- (01 Patient, 02 Physician, 03 Staff, 04 Admin), 2-digit registration
    -- year, per-role-per-year sequence. Set by assignUserIdNumber() in
    -- includes/auth.php right after the account is created.
    IDNumber      CHAR(10)     NULL UNIQUE,
    ClinicID      INT NULL,
    RoleID        INT NOT NULL,
    FirstName     VARCHAR(50)  NOT NULL,
    LastName      VARCHAR(50)  NOT NULL,
    Email         VARCHAR(100) NOT NULL UNIQUE,
    ContactNumber VARCHAR(20)  NULL,
    PasswordHash  VARCHAR(255) NOT NULL,
    CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DeletedAt     DATETIME     NULL,
    Status        VARCHAR(20)  NOT NULL DEFAULT 'Active',
    Archived      TINYINT      NOT NULL DEFAULT 0,
    -- Physician's own self-reported availability toggle, shown on their
    -- dashboard. Meaningless for other roles; left at the default for them.
    AvailabilityStatus VARCHAR(20) NOT NULL DEFAULT 'Available',
    ProfilePhoto  VARCHAR(255) NULL,
    -- Patient wallet balance. Meaningless for other roles; a simulated
    -- store of value only -- top-ups and payments never touch real money.
    WalletBalance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Patient-entered medical information shown on their profile.
    -- Meaningless for other roles; left NULL for them.
    BloodType              VARCHAR(5)   NULL,
    Allergies              VARCHAR(500) NULL,
    EmergencyContactName   VARCHAR(100) NULL,
    EmergencyContactNumber VARCHAR(20)  NULL,
    CONSTRAINT fk_users_role  FOREIGN KEY (RoleID)   REFERENCES Roles (RoleID),
    CONSTRAINT fk_users_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID)
);

-- Stores hashed, single-use, time-limited password reset tokens.
-- Only the SHA-256 hash of the token is stored (never the raw token),
-- matching the same principle as PasswordHash on Users.
CREATE TABLE IF NOT EXISTS PasswordResets (
    ResetID   INT AUTO_INCREMENT PRIMARY KEY,
    UserID    INT NOT NULL,
    TokenHash CHAR(64) NOT NULL,
    ExpiresAt DATETIME NOT NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UsedAt    DATETIME NULL,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (UserID) REFERENCES Users (UserID),
    UNIQUE KEY uq_token_hash (TokenHash)
);

-- Physician weekly availability (Data Dictionary Table 11). A physician
-- manages their own rows here to advertise which days/times they see
-- patients (storyboard Figure 40). Not yet enforced by the patient
-- booking form -- see physician/availability.php's note.
CREATE TABLE IF NOT EXISTS PhysicianAvailability (
    AvailabilityID INT AUTO_INCREMENT PRIMARY KEY,
    PhysicianID    INT NOT NULL,
    DayOfWeek      TINYINT NOT NULL, -- 1=Monday .. 7=Sunday (ISO-8601)
    StartTime      TIME NOT NULL,
    EndTime        TIME NOT NULL,
    CONSTRAINT fk_availability_physician FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    INDEX idx_availability_physician (PhysicianID)
);

-- Patient appointment requests. Clinic staff can later confirm these and
-- assign a physician/queue token from the staff workflow.
CREATE TABLE IF NOT EXISTS Appointments (
    AppointmentID   INT AUTO_INCREMENT PRIMARY KEY,
    PatientID       INT NOT NULL,
    ClinicID        INT NOT NULL,
    PhysicianID     INT NULL,
    AppointmentDate DATE NOT NULL,
    AppointmentTime TIME NULL,
    Concern         TEXT NULL,
    Status          VARCHAR(20) NOT NULL DEFAULT 'Pending',
    -- The simulated checkout gate: a request only reaches the clinic's
    -- Appointment Requests queue once the patient has "paid" the booking
    -- fee (no real payment gateway is integrated -- see patient/checkout.php).
    BookingFeePaid    TINYINT  NOT NULL DEFAULT 0,
    BookingFeePaidAt  DATETIME NULL,
    -- Patient-supplied reason when they cancel their own request (one of a
    -- fixed set of choices, or free text when they pick "Others").
    CancellationReason VARCHAR(255) NULL,
    CreatedAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_appointments_patient FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_appointments_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_appointments_physician FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    INDEX idx_appointments_patient_date (PatientID, AppointmentDate),
    INDEX idx_appointments_clinic_date (ClinicID, AppointmentDate)
);

-- Financial record created only when an appointment payment succeeds.
-- The split is retained at the time of payment: 90% to the physician and
-- 10% to HealthQueue as the platform commission.
CREATE TABLE IF NOT EXISTS AppointmentPayments (
    PaymentID           INT AUTO_INCREMENT PRIMARY KEY,
    AppointmentID       INT NOT NULL,
    GrossAmount         DECIMAL(10,2) NOT NULL,
    PhysicianRevenue    DECIMAL(10,2) NOT NULL,
    PlatformCommission  DECIMAL(10,2) NOT NULL,
    PaymentStatus       VARCHAR(20) NOT NULL DEFAULT 'Paid',
    PaidAt              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    RefundedAt          DATETIME NULL,
    CONSTRAINT fk_payments_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    UNIQUE KEY uq_payment_appointment (AppointmentID),
    INDEX idx_payments_status_paidat (PaymentStatus, PaidAt)
);

-- Broadcast messages an Admin posts to patients/staff/physicians (Figure 48).
CREATE TABLE IF NOT EXISTS Announcements (
    AnnouncementID INT AUTO_INCREMENT PRIMARY KEY,
    PostedByUserID INT NOT NULL,
    Title         VARCHAR(150) NOT NULL,
    Message       TEXT NOT NULL,
    Audience      VARCHAR(20) NOT NULL DEFAULT 'Everyone',
    CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_announcements_user FOREIGN KEY (PostedByUserID) REFERENCES Users (UserID)
);

-- Admin activity trail (Data Dictionary Table 19, Figure 49). ClinicID is
-- nullable here (unlike the printed dictionary) since several logged
-- actions -- posting an announcement, reviewing a registration inquiry
-- before a Clinic row even exists -- aren't scoped to one clinic.
CREATE TABLE IF NOT EXISTS AuditLogs (
    LogID          INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID       INT NULL,
    UserID         INT NULL,
    ActionExecuted VARCHAR(150) NOT NULL,
    Description    VARCHAR(255) NULL,
    CreatedAt      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auditlogs_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_auditlogs_user FOREIGN KEY (UserID) REFERENCES Users (UserID),
    INDEX idx_auditlogs_created (CreatedAt)
);

-- Consultations (Data Dictionary Table 15). One row per completed visit;
-- the actual clinical content lives in ConsultationVersions so edits keep
-- a full history instead of silently overwriting the record.
CREATE TABLE IF NOT EXISTS Consultations (
    ConsultationID       INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID             INT NOT NULL,
    AppointmentID        INT NOT NULL,
    PatientID            INT NOT NULL,
    DoctorID             INT NOT NULL,
    AudioFilePath        VARCHAR(255) NULL,
    AudioDurationSeconds INT NULL,
    CompletedAt          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DeletedAt            DATETIME NULL,
    Status               VARCHAR(20) NOT NULL DEFAULT 'Finalized',
    Archived             TINYINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_consultations_clinic      FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_consultations_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    CONSTRAINT fk_consultations_patient     FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_consultations_doctor      FOREIGN KEY (DoctorID) REFERENCES Users (UserID),
    UNIQUE KEY uq_consultation_appointment (AppointmentID)
);

-- AI Transcript Versions (Data Dictionary Table 17). TranscriptType is one
-- of Original_STT / Doctor_Corrected / AI_Summary. WordErrorRate is filled
-- in only once a real STT engine is wired up (see includes/ai-transcription.php).
CREATE TABLE IF NOT EXISTS AITranscriptVersions (
    TranscriptVersionID INT AUTO_INCREMENT PRIMARY KEY,
    ConsultationID      INT NOT NULL,
    TranscriptType      VARCHAR(30) NOT NULL,
    TranscriptText      TEXT NOT NULL,
    WordErrorRate       DECIMAL(5,2) NULL,
    GeneratedAt         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aitranscripts_consultation FOREIGN KEY (ConsultationID) REFERENCES Consultations (ConsultationID),
    INDEX idx_aitranscripts_consultation (ConsultationID, TranscriptType, GeneratedAt)
);

-- Consultation Versions (Data Dictionary Table 16). Every save inserts a
-- new row rather than updating in place, so notes can never be silently
-- lost -- only the latest RevisionNumber is shown by default.
CREATE TABLE IF NOT EXISTS ConsultationVersions (
    VersionID           INT AUTO_INCREMENT PRIMARY KEY,
    ConsultationID       INT NOT NULL,
    EditedByPhysicianID  INT NOT NULL,
    RevisionNumber       INT NOT NULL,
    ClinicalNotes        TEXT NOT NULL,
    PrescriptionText     TEXT NULL,
    UpdatedAt            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DeletedAt            DATETIME NULL,
    Status               VARCHAR(20) NOT NULL DEFAULT 'Active',
    Archived             TINYINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_consultationversions_consultation FOREIGN KEY (ConsultationID) REFERENCES Consultations (ConsultationID),
    CONSTRAINT fk_consultationversions_physician FOREIGN KEY (EditedByPhysicianID) REFERENCES Users (UserID),
    INDEX idx_consultationversions_consultation (ConsultationID, RevisionNumber)
);

-- One row per patient waiting in a clinic's walk-in/day-of queue. Always
-- tied to an Appointment (walk-ins get one created on the spot with no
-- prior request step); the physician is copied in at registration time so
-- the queue can be filtered even if the appointment's physician changes.
CREATE TABLE IF NOT EXISTS Queue (
    QueueID          INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID         INT NOT NULL,
    AppointmentID    INT NOT NULL,
    PhysicianID      INT NULL,
    QueueNumber      INT NOT NULL,
    ScheduledNumber  INT NULL,
    RegularNumber    INT NULL,
    Status           VARCHAR(20) NOT NULL DEFAULT 'Waiting', -- Waiting, Calling, Serving, Completed, Forfeited_Late, Removed
    CreatedByStaffID INT NULL,
    CreatedAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CalledAt         DATETIME NULL,
    ServedAt         DATETIME NULL,
    CONSTRAINT fk_queue_clinic      FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_queue_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    CONSTRAINT fk_queue_physician   FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    CONSTRAINT fk_queue_staff       FOREIGN KEY (CreatedByStaffID) REFERENCES Users (UserID),
    INDEX idx_queue_clinic_date (ClinicID, CreatedAt)
);

-- Lightweight, clinic-wide (not per-user) notification feed for staff --
-- read state is shared across everyone on the clinic's team, which keeps
-- "Mark all read" meaningful without a separate per-user read-tracking table.
CREATE TABLE IF NOT EXISTS Notifications (
    NotificationID       INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID             INT NOT NULL,
    Message              VARCHAR(255) NOT NULL,
    RelatedAppointmentID INT NULL,
    IsRead               TINYINT NOT NULL DEFAULT 0,
    CreatedAt            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_clinic      FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_notifications_appointment FOREIGN KEY (RelatedAppointmentID) REFERENCES Appointments (AppointmentID),
    INDEX idx_notifications_clinic_read (ClinicID, IsRead)
);

-- Patient-uploaded medical records (e.g. lab results, prior prescriptions).
-- Files are stored outside the webroot and served only through
-- patient/record-stream.php after an ownership check, same pattern as the
-- consultation audio recordings.
CREATE TABLE IF NOT EXISTS MedicalRecords (
    RecordID      INT AUTO_INCREMENT PRIMARY KEY,
    PatientID     INT NOT NULL,
    Title         VARCHAR(150) NOT NULL,
    Category      VARCHAR(30)  NOT NULL DEFAULT 'Other', -- Lab result, Imaging, Prescription, Medical certificate, Other
    Description   TEXT NULL,
    FilePath      VARCHAR(255) NOT NULL,
    FileType      VARCHAR(50) NOT NULL,
    UploadedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_medicalrecords_patient FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    INDEX idx_medicalrecords_patient (PatientID)
);

-- Personal notification feed for one patient (unlike the clinic-shared
-- Notifications table used by staff, read state here is per patient).
CREATE TABLE IF NOT EXISTS PatientNotifications (
    NotificationID       INT AUTO_INCREMENT PRIMARY KEY,
    PatientID            INT NOT NULL,
    Message              VARCHAR(255) NOT NULL,
    RelatedAppointmentID INT NULL,
    IsRead               TINYINT NOT NULL DEFAULT 0,
    CreatedAt            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_patientnotifications_patient     FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_patientnotifications_appointment FOREIGN KEY (RelatedAppointmentID) REFERENCES Appointments (AppointmentID),
    INDEX idx_patientnotifications_patient_read (PatientID, IsRead)
);

-- Patient feedback for one completed visit. One row per appointment --
-- patients can only rate a visit once it's Completed, and only once.
CREATE TABLE IF NOT EXISTS Feedback (
    FeedbackID    INT AUTO_INCREMENT PRIMARY KEY,
    AppointmentID INT NOT NULL,
    PatientID     INT NOT NULL,
    ClinicID      INT NOT NULL,
    PhysicianID   INT NULL,
    Rating        TINYINT NOT NULL,
    Comment       TEXT NULL,
    CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_feedback_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    CONSTRAINT fk_feedback_patient     FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_feedback_clinic      FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_feedback_physician   FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    UNIQUE KEY uq_feedback_appointment (AppointmentID),
    INDEX idx_feedback_clinic (ClinicID)
);

-- Append-only ledger backing Users.WalletBalance -- every top-up, booking
-- payment, and cancellation refund gets its own row so the balance can
-- always be reconstructed/audited, never just overwritten.
CREATE TABLE IF NOT EXISTS WalletTransactions (
    TransactionID        INT AUTO_INCREMENT PRIMARY KEY,
    PatientID             INT NOT NULL,
    Type                  VARCHAR(20) NOT NULL, -- TopUp, BookingPayment, Refund
    Amount                DECIMAL(10,2) NOT NULL,
    BalanceAfter          DECIMAL(10,2) NOT NULL,
    RelatedAppointmentID  INT NULL,
    Description           VARCHAR(255) NULL,
    CreatedAt             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallettx_patient     FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_wallettx_appointment FOREIGN KEY (RelatedAppointmentID) REFERENCES Appointments (AppointmentID),
    INDEX idx_wallettx_patient (PatientID, CreatedAt)
);

-- Upgrade path for databases created before the medical-info columns
-- existed (CREATE TABLE IF NOT EXISTS above won't add them).
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS BloodType              VARCHAR(5)   NULL,
    ADD COLUMN IF NOT EXISTS Allergies              VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS EmergencyContactName   VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS EmergencyContactNumber VARCHAR(20)  NULL;

-- Existing users get their IDNumber backfilled by assignUserIdNumber().
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS IDNumber CHAR(10) NULL AFTER UserID,
    ADD UNIQUE INDEX IF NOT EXISTS uq_users_idnumber (IDNumber);

ALTER TABLE Clinic
    ADD COLUMN IF NOT EXISTS Specialties VARCHAR(255) NULL AFTER Description,
    ADD COLUMN IF NOT EXISTS OpenDays    VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5,6' AFTER Specialties,
    ADD COLUMN IF NOT EXISTS OpenTime    TIME         NOT NULL DEFAULT '08:00:00' AFTER OpenDays,
    ADD COLUMN IF NOT EXISTS CloseTime   TIME         NOT NULL DEFAULT '17:00:00' AFTER OpenTime;

INSERT INTO Clinic (ClinicName, Address, ContactNumber, BaseConsultationFee, PhotoUrl)
VALUES
    ('Sunrise Medical Center', '123 Rizal Ave, Manila', '+63 2 8123 4567', 300.00, 'sunrise.jpg'),
    ('Northgate Family Clinic', '456 Aurora Blvd, Quezon City', '+63 2 8987 6543', 250.00, 'northgate.jpg'),
    ('Bayview Health Hub', '789 Roxas Blvd, Pasay', '+63 2 8555 0102', 350.00, 'bayview.jpg'),
    ('Eastside Wellness Clinic', '321 Marcos Highway, Antipolo', '+63 2 8222 7788', 280.00, 'eastside.jpg');

ALTER TABLE MedicalRecords
    ADD COLUMN IF NOT EXISTS Category VARCHAR(30) NOT NULL DEFAULT 'Other' AFTER Title;

-- Announcements: optional source clinic (NULL = HealthQueue platform-wide),
-- a category for the patient-side tag, and an optional date the announcement
-- affects (patients booked at that clinic on that date see it pinned).
ALTER TABLE Announcements
    ADD COLUMN IF NOT EXISTS ClinicID    INT         NULL AFTER PostedByUserID,
    ADD COLUMN IF NOT EXISTS Category    VARCHAR(30) NOT NULL DEFAULT 'General' AFTER Title, -- General, Closure, Schedule change, Event, Health advisory
    ADD COLUMN IF NOT EXISTS AffectsDate DATE        NULL AFTER Audience;
ALTER TABLE Announcements
    ADD CONSTRAINT fk_announcements_clinic FOREIGN KEY IF NOT EXISTS (ClinicID) REFERENCES Clinic (ClinicID);

-- When the patient last opened Announcements (drives the "New" dots/count).
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS AnnouncementsSeenAt DATETIME NULL;

-- Announcements a patient has deleted from their own view. Announcements are
-- shared, so "delete" only hides it for that user.
CREATE TABLE IF NOT EXISTS AnnouncementDismissals (
    UserID         INT NOT NULL,
    AnnouncementID INT NOT NULL,
    DismissedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (UserID, AnnouncementID),
    CONSTRAINT fk_ann_dismiss_user FOREIGN KEY (UserID) REFERENCES Users (UserID),
    CONSTRAINT fk_ann_dismiss_announcement FOREIGN KEY (AnnouncementID) REFERENCES Announcements (AnnouncementID) ON DELETE CASCADE
);

-- Reason the clinic gave when declining a request (shown to the patient).
-- Separate from CancellationReason, which is the patient's own reason.
ALTER TABLE Appointments
    ADD COLUMN IF NOT EXISTS DeclineReason VARCHAR(255) NULL AFTER CancellationReason;

ALTER TABLE Appointments MODIFY COLUMN AppointmentTime TIME NULL;

-- Queue ordering + priority. Position lets front-desk staff reorder the
-- waiting line (NULL = natural order, QueueNumber * 10); Priority tags
-- walk-ins such as seniors/PWD/pregnant who are placed ahead of the line.
ALTER TABLE Queue
    ADD COLUMN IF NOT EXISTS Position INT         NULL AFTER QueueNumber,
    ADD COLUMN IF NOT EXISTS Priority VARCHAR(20) NULL AFTER Position,
    ADD COLUMN IF NOT EXISTS ScheduledNumber INT NULL AFTER QueueNumber,
    ADD COLUMN IF NOT EXISTS RegularNumber INT NULL AFTER ScheduledNumber;

-- Optional photo attached to an announcement (file in assets/uploads/announcements).
ALTER TABLE Announcements
    ADD COLUMN IF NOT EXISTS PhotoPath VARCHAR(255) NULL AFTER Message;

-- Weekly schedule extras: optional booking capacity per hour, and an Active
-- flag so a physician can switch a whole day off without losing its hours.
ALTER TABLE PhysicianAvailability
    ADD COLUMN IF NOT EXISTS PatientsPerHour TINYINT NULL AFTER EndTime,
    ADD COLUMN IF NOT EXISTS Active TINYINT NOT NULL DEFAULT 1 AFTER PatientsPerHour;

-- Physician availability by calendar date (replaces the weekly
-- PhysicianAvailability schedule). A date with hour blocks is bookable; a
-- row with IsDayOff = 1 marks a day off; a date with no rows is "not set"
-- and not bookable.
CREATE TABLE IF NOT EXISTS PhysicianDateAvailability (
    SlotID          INT AUTO_INCREMENT PRIMARY KEY,
    PhysicianID     INT NOT NULL,
    AvailDate       DATE NOT NULL,
    StartTime       TIME NULL,
    EndTime         TIME NULL,
    PatientsPerHour TINYINT NULL,
    IsDayOff        TINYINT NOT NULL DEFAULT 0,
    CreatedAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dateavail_physician FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    INDEX idx_dateavail_physician_date (PhysicianID, AvailDate)
);

-- Structured consultation notes (SOAP sections, vitals, medicine rows) kept
-- as JSON alongside the plain-text ClinicalNotes/PrescriptionText, which are
-- still written for the patient-facing record pages.
ALTER TABLE ConsultationVersions
    ADD COLUMN IF NOT EXISTS NotesJson TEXT NULL AFTER PrescriptionText;

-- Whether the patient consented to the consultation being recorded.
ALTER TABLE Consultations
    ADD COLUMN IF NOT EXISTS RecordingConsent TINYINT NOT NULL DEFAULT 0 AFTER AudioDurationSeconds;

-- How the (simulated) booking fee was paid: wallet, gcash, maya or card.
ALTER TABLE Appointments
    ADD COLUMN IF NOT EXISTS BookingPaymentMethod VARCHAR(20) NULL AFTER BookingFeePaidAt;
