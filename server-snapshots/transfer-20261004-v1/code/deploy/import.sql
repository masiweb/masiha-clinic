CREATE TABLE IF NOT EXISTS import_connections (
 id INT PRIMARY KEY, login_url VARCHAR(255) NOT NULL DEFAULT 'https://account.boghrat.com/auth/login',
 source_url VARCHAR(512) NOT NULL DEFAULT 'https://app.boghrat.com/clinic/secretary/reception/',
 username VARCHAR(100) NOT NULL DEFAULT '', password_cipher TEXT NULL,
 hourly_limit INT NOT NULL DEFAULT 30,delay_seconds INT NOT NULL DEFAULT 8,total_limit INT NOT NULL DEFAULT 100,storage_mb INT NOT NULL DEFAULT 128,
 clinic_name VARCHAR(160) NOT NULL DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO import_connections(id) VALUES(1);
CREATE TABLE IF NOT EXISTS import_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_key CHAR(64) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'paused',page_no INT NOT NULL DEFAULT 1,row_no INT NOT NULL DEFAULT 0,
 hourly_limit INT NOT NULL,delay_seconds INT NOT NULL DEFAULT 8,total_limit INT NOT NULL,storage_mb INT NOT NULL,
 processed INT NOT NULL DEFAULT 0,saved INT NOT NULL DEFAULT 0,duplicates INT NOT NULL DEFAULT 0,
 window_start DATETIME NULL,window_count INT NOT NULL DEFAULT 0, not_before DATETIME NULL,
 message VARCHAR(500) NOT NULL DEFAULT '',created_by BIGINT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS import_records (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_key CHAR(64) NOT NULL,source_key CHAR(64) NOT NULL,
 run_id BIGINT UNSIGNED NOT NULL,source_page INT NOT NULL,source_row INT NOT NULL,
 display_name VARCHAR(255) NOT NULL DEFAULT '',national_id VARCHAR(20) NOT NULL DEFAULT '',mobile VARCHAR(30) NOT NULL DEFAULT '',
 confidence VARCHAR(20) NOT NULL DEFAULT 'review',payload MEDIUMTEXT NOT NULL,payload_hash CHAR(64) NOT NULL,bytes INT NOT NULL,
 completeness VARCHAR(30) NOT NULL DEFAULT 'partial',pid BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(account_key,source_key),INDEX(run_id),INDEX(national_id),INDEX(mobile)
);
CREATE TABLE IF NOT EXISTS import_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_id BIGINT UNSIGNED NOT NULL,code VARCHAR(40) NOT NULL,
 page_no INT NOT NULL DEFAULT 0,row_no INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(run_id)
);
CREATE TABLE IF NOT EXISTS import_quota(account_key CHAR(64) PRIMARY KEY,window_start DATETIME NOT NULL,attempts INT NOT NULL DEFAULT 0,next_at DATETIME NOT NULL);
CREATE TABLE IF NOT EXISTS import_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,record_id BIGINT UNSIGNED NOT NULL,payload MEDIUMTEXT NOT NULL,bytes INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(record_id));

ALTER TABLE import_connections ADD COLUMN IF NOT EXISTS delay_seconds INT NOT NULL DEFAULT 8 AFTER hourly_limit;
ALTER TABLE import_runs ADD COLUMN IF NOT EXISTS delay_seconds INT NOT NULL DEFAULT 8 AFTER hourly_limit;

