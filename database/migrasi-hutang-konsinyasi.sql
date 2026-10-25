-- Hutang pembelian per supplier dan barang konsinyasi (titipan). Aman dijalankan ulang;
-- galat 'Duplicate column' pada ALTER boleh diabaikan.
CREATE TABLE IF NOT EXISTS hutang (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pemasok VARCHAR(100) NOT NULL,
  faktur VARCHAR(60) NOT NULL DEFAULT '',
  tanggal DATE NOT NULL,
  jatuh_tempo DATE NULL,
  total INT UNSIGNED NOT NULL,
  dibayar INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('belum','sebagian','lunas') NOT NULL DEFAULT 'belum',
  catatan VARCHAR(255) NOT NULL DEFAULT '',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_hutang_pemasok (pemasok),
  KEY idx_hutang_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hutang_bayar (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  jenis ENUM('hutang','konsinyasi') NOT NULL,
  hutang_id INT UNSIGNED NULL,
  pemasok VARCHAR(100) NOT NULL,
  jumlah INT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  catatan VARCHAR(255) NOT NULL DEFAULT '',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_hbayar_pemasok (pemasok, jenis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE barang ADD COLUMN konsinyasi_pemasok VARCHAR(100) NULL;
ALTER TABLE penjualan_item ADD COLUMN konsinyasi VARCHAR(100) NULL;
