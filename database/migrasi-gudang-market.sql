-- Stok dipisah: Gudang (gudang) dan Market (unit utama kasir, kunci 'ikhwan' dipertahankan demi data lama).
-- Aman dijalankan ulang. Dijalankan otomatis oleh tools/install.php atau tempel di phpMyAdmin satu per satu.
ALTER TABLE stok MODIFY unit ENUM('ikhwan','akhwat','gudang') NOT NULL;
ALTER TABLE stok_mutasi MODIFY unit ENUM('ikhwan','akhwat','gudang') NOT NULL;
ALTER TABLE stok_mutasi MODIFY tipe ENUM('masuk','keluar','opname','jual','batal_jual','transfer_masuk','transfer_keluar') NOT NULL;
