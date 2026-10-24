-- Sistem SPP & Pendataan Santri - Yayasan Luqmanul Hakim (v2)
-- MySQL / MariaDB (utf8mb4). Aman dijalankan ulang: hanya CREATE TABLE IF NOT EXISTS / INSERT IGNORE.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nama VARCHAR(100) NOT NULL,
  role ENUM('admin','tu','bendahara','pimpinan','kasir','gudang','ustadz','pembina') NOT NULL,
  unit_akses ENUM('semua','ikhwan','akhwat') NOT NULL DEFAULT 'semua',
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  session_version INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS santri (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nis VARCHAR(20) NOT NULL UNIQUE,
  nama VARCHAR(120) NOT NULL,
  jenis_kelamin ENUM('L','P') NOT NULL,
  unit ENUM('ikhwan','akhwat') NOT NULL,
  jenjang ENUM('TKQ','Ula','Wustha','Ulya') NOT NULL,
  kelas VARCHAR(30) NULL,
  tempat_lahir VARCHAR(80) NULL,
  tanggal_lahir DATE NULL,
  alamat TEXT NULL,
  asal_sekolah VARCHAR(120) NULL,
  nama_wali VARCHAR(120) NOT NULL,
  hubungan_wali VARCHAR(30) NULL,
  hp_wali VARCHAR(30) NULL,
  tahun_masuk SMALLINT NOT NULL,
  status ENUM('calon','aktif','nonaktif','lulus','pindah','ditolak') NOT NULL DEFAULT 'aktif',
  sumber ENUM('admin','online','impor') NOT NULL DEFAULT 'admin',
  potongan_spp INT UNSIGNED NOT NULL DEFAULT 0,
  catatan VARCHAR(255) NULL,
  -- Kolom sesuai Excel DATA SISWA (identitas dan orang tua). Hanya admin dan TU yang boleh melihat.
  nisn VARCHAR(20) NULL,
  nis_internal VARCHAR(20) NULL,
  nik VARCHAR(30) NULL,
  no_kk VARCHAR(20) NULL,
  va_lama VARCHAR(30) NULL,
  tgl_masuk DATE NULL,
  npsn_asal VARCHAR(20) NULL,
  alamat_asal VARCHAR(255) NULL,
  ayah_nama VARCHAR(120) NULL,
  ayah_nik VARCHAR(30) NULL,
  ayah_pendidikan VARCHAR(40) NULL,
  ayah_pekerjaan VARCHAR(80) NULL,
  ayah_penghasilan VARCHAR(60) NULL,
  ibu_nama VARCHAR(120) NULL,
  ibu_nik VARCHAR(30) NULL,
  ibu_pendidikan VARCHAR(40) NULL,
  ibu_pekerjaan VARCHAR(80) NULL,
  ibu_penghasilan VARCHAR(60) NULL,
  hp_ayah VARCHAR(30) NULL,
  hp_ibu VARCHAR(30) NULL,
  jumlah_saudara SMALLINT UNSIGNED NULL,
  anak_ke SMALLINT UNSIGNED NULL,
  tahun_ajaran VARCHAR(9) NULL,
  usia_masuk VARCHAR(20) NULL,
  keterangan VARCHAR(255) NULL,
  foto VARCHAR(40) NULL,
  status_masuk VARCHAR(10) NULL,
  surat_rekomendasi VARCHAR(40) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_santri_nisn (nisn),
  KEY idx_santri_nik (nik),
  KEY idx_santri_unit_jenjang (unit, jenjang),
  KEY idx_santri_status (status),
  KEY idx_santri_nama (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jenis_biaya (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kode VARCHAR(20) NOT NULL UNIQUE,
  nama VARCHAR(60) NOT NULL,
  tipe ENUM('bulanan','sekali') NOT NULL DEFAULT 'sekali',
  aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO jenis_biaya (kode, nama, tipe, aktif) VALUES
  ('SPP', 'SPP Bulanan', 'bulanan', 1),
  ('PENDAFTARAN', 'Uang Pendaftaran', 'sekali', 1),
  ('SERAGAM', 'Seragam', 'sekali', 1),
  ('KITAB', 'Kitab & Buku', 'sekali', 1),
  ('KEGIATAN', 'Kegiatan', 'sekali', 1);

CREATE TABLE IF NOT EXISTS tarif_spp (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tahun_ajaran VARCHAR(9) NOT NULL,
  jenjang ENUM('TKQ','Ula','Wustha','Ulya') NOT NULL,
  unit ENUM('ikhwan','akhwat') NOT NULL,
  nominal INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_tarif (tahun_ajaran, jenjang, unit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tagihan (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  santri_id INT UNSIGNED NOT NULL,
  jenis_id INT UNSIGNED NOT NULL,
  periode CHAR(7) NOT NULL,
  keterangan VARCHAR(100) NOT NULL DEFAULT '',
  nominal INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_tagihan (santri_id, jenis_id, periode, keterangan),
  KEY idx_tagihan_periode (periode),
  CONSTRAINT fk_tagihan_santri FOREIGN KEY (santri_id) REFERENCES santri(id),
  CONSTRAINT fk_tagihan_jenis FOREIGN KEY (jenis_id) REFERENCES jenis_biaya(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pembayaran (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no_kuitansi VARCHAR(30) NOT NULL UNIQUE,
  tagihan_id INT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  jumlah INT UNSIGNED NOT NULL,
  metode ENUM('tunai','transfer','bni_va') NOT NULL DEFAULT 'tunai',
  keterangan VARCHAR(255) NULL,
  dibuat_oleh INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  dibatalkan TINYINT(1) NOT NULL DEFAULT 0,
  alasan_batal VARCHAR(255) NULL,
  dibatalkan_oleh INT UNSIGNED NULL,
  dibatalkan_at DATETIME NULL,
  KEY idx_bayar_tanggal (tanggal),
  KEY idx_bayar_tagihan (tagihan_id),
  CONSTRAINT fk_bayar_tagihan FOREIGN KEY (tagihan_id) REFERENCES tagihan(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limit (
  k CHAR(40) NOT NULL PRIMARY KEY,
  n INT NOT NULL,
  started INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  aksi VARCHAR(40) NOT NULL,
  objek VARCHAR(40) NOT NULL,
  objek_id INT UNSIGNED NULL,
  detail TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit_objek (objek, objek_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== V3: POS, Gudang, Dompet santri =====
-- Untuk database V1/V2 yang sudah ada: tambah peran baru.
ALTER TABLE users MODIFY role ENUM('admin','tu','bendahara','pimpinan','kasir','gudang','ustadz','pembina') NOT NULL;

CREATE TABLE IF NOT EXISTS barang (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kode VARCHAR(30) NOT NULL UNIQUE,
  nama VARCHAR(120) NOT NULL,
  kategori VARCHAR(40) NOT NULL DEFAULT '',
  satuan VARCHAR(20) NOT NULL DEFAULT 'pcs',
  harga_beli INT UNSIGNED NOT NULL DEFAULT 0,
  harga_jual INT UNSIGNED NOT NULL DEFAULT 0,
  stok_min INT UNSIGNED NOT NULL DEFAULT 0,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stok dipisah per unit (koperasi Ikhwan dan Akhwat).
CREATE TABLE IF NOT EXISTS stok (
  barang_id INT UNSIGNED NOT NULL,
  unit ENUM('ikhwan','akhwat') NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  PRIMARY KEY (barang_id, unit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stok_mutasi (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  barang_id INT UNSIGNED NOT NULL,
  unit ENUM('ikhwan','akhwat') NOT NULL,
  tipe ENUM('masuk','keluar','opname','jual','batal_jual') NOT NULL,
  qty INT NOT NULL,
  stok_setelah INT NOT NULL,
  harga_beli INT UNSIGNED NULL,
  pemasok VARCHAR(100) NOT NULL DEFAULT '',
  ref_id INT UNSIGNED NULL,
  catatan VARCHAR(255) NOT NULL DEFAULT '',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_mutasi_barang (barang_id, unit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dompet (
  santri_id INT UNSIGNED NOT NULL PRIMARY KEY,
  saldo INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dompet_mutasi (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  santri_id INT UNSIGNED NOT NULL,
  tipe ENUM('topup','belanja','batal','koreksi') NOT NULL,
  jumlah INT NOT NULL,
  saldo_setelah INT NOT NULL,
  ref_id INT UNSIGNED NULL,
  catatan VARCHAR(255) NOT NULL DEFAULT '',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_dm_santri (santri_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS penjualan (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no_struk VARCHAR(30) NOT NULL DEFAULT '',
  tanggal DATE NOT NULL,
  unit ENUM('ikhwan','akhwat') NOT NULL,
  santri_id INT UNSIGNED NULL,
  total INT UNSIGNED NOT NULL,
  metode ENUM('tunai','dompet') NOT NULL,
  dibayar INT UNSIGNED NOT NULL DEFAULT 0,
  dibatalkan TINYINT(1) NOT NULL DEFAULT 0,
  alasan_batal VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_pj_tgl (tanggal, unit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS penjualan_item (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  penjualan_id INT UNSIGNED NOT NULL,
  barang_id INT UNSIGNED NOT NULL,
  nama VARCHAR(120) NOT NULL,
  qty INT UNSIGNED NOT NULL,
  harga INT UNSIGNED NOT NULL,
  harga_beli INT UNSIGNED NOT NULL DEFAULT 0,
  subtotal INT UNSIGNED NOT NULL,
  KEY idx_pji (penjualan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== V4: Pembayaran online BNI Virtual Account =====
ALTER TABLE pembayaran MODIFY metode ENUM('tunai','transfer','bni_va') NOT NULL DEFAULT 'tunai';

CREATE TABLE IF NOT EXISTS va_billing (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trx_id VARCHAR(30) NOT NULL UNIQUE,
  santri_id INT UNSIGNED NOT NULL,
  virtual_account VARCHAR(20) NOT NULL,
  jumlah INT UNSIGNED NOT NULL,
  status ENUM('menunggu','dibayar','kedaluwarsa','batal') NOT NULL DEFAULT 'menunggu',
  expired_at DATETIME NOT NULL,
  dibayar_at DATETIME NULL,
  ntb VARCHAR(40) NULL,
  kelebihan INT UNSIGNED NOT NULL DEFAULT 0,
  mode VARCHAR(10) NOT NULL DEFAULT 'simulasi',
  dibuat_oleh INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_va_santri (santri_id, status),
  KEY idx_va_status (status, expired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tagihan mana saja yang dilunasi oleh satu VA.
CREATE TABLE IF NOT EXISTS va_item (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  billing_id INT UNSIGNED NOT NULL,
  tagihan_id INT UNSIGNED NOT NULL,
  jumlah INT UNSIGNED NOT NULL,
  KEY idx_vai_billing (billing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catatan semua notifikasi masuk dari bank (audit & rekonsiliasi).
CREATE TABLE IF NOT EXISTS bank_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  arah ENUM('masuk','keluar') NOT NULL,
  jenis VARCHAR(30) NOT NULL,
  trx_id VARCHAR(30) NULL,
  hasil VARCHAR(40) NOT NULL DEFAULT '',
  ip VARCHAR(45) NULL,
  isi TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_bl_trx (trx_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== V5: Tahfidz, Kehadiran, Perizinan =====
ALTER TABLE users MODIFY role ENUM('admin','tu','bendahara','pimpinan','kasir','gudang','ustadz','pembina') NOT NULL;

CREATE TABLE IF NOT EXISTS hafalan (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  santri_id INT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  jenis ENUM('ziyadah','murojaah','tasmi') NOT NULL,
  juz TINYINT UNSIGNED NOT NULL,
  surat VARCHAR(40) NOT NULL DEFAULT '',
  ayat VARCHAR(20) NOT NULL DEFAULT '',
  halaman DECIMAL(5,1) NOT NULL DEFAULT 0,
  kelancaran ENUM('lancar','kurang','ulang') NOT NULL DEFAULT 'lancar',
  nilai TINYINT UNSIGNED NOT NULL,
  grade VARCHAR(2) NOT NULL,
  catatan VARCHAR(255) NOT NULL DEFAULT '',
  ustadz_id INT UNSIGNED NULL,
  dibatalkan TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_haf_santri (santri_id, tanggal),
  KEY idx_haf_tgl (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kehadiran (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  santri_id INT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  sesi VARCHAR(40) NOT NULL,
  status ENUM('H','I','S','A') NOT NULL,
  keterangan VARCHAR(150) NOT NULL DEFAULT '',
  dicatat_oleh INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_hadir (santri_id, tanggal, sesi),
  KEY idx_hadir_tgl (tanggal, sesi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS izin (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  santri_id INT UNSIGNED NOT NULL,
  jenis ENUM('pulang','berobat','keperluan','lainnya') NOT NULL,
  mulai DATETIME NOT NULL,
  selesai DATETIME NOT NULL,
  alasan VARCHAR(255) NOT NULL,
  pengaju ENUM('wali','petugas') NOT NULL,
  status ENUM('menunggu','disetujui','ditolak','keluar','kembali','batal') NOT NULL DEFAULT 'menunggu',
  penyetuju_id INT UNSIGNED NULL,
  catatan VARCHAR(255) NOT NULL DEFAULT '',
  keluar_at DATETIME NULL,
  kembali_at DATETIME NULL,
  terlambat TINYINT(1) NOT NULL DEFAULT 0,
  dibuat_oleh INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_izin_santri (santri_id, status),
  KEY idx_izin_status (status, mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tampilan tabel persis seperti Excel DATA SISWA (hanya baca). Dipakai untuk melihat dan ekspor di phpMyAdmin.
CREATE OR REPLACE VIEW data_santri_excel AS
SELECT
  kelas AS `KLS`,
  va_lama AS `VITUAL ACCOUNT`,
  nisn AS `NISN`,
  nis_internal AS `NIS`,
  nis AS `NIS LOKAL`,
  nik AS `NIK SISWA`,
  nama AS `NAMA SISWA`,
  tempat_lahir AS `TEMPAT LAHIR`,
  tanggal_lahir AS `TGL LAHIR`,
  usia_masuk AS `USIA MASUK`,
  CASE jenis_kelamin WHEN 'L' THEN 'LAKI-LAKI' ELSE 'PEREMPUAN' END AS `L/P`,
  tgl_masuk AS `TGL MASUK`,
  tahun_ajaran AS `TAHUN AJARAN`,
  alamat AS `ALAMAT`,
  npsn_asal AS `NPSN ASAL SEKOLAH`,
  asal_sekolah AS `NAMA ASAL SEKOLAH`,
  alamat_asal AS `ALAMAT ASAL SEKOLAH`,
  no_kk AS `KK`,
  ayah_nama AS `AYAH`,
  ayah_nik AS `NIK AYAH`,
  ayah_pendidikan AS `PENDIDIKAN AYAH`,
  ayah_pekerjaan AS `PEKERJAAN AYAH`,
  ayah_penghasilan AS `PENGHASILAN AYAH`,
  ibu_nama AS `IBU`,
  ibu_nik AS `NIK IBU`,
  ibu_pendidikan AS `PENDIDIKAN IBU`,
  ibu_pekerjaan AS `PEKERJAAN IBU`,
  ibu_penghasilan AS `PENGHASILAN IBU`,
  hp_ayah AS `NO. WA AYAH`,
  hp_ibu AS `NO. WA IBU`,
  jumlah_saudara AS `JUMLAH SAUDARA`,
  anak_ke AS `ANAK KE`,
  keterangan AS `KETERANGAN`,
  unit AS `UNIT`,
  status AS `STATUS`,
  id AS `ID`
FROM santri;

CREATE TABLE IF NOT EXISTS prestasi (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  santri_id INT UNSIGNED NOT NULL,
  jenis VARCHAR(30) NOT NULL,
  nama VARCHAR(150) NOT NULL,
  tahun SMALLINT UNSIGNED NULL,
  sertifikat VARCHAR(40) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_prestasi_santri (santri_id),
  CONSTRAINT fk_prestasi_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