ALTER TABLE patients ADD COLUMN IF NOT EXISTS phone_home VARCHAR(30) NOT NULL DEFAULT '' AFTER phone_cell;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS father_name VARCHAR(160) NOT NULL DEFAULT '' AFTER national_id;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS marital_status VARCHAR(30) NOT NULL DEFAULT '' AFTER sex;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS referral_source VARCHAR(160) NOT NULL DEFAULT '' AFTER marital_status;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS source_registered_date DATE NULL AFTER referral_source;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS clinic_registered_date DATE NULL AFTER source_registered_date;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS medical_conditions TEXT NOT NULL DEFAULT '' AFTER notes;
ALTER TABLE patients MODIFY COLUMN medical_conditions TEXT NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS import_patient_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 record_id BIGINT UNSIGNED NOT NULL,
 event_no INT NOT NULL DEFAULT 0,
 appointment_code VARCHAR(40) NOT NULL DEFAULT '',
 date_jalali VARCHAR(20) NOT NULL DEFAULT '',
 time_text VARCHAR(20) NOT NULL DEFAULT '',
 practitioner VARCHAR(255) NOT NULL DEFAULT '',
 reason VARCHAR(255) NOT NULL DEFAULT '',
 mode VARCHAR(120) NOT NULL DEFAULT '',
 status VARCHAR(160) NOT NULL DEFAULT '',
 notes TEXT NOT NULL DEFAULT '',
 services TEXT NOT NULL DEFAULT '',
 goods TEXT NOT NULL DEFAULT '',
 payload TEXT NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(record_id,event_no),INDEX(appointment_code),INDEX(record_id)
);
CREATE TABLE IF NOT EXISTS import_financial_lines (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 record_id BIGINT UNSIGNED NOT NULL,
 line_no INT NOT NULL DEFAULT 0,
 line_text VARCHAR(1000) NOT NULL,
 line_hash CHAR(64) NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(record_id,line_no),INDEX(record_id),INDEX(line_hash)
);

ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS debt_text VARCHAR(255) NOT NULL DEFAULT '' AFTER status;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS debt_toman BIGINT NULL AFTER debt_text;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS credit_toman BIGINT NULL AFTER debt_toman;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS service_cost_toman BIGINT NULL AFTER credit_toman;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS payments_toman BIGINT NULL AFTER service_cost_toman;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS settled_toman BIGINT NULL AFTER payments_toman;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS payment_methods TEXT NOT NULL DEFAULT '' AFTER settled_toman;

CREATE TABLE IF NOT EXISTS import_financial_summary (
 record_id BIGINT UNSIGNED PRIMARY KEY,
 service_revenue_toman BIGINT NULL,
 goods_revenue_toman BIGINT NULL,
 payments_toman BIGINT NULL,
 refunds_toman BIGINT NULL,
 discounts_toman BIGINT NULL,
 difference_toman BIGINT NULL,
 payload TEXT NOT NULL DEFAULT '',
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


ALTER TABLE patients ADD COLUMN IF NOT EXISTS occupation VARCHAR(160) NOT NULL DEFAULT '' AFTER referral_source;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS education VARCHAR(160) NOT NULL DEFAULT '' AFTER occupation;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS height_cm SMALLINT UNSIGNED NULL AFTER education;

CREATE TABLE IF NOT EXISTS import_patient_profiles (
 record_id BIGINT UNSIGNED PRIMARY KEY,
 full_name VARCHAR(255) NOT NULL DEFAULT '',
 mobile VARCHAR(30) NOT NULL DEFAULT '',
 phone_home VARCHAR(30) NOT NULL DEFAULT '',
 national_id VARCHAR(20) NOT NULL DEFAULT '',
 father_name VARCHAR(160) NOT NULL DEFAULT '',
 marital_status VARCHAR(30) NOT NULL DEFAULT '',
 birth_jalali VARCHAR(20) NOT NULL DEFAULT '',
 referral_source VARCHAR(160) NOT NULL DEFAULT '',
 source_registered_jalali VARCHAR(20) NOT NULL DEFAULT '',
 clinic_registered_jalali VARCHAR(20) NOT NULL DEFAULT '',
 address TEXT NOT NULL DEFAULT '',
 medical_conditions TEXT NOT NULL DEFAULT '',
 occupation VARCHAR(160) NOT NULL DEFAULT '',
 education VARCHAR(160) NOT NULL DEFAULT '',
 height_cm SMALLINT UNSIGNED NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX(mobile),INDEX(national_id)
);

ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS registered_at_jalali VARCHAR(40) NOT NULL DEFAULT '' AFTER time_text;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS registration_method VARCHAR(160) NOT NULL DEFAULT '' AFTER registered_at_jalali;

CREATE TABLE IF NOT EXISTS import_event_services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 record_id BIGINT UNSIGNED NOT NULL,
 event_no INT NOT NULL,
 item_no INT NOT NULL,
 service_name VARCHAR(255) NOT NULL,
 UNIQUE(record_id,event_no,item_no),
 INDEX(record_id),INDEX(service_name)
);

CREATE TABLE IF NOT EXISTS import_event_goods (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 record_id BIGINT UNSIGNED NOT NULL,
 event_no INT NOT NULL,
 item_no INT NOT NULL,
 goods_name VARCHAR(255) NOT NULL,
 quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
 UNIQUE(record_id,event_no,item_no),
 INDEX(record_id),INDEX(goods_name)
);

