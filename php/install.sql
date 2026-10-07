-- MESSI — MySQL/MariaDB schema.
-- Import once from cPanel: phpMyAdmin -> pilih database -> Import -> pilih file ini.
--
-- All timestamps are stored in UTC as DATETIME. The Jakarta calendar day a report
-- belongs to is kept separately in `cycles.day`, because that day — not the instant —
-- is what makes a report unique (see docs/14-followup-engine.md).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Tim alias divisi: OASYS, HR, Finance — namanya terserah perusahaannya. Satu orang
-- satu tim. Pertanyaan, ambang, jam dan space chat-nya milik tim, bukan milik seluruh
-- perusahaan: HR tidak menghitung hal yang sama dengan sales.
CREATE TABLE IF NOT EXISTS teams (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(60) NOT NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teams_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(190) NOT NULL,
  name            VARCHAR(120) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('player','leader','admin','owner') NOT NULL DEFAULT 'player',
  team_id         INT UNSIGNED DEFAULT NULL,
  joined_on       DATE NOT NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  -- Undangan: akun ada sejak diundang, tapi belum bisa dipakai sampai orangnya
  -- membuat passwordnya sendiri. password_hash kosong sampai saat itu.
  invited_at      DATETIME DEFAULT NULL,
  accepted_at     DATETIME DEFAULT NULL,
  -- "Saya lupa passwordnya." Selama pemasangan ini belum bisa mengirim email, satu-satunya
  -- jalan pulang adalah admin yang mengeluarkan link — dan dia harus bisa melihat bahwa
  -- ada yang menunggu. Dikosongkan lagi begitu linknya dikeluarkan.
  reset_asked_at  DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_active (active, role),
  KEY ix_users_team (team_id),
  CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
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
  -- Undangan dan link masuk sama-sama sekali pakai dan berumur pendek; yang berbeda
  -- cuma apa yang terjadi setelah ditebus.
  kind            ENUM('login','invite') NOT NULL DEFAULT 'login',
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
  -- Tim yang berlaku saat laporan ini dikirim. Dicatat, bukan dibaca dari orangnya:
  -- kalau tidak, memindahkan seseorang ke tim lain akan menulis ulang rekap bulan lalu.
  team_id         INT UNSIGNED DEFAULT NULL,
  status          ENUM('pending','submitted','late','missed') NOT NULL DEFAULT 'pending',
  answers         LONGTEXT DEFAULT NULL CHECK (answers IS NULL OR JSON_VALID(answers)),
  submitted_at    DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cycle_day (user_id, day),
  KEY ix_cycles_day (day, status),
  CONSTRAINT fk_cycles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cycles_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
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

-- Setelan per modul, satu baris per modul, isinya JSON. Sebuah tabel dan bukan berkas
-- config karena yang mengubahnya adalah admin lewat halaman, bukan orang lewat FTP —
-- dan karena halaman setelannya harus bisa salah tanpa membuat situsnya mati.
CREATE TABLE IF NOT EXISTS settings (
  name            VARCHAR(40) NOT NULL,
  team_id         INT UNSIGNED NOT NULL,
  value           LONGTEXT NOT NULL CHECK (JSON_VALID(value)),
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by      INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (name, team_id),
  KEY ix_settings_team (team_id),
  CONSTRAINT fk_settings_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Percobaan masuk yang gagal. Tanpa ini, password sepuluh karakter cuma menunda
-- penebak yang sabar selama beberapa menit.
CREATE TABLE IF NOT EXISTS login_attempts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  at              DATETIME NOT NULL,
  email           VARCHAR(190) NOT NULL,
  ip              VARCHAR(45) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_attempts_email (email, at),
  KEY ix_attempts_ip (ip, at)
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
