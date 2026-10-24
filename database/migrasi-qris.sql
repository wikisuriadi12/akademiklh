-- Migrasi QRIS kasir: izinkan metode pembayaran qris pada penjualan.
-- Aman dijalankan ulang. Dijalankan otomatis oleh tools/install.php atau tempel di phpMyAdmin.
ALTER TABLE penjualan MODIFY metode ENUM('tunai','dompet','qris') NOT NULL;
