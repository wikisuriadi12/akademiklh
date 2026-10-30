# Sistem Pesantren — SPP, Data Santri, POS & Gudang (V3) — Yayasan Luqmanul Hakim

Aplikasi web PHP 8.2+ (tanpa framework) + MySQL/MariaDB untuk TKQ, Ula, Wustha, Ulya; pondok Ikhwan dan Akhwat terpisah.

## Fitur
- Data santri (NIS otomatis, unit mengikuti jenis kelamin), detail, impor CSV, naik kelas/lulus massal
- Pendaftaran online publik (`?p=daftar`) -> daftar calon -> terima/tolak
- Jenis biaya (SPP bulanan; pendaftaran, seragam, kitab, kegiatan sekali bayar), tarif per tahun ajaran (Juli-Juni), salin tarif
- Tagihan, pembayaran cicilan, kuitansi cetak, pembatalan dengan alasan (tanpa hapus permanen)
- Laporan penerimaan/tunggakan, ekspor CSV, pengingat WhatsApp (wa.me)
- Portal wali (`?p=cek`): NIS + 4 digit terakhir HP
- Dashboard grafik, mode gelap, tampilan HP, pengaturan, log audit
- Peran: admin, tu, bendahara, pimpinan + cakupan unit (semua/ikhwan/akhwat)

## Koperasi, Gudang & Dompet (V3)
- **Kasir (POS)** `?p=pos`: pilih barang dengan tombol +/-, bayar tunai (kembalian otomatis) atau dengan dompet santri, cetak struk
- **Stok Gudang** `?p=gudang`: katalog barang, stok terpisah per pondok (Ikhwan/Akhwat), barang masuk, keluar (rusak/hilang), opname, peringatan stok menipis, nilai persediaan
- **Terhubung otomatis:** penjualan memotong stok gudang; pembatalan mengembalikan stok dan saldo
- **Dompet santri** `?p=dompet`: top up uang saku, koreksi saldo beralasan, riwayat
- **Penjualan** `?p=penjualan`: omzet, laba kotor, barang terlaris, ekspor CSV
- Peran baru: `kasir` (POS, dompet) dan `gudang` (stok); cakupan unit tetap berlaku

## Pintu masuk terpisah per sistem
Halaman depan `?p=portal` menampilkan tiga sistem. Tiap sistem punya login dan menu sendiri:
- **Sistem Pendidikan** (admin, tu, bendahara, pimpinan): data santri, pendaftaran, SPP
- **Sistem Koperasi/POS** (admin, kasir, bendahara): kasir, dompet, penjualan
- **Sistem Gudang** (admin, gudang, pimpinan): stok barang
Akun yang masuk lewat pintu yang bukan bagiannya ditolak. Wali santri tetap tanpa akun (`?p=cek`, `?p=daftar`).

## Akun & sesi
Admin dapat menambah, mengubah peran/unit, mereset password, dan menonaktifkan akun (`?p=users`); kolom "Akses sistem" menunjukkan pintu mana yang boleh dimasuki. Admin aktif terakhir tidak bisa diturunkan atau dinonaktifkan. Reset/ganti password mengakhiri semua sesi akun itu. Sesi menganggur >2 jam berakhir otomatis. Percobaan login dibatasi per sesi dan per IP.

## Pembayaran SPP lewat BNI Virtual Account (V4)
- Bendahara membuat VA per santri (`?p=va_baru`); wali juga bisa membuat sendiri dari portal wali bila diaktifkan di Pengaturan
- Nomor VA = awalan BNI + NIS santri; nominal pas, masa berlaku bisa diatur; hanya satu VA aktif per santri
- BNI memanggil `?p=bni_callback` saat wali membayar; sistem mendekripsi, memverifikasi (client_id, VA, nominal), lalu melunasi tagihan otomatis (metode `bni_va`) dan menerbitkan kuitansi
- Aman dari callback ganda, nominal salah, dan VA kedaluwarsa/batal; pembayaran tunai yang mendahului ditandai "Perlu dicek"
- Semua pesan masuk/keluar bank tercatat di tab "Log notifikasi bank"
- **Mode** di `config.php` (`bni.mode`): `simulasi` (tanpa BNI, ada tombol simulasi bayar), `sandbox`, `produksi`
- Kunci rahasia BNI hanya di `config.php`. Daftarkan alamat callback ke BNI; opsional batasi IP BNI dan token di URL (`bni.ips`, `bni.secret_cb`)
- **Wajib dicocokkan dengan dokumen teknis BNI e-Collection** sebelum produksi: format enkripsi, nama field, format nomor VA/awalan, aturan batal billing, alamat API. Jalankan uji sandbox BNI.
- Jadwalkan `php tools/bni_expire.php` tiap jam (cron) untuk menandai VA kedaluwarsa