CREATE TABLE IF NOT EXISTS import_event_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 record_id BIGINT UNSIGNED NOT NULL,
 event_no INT NOT NULL,
 payment_no INT NOT NULL,
 amount_toman BIGINT NULL,
 method VARCHAR(160) NOT NULL DEFAULT '',
 UNIQUE(record_id,event_no,payment_no),
 INDEX(record_id),INDEX(method)
);

CREATE TABLE IF NOT EXISTS import_patient_form_fields (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 record_id BIGINT UNSIGNED NOT NULL,
 form_no INT NOT NULL DEFAULT 0,
 field_no INT NOT NULL DEFAULT 0,
 form_name VARCHAR(160) NOT NULL DEFAULT '',
 field_name VARCHAR(160) NOT NULL DEFAULT '',
 field_value TEXT NOT NULL DEFAULT '',
 UNIQUE(record_id,form_no,field_no),
 INDEX(record_id),INDEX(form_name),INDEX(field_name)
);

ALTER TABLE import_event_services ADD COLUMN IF NOT EXISTS amount_toman BIGINT NULL AFTER service_name;

CREATE TABLE IF NOT EXISTS import_financial_transactions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 record_id BIGINT UNSIGNED NOT NULL,
 event_no INT NOT NULL DEFAULT -1,
 tx_no INT NOT NULL DEFAULT 0,
 tx_type VARCHAR(40) NOT NULL,
 amount_toman BIGINT NULL,
 method VARCHAR(160) NOT NULL DEFAULT '',
 date_jalali VARCHAR(20) NOT NULL DEFAULT '',
 appointment_code VARCHAR(40) NOT NULL DEFAULT '',
 description VARCHAR(500) NOT NULL DEFAULT '',
 is_snapshot TINYINT NOT NULL DEFAULT 0,
 payload TEXT NOT NULL DEFAULT '',
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(record_id,event_no,tx_no),
 INDEX(record_id),INDEX(tx_type),INDEX(method),INDEX(appointment_code)
);

ALTER TABLE patients DROP INDEX IF EXISTS phone_cell;
CREATE INDEX IF NOT EXISTS idx_patients_phone_cell ON patients(phone_cell);

ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS event_date DATE NULL AFTER date_jalali;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS registered_at DATETIME NULL AFTER registered_at_jalali;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS charge_total_toman BIGINT NULL AFTER service_cost_toman;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS goods_cost_toman BIGINT NULL AFTER charge_total_toman;

ALTER TABLE import_financial_summary ADD COLUMN IF NOT EXISTS outstanding_toman BIGINT NULL AFTER difference_toman;
ALTER TABLE import_financial_summary ADD COLUMN IF NOT EXISTS credit_balance_toman BIGINT NULL AFTER outstanding_toman;

ALTER TABLE import_financial_transactions ADD COLUMN IF NOT EXISTS tx_date DATE NULL AFTER date_jalali;

ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS service_items_total_toman BIGINT NULL AFTER charge_total_toman;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS discounts_toman BIGINT NULL AFTER goods_cost_toman;
ALTER TABLE import_event_goods ADD COLUMN IF NOT EXISTS amount_toman BIGINT NULL AFTER quantity;

CREATE INDEX IF NOT EXISTS idx_import_patient_events_event_date ON import_patient_events(event_date);
CREATE INDEX IF NOT EXISTS idx_import_financial_transactions_tx_date ON import_financial_transactions(tx_date);

CREATE TABLE IF NOT EXISTS import_source_services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_name VARCHAR(255) NOT NULL,
 normalized_key CHAR(64) NOT NULL,
 first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(source_name),
 INDEX(normalized_key)
);

CREATE TABLE IF NOT EXISTS import_source_goods (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_name VARCHAR(255) NOT NULL,
 normalized_key CHAR(64) NOT NULL,
 first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(source_name),
 INDEX(normalized_key)
);

ALTER TABLE import_event_services ADD COLUMN IF NOT EXISTS source_service_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE import_event_services ADD INDEX IF NOT EXISTS idx_import_event_services_source_service(source_service_id);
ALTER TABLE import_event_goods ADD COLUMN IF NOT EXISTS source_goods_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE import_event_goods ADD INDEX IF NOT EXISTS idx_import_event_goods_source_goods(source_goods_id);

