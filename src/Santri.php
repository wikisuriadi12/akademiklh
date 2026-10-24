<?php
declare(strict_types=1);

final class Santri
{
    private const PER_HALAMAN = 50;
    private const MAX_IMPOR = 1000;

    /** Validasi + normalisasi input form. Mengembalikan [bersih, galat]. */
    public static function validate(array $in): array
    {
        $e = [];
        $c = [];
        $t = static fn(string $k): string => trim((string) ($in[$k] ?? ''));

        $c['nama'] = $t('nama');
        if ($c['nama'] === '' || mb_strlen($c['nama']) > 120) { $e[] = 'Nama santri wajib diisi (maks. 120 karakter).'; }

        $c['jenis_kelamin'] = $t('jenis_kelamin');
        if (!in_array($c['jenis_kelamin'], ['L', 'P'], true)) { $e[] = 'Pilih jenis kelamin.'; }
        // Pondok terpisah: ikhwan = laki-laki, akhwat = perempuan.
        $c['unit'] = $c['jenis_kelamin'] === 'P' ? 'akhwat' : 'ikhwan';

        $c['jenjang'] = $t('jenjang');
        if (!in_array($c['jenjang'], Domain::JENJANG, true)) { $e[] = 'Pilih jenjang.'; }

        $c['kelas'] = mb_substr($t('kelas'), 0, 30);
        $c['tempat_lahir'] = mb_substr($t('tempat_lahir'), 0, 80);
        $c['tanggal_lahir'] = $t('tanggal_lahir');
        if ($c['tanggal_lahir'] !== '' && !Domain::validDate($c['tanggal_lahir'])) { $e[] = 'Tanggal lahir tidak valid.'; }
        $c['alamat'] = mb_substr($t('alamat'), 0, 500);
        $c['asal_sekolah'] = mb_substr($t('asal_sekolah'), 0, 120);

        $c['nama_wali'] = mb_substr($t('nama_wali'), 0, 120);
        if ($c['nama_wali'] === '') { $e[] = 'Nama wali wajib diisi.'; }
        $c['hubungan_wali'] = mb_substr($t('hubungan_wali'), 0, 30);
        $c['hp_wali'] = $t('hp_wali');
        if ($c['hp_wali'] !== '' && !preg_match('/^[0-9+\-\s]{6,30}$/', $c['hp_wali'])) { $e[] = 'Nomor HP wali tidak valid.'; }

        $c['tahun_masuk'] = (int) $t('tahun_masuk');
        if ($c['tahun_masuk'] < 1990 || $c['tahun_masuk'] > (int) date('Y') + 1) { $e[] = 'Tahun masuk tidak valid.'; }

        $c['status'] = $t('status');
        if (!isset(Domain::STATUS[$c['status']])) { $e[] = 'Status tidak valid.'; }

        $pot = $t('potongan_spp');
        $c['potongan_spp'] = $pot === '' ? 0 : (int) preg_replace('/\D/', '', $pot);
        $c['catatan'] = mb_substr($t('catatan'), 0, 255);
        $c['nis'] = $t('nis');
        if ($c['nis'] !== '' && !preg_match('/^[A-Za-z0-9.\-]{3,20}$/', $c['nis'])) { $e[] = 'NIS hanya huruf/angka/titik/strip (3-20 karakter).'; }

        return [$c, $e];
    }

    /** NIS otomatis: YY + unit(1/2) + jenjang(1-4) + urut 4 digit. */
    public static function nisBaru(int $tahunMasuk, string $unit, string $jenjang): string
    {
        $prefix = sprintf('%02d%d%d', $tahunMasuk % 100, $unit === 'akhwat' ? 2 : 1, array_search($jenjang, Domain::JENJANG, true) + 1);
        $n = 1;
        do {
            $nis = $prefix . sprintf('%04d', $n++);
        } while (Db::val('SELECT id FROM santri WHERE nis = ?', [$nis]));
        return $nis;
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM santri WHERE id = ?', [$id]);
    }

