-- Harga khusus pegawai/karyawan per barang (kosong = sama dengan harga jual umum).
-- Bila kolom sudah ada, galat 'Duplicate column' aman diabaikan.
ALTER TABLE barang ADD COLUMN harga_pegawai INT UNSIGNED NULL;