CREATE TABLE IF NOT EXISTS import_financial_allocations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transaction_id BIGINT UNSIGNED NOT NULL,
 record_id BIGINT UNSIGNED NOT NULL,
 event_no INT NOT NULL,
 appointment_code VARCHAR(40) NOT NULL DEFAULT '',
 amount_toman BIGINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(transaction_id,event_no),
 INDEX(transaction_id),INDEX(record_id,event_no),INDEX(appointment_code)
);


CREATE TABLE IF NOT EXISTS import_source_service_map (
 source_service_id BIGINT UNSIGNED PRIMARY KEY,
 service_id BIGINT UNSIGNED NOT NULL,
 mapping_mode VARCHAR(20) NOT NULL DEFAULT 'auto',
 mapped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(service_id)
);

CREATE TABLE IF NOT EXISTS inventory_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(255) NOT NULL,
 unit VARCHAR(40) NOT NULL DEFAULT 'عدد',
 sale_price_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
 opening_quantity DECIMAL(14,3) NULL,
 opening_as_of DATE NULL,
 active TINYINT NOT NULL DEFAULT 1,
 source_good_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(source_good_id),
 UNIQUE(name)
);

CREATE TABLE IF NOT EXISTS inventory_movements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 item_id BIGINT UNSIGNED NOT NULL,
 pid BIGINT NULL,
 source_record_id BIGINT UNSIGNED NULL,
 event_no INT NULL,
 item_no INT NULL,
 appointment_code VARCHAR(40) NOT NULL DEFAULT '',
 movement_type VARCHAR(40) NOT NULL,
 quantity_delta DECIMAL(14,3) NOT NULL,
 unit_price_toman BIGINT NULL,
 total_toman BIGINT NULL,
 occurred_on DATE NULL,
 affects_stock TINYINT NOT NULL DEFAULT 1,
 note VARCHAR(500) NOT NULL DEFAULT '',
 created_by BIGINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(source_record_id,event_no,item_no,movement_type),
 INDEX(item_id),INDEX(pid),INDEX(occurred_on),INDEX(movement_type)
);

CREATE TABLE IF NOT EXISTS patient_account_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 pid BIGINT NOT NULL,
 entry_date DATE NOT NULL,
 entry_type VARCHAR(40) NOT NULL,
 debit_toman BIGINT NOT NULL DEFAULT 0,
 credit_toman BIGINT NOT NULL DEFAULT 0,
 reference VARCHAR(255) NOT NULL DEFAULT '',
 source_system VARCHAR(40) NOT NULL DEFAULT 'masiha',
 source_record_id BIGINT UNSIGNED NULL,
 entry_key VARCHAR(120) NULL,
 created_by BIGINT NOT NULL DEFAULT 0,
 voided TINYINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(entry_key),
 INDEX(pid),INDEX(entry_date),INDEX(entry_type),INDEX(source_system)
);

ALTER TABLE patient_account_ledger MODIFY COLUMN debit_toman BIGINT NOT NULL DEFAULT 0, MODIFY COLUMN credit_toman BIGINT NOT NULL DEFAULT 0;


CREATE TABLE IF NOT EXISTS import_source_practitioners (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_name VARCHAR(255) NOT NULL,
 normalized_key CHAR(64) NOT NULL,
 first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(source_name),
 INDEX(normalized_key)
);

CREATE TABLE IF NOT EXISTS import_source_practitioner_map (
 source_practitioner_id BIGINT UNSIGNED PRIMARY KEY,
 staff_id BIGINT UNSIGNED NOT NULL,
 mapping_mode VARCHAR(20) NOT NULL DEFAULT 'auto',
 mapped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(staff_id)
);

ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS source_practitioner_id BIGINT UNSIGNED NULL AFTER practitioner;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS therapist_id BIGINT UNSIGNED NULL AFTER source_practitioner_id;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS status_code VARCHAR(40) NOT NULL DEFAULT 'unknown' AFTER status;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS mode_code VARCHAR(40) NOT NULL DEFAULT 'unknown' AFTER mode;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS registration_code VARCHAR(40) NOT NULL DEFAULT 'unknown' AFTER registration_method;
ALTER TABLE import_patient_events ADD COLUMN IF NOT EXISTS reason_code VARCHAR(40) NOT NULL DEFAULT 'other' AFTER reason;
ALTER TABLE import_patient_events ADD INDEX IF NOT EXISTS idx_import_patient_events_therapist(therapist_id);
ALTER TABLE import_patient_events ADD INDEX IF NOT EXISTS idx_import_patient_events_status_code(status_code);
ALTER TABLE import_patient_events ADD INDEX IF NOT EXISTS idx_import_patient_events_reason_code(reason_code);

