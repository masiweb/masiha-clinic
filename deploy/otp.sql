CREATE TABLE IF NOT EXISTS masiha_otp_limits (
 bucket CHAR(64) PRIMARY KEY, window_start BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS masiha_otp_challenges (
 id CHAR(64) PRIMARY KEY, pid BIGINT NOT NULL DEFAULT 0, mobile_hash CHAR(64) NOT NULL,
 code_hash CHAR(64) NOT NULL, expires_at BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0,
 consumed TINYINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, INDEX(expires_at)
) ENGINE=InnoDB;
