-- Foto bukti notifikasi QRIS per transaksi. Bila kolom sudah ada, galat 'Duplicate column' aman diabaikan.
ALTER TABLE penjualan ADD COLUMN bukti_qris VARCHAR(60) NULL;
