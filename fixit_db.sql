-- =============================================
-- FixIt Database Schema v2
-- Fitur baru: foto laporan, avatar user, nomor urut konsisten
-- =============================================

CREATE DATABASE IF NOT EXISTS fixit_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fixit_db;

-- ─── USERS ────────────────────────────────────────────────
CREATE TABLE users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100)  NOT NULL,
  email       VARCHAR(150)  NOT NULL UNIQUE,
  password    VARCHAR(255)  NOT NULL,
  role        ENUM('user','admin') DEFAULT 'user',
  avatar      VARCHAR(255)  DEFAULT NULL,
  is_active   TINYINT(1)    DEFAULT 1,
  created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── CATEGORIES ───────────────────────────────────────────
CREATE TABLE categories (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(100) NOT NULL,
  icon  VARCHAR(10)  DEFAULT '📌'
);

INSERT INTO categories (name, icon) VALUES
  ('Jalan & Infrastruktur',  '🛣️'),
  ('Penerangan Jalan',       '💡'),
  ('Sampah & Sanitasi',      '🗑️'),
  ('Keselamatan Publik',     '🚨'),
  ('Taman & Area Hijau',     '🌳'),
  ('Air & Drainase',         '💧'),
  ('Kebisingan',             '🔊'),
  ('Lainnya',                '📌');

-- ─── REPORTS ──────────────────────────────────────────────
CREATE TABLE reports (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nomor_urut   INT           NOT NULL DEFAULT 0,
  user_id      INT           NOT NULL,
  category_id  INT           NOT NULL,
  title        VARCHAR(200)  NOT NULL,
  description  TEXT          NOT NULL,
  location     VARCHAR(300)  NOT NULL,
  latitude     DECIMAL(10,8) DEFAULT NULL,
  longitude    DECIMAL(11,8) DEFAULT NULL,
  photo        VARCHAR(255)  DEFAULT NULL,
  status       ENUM('pending','in_progress','resolved','rejected') DEFAULT 'pending',
  priority     ENUM('low','medium','high') DEFAULT 'medium',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

-- Trigger: set nomor_urut otomatis saat laporan baru dibuat
DELIMITER $$
CREATE TRIGGER set_nomor_urut BEFORE INSERT ON reports
FOR EACH ROW
BEGIN
  SET NEW.nomor_urut = (SELECT COALESCE(MAX(nomor_urut), 0) + 1 FROM reports);
END$$
DELIMITER ;

-- Procedure: reorder nomor_urut setelah hapus laporan
DELIMITER $$
CREATE PROCEDURE reorder_nomor_urut()
BEGIN
  SET @row = 0;
  UPDATE reports SET nomor_urut = (@row := @row + 1) ORDER BY id ASC;
END$$
DELIMITER ;

-- ─── REPORT COMMENTS ──────────────────────────────────────
CREATE TABLE report_comments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  report_id  INT  NOT NULL,
  user_id    INT  NOT NULL,
  comment    TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
);

-- ─── SESSIONS ─────────────────────────────────────────────
CREATE TABLE sessions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  token      VARCHAR(64)  NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ─── DEMO DATA ─────────────────────────────────────────────
-- Password semua akun: password123
INSERT INTO users (name, email, password, role) VALUES
  ('Admin FixIt',  'admin@fixit.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
  ('Budi Santoso', 'budi@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user');

INSERT INTO reports (user_id, category_id, title, description, location, status, priority) VALUES
  (2, 1, 'Jalan berlubang di depan pasar',  'Lubang cukup dalam, berbahaya untuk pengendara motor', 'Jl. Sudirman No.12, Medan', 'in_progress', 'high'),
  (2, 2, 'Lampu jalan mati sudah 3 hari',   'Area gelap di malam hari, rawan kejahatan',            'Jl. Gatot Subroto, Medan',  'pending',     'medium'),
  (2, 3, 'Tumpukan sampah tidak diangkut',  'Sampah menumpuk sejak seminggu lalu',                  'Jl. Kebon Jeruk, Medan',    'resolved',    'low');