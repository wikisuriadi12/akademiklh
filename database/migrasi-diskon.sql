-- Diskon pada penjualan kasir. Dijalankan otomatis oleh tools/install.php atau tempel di phpMyAdmin.
-- Bila kolom sudah ada, galat 'Duplicate column' aman diabaikan.
ALTER TABLE penjualan ADD COLUMN diskon INT UNSIGNED NOT NULL DEFAULT 0;
