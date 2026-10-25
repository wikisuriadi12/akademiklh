CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT, nama TEXT, role TEXT, unit_akses TEXT DEFAULT 'semua', aktif INT DEFAULT 1, session_version INT DEFAULT 1, created_at TEXT);
CREATE TABLE santri (id INTEGER PRIMARY KEY AUTOINCREMENT, nis TEXT UNIQUE, nama TEXT, jenis_kelamin TEXT, unit TEXT, jenjang TEXT, kelas TEXT, tempat_lahir TEXT, tanggal_lahir TEXT, alamat TEXT, asal_sekolah TEXT, nama_wali TEXT, hubungan_wali TEXT, hp_wali TEXT, tahun_masuk INT, status TEXT DEFAULT 'aktif', sumber TEXT DEFAULT 'admin', potongan_spp INT DEFAULT 0, catatan TEXT, nisn TEXT, nis_internal TEXT, nik TEXT, no_kk TEXT, va_lama TEXT, tgl_masuk TEXT, npsn_asal TEXT, alamat_asal TEXT, ayah_nama TEXT, ayah_nik TEXT, ayah_pendidikan TEXT, ayah_pekerjaan TEXT, ayah_penghasilan TEXT, ibu_nama TEXT, ibu_nik TEXT, ibu_pendidikan TEXT, ibu_pekerjaan TEXT, ibu_penghasilan TEXT, hp_ayah TEXT, hp_ibu TEXT, jumlah_saudara INT, anak_ke INT, tahun_ajaran TEXT, usia_masuk TEXT, keterangan TEXT, foto TEXT, status_masuk TEXT, surat_rekomendasi TEXT, created_by INT, created_at TEXT, updated_at TEXT);
CREATE TABLE jenis_biaya (id INTEGER PRIMARY KEY AUTOINCREMENT, kode TEXT UNIQUE, nama TEXT, tipe TEXT DEFAULT 'sekali', aktif INT DEFAULT 1);
INSERT INTO jenis_biaya (kode, nama, tipe, aktif) VALUES ('SPP','SPP Bulanan','bulanan',1),('PENDAFTARAN','Uang Pendaftaran','sekali',1),('SERAGAM','Seragam','sekali',1),('KITAB','Kitab & Buku','sekali',1),('KEGIATAN','Kegiatan','sekali',1);
CREATE TABLE tarif_spp (id INTEGER PRIMARY KEY AUTOINCREMENT, tahun_ajaran TEXT, jenjang TEXT, unit TEXT, nominal INT, updated_at TEXT, UNIQUE(tahun_ajaran,jenjang,unit));
CREATE TABLE tagihan (id INTEGER PRIMARY KEY AUTOINCREMENT, santri_id INT, jenis_id INT, periode TEXT, keterangan TEXT NOT NULL DEFAULT '', nominal INT, created_by INT, created_at TEXT, UNIQUE(santri_id,jenis_id,periode,keterangan));
CREATE TABLE pembayaran (id INTEGER PRIMARY KEY AUTOINCREMENT, no_kuitansi TEXT UNIQUE, tagihan_id INT, tanggal TEXT, jumlah INT, metode TEXT, keterangan TEXT, dibuat_oleh INT, created_at TEXT, dibatalkan INT DEFAULT 0, alasan_batal TEXT, dibatalkan_oleh INT, dibatalkan_at TEXT);
CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE rate_limit (k TEXT PRIMARY KEY, n INT, started INT);
CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, aksi TEXT, objek TEXT, objek_id INT, detail TEXT, created_at TEXT);

CREATE TABLE barang (id INTEGER PRIMARY KEY AUTOINCREMENT, konsinyasi_pemasok TEXT, harga_pegawai INT, kode TEXT UNIQUE, nama TEXT, kategori TEXT DEFAULT '', satuan TEXT DEFAULT 'pcs', harga_beli INT DEFAULT 0, harga_jual INT DEFAULT 0, stok_min INT DEFAULT 0, aktif INT DEFAULT 1, created_at TEXT);
CREATE TABLE stok (barang_id INT, unit TEXT, qty INT DEFAULT 0, PRIMARY KEY (barang_id, unit));
CREATE TABLE stok_mutasi (id INTEGER PRIMARY KEY AUTOINCREMENT, barang_id INT, unit TEXT, tipe TEXT, qty INT, stok_setelah INT, harga_beli INT, pemasok TEXT DEFAULT '', ref_id INT, catatan TEXT DEFAULT '', user_id INT, created_at TEXT);
CREATE TABLE dompet (santri_id INT PRIMARY KEY, saldo INT DEFAULT 0);
CREATE TABLE dompet_mutasi (id INTEGER PRIMARY KEY AUTOINCREMENT, santri_id INT, tipe TEXT, jumlah INT, saldo_setelah INT, ref_id INT, catatan TEXT DEFAULT '', user_id INT, created_at TEXT);
CREATE TABLE penjualan (id INTEGER PRIMARY KEY AUTOINCREMENT, no_struk TEXT DEFAULT '', tanggal TEXT, unit TEXT, santri_id INT, total INT, metode TEXT, dibayar INT DEFAULT 0, dibatalkan INT DEFAULT 0, alasan_batal TEXT, user_id INT, created_at TEXT, diskon INT DEFAULT 0, pelanggan TEXT, bukti_qris TEXT);
CREATE TABLE penjualan_item (id INTEGER PRIMARY KEY AUTOINCREMENT, konsinyasi TEXT, penjualan_id INT, barang_id INT, nama TEXT, qty INT, harga INT, harga_beli INT DEFAULT 0, subtotal INT);

