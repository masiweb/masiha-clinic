-- Additive appointment metadata. Room remains independent from insurance.
CREATE TABLE IF NOT EXISTS visit_types (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL UNIQUE,
 active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS diagnoses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(255) NOT NULL UNIQUE,
 active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE physio_sessions ADD COLUMN IF NOT EXISTS visit_type_id BIGINT UNSIGNED NULL;
ALTER TABLE physio_sessions ADD COLUMN IF NOT EXISTS package_id BIGINT UNSIGNED NULL;
CREATE TABLE IF NOT EXISTS session_diagnoses (
 session_id BIGINT UNSIGNED NOT NULL,
 diagnosis_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(session_id,diagnosis_id),
 FOREIGN KEY(session_id) REFERENCES physio_sessions(id),
 FOREIGN KEY(diagnosis_id) REFERENCES diagnoses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS session_labels (
 session_id BIGINT UNSIGNED NOT NULL,
 label_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(session_id,label_id),
 FOREIGN KEY(session_id) REFERENCES physio_sessions(id),
 FOREIGN KEY(label_id) REFERENCES labels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO session_labels(session_id,label_id)
SELECT s.id,s.label_id FROM physio_sessions s JOIN labels l ON l.id=s.label_id;
