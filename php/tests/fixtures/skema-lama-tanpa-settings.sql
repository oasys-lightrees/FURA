-- MESSI — MySQL/MariaDB schema.
-- Import once from cPanel: phpMyAdmin -> pilih database -> Import -> pilih file ini.
--
-- All timestamps are stored in UTC as DATETIME. The Jakarta calendar day a report
-- belongs to is kept separately in `cycles.day`, because that day — not the instant —
-- is what makes a report unique (see docs/14-followup-engine.md).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(190) NOT NULL,
  name            VARCHAR(120) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('player','leader','admin') NOT NULL DEFAULT 'player',
  joined_on       DATE NOT NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_active (active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token           CHAR(64) NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  expires_at      DATETIME NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token),
  KEY ix_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time login links. Google Chat webhooks post to a shared space, so these are
-- never posted there — anyone in the space could spend one. An admin hands them out
-- privately instead, for somebody locked out.
CREATE TABLE IF NOT EXISTS login_tokens (
  token           CHAR(64) NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  expires_at      DATETIME NOT NULL,
  used_at         DATETIME DEFAULT NULL,
  PRIMARY KEY (token),
  KEY ix_login_tokens_user (user_id),
  CONSTRAINT fk_login_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One report per person per Jakarta working day. The unique key is the whole
-- idempotency story: the cron may run every hour and still create exactly one.
CREATE TABLE IF NOT EXISTS cycles (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  day             DATE NOT NULL,
  status          ENUM('pending','submitted','late','missed') NOT NULL DEFAULT 'pending',
  answers         LONGTEXT DEFAULT NULL CHECK (answers IS NULL OR JSON_VALID(answers)),
  submitted_at    DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cycle_day (user_id, day),
  KEY ix_cycles_day (day, status),
  CONSTRAINT fk_cycles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commitments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  cycle_id        INT UNSIGNED DEFAULT NULL,
  action_text     TEXT NOT NULL,
  due_date        DATE NOT NULL,
  status          ENUM('open','kept','broken','cancelled') NOT NULL DEFAULT 'open',
  resolved_at     DATETIME DEFAULT NULL,
  resolved_cycle_id INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_commitments_open (status, due_date),
  KEY ix_commitments_user (user_id, status),
  CONSTRAINT fk_commitments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_commitments_cycle FOREIGN KEY (cycle_id) REFERENCES cycles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only record of what the cron and the bot did. Without it, "kenapa saya tidak
-- dapat pesan?" has no answer.
CREATE TABLE IF NOT EXISTS job_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ran_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind            VARCHAR(40) NOT NULL,
  detail          TEXT,
  PRIMARY KEY (id),
  KEY ix_job_log_time (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
