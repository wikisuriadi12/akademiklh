-- Transaksi QRIS dinamis (API bank / payment gateway). Aman dijalankan ulang.
CREATE TABLE IF NOT EXISTS qris_trx (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(40) NOT NULL UNIQUE,
  provider VARCHAR(20) NOT NULL,
  jumlah INT UNSIGNED NOT NULL,
  status ENUM('menunggu','lunas','kedaluwarsa','gagal') NOT NULL DEFAULT 'menunggu',
  qr_url VARCHAR(500) NULL,
  penjualan_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  KEY idx_qris_trx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
