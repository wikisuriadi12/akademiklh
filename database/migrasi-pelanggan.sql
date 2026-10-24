-- Jenis pelanggan kasir (Umum / Pegawai: nama). Dijalankan otomatis oleh tools/install.php atau tempel di phpMyAdmin.
-- Bila kolom sudah ada, galat 'Duplicate column' aman diabaikan.
ALTER TABLE penjualan ADD COLUMN pelanggan VARCHAR(80) NULL;