ALTER TABLE import_event_services ADD COLUMN IF NOT EXISTS service_id BIGINT UNSIGNED NULL AFTER source_service_id;
ALTER TABLE import_event_services ADD INDEX IF NOT EXISTS idx_import_event_services_service(service_id);

ALTER TABLE import_event_goods ADD COLUMN IF NOT EXISTS inventory_item_id BIGINT UNSIGNED NULL AFTER source_goods_id;
ALTER TABLE import_event_goods ADD INDEX IF NOT EXISTS idx_import_event_goods_inventory_item(inventory_item_id);


ALTER TABLE import_patient_form_fields ADD COLUMN IF NOT EXISTS field_type VARCHAR(30) NOT NULL DEFAULT 'text' AFTER field_value;
ALTER TABLE import_patient_form_fields ADD COLUMN IF NOT EXISTS is_meta TINYINT NOT NULL DEFAULT 0 AFTER field_type;
ALTER TABLE import_patient_form_fields ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER is_meta;

CREATE TABLE IF NOT EXISTS clinic_form_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(160) NOT NULL,
 kind VARCHAR(20) NOT NULL DEFAULT 'general',
 source_system VARCHAR(40) NOT NULL DEFAULT 'masiha',
 source_name VARCHAR(160) NOT NULL DEFAULT '',
 active TINYINT NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(source_system,source_name)
);

CREATE TABLE IF NOT EXISTS clinic_form_template_fields (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 template_id BIGINT UNSIGNED NOT NULL,
 field_key CHAR(64) NOT NULL,
 label VARCHAR(160) NOT NULL,
 field_type VARCHAR(30) NOT NULL DEFAULT 'text',
 sort_order INT NOT NULL DEFAULT 0,
 required TINYINT NOT NULL DEFAULT 0,
 source_field_name VARCHAR(160) NOT NULL DEFAULT '',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(template_id,field_key),
 INDEX(template_id),INDEX(sort_order)
);

CREATE TABLE IF NOT EXISTS clinic_form_submissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 template_id BIGINT UNSIGNED NOT NULL,
 pid BIGINT NOT NULL,
 source_system VARCHAR(40) NOT NULL DEFAULT 'masiha',
 source_record_id BIGINT UNSIGNED NULL,
 source_submitted_jalali VARCHAR(20) NOT NULL DEFAULT '',
 source_submitted_date DATE NULL,
 created_by BIGINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(source_system,source_record_id,template_id),
 INDEX(pid),INDEX(template_id),INDEX(source_submitted_date)
);

CREATE TABLE IF NOT EXISTS clinic_form_submission_values (
 submission_id BIGINT UNSIGNED NOT NULL,
 field_id BIGINT UNSIGNED NOT NULL,
 value_text TEXT NOT NULL DEFAULT '',
 PRIMARY KEY(submission_id,field_id),
 INDEX(field_id)
);


CREATE TABLE IF NOT EXISTS service_tariffs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 service_id BIGINT UNSIGNED NOT NULL,
 tariff_type VARCHAR(20) NOT NULL DEFAULT 'normal',
 title VARCHAR(160) NOT NULL DEFAULT '',
 contract_name VARCHAR(160) NOT NULL DEFAULT '',
 price_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 active TINYINT NOT NULL DEFAULT 1,
 source_system VARCHAR(40) NOT NULL DEFAULT 'masiha',
 created_by BIGINT NOT NULL DEFAULT 0,
 deleted TINYINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX(service_id),INDEX(tariff_type),INDEX(effective_from),INDEX(effective_to),INDEX(active,deleted)
);

ALTER TABLE physio_sessions ADD COLUMN IF NOT EXISTS tariff_id BIGINT UNSIGNED NULL AFTER service_id;
ALTER TABLE physio_sessions ADD COLUMN IF NOT EXISTS tariff_title_snapshot VARCHAR(160) NOT NULL DEFAULT '' AFTER tariff_id;
ALTER TABLE physio_sessions ADD INDEX IF NOT EXISTS idx_physio_sessions_tariff(tariff_id);
