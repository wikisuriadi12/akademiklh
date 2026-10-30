-- OPSIONAL. Hanya bila tabel santri berisi data yang ingin dibuang sebelum impor ulang.
-- Menghapus SEMUA santri beserta tagihan, pembayaran, hafalan, kehadiran, izin, VA, dan dompet.
-- Tidak menyentuh akun pengguna, pengaturan, tarif, jenis biaya, dan data barang gudang.
-- Ekspor database dulu (phpMyAdmin, Export).
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE va_item; TRUNCATE va_billing;
TRUNCATE pembayaran; TRUNCATE tagihan;
TRUNCATE hafalan; TRUNCATE kehadiran; TRUNCATE izin;
TRUNCATE dompet_mutasi; TRUNCATE dompet; TRUNCATE santri;
SET FOREIGN_KEY_CHECKS = 1;
