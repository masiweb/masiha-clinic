-- Additive per-visit treatment programs and multi-therapist patient relationships.
CREATE TABLE IF NOT EXISTS treatment_programs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL UNIQUE,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS session_treatment_programs(
 session_id BIGINT UNSIGNED NOT NULL,
 program_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(session_id,program_id),
 CONSTRAINT fk_stp_session FOREIGN KEY(session_id) REFERENCES physio_sessions(id) ON DELETE CASCADE,
 CONSTRAINT fk_stp_program FOREIGN KEY(program_id) REFERENCES treatment_programs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS session_treatment_plans(
 session_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 plan_text TEXT NOT NULL,
 updated_by BIGINT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_stplan_session FOREIGN KEY(session_id) REFERENCES physio_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS patient_therapists(
 pid BIGINT NOT NULL,
 therapist_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(pid,therapist_id,service_id),
 INDEX idx_patient_therapists_tid(therapist_id),
 CONSTRAINT fk_patient_therapists_patient FOREIGN KEY(pid) REFERENCES patients(pid) ON DELETE CASCADE,
 CONSTRAINT fk_patient_therapists_staff FOREIGN KEY(therapist_id) REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index existing therapist relationships; do not rewrite the primary provider or appointments.
INSERT IGNORE INTO patient_therapists(pid,therapist_id,service_id)
SELECT DISTINCT ep.pid,s.therapist_id,COALESCE(s.service_id,0)
FROM physio_sessions s JOIN physio_episodes ep ON ep.id=s.episode_id
WHERE s.therapist_id IS NOT NULL;
-- Generic selectable program categories; individual clinical plans remain therapist-defined.
INSERT INTO treatment_programs(name,active) VALUES('ورزش درمانی',1)
 ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO treatment_programs(name,active) VALUES('تمرین پلاس',1)
 ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO treatment_programs(name,active) VALUES('فیزیوتراپی و تمرین',1)
 ON DUPLICATE KEY UPDATE name=VALUES(name);
