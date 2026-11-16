-- UPDATE DATABASE (untuk yang sudah import database.sql versi lama)
-- Menambah: (1) tabel "users" untuk login, (2) kolom "metode" Saldo/Cash di transaksi.
-- Catatan: tabel ini sebenarnya dibuat otomatis saat halaman login dibuka
-- pertama kali, jadi file ini hanya cadangan. Aman dijalankan berulang kali.
-- Cara: phpMyAdmin > klik tabungan_db > tab "SQL" > tempel isi file ini > Kirim.

USE tabungan_db;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kolom "metode" (Saldo / Cash) pada transaksi. Data lama otomatis dianggap 'saldo'.
-- (Juga ditambahkan otomatis oleh aplikasi. Perintah ini untuk MariaDB bawaan XAMPP.)
ALTER TABLE transaksi
  ADD COLUMN IF NOT EXISTS metode ENUM('saldo','cash') NOT NULL DEFAULT 'saldo' AFTER jenis;

-- Tabel pindah (pemindahan dana antara saldo dan cash dalam satu target)
CREATE TABLE IF NOT EXISTS pindah (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_id  INT UNSIGNED NOT NULL,
  tanggal    DATE NOT NULL,
  dari       ENUM('saldo','cash') NOT NULL,
  nominal    BIGINT UNSIGNED NOT NULL,
  keterangan VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pindah_target (target_id, tanggal),
  CONSTRAINT fk_pindah_target
    FOREIGN KEY (target_id) REFERENCES target (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lupa password? Hapus akun lama, lalu buka halaman login untuk membuat akun baru:
--   DELETE FROM users;
