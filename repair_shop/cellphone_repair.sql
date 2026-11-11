-- Safe initial schema bootstrap: this never drops an existing database.
-- Existing installations need a reviewed migration, not a re-import. Requires MySQL 8+.

CREATE DATABASE IF NOT EXISTS cellphone_repair
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE cellphone_repair;

-- Core tables are ordered so referenced parent tables are created first.

-- Authentication, password-reset, and audit records.

CREATE TABLE users (
  user_id        INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(50)  NOT NULL,
  email          VARCHAR(100) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(100) NOT NULL,
  role           ENUM('admin','staff','technician','customer') NOT NULL DEFAULT 'staff',
  is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_users_username UNIQUE (username),
  CONSTRAINT uq_users_email    UNIQUE (email)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  reset_id    INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          NOT NULL,
  token_hash  VARCHAR(255) NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  log_id        INT AUTO_INCREMENT PRIMARY KEY,
  admin_id      INT         NOT NULL,
  action        VARCHAR(50) NOT NULL,
  target_table  VARCHAR(50) NOT NULL,
  target_id     INT         NULL,
  created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id)
    REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Customer identity is optional-linked to a login; device ownership is required.

CREATE TABLE customers (
  customer_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT          NULL,            -- NULL for customers without an account.
  full_name       VARCHAR(100) NOT NULL,
  contact_number  VARCHAR(20)  NOT NULL,
  email           VARCHAR(100) NULL,
  address         VARCHAR(255) NULL,
  consent_given   BOOLEAN      NOT NULL DEFAULT FALSE,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_customers_user    UNIQUE (user_id),
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE suppliers (
  supplier_id     INT AUTO_INCREMENT PRIMARY KEY,
  supplier_name   VARCHAR(100) NOT NULL,
  contact_person  VARCHAR(100) NULL,
  contact_number  VARCHAR(20)  NULL,
  email           VARCHAR(100) NULL,
  address         VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE devices (
  device_id     INT AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT         NOT NULL,
  supplier_id   INT         NULL,
  brand         VARCHAR(50) NOT NULL,
  model         VARCHAR(50) NOT NULL,
  imei_serial   VARCHAR(30) NULL,
  CONSTRAINT fk_devices_customer FOREIGN KEY (customer_id)
    REFERENCES customers (customer_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_devices_supplier FOREIGN KEY (supplier_id)
    REFERENCES suppliers (supplier_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Technician-specific profile data; each technician maps to one user account.

CREATE TABLE technicians (
  technician_id   INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT          NOT NULL,
  specialization  VARCHAR(100) NULL,
  contact_number  VARCHAR(20)  NULL,
  is_available    BOOLEAN      NOT NULL DEFAULT TRUE,
  CONSTRAINT uq_technicians_user UNIQUE (user_id),
  CONSTRAINT fk_technicians_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Status labels and ordering are shared by workflow, tracking, and notifications.

CREATE TABLE repair_statuses (
  status_id       INT AUTO_INCREMENT PRIMARY KEY,
  status_name     VARCHAR(30) NOT NULL,
  customer_label  VARCHAR(50) NOT NULL,   -- Public-facing status text.
  is_milestone    BOOLEAN     NOT NULL DEFAULT FALSE,  -- Enables status notifications.
  sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT uq_repair_statuses_name UNIQUE (status_name)
) ENGINE=InnoDB;

-- Inventory quantities are constrained to non-negative values.

CREATE TABLE parts (
  part_id      INT AUTO_INCREMENT PRIMARY KEY,
  part_number  VARCHAR(50)   NOT NULL,
  part_name    VARCHAR(100)  NOT NULL,
  unit_cost    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  stock_qty    INT           NOT NULL DEFAULT 0,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_parts_number UNIQUE (part_number),
  CONSTRAINT chk_parts_stock CHECK (stock_qty >= 0),
  CONSTRAINT chk_parts_cost  CHECK (unit_cost >= 0)
) ENGINE=InnoDB;

-- A job links a customer/device to its current workflow status and technician.

CREATE TABLE job_orders (
  job_order_id      INT AUTO_INCREMENT PRIMARY KEY,
  reference_no      VARCHAR(50)   NOT NULL,
  customer_id       INT           NOT NULL,
  device_id         INT           NOT NULL,
  assigned_tech_id  INT           NULL,
  current_status_id INT           NOT NULL,
  reported_issue    TEXT          NOT NULL,
  condition_notes   TEXT          NULL,
  diagnosis         TEXT          NULL,
  estimated_cost    DECIMAL(10,2) NULL,
  cancel_reason     VARCHAR(255)  NULL,
  customer_photo_path VARCHAR(255) NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_job_orders_reference UNIQUE (reference_no),
  CONSTRAINT chk_job_orders_cost CHECK (estimated_cost IS NULL OR estimated_cost >= 0),
  CONSTRAINT fk_jo_customer FOREIGN KEY (customer_id)
    REFERENCES customers (customer_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_jo_device FOREIGN KEY (device_id)
    REFERENCES devices (device_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_jo_tech FOREIGN KEY (assigned_tech_id)
    REFERENCES technicians (technician_id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_jo_status FOREIGN KEY (current_status_id)
    REFERENCES repair_statuses (status_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE job_order_parts (
  job_order_part_id INT AUTO_INCREMENT PRIMARY KEY,
  job_order_id      INT           NOT NULL,
  part_id           INT           NOT NULL,
  quantity_used     INT           NOT NULL DEFAULT 1,
  unit_price        DECIMAL(10,2) NOT NULL,   -- Snapshot of the charge when the part is used.
  CONSTRAINT chk_jop_qty CHECK (quantity_used > 0),
  CONSTRAINT fk_jop_job FOREIGN KEY (job_order_id)
    REFERENCES job_orders (job_order_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_jop_part FOREIGN KEY (part_id)
    REFERENCES parts (part_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE job_notes (
  note_id        INT AUTO_INCREMENT PRIMARY KEY,
  job_order_id   INT          NOT NULL,
  technician_id  INT          NOT NULL,
  note_text      TEXT         NOT NULL,
  parts_used     VARCHAR(255) NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notes_job FOREIGN KEY (job_order_id)
    REFERENCES job_orders (job_order_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_notes_tech FOREIGN KEY (technician_id)
    REFERENCES technicians (technician_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE status_history (
  history_id    INT AUTO_INCREMENT PRIMARY KEY,
  job_order_id  INT          NOT NULL,
  status_id     INT          NOT NULL,
  remarks       VARCHAR(255) NULL,
  changed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sh_job FOREIGN KEY (job_order_id)
    REFERENCES job_orders (job_order_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sh_status FOREIGN KEY (status_id)
    REFERENCES repair_statuses (status_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Notification queue and delivery history; user_id records the staff trigger when present.

CREATE TABLE notifications (
  notification_id  INT AUTO_INCREMENT PRIMARY KEY,
  job_order_id     INT         NOT NULL,
  customer_id      INT         NOT NULL,
  user_id          INT         NULL,          -- NULL for system-generated messages.
  channel          ENUM('sms','email','in_app') NOT NULL DEFAULT 'sms',
  trigger_type     VARCHAR(30) NOT NULL,
  message          TEXT        NOT NULL,
  image_path       VARCHAR(255) NULL,
  delivery_status  VARCHAR(20) NOT NULL DEFAULT 'pending',
  sent_at          DATETIME    NULL,
  CONSTRAINT fk_notif_job FOREIGN KEY (job_order_id)
    REFERENCES job_orders (job_order_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_notif_customer FOREIGN KEY (customer_id)
    REFERENCES customers (customer_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id)
    REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  attempt_id    BIGINT AUTO_INCREMENT PRIMARY KEY,
  attempt_key   CHAR(64) NOT NULL,
  attempted_at  DATETIME NOT NULL,
  INDEX idx_login_attempt_key_time (attempt_key, attempted_at),
  INDEX idx_login_attempt_time (attempted_at)
) ENGINE=InnoDB;

-- Add composite/search indexes for application query patterns not covered by
-- InnoDB's foreign-key indexes or the unique constraints above.

-- Supports customer-name lookup in the staff workflow.
CREATE INDEX idx_customers_name        ON customers (full_name);

-- Supports device searches by serial number and make/model.
CREATE INDEX idx_devices_imei          ON devices (imei_serial);
CREATE INDEX idx_devices_brand_model   ON devices (brand, model);

-- Supports job dashboard filters and customer/job history queries.
CREATE INDEX idx_jo_status_created     ON job_orders (current_status_id, created_at);
CREATE INDEX idx_jo_tech_status        ON job_orders (assigned_tech_id, current_status_id);
CREATE INDEX idx_jo_created            ON job_orders (created_at);
CREATE INDEX idx_jo_customer_created   ON job_orders (customer_id, created_at);

-- Supports chronological history and note retrieval per job.
CREATE INDEX idx_sh_job_changed        ON status_history (job_order_id, changed_at);
CREATE INDEX idx_notes_job_created     ON job_notes (job_order_id, created_at);

-- Supports per-job part lookups and inventory reports.
CREATE INDEX idx_jop_job_part          ON job_order_parts (job_order_id, part_id);
CREATE INDEX idx_parts_name            ON parts (part_name);

-- Supports pending-queue processing and notification history views.
CREATE INDEX idx_notif_delivery        ON notifications (delivery_status, sent_at);
CREATE INDEX idx_notif_job_sent        ON notifications (job_order_id, sent_at);

-- Supports reset-token expiry checks and audit-log filtering.
CREATE INDEX idx_pwreset_token_expiry  ON password_resets (token_hash, expires_at);
CREATE INDEX idx_audit_admin_created   ON audit_logs (admin_id, created_at);
CREATE INDEX idx_audit_target          ON audit_logs (target_table, target_id);

-- Supports technician availability lookups during assignment.
CREATE INDEX idx_tech_available        ON technicians (is_available);

-- Seed the default workflow. Keep status_name values in sync with application
-- logic; milestone flags control customer-facing status notifications.

INSERT INTO repair_statuses (status_name, customer_label, is_milestone, sort_order) VALUES
  ('received',        'Device received',           TRUE,  1),
  ('diagnosing',      'Being diagnosed',           FALSE, 2),
  ('awaiting_approval','Waiting for your approval', TRUE,  3),
  ('awaiting_parts',  'Waiting for parts',         FALSE, 4),
  ('in_repair',       'Under repair',              FALSE, 5),
  ('ready_for_pickup','Ready for pickup',          TRUE,  6),
  ('completed',       'Completed',                 TRUE,  7),
  ('cancelled',       'Cancelled',                 TRUE,  8);

-- Reusable read models for job details, history, totals, and dashboards.

-- Consolidates the related entities used by staff and public tracking pages.
CREATE OR REPLACE VIEW v_job_order_details AS
SELECT
  jo.job_order_id,
  jo.reference_no,
  c.customer_id,
  c.full_name        AS customer_name,
  c.contact_number   AS customer_contact,
  d.device_id,
  d.brand,
  d.model,
  d.imei_serial,
  jo.reported_issue,
  jo.condition_notes,
  jo.diagnosis,
  jo.estimated_cost,
  rs.status_name,
  rs.customer_label  AS status_label,
  t.technician_id,
  tu.full_name       AS technician_name,
  jo.created_at,
  jo.updated_at
FROM job_orders jo
JOIN customers       c  ON c.customer_id   = jo.customer_id
JOIN devices         d  ON d.device_id     = jo.device_id
JOIN repair_statuses rs ON rs.status_id    = jo.current_status_id
LEFT JOIN technicians t  ON t.technician_id = jo.assigned_tech_id
LEFT JOIN users       tu ON tu.user_id      = t.user_id;

-- Provides ordered status-history fields for a job's timeline.
CREATE OR REPLACE VIEW v_status_timeline AS
SELECT
  jo.job_order_id,
  jo.reference_no,
  sh.history_id,
  rs.status_name,
  rs.customer_label,
  rs.is_milestone,
  sh.remarks,
  sh.changed_at
FROM status_history sh
JOIN job_orders      jo ON jo.job_order_id = sh.job_order_id
JOIN repair_statuses rs ON rs.status_id    = sh.status_id;

-- Separates recorded customer charges from current inventory cost.
CREATE OR REPLACE VIEW v_job_order_totals AS
SELECT
  jo.job_order_id,
  jo.reference_no,
  COALESCE(SUM(jop.quantity_used * jop.unit_price), 0)  AS parts_total_charged,
  COALESCE(SUM(jop.quantity_used * p.unit_cost), 0)     AS parts_total_cost,
  COALESCE(SUM(jop.quantity_used), 0)                   AS parts_count
FROM job_orders jo
LEFT JOIN job_order_parts jop ON jop.job_order_id = jo.job_order_id
LEFT JOIN parts           p   ON p.part_id        = jop.part_id
GROUP BY jo.job_order_id, jo.reference_no;

-- Counts assigned jobs excluding terminal completed/cancelled statuses.
CREATE OR REPLACE VIEW v_technician_workload AS
SELECT
  t.technician_id,
  u.full_name,
  t.specialization,
  t.is_available,
  COUNT(jo.job_order_id) AS open_jobs
FROM technicians t
JOIN users u ON u.user_id = t.user_id
LEFT JOIN job_orders jo
       ON jo.assigned_tech_id = t.technician_id
      AND jo.current_status_id NOT IN (
            SELECT status_id FROM repair_statuses
            WHERE status_name IN ('completed','cancelled'))
GROUP BY t.technician_id, u.full_name, t.specialization, t.is_available;

-- Apply the inventory threshold used by the low-stock report.
CREATE OR REPLACE VIEW v_low_stock_parts AS
SELECT part_id, part_number, part_name, stock_qty, unit_cost
FROM parts
WHERE stock_qty <= 5;

-- Reference queries for common application reports and troubleshooting.

-- Public tracking lookup by reference number.
-- SELECT reference_no, status_label, brand, model, updated_at
-- FROM v_job_order_details
-- WHERE reference_no = 'JO-2026-0001';

-- Status history for one job.
-- SELECT status_name, customer_label, remarks, changed_at
-- FROM v_status_timeline
-- WHERE reference_no = 'JO-2026-0001'
-- ORDER BY changed_at;

-- Repair history grouped by customer.
-- SELECT jo.reference_no, d.brand, d.model, rs.customer_label, jo.created_at
-- FROM job_orders jo
-- JOIN devices d         ON d.device_id  = jo.device_id
-- JOIN repair_statuses rs ON rs.status_id = jo.current_status_id
-- WHERE jo.customer_id = 1
-- ORDER BY jo.created_at DESC;

-- Part usage and charge totals for one job.
-- SELECT p.part_number, p.part_name, jop.quantity_used, jop.unit_price,
--        jop.quantity_used * jop.unit_price AS line_total
-- FROM job_order_parts jop
-- JOIN parts p ON p.part_id = jop.part_id
-- WHERE jop.job_order_id = 1;

-- Technician work log with the author attached.
-- SELECT jn.created_at, u.full_name AS technician, jn.note_text, jn.parts_used
-- FROM job_notes jn
-- JOIN technicians t ON t.technician_id = jn.technician_id
-- JOIN users u       ON u.user_id       = t.user_id
-- WHERE jn.job_order_id = 1
-- ORDER BY jn.created_at;

-- Notification queue entries with customer delivery details.
-- SELECT n.notification_id, jo.reference_no, c.full_name, c.contact_number,
--        c.email, n.channel, n.message
-- FROM notifications n
-- JOIN job_orders jo ON jo.job_order_id = n.job_order_id
-- JOIN customers c   ON c.customer_id   = n.customer_id
-- WHERE n.delivery_status = 'pending'
--   AND c.consent_given = TRUE;

-- Device repair volume by supplier and model.
-- SELECT s.supplier_name, d.brand, d.model, COUNT(jo.job_order_id) AS repairs
-- FROM suppliers s
-- JOIN devices d         ON d.supplier_id = s.supplier_id
-- LEFT JOIN job_orders jo ON jo.device_id = d.device_id
-- GROUP BY s.supplier_name, d.brand, d.model;

-- Audit activity with the account that performed each action.
-- SELECT al.created_at, u.username, al.action, al.target_table, al.target_id
-- FROM audit_logs al
-- JOIN users u ON u.user_id = al.admin_id
-- ORDER BY al.created_at DESC;

-- Job counts for each workflow status, including statuses with no jobs.
-- SELECT rs.status_name, COUNT(jo.job_order_id) AS total
-- FROM repair_statuses rs
-- LEFT JOIN job_orders jo ON jo.current_status_id = rs.status_id
-- GROUP BY rs.status_id, rs.status_name, rs.sort_order
-- ORDER BY rs.sort_order;
