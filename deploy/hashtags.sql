-- Real visit hashtags are separate from patient/appointment labels.
-- Do not backfill from labels or session_labels: those values have different semantics.
CREATE TABLE IF NOT EXISTS hashtags (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS session_hashtags (
 session_id BIGINT UNSIGNED NOT NULL,
 hashtag_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(session_id,hashtag_id),
 KEY idx_session_hashtags_tag(hashtag_id),
 CONSTRAINT fk_session_hashtags_session FOREIGN KEY(session_id) REFERENCES physio_sessions(id),
 CONSTRAINT fk_session_hashtags_tag FOREIGN KEY(hashtag_id) REFERENCES hashtags(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