## Akademik & Kepengasuhan (V5)
- **Tahfidz** `?p=tahfidz`: setoran ziyadah/murojaah/tasmi', juz, surat, ayat, halaman, nilai → grade otomatis (A ≥ 90, B+ ≥ 80, B ≥ 70, C ≥ 60, D); daftar yang belum setor; progres per juz (1 juz = 20 halaman, hanya ziyadah); salah input dibatalkan, tidak dihapus
- **Kehadiran** `?p=kehadiran`: absensi satu kelas per sesi (H/I/S/A), sesi diatur di Pengaturan; izin yang disetujui terisi otomatis; rekap bulanan per santri
- **Perizinan** `?p=izin`: wali mengajukan dari portal → pembina setujui/tolak → santri keluar → kembali (terlambat >15 menit ditandai); izin dari petugas langsung disetujui; tidak boleh bentrok
- Portal wali menampilkan perkembangan hafalan, kehadiran bulan ini, dan form izin
- Peran baru: `ustadz` (tahfidz, absensi) dan `pembina` (izin, absensi), masuk lewat Sistem Pendidikan

## Subdomain per sistem
Domain: `yayasanluqmanulhakimmarelan.com` (isi `domain` di `config.php`). Buat subdomain di cPanel IDwebhost yang **semuanya mengarah ke folder aplikasi yang sama**:

| Alamat | Isi |
|---|---|
| `akademiklh.yayasanluqmanulhakimmarelan.com` | Sistem Pendidikan + portal pilih sistem (`?p=portal`) |
| `poslh.yayasanluqmanulhakimmarelan.com` | Sistem Koperasi & Gudang (petugas gudang langsung ke stok) |
| `walilh.yayasanluqmanulhakimmarelan.com` | Portal wali tanpa akun |

Nama subdomain `poslh` dan `walilh` bisa diganti di `config.php` (`subdomain`). Halaman yang dibuka di subdomain yang salah otomatis dialihkan ke subdomain pemiliknya. Login tiap subdomain terpisah. Pasang SSL (AutoSSL) untuk semua subdomain. Callback BNI: `https://akademiklh.yayasanluqmanulhakimmarelan.com/?p=bni_callback`. Bila `domain` dikosongkan (mis. di PC lokal), semua sistem jalan dari satu alamat lewat portal.

## Instalasi
1. PHP 8.2+, MySQL/MariaDB. Salin `config.example.php` menjadi `config.php`, isi kredensial DB.
2. Impor `database/schema.sql` (atau jalankan installer).
3. `php tools/install.php` untuk membuat admin pertama.
4. Arahkan domain ke folder ini. `.htaccess` melindungi folder internal.

Upgrade dari V1: impor ulang `schema.sql` pada database baru (tabel baru: `jenis_biaya`, `settings`, `rate_limit`, `barang`, `stok`, `stok_mutasi`, `dompet`, `dompet_mutasi`, `penjualan`, `penjualan_item`; `tagihan` punya `jenis_id`, `keterangan`; peran `kasir` dan `gudang`).

## Pengujian
- `php tests/run.php` — uji unit (php tests/run.php) (SQLite memori)
- `php tests/smoke.php <halaman> [query] [post]` — render halaman/alur POST dengan data contoh

Data contoh di `tests/seed.php` hanya untuk uji, bukan data asli.

## Catatan
Kode POS (LH Mart & Gudang) dipisah ke repositori `Poin-Of-Sales-LH` dan dipasang ke cPanel dari sana. Repositori ini khusus sistem akademik.

## Alur kerja: edit di GitHub, perbarui cPanel

Repositori cPanel `akademiklh` berada langsung di folder aplikasi (`/home/yayas183/akademiklh_app`), jadi tidak perlu `.cpanel.yml`.

1. Edit kode di GitHub (cabang `main`, atau lewat Pull Request lalu merge).
2. cPanel > Git Version Control > repositori `akademiklh` > **Pull or Deploy** > **Update from Remote**.
3. `config.php`, `config_google_drive.php`, dan `storage/` ada di `.gitignore`, jadi tidak tertimpa.
4. Bila rilis menambah migrasi database, buka menu **Periksa Database** sebagai admin lalu klik **Perbarui database sekarang**.
5. Jangan edit langsung di File Manager cPanel: pohon kerja server yang berbeda dari GitHub membuat Update from Remote gagal.
