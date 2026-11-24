CREATE TABLE IF NOT EXISTS import_identity_queue (
 account_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 boghrat_patient_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 status ENUM('pending','processing','partial','complete','review') NOT NULL DEFAULT 'pending',
 linked_pid BIGINT NULL,
 linked_record_id BIGINT UNSIGNED NULL,
 last_seen_page INT UNSIGNED NULL,
 last_seen_row INT UNSIGNED NULL,
 first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 PRIMARY KEY (account_key,boghrat_patient_id),
 KEY identity_queue_status (account_key,status,first_seen_at,boghrat_patient_id)
) ENGINE=InnoDB;
