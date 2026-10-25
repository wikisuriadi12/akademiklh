-- Penjualan hutang/bon di kasir (piutang pelanggan). Aman dijalankan ulang.
ALTER TABLE penjualan MODIFY metode ENUM('tunai','dompet','qris','hutang') NOT NULL;
CREATE TABLE IF NOT EXISTS piutang (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  penjualan_id INT UNSIGNED NOT NULL UNIQUE,
  santri_id INT UNSIGNED NULL,
  nama VARCHAR(100) NOT NULL,
  total INT UNSIGNED NOT NULL,
  dibayar INT UNSIGNED NOT NULL DEFAULT 0,
  jatuh_tempo DATE NULL,
  status ENUM('belum','sebagian','lunas','batal') NOT NULL DEFAULT 'belum',
  created_at DATETIME NOT NULL,
  KEY idx_piutang_nama (nama),
  KEY idx_piutang_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS piutang_bayar (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  piutang_id INT UNSIGNED NOT NULL,
  jumlah INT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  catatan VARCHAR(255) NOT NULL DEFAULT '',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_pbayar_piutang (piutang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
