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
    public static function importCsv(string $path, ?string $scope, ?int $userId): array
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
        $cols = [];
        foreach ($head as $i => $h) {
            $h = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
            $h = str_replace([' ', '-'], '_', $h);
            $cols[$i] = $alias[$h] ?? $h;
        }
        foreach (['nama', 'jenis_kelamin', 'jenjang', 'nama_wali'] as $req) {
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
            $jk = strtolower($in['jenis_kelamin'] ?? '');
            $in['jenis_kelamin'] = in_array($jk, ['l', 'laki-laki', 'laki', 'pria', 'ikhwan'], true) ? 'L' : (in_array($jk, ['p', 'perempuan', 'wanita', 'akhwat'], true) ? 'P' : '');
            $in['jenjang'] = $jenjangMap[strtolower($in['jenjang'] ?? '')] ?? '';
            $in['tahun_masuk'] = ($in['tahun_masuk'] ?? '') !== '' ? $in['tahun_masuk'] : (string) date('Y');
            $in['status'] = ($in['status'] ?? '') !== '' ? strtolower($in['status']) : 'aktif';
            [$c, $e] = self::validate($in);
            if ($scope && $c['unit'] !== $scope) { $e[] = 'di luar cakupan unit akun Anda.'; }
            if ($c['nis'] !== '') {
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
        Db::transaction(function () use ($valid, $userId) {
            foreach ($valid as $c) { self::create($c, $userId, 'impor'); }
        });
        Db::audit($userId, 'impor', 'santri', null, count($valid) . ' baris');
        return ['ok' => true, 'errors' => [], 'jumlah_galat' => 0, 'count' => count($valid)];
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