CREATE TABLE va_billing (id INTEGER PRIMARY KEY AUTOINCREMENT, trx_id TEXT UNIQUE, santri_id INT, virtual_account TEXT, jumlah INT, status TEXT DEFAULT 'menunggu', expired_at TEXT, dibayar_at TEXT, ntb TEXT, kelebihan INT DEFAULT 0, mode TEXT DEFAULT 'simulasi', dibuat_oleh INT, created_at TEXT);
CREATE TABLE va_item (id INTEGER PRIMARY KEY AUTOINCREMENT, billing_id INT, tagihan_id INT, jumlah INT);
CREATE TABLE bank_log (id INTEGER PRIMARY KEY AUTOINCREMENT, arah TEXT, jenis TEXT, trx_id TEXT, hasil TEXT DEFAULT '', ip TEXT, isi TEXT, created_at TEXT);

CREATE TABLE hafalan (id INTEGER PRIMARY KEY AUTOINCREMENT, santri_id INT, tanggal TEXT, jenis TEXT, juz INT, surat TEXT DEFAULT '', ayat TEXT DEFAULT '', halaman REAL DEFAULT 0, kelancaran TEXT DEFAULT 'lancar', nilai INT, grade TEXT, catatan TEXT DEFAULT '', ustadz_id INT, dibatalkan INT DEFAULT 0, created_at TEXT);
CREATE TABLE kehadiran (id INTEGER PRIMARY KEY AUTOINCREMENT, santri_id INT, tanggal TEXT, sesi TEXT, status TEXT, keterangan TEXT DEFAULT '', dicatat_oleh INT, updated_at TEXT, UNIQUE (santri_id, tanggal, sesi));
CREATE TABLE izin (id INTEGER PRIMARY KEY AUTOINCREMENT, santri_id INT, jenis TEXT, mulai TEXT, selesai TEXT, alasan TEXT, pengaju TEXT, status TEXT DEFAULT 'menunggu', penyetuju_id INT, catatan TEXT DEFAULT '', keluar_at TEXT, kembali_at TEXT, terlambat INT DEFAULT 0, dibuat_oleh INT, created_at TEXT);
CREATE VIEW data_santri_excel AS
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
CREATE TABLE prestasi (id INTEGER PRIMARY KEY AUTOINCREMENT, santri_id INT, jenis TEXT, nama TEXT, tahun INT, sertifikat TEXT, created_by INT, created_at TEXT);

CREATE TABLE qris_trx (id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT UNIQUE, provider TEXT, jumlah INT, status TEXT DEFAULT 'menunggu', qr_url TEXT, penjualan_id INT, user_id INT, created_at TEXT, paid_at TEXT);

CREATE TABLE hutang (id INTEGER PRIMARY KEY AUTOINCREMENT, pemasok TEXT, faktur TEXT DEFAULT '', tanggal TEXT, jatuh_tempo TEXT, total INT, dibayar INT DEFAULT 0, status TEXT DEFAULT 'belum', catatan TEXT DEFAULT '', user_id INT, created_at TEXT);
CREATE TABLE hutang_bayar (id INTEGER PRIMARY KEY AUTOINCREMENT, jenis TEXT, hutang_id INT, pemasok TEXT, jumlah INT, tanggal TEXT, catatan TEXT DEFAULT '', user_id INT, created_at TEXT);

CREATE TABLE piutang (id INTEGER PRIMARY KEY AUTOINCREMENT, penjualan_id INT UNIQUE, santri_id INT, nama TEXT, total INT, dibayar INT DEFAULT 0, jatuh_tempo TEXT, status TEXT DEFAULT 'belum', created_at TEXT);
CREATE TABLE piutang_bayar (id INTEGER PRIMARY KEY AUTOINCREMENT, piutang_id INT, jumlah INT, tanggal TEXT, catatan TEXT DEFAULT '', user_id INT, created_at TEXT);