    public static function create(array $c, ?int $userId, string $sumber = 'admin'): int
    {
        if ($c['nis'] === '') {
            $c['nis'] = self::nisBaru($c['tahun_masuk'], $c['unit'], $c['jenjang']);
        } elseif (Db::val('SELECT id FROM santri WHERE nis = ?', [$c['nis']])) {
            throw new InvalidArgumentException('NIS sudah dipakai santri lain.');
        }
        $now = date('Y-m-d H:i:s');
        $id = Db::insert(
            'INSERT INTO santri (nis, nama, jenis_kelamin, unit, jenjang, kelas, tempat_lahir, tanggal_lahir, alamat, asal_sekolah, nama_wali, hubungan_wali, hp_wali, tahun_masuk, status, sumber, potongan_spp, catatan, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$c['nis'], $c['nama'], $c['jenis_kelamin'], $c['unit'], $c['jenjang'], $c['kelas'] ?: null, $c['tempat_lahir'] ?: null,
             $c['tanggal_lahir'] ?: null, $c['alamat'] ?: null, $c['asal_sekolah'] ?: null, $c['nama_wali'], $c['hubungan_wali'] ?: null, $c['hp_wali'] ?: null,
             $c['tahun_masuk'], $c['status'], $sumber, $c['potongan_spp'], $c['catatan'] ?: null, $userId, $now, $now]
        );
        Db::audit($userId, 'tambah', 'santri', $id, $c['nis'] . ' ' . $c['nama'] . ' (' . $sumber . ')');
        return $id;
    }

    public static function update(int $id, array $c, ?int $userId): void
    {
        if ($c['nis'] === '') {
            throw new InvalidArgumentException('NIS tidak boleh kosong saat mengubah data.');
        }
        if (Db::val('SELECT id FROM santri WHERE nis = ? AND id <> ?', [$c['nis'], $id])) {
            throw new InvalidArgumentException('NIS sudah dipakai santri lain.');
        }
        Db::exec(
            'UPDATE santri SET nis=?, nama=?, jenis_kelamin=?, unit=?, jenjang=?, kelas=?, tempat_lahir=?, tanggal_lahir=?, alamat=?, asal_sekolah=?, nama_wali=?, hubungan_wali=?, hp_wali=?, tahun_masuk=?, status=?, potongan_spp=?, catatan=?, updated_at=? WHERE id=?',
            [$c['nis'], $c['nama'], $c['jenis_kelamin'], $c['unit'], $c['jenjang'], $c['kelas'] ?: null, $c['tempat_lahir'] ?: null,
             $c['tanggal_lahir'] ?: null, $c['alamat'] ?: null, $c['asal_sekolah'] ?: null, $c['nama_wali'], $c['hubungan_wali'] ?: null, $c['hp_wali'] ?: null,
             $c['tahun_masuk'], $c['status'], $c['potongan_spp'], $c['catatan'] ?: null, date('Y-m-d H:i:s'), $id]
        );
        Db::audit($userId, 'ubah', 'santri', $id, $c['nis'] . ' ' . $c['nama']);
    }

    /** @return array{0: array, 1: int} baris dan total */
    public static function daftar(array $f, ?string $scope, int $page = 1, bool $semua = false): array
    {
        $where = ['1=1'];
        $p = [];
        if (!empty($f['q'])) { $where[] = '(nama LIKE ? OR nis LIKE ? OR nama_wali LIKE ?)'; $q = '%' . $f['q'] . '%'; array_push($p, $q, $q, $q); }
        if (!empty($f['unit'])) { $where[] = 'unit = ?'; $p[] = $f['unit']; }
        if (!empty($f['jenjang'])) { $where[] = 'jenjang = ?'; $p[] = $f['jenjang']; }
        if (!empty($f['kelas'])) { $where[] = 'kelas = ?'; $p[] = $f['kelas']; }
        if (!empty($f['status'])) { $where[] = 'status = ?'; $p[] = $f['status']; }
        if ($scope) { $where[] = 'unit = ?'; $p[] = $scope; }
        $w = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM santri WHERE $w", $p);
        $limit = $semua ? '' : ' LIMIT ' . self::PER_HALAMAN . ' OFFSET ' . (max(1, $page) - 1) * self::PER_HALAMAN;
        $rows = Db::all("SELECT * FROM santri WHERE $w ORDER BY unit, jenjang, kelas, nama$limit", $p);
        return [$rows, $total];
    }

    public static function perHalaman(): int
    {
        return self::PER_HALAMAN;
    }

    public static function rekap(?string $scope): array
    {
        $sql = "SELECT unit, jenjang, COUNT(*) AS n FROM santri WHERE status = 'aktif'" . ($scope ? ' AND unit = ?' : '') . ' GROUP BY unit, jenjang';
        $out = [];
        foreach (Db::all($sql, $scope ? [$scope] : []) as $r) {
            $out[$r['unit']][$r['jenjang']] = (int) $r['n'];
        }
        return $out;
    }

    public static function hitungStatus(string $status, ?string $scope): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM santri WHERE status = ?' . ($scope ? ' AND unit = ?' : ''), $scope ? [$status, $scope] : [$status]);
    }


    // ---------- Profil (identitas & orang tua) ----------

    /** kunci => [label, jenis, panjang maks]. Jenis: angka (digit), identitas (NIK/NPSN; bisa paspor atau awalan huruf), teks, hp, tanggal, bilangan. */
    public const PROFIL = [
        'nisn' => ['NISN', 'angka', 20], 'nis_internal' => ['NIS (buku induk)', 'teks', 20], 'nik' => ['NIK santri', 'identitas', 30],
        'no_kk' => ['No. KK', 'angka', 20], 'va_lama' => ['Virtual Account lama', 'angka', 30], 'tgl_masuk' => ['Tanggal masuk', 'tanggal', 10],
        'npsn_asal' => ['NPSN asal sekolah', 'identitas', 20], 'alamat_asal' => ['Alamat asal sekolah', 'teks', 255],
        'ayah_nama' => ['Nama ayah', 'teks', 120], 'ayah_nik' => ['NIK ayah', 'identitas', 30], 'ayah_pendidikan' => ['Pendidikan ayah', 'teks', 40],
        'ayah_pekerjaan' => ['Pekerjaan ayah', 'teks', 80], 'ayah_penghasilan' => ['Penghasilan ayah', 'teks', 60], 'hp_ayah' => ['HP/WA ayah', 'hp', 30],
        'ibu_nama' => ['Nama ibu', 'teks', 120], 'ibu_nik' => ['NIK ibu', 'identitas', 30], 'ibu_pendidikan' => ['Pendidikan ibu', 'teks', 40],
        'ibu_pekerjaan' => ['Pekerjaan ibu', 'teks', 80], 'ibu_penghasilan' => ['Penghasilan ibu', 'teks', 60], 'hp_ibu' => ['HP/WA ibu', 'hp', 30],
        'jumlah_saudara' => ['Jumlah saudara', 'bilangan', 3], 'anak_ke' => ['Anak ke', 'bilangan', 3],
        'tahun_ajaran' => ['Tahun ajaran', 'teks', 9], 'usia_masuk' => ['Usia masuk', 'teks', 20], 'keterangan' => ['Keterangan', 'teks', 255],
    ];

    /** @return array{0: array, 1: string[]} */
    public static function validateProfil(array $in): array
    {
        $c = [];
        $e = [];
        foreach (self::PROFIL as $k => [$label, $jenis, $maks]) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') { $c[$k] = null; continue; }
            if (mb_strlen($v) > $maks) { $e[] = "$label terlalu panjang (maks. $maks karakter)."; continue; }
            if ($jenis === 'angka' && !preg_match('/^[0-9]+$/', $v)) { $e[] = "$label hanya boleh angka."; continue; }
            if ($jenis === 'identitas' && !preg_match('/^[A-Za-z0-9 .:\\-\/]+$/', $v)) { $e[] = "$label hanya boleh huruf, angka, dan tanda . : - /"; continue; }
            if ($jenis === 'hp' && !preg_match('/^[0-9+\-\s]{6,30}$/', $v)) { $e[] = "$label tidak valid."; continue; }
            if ($jenis === 'tanggal' && !Domain::validDate($v)) { $e[] = "$label tidak valid."; continue; }
            if ($jenis === 'bilangan') {
                if (!preg_match('/^[0-9]{1,3}$/', $v)) { $e[] = "$label harus berupa angka."; continue; }
                $v = (string) (int) $v;
            }
            $c[$k] = $v;
        }
        return [$c, $e];
    }

    public static function profil(int $id): ?array
    {
        return Db::one('SELECT ' . implode(', ', array_keys(self::PROFIL)) . ' FROM santri WHERE id = ?', [$id]);
    }

    /** Simpan (ganti seluruh) kolom identitas & orang tua pada baris santri. Isian kosong menjadi NULL. */
    public static function simpanProfil(int $id, array $c): void
    {
        $kolom = array_keys(self::PROFIL);
        $vals = [];
        foreach ($kolom as $k) { $vals[] = $c[$k] ?? null; }
        $vals[] = $id;
        Db::exec('UPDATE santri SET ' . implode(', ', array_map(static fn($k) => "$k = ?", $kolom)) . ' WHERE id = ?', $vals);
    }

    // ---------- Tampilan/ekspor/impor dengan header Excel ----------

    /** Urutan dan nama kolom persis seperti Excel DATA SISWA. */
    public const EXCEL_HEADER = ['KLS', 'VITUAL ACCOUNT', 'NISN', 'NIS', 'NIS LOKAL', 'NIK SISWA', 'NAMA SISWA', 'TEMPAT LAHIR', 'TGL LAHIR', 'USIA MASUK', 'L/P', 'TGL MASUK', 'TAHUN AJARAN', 'ALAMAT',
        'NPSN ASAL SEKOLAH', 'NAMA ASAL SEKOLAH', 'ALAMAT ASAL SEKOLAH', 'KK', 'AYAH', 'NIK AYAH', 'PENDIDIKAN', 'PEKERJAAN AYAH', 'PENGHASILAN', 'IBU', 'NIK IBU', 'PENDIDIKAN', 'PEKERJAAN IBU', 'PENGHASILAN',
        'NO. WA AYAH', 'NO. WA IBU', 'JUMLAH SAUDARA', 'ANAK KE', 'KETERANGAN'];

    /** Satu baris santri (gabungan santri + profil) dalam urutan EXCEL_HEADER. */
    public static function barisExcel(array $r): array
    {
        $g = static fn(string $k) => (string) ($r[$k] ?? '');
        return [$g('kelas'), $g('va_lama'), $g('nisn'), $g('nis_internal'), $g('nis'), $g('nik'), $g('nama'), $g('tempat_lahir'), $g('tanggal_lahir'), $g('usia_masuk'),
            $r['jenis_kelamin'] === 'L' ? 'LAKI-LAKI' : 'PEREMPUAN', $g('tgl_masuk'), $g('tahun_ajaran'), $g('alamat'),
            $g('npsn_asal'), $g('asal_sekolah'), $g('alamat_asal'), $g('no_kk'), $g('ayah_nama'), $g('ayah_nik'), $g('ayah_pendidikan'), $g('ayah_pekerjaan'), $g('ayah_penghasilan'),
            $g('ibu_nama'), $g('ibu_nik'), $g('ibu_pendidikan'), $g('ibu_pekerjaan'), $g('ibu_penghasilan'), $g('hp_ayah'), $g('hp_ibu'), $g('jumlah_saudara'), $g('anak_ke'), $g('keterangan')];
    }

    /** @return array{0: array, 1: int} baris (santri + profil) dan total */
    public static function daftarLengkap(array $f, ?string $scope, int $page = 1, bool $semua = false): array
    {
        $where = ['1=1'];
        $p = [];
        if (!empty($f['q'])) { $where[] = '(s.nama LIKE ? OR s.nis LIKE ? OR s.nik LIKE ? OR s.nisn LIKE ? OR s.ayah_nama LIKE ? OR s.ibu_nama LIKE ?)'; $q = '%' . $f['q'] . '%'; array_push($p, $q, $q, $q, $q, $q, $q); }
        if (!empty($f['unit'])) { $where[] = 's.unit = ?'; $p[] = $f['unit']; }
        if (!empty($f['jenjang'])) { $where[] = 's.jenjang = ?'; $p[] = $f['jenjang']; }
        if (!empty($f['status'])) { $where[] = 's.status = ?'; $p[] = $f['status']; }
        if ($scope) { $where[] = 's.unit = ?'; $p[] = $scope; }
        $w = implode(' AND ', $where);
        $from = 'FROM santri s';
        $total = (int) Db::val("SELECT COUNT(*) $from WHERE $w", $p);
        $limit = $semua ? '' : ' LIMIT ' . self::PER_HALAMAN . ' OFFSET ' . (max(1, $page) - 1) * self::PER_HALAMAN;
        $rows = Db::all("SELECT s.* $from WHERE $w ORDER BY s.unit, s.jenjang, s.kelas, s.nama$limit", $p);
        return [$rows, $total];
    }

    /** Ubah tanggal dd-mm-yyyy, dd/mm/yyyy, atau yyyy-mm-dd[ hh:mm:ss] menjadi yyyy-mm-dd; '' bila tidak terbaca. */
    public static function tanggalFleksibel(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $v, $m)) { return Domain::validDate("$m[1]-$m[2]-$m[3]") ? "$m[1]-$m[2]-$m[3]" : ''; }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $v, $m)) { $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); return Domain::validDate($d) ? $d : ''; }
        return '';
    }

    /** Jenjang dari kolom KLS Excel: TKQ; 1A-6B = Ula; 7-9 = Wustha; 10-12 = Ulya. */
    public static function jenjangDariKls(string $kls): string
    {
        $k = strtoupper(trim($kls));
        if ($k === 'TKQ') { return 'TKQ'; }
        if (preg_match('/^[1-6][AB]?$/', $k)) { return 'Ula'; }
        if (in_array($k, ['7', '8', '9'], true)) { return 'Wustha'; }
        if (in_array($k, ['10', '11', '12'], true)) { return 'Ulya'; }
        return '';
    }

    /** Petakan nama kolom ber-header Excel ke nama kolom internal. $i = urutan kemunculan kolom (PENDIDIKAN/PENGHASILAN muncul dua kali: ayah lalu ibu). */
    public static function kolomExcel(string $h, int $kemunculan): string
    {
        static $map = ['kls' => 'kls', 'vitual_account' => 'va_lama', 'virtual_account' => 'va_lama', 'nisn' => 'nisn', 'nis' => 'nis_internal', 'nis_lokal' => 'nis', 'nik_siswa' => 'nik',
            'nama_siswa' => 'nama', 'tempat_lahir' => 'tempat_lahir', 'tgl_lahir' => 'tanggal_lahir', 'usia_masuk' => 'usia_masuk', 'lp' => 'jenis_kelamin', 'tgl_masuk' => 'tgl_masuk',
            'tahun_ajaran' => 'tahun_ajaran', 'alamat' => 'alamat', 'npsn_asal_sekolah' => 'npsn_asal', 'nama_asal_sekolah' => 'asal_sekolah', 'alamat_asal_sekolah' => 'alamat_asal',
            'kk' => 'no_kk', 'ayah' => 'ayah_nama', 'nik_ayah' => 'ayah_nik', 'pekerjaan_ayah' => 'ayah_pekerjaan', 'ibu' => 'ibu_nama', 'nik_ibu' => 'ibu_nik', 'pekerjaan_ibu' => 'ibu_pekerjaan',
            'no_wa_ayah' => 'hp_ayah', 'no_wa_ibu' => 'hp_ibu', 'jumlah_saudara' => 'jumlah_saudara', 'anak_ke' => 'anak_ke', 'keterangan' => 'keterangan'];
        if ($h === 'pendidikan') { return $kemunculan === 0 ? 'ayah_pendidikan' : 'ibu_pendidikan'; }
        if ($h === 'penghasilan') { return $kemunculan === 0 ? 'ayah_penghasilan' : 'ibu_penghasilan'; }
        return $map[$h] ?? $h;
    }

    /** Lengkapi satu baris berformat Excel: jenjang, wali, HP wali, tahun masuk, tanggal, jenis kelamin. */
    public static function dariExcel(array $in): array
    {
        foreach ($in as $k => $v) { if ($v === '-') { $in[$k] = ''; } }
        $in['kelas'] = $in['kls'] ?? '';
        $in['jenjang'] = self::jenjangDariKls($in['kls'] ?? '');
        $ayah = trim((string) ($in['ayah_nama'] ?? ''));
        $in['nama_wali'] = $ayah !== '' ? $ayah : trim((string) ($in['ibu_nama'] ?? ''));
        $in['hubungan_wali'] = $ayah !== '' ? 'Ayah' : 'Ibu';
        $in['hp_wali'] = trim((string) ($in['hp_ayah'] ?? '')) !== '' ? $in['hp_ayah'] : (string) ($in['hp_ibu'] ?? '');
        $in['tanggal_lahir'] = self::tanggalFleksibel((string) ($in['tanggal_lahir'] ?? ''));
        $in['tgl_masuk'] = self::tanggalFleksibel((string) ($in['tgl_masuk'] ?? ''));
        $in['tahun_masuk'] = $in['tgl_masuk'] !== '' ? substr($in['tgl_masuk'], 0, 4) : (preg_match('/^(\d{4})/', (string) ($in['tahun_ajaran'] ?? ''), $m) ? $m[1] : '');
        foreach (['hp_wali', 'hp_ayah', 'hp_ibu'] as $k) {
            $d = preg_replace('/\D/', '', (string) ($in[$k] ?? ''));
            if (strlen($d) < 6) { $in[$k] = ''; }
        }
        return $in;
    }

    // ---------- Pendaftaran ----------

    /** Pendaftaran online (publik). Santri masuk sebagai calon menunggu verifikasi TU. */
    public static function daftarOnline(array $in): array
    {
        $in['status'] = 'calon';
        $in['tahun_masuk'] = (string) date('Y');
        $in['potongan_spp'] = '0';
        $in['nis'] = '';
        [$c, $errors] = self::validate($in);
        if (trim((string) ($c['hp_wali'])) === '') { $errors[] = 'Nomor HP wali wajib diisi.'; }
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        $id = self::create($c, null, 'online');
        return self::find($id);
    }

    public static function terima(int $id, ?string $scope, ?int $userId): void
    {
        $s = self::find($id);
        if (!$s || $s['status'] !== 'calon') {
            throw new InvalidArgumentException('Hanya calon santri yang dapat diterima.');
        }
        if ($scope && $s['unit'] !== $scope) {
            throw new InvalidArgumentException('Calon santri di luar cakupan akun Anda.');
        }
        Db::exec("UPDATE santri SET status='aktif', updated_at=? WHERE id=?", [date('Y-m-d H:i:s'), $id]);
        Db::audit($userId, 'terima', 'santri', $id, $s['nis'] . ' ' . $s['nama']);
    }

    public static function tolak(int $id, string $alasan, ?string $scope, ?int $userId): void
    {
        $alasan = trim($alasan);
        $s = self::find($id);
        if (!$s || $s['status'] !== 'calon') {
            throw new InvalidArgumentException('Hanya calon santri yang dapat ditolak.');
        }
        if ($scope && $s['unit'] !== $scope) {
            throw new InvalidArgumentException('Calon santri di luar cakupan akun Anda.');
        }
        if (mb_strlen($alasan) < 3) {
            throw new InvalidArgumentException('Isi alasan penolakan.');
        }
        Db::exec("UPDATE santri SET status='ditolak', catatan=?, updated_at=? WHERE id=?", [mb_substr($alasan, 0, 255), date('Y-m-d H:i:s'), $id]);
        Db::audit($userId, 'tolak', 'santri', $id, $alasan);
    }

    // ---------- Kenaikan kelas / kelulusan ----------

    /** @param int[] $ids */
    public static function naikkan(array $ids, string $aksi, string $jenjang, string $kelas, ?string $scope, ?int $userId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            throw new InvalidArgumentException('Pilih minimal satu santri.');
        }
        if (!in_array($aksi, ['pindah_kelas', 'lulus'], true)) {
            throw new InvalidArgumentException('Aksi tidak dikenal.');
        }
        $kelas = trim($kelas);
        if ($aksi === 'pindah_kelas') {
            if ($jenjang === '' && $kelas === '') {
                throw new InvalidArgumentException('Isi jenjang tujuan dan/atau kelas tujuan.');
            }
            if ($jenjang !== '' && !in_array($jenjang, Domain::JENJANG, true)) {
                throw new InvalidArgumentException('Jenjang tujuan tidak valid.');
            }
            if (mb_strlen($kelas) > 30) {
                throw new InvalidArgumentException('Kelas tujuan terlalu panjang.');
            }
        }
        return Db::transaction(function () use ($ids, $aksi, $jenjang, $kelas, $scope, $userId) {
            $n = 0;
            foreach ($ids as $id) {
                $s = self::find($id);
                if (!$s || $s['status'] !== 'aktif' || ($scope && $s['unit'] !== $scope)) {
                    continue;
                }
                $now = date('Y-m-d H:i:s');
                if ($aksi === 'lulus') {
                    Db::exec("UPDATE santri SET status='lulus', updated_at=? WHERE id=?", [$now, $id]);
                    Db::audit($userId, 'lulus', 'santri', $id, $s['jenjang'] . ' ' . (string) $s['kelas']);
                } else {
                    $j = $jenjang !== '' ? $jenjang : $s['jenjang'];
                    $k = $kelas !== '' ? $kelas : $s['kelas'];
                    Db::exec('UPDATE santri SET jenjang=?, kelas=?, updated_at=? WHERE id=?', [$j, $k, $now, $id]);
                    Db::audit($userId, 'naik', 'santri', $id, $s['jenjang'] . ' ' . (string) $s['kelas'] . " -> $j " . (string) $k);
                }
                $n++;
            }
            return $n;
        });
    }

    // ---------- Impor CSV ----------

    public static function templateCsv(): array
    {
        return [
            ['nama', 'jenis_kelamin', 'jenjang', 'kelas', 'nama_wali', 'hp_wali', 'tahun_masuk', 'tanggal_lahir', 'tempat_lahir', 'alamat', 'potongan_spp', 'nis'],
            ['Muhammad Fatih', 'L', 'Ula', '3A', 'Abdullah Rahman', '081234567801', '2024', '2016-05-12', 'Medan', 'Jl. Contoh No. 1', '0', ''],
            ['Aisyah Humaira', 'P', 'Wustha', '1B', 'Ahmad Dalimunthe', '081255506608', '2025', '2013-09-03', 'Marelan', 'Jl. Contoh No. 2', '50000', ''],
        ];
    }

    /** Semua-atau-tidak-sama-sekali: bila ada baris bermasalah, tidak ada yang disimpan. */
    public static function importCsv(string $path, ?string $scope, ?int $userId, bool $perbarui = false): array
    {
        $fh = @fopen($path, 'r');
        if (!$fh) {
            throw new InvalidArgumentException('Berkas tidak dapat dibaca.');
        }
        $first = (string) fgets($fh);
        rewind($fh);
        $d = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $head = fgetcsv($fh, 0, $d, '"', '\\');
        if (!$head) {
            fclose($fh);
            throw new InvalidArgumentException('Berkas kosong.');
        }
        $alias = ['jk' => 'jenis_kelamin', 'gender' => 'jenis_kelamin', 'wali' => 'nama_wali', 'hp' => 'hp_wali', 'no_hp_wali' => 'hp_wali', 'tahun' => 'tahun_masuk', 'potongan' => 'potongan_spp'];
        // Format Excel DATA SISWA dikenali dari kolom "NAMA SISWA": header dipakai apa adanya.
        $norm = static fn($h) => preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-'], '_', strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)))));
        $modeExcel = in_array('nama_siswa', array_map($norm, $head), true);
        $cols = [];
        $muncul = [];
        foreach ($head as $i => $h) {
            if ($modeExcel) {
                $n = $norm($h);
                $muncul[$n] = $muncul[$n] ?? 0;
                $cols[$i] = self::kolomExcel($n, $muncul[$n]++);
                continue;
            }
            $h = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
            $h = str_replace([' ', '-'], '_', $h);
            $cols[$i] = $alias[$h] ?? $h;
        }
        $wajib = $modeExcel ? ['nama', 'kls', 'jenis_kelamin'] : ['nama', 'jenis_kelamin', 'jenjang', 'nama_wali'];
        foreach ($wajib as $req) {
            if (!in_array($req, $cols, true)) {
                fclose($fh);
                throw new InvalidArgumentException("Kolom wajib '$req' tidak ada pada baris judul.");
            }
        }
        $jenjangMap = [];
        foreach (Domain::JENJANG as $j) { $jenjangMap[strtolower($j)] = $j; }

        $valid = [];
        $errors = [];
        $nisPakai = [];
        $nisLihat = [];
        $dobel = 0;
        $baris = 1;
        while (($r = fgetcsv($fh, 0, $d, '"', '\\')) !== false) {
            $baris++;
            if (count($r) === 1 && trim((string) $r[0]) === '') { continue; }
            if (count($valid) >= self::MAX_IMPOR) {
                $errors[] = 'Maksimal ' . self::MAX_IMPOR . ' baris per impor.';
                break;
            }
            $in = [];
            foreach ($cols as $i => $name) { $in[$name] = trim((string) ($r[$i] ?? '')); }
            if ($modeExcel) { $in = self::dariExcel($in); if (($in['nis'] ?? '') !== '') { if (isset($nisLihat[$in['nis']])) { $in['nis'] = ''; $dobel++; } else { $nisLihat[$in['nis']] = true; } } }
            $jk = strtolower($in['jenis_kelamin'] ?? '');
            $in['jenis_kelamin'] = in_array($jk, ['l', 'laki-laki', 'laki', 'pria', 'ikhwan'], true) ? 'L' : (in_array($jk, ['p', 'perempuan', 'wanita', 'akhwat'], true) ? 'P' : '');
            $in['jenjang'] = $jenjangMap[strtolower($in['jenjang'] ?? '')] ?? '';
            $in['tahun_masuk'] = ($in['tahun_masuk'] ?? '') !== '' ? $in['tahun_masuk'] : (string) date('Y');
            $in['status'] = ($in['status'] ?? '') !== '' ? strtolower($in['status']) : 'aktif';
            [$c, $e] = self::validate($in);
            [$pf, $epf] = self::validateProfil($in);
            $e = array_merge($e, $epf);
            $c['_profil'] = $pf;
            if ($scope && $c['unit'] !== $scope) { $e[] = 'di luar cakupan unit akun Anda.'; }
            if ($perbarui) {
                // Mode perbarui: cocokkan santri yang sudah ada (NIS, atau nama + tanggal lahir bila NIS kosong); hanya data identitas & orang tua yang diisi.
                $cocok = null;
                if ($c['nis'] !== '') {
                    $cocok = Db::one('SELECT id, unit FROM santri WHERE nis = ?', [$c['nis']]);
                } elseif ($c['tanggal_lahir'] !== '') {
                    $kand = Db::all('SELECT id, unit FROM santri WHERE nama = ? AND tanggal_lahir = ?', [$c['nama'], $c['tanggal_lahir']]);
                    $cocok = count($kand) === 1 ? $kand[0] : null;
                }
                if (!$cocok) { $e[] = 'santri ' . $c['nama'] . ' tidak ditemukan di sistem (mode perbarui tidak menambah santri baru).'; }
                elseif ($scope && $cocok['unit'] !== $scope) { $e[] = 'di luar cakupan unit akun Anda.'; }
                else { $c['_id'] = (int) $cocok['id']; }
                if (!empty($c['_id']) && isset($nisPakai['id' . $c['_id']])) { $e[] = 'santri ' . $c['nama'] . ' muncul dua kali dalam berkas.'; }
                if (!empty($c['_id'])) { $nisPakai['id' . $c['_id']] = true; }
            } elseif ($c['nis'] !== '') {
                if (isset($nisPakai[$c['nis']]) || Db::val('SELECT id FROM santri WHERE nis = ?', [$c['nis']])) { $e[] = 'NIS ' . $c['nis'] . ' sudah dipakai.'; }
                $nisPakai[$c['nis']] = true;
            }
            if ($e) {
                $errors[] = "Baris $baris: " . implode(' ', $e);
            } else {
                $valid[] = $c;
            }
        }
        fclose($fh);
        if ($errors) {
            return ['ok' => false, 'errors' => array_slice($errors, 0, 50), 'jumlah_galat' => count($errors), 'count' => 0];
        }
        if (!$valid) {
            throw new InvalidArgumentException('Tidak ada baris data.');
        }
        Db::transaction(function () use ($valid, $userId, $perbarui) {
            foreach ($valid as $c) {
                $pf = $c['_profil'];
                if ($perbarui) { self::simpanProfil((int) $c['_id'], $pf); continue; }
                unset($c['_profil']);
                self::simpanProfil(self::create($c, $userId, 'impor'), $pf);
            }
        });
        Db::audit($userId, $perbarui ? 'impor-perbarui' : 'impor', 'santri', null, count($valid) . ' baris');
        return ['ok' => true, 'errors' => [], 'jumlah_galat' => 0, 'count' => count($valid), 'nis_dobel' => $dobel, 'perbarui' => $perbarui];
    }

    // ---------- Portal wali ----------

    /** Verifikasi wali: NIS + 4 digit terakhir HP wali. null bila tidak cocok (tanpa membedakan sebabnya). */
    public static function verifikasiWali(string $nis, string $last4): ?array
    {
        $s = Db::one("SELECT * FROM santri WHERE nis = ? AND status IN ('aktif','calon','nonaktif')", [trim($nis)]);
        $digits = preg_replace('/\D/', '', (string) ($s['hp_wali'] ?? ''));
        if (!$s || strlen($digits) < 4 || !preg_match('/^\d{4}$/', trim($last4))) {
            return null;
        }
        return hash_equals(substr($digits, -4), trim($last4)) ? $s : null;
    }
}
