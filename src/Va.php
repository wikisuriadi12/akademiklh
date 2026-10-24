<?php
declare(strict_types=1);

/** Tagihan SPP yang dibayar lewat BNI Virtual Account: pembuatan VA, callback, pelunasan otomatis. */
final class Va
{
    public const STATUS = ['menunggu' => 'Menunggu bayar', 'dibayar' => 'Dibayar', 'kedaluwarsa' => 'Kedaluwarsa', 'batal' => 'Dibatalkan'];

    public static function catat(string $arah, string $jenis, ?string $trx, string $hasil, ?string $ip, ?string $isi): void
    {
        try {
            Db::exec('INSERT INTO bank_log (arah, jenis, trx_id, hasil, ip, isi, created_at) VALUES (?,?,?,?,?,?,?)',
                [$arah, mb_substr($jenis, 0, 30), $trx !== null ? mb_substr($trx, 0, 30) : null, mb_substr($hasil, 0, 40), $ip, $isi !== null ? mb_substr($isi, 0, 2000) : null, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('bank_log: ' . $e->getMessage());
        }
    }

    public static function kedaluwarsa(): int
    {
        return Db::exec("UPDATE va_billing SET status = 'kedaluwarsa' WHERE status = 'menunggu' AND expired_at < ?", [date('Y-m-d H:i:s')]);
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT v.*, s.nama, s.nis, s.unit, s.hp_wali, s.nama_wali, s.jenjang FROM va_billing v JOIN santri s ON s.id = v.santri_id WHERE v.id = ?', [$id]);
    }

    public static function items(int $billingId): array
    {
        return Db::all('SELECT i.*, t.periode, t.keterangan AS tagihan_ket, t.nominal, j.nama AS jenis_nama, j.kode AS jenis_kode, j.tipe AS jenis_tipe
            FROM va_item i JOIN tagihan t ON t.id = i.tagihan_id LEFT JOIN jenis_biaya j ON j.id = t.jenis_id WHERE i.billing_id = ? ORDER BY t.periode, i.id', [$billingId]);
    }

    /** VA aktif (belum kedaluwarsa) milik santri, bila ada. */
    public static function aktifUntuk(int $santriId): ?array
    {
        self::kedaluwarsa();
        return Db::one("SELECT * FROM va_billing WHERE santri_id = ? AND status = 'menunggu' ORDER BY id DESC LIMIT 1", [$santriId]);
    }

    /** @param array<int,int> $tagihanIds kosong = semua tagihan yang masih ada sisa */
    public static function buat(int $santriId, array $tagihanIds, ?int $userId, ?string $scope): int
    {
        if (!Bni::siap()) { throw new InvalidArgumentException('Konfigurasi BNI belum lengkap di config.php.'); }
        $s = Santri::find($santriId);
        if (!$s) { throw new InvalidArgumentException('Santri tidak ditemukan.'); }
        if ($scope !== null && $scope !== $s['unit']) { throw new InvalidArgumentException('Santri di luar cakupan unit akun Anda.'); }
        if (!in_array($s['status'], ['aktif', 'calon'], true)) { throw new InvalidArgumentException('Hanya santri aktif atau calon yang dapat dibuatkan VA.'); }
        if (self::aktifUntuk($santriId)) { throw new InvalidArgumentException('Masih ada VA aktif untuk santri ini. Batalkan atau tunggu sampai kedaluwarsa.'); }

        $pilih = []; $total = 0;
        foreach (Spp::tagihanSantri($santriId) as $t) {
            $sisa = (int) $t['nominal'] - (int) $t['dibayar'];
            if ($sisa <= 0) { continue; }
            if ($tagihanIds && !in_array((int) $t['id'], array_map('intval', $tagihanIds), true)) { continue; }
            $pilih[(int) $t['id']] = $sisa; $total += $sisa;
        }
        if (!$pilih) { throw new InvalidArgumentException('Tidak ada tagihan yang belum lunas.'); }
        if ($total < 1000) { throw new InvalidArgumentException('Total minimal Rp 1.000.'); }

        $jam = max(1, min(720, (int) Settings::get('va_jam', '72')));
        $exp = time() + $jam * 3600;
        $trx = 'LH' . date('ymdHis') . random_int(100, 999);
        $va = Bni::nomorVa($s['nis']);
        return Db::transaction(function () use ($santriId, $pilih, $total, $exp, $trx, $va, $s, $userId) {
            $id = Db::insert('INSERT INTO va_billing (trx_id, santri_id, virtual_account, jumlah, status, expired_at, mode, dibuat_oleh, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$trx, $santriId, $va, $total, 'menunggu', date('Y-m-d H:i:s', $exp), Bni::mode(), $userId, date('Y-m-d H:i:s')]);
            foreach ($pilih as $tid => $jml) { Db::exec('INSERT INTO va_item (billing_id, tagihan_id, jumlah) VALUES (?,?,?)', [$id, $tid, $jml]); }
            Bni::buatBilling($trx, $va, $total, $s['nama'], (string) $s['hp_wali'], date('c', $exp), 'SPP ' . $s['nama'] . ' (' . $s['nis'] . ')');
            Db::audit($userId, 'buat', 'va_billing', $id, "$trx $va $total");
            return $id;
        });
    }

    public static function batalkan(int $id, ?int $userId, ?string $scope): void
    {
        $v = self::find($id);
        if (!$v) { throw new InvalidArgumentException('VA tidak ditemukan.'); }
        if ($scope !== null && $scope !== $v['unit']) { throw new InvalidArgumentException('Data di luar cakupan unit akun Anda.'); }
        if ($v['status'] !== 'menunggu') { throw new InvalidArgumentException('Hanya VA berstatus menunggu yang dapat dibatalkan.'); }
        Bni::batalBilling($v['trx_id']);
        Db::exec("UPDATE va_billing SET status = 'batal' WHERE id = ? AND status = 'menunggu'", [$id]);
        Db::audit($userId, 'batal', 'va_billing', $id, $v['trx_id']);
    }

    /** Izin pemanggil callback: token opsional dan daftar IP opsional. */
    public static function izinkan(string $ip, string $token): bool
    {
        $c = Bni::cfg();
        if (($c['secret_cb'] ?? '') !== '' && !hash_equals((string) $c['secret_cb'], $token)) { return false; }
        if (!empty($c['ips']) && !in_array($ip, (array) $c['ips'], true)) { return false; }
        return true;
    }

    /**
     * Memproses notifikasi pembayaran dari BNI.
     * @return array{status:string,pesan?:string} BNI mengharapkan {"status":"000"} bila sukses
     */
    public static function proses(string $raw, string $ip = ''): array
    {
        $j = json_decode($raw, true);
        [$cid, $secret] = Bni::kunci();
        if (!is_array($j) || !isset($j['client_id'], $j['data']) || !is_string($j['data'])) {
            self::catat('masuk', 'callback', null, 'format-salah', $ip, $raw);
            return ['status' => '999', 'pesan' => 'format salah'];
        }
        if (!hash_equals((string) $cid, (string) $j['client_id'])) {
            self::catat('masuk', 'callback', null, 'client-salah', $ip, $raw);
            return ['status' => '999', 'pesan' => 'client_id salah'];
        }
        $d = BniEnc::decrypt($j['data'], $cid, $secret);
        if ($d === null) {
            self::catat('masuk', 'callback', null, 'dekripsi-gagal', $ip, $raw);
            return ['status' => '999', 'pesan' => 'data tidak valid atau kedaluwarsa'];
        }
        $trx = (string) ($d['trx_id'] ?? '');
        $ntb = (string) ($d['payment_ntb'] ?? '');
        $jml = (int) ($d['payment_amount'] ?? 0);
        $isi = json_encode($d);
        $v = $trx !== '' ? Db::one('SELECT * FROM va_billing WHERE trx_id = ?', [$trx]) : null;
        if (!$v) { self::catat('masuk', 'callback', $trx, 'trx-tidak-ada', $ip, $isi); return ['status' => '999', 'pesan' => 'trx_id tidak ditemukan']; }
        if (!empty($d['virtual_account']) && (string) $d['virtual_account'] !== $v['virtual_account']) {
            self::catat('masuk', 'callback', $trx, 'va-beda', $ip, $isi); return ['status' => '102', 'pesan' => 'nomor VA tidak cocok'];
        }
        if ($v['status'] === 'dibayar') { self::catat('masuk', 'callback', $trx, 'duplikat', $ip, $isi); return ['status' => '000']; }
        if ($v['status'] === 'batal') { self::catat('masuk', 'callback', $trx, 'sudah-batal', $ip, $isi); return ['status' => '999', 'pesan' => 'billing dibatalkan']; }
        if ($jml !== (int) $v['jumlah']) { self::catat('masuk', 'callback', $trx, 'nominal-beda', $ip, $isi); return ['status' => '102', 'pesan' => 'nominal tidak sesuai']; }

        // Klaim atomik: hanya satu proses yang berhasil mengubah status.
        $klaim = Db::exec("UPDATE va_billing SET status = 'dibayar', dibayar_at = ?, ntb = ? WHERE id = ? AND status IN ('menunggu','kedaluwarsa')",
            [date('Y-m-d H:i:s'), mb_substr($ntb, 0, 40), $v['id']]);
        if ($klaim !== 1) { self::catat('masuk', 'callback', $trx, 'duplikat', $ip, $isi); return ['status' => '000']; }

        $sisaTak = 0;
        foreach (self::items((int) $v['id']) as $it) {
            $dibayar = (int) Db::val('SELECT COALESCE(SUM(jumlah),0) FROM pembayaran WHERE tagihan_id = ? AND dibatalkan = 0', [$it['tagihan_id']]);
            $alok = min((int) $it['jumlah'], max(0, (int) $it['nominal'] - $dibayar));
            if ($alok > 0) {
                try {
                    Spp::bayar((int) $it['tagihan_id'], date('Y-m-d'), $alok, 'bni_va', 'BNI VA ' . $v['virtual_account'] . ' NTB ' . $ntb, null, null);
                } catch (Throwable $e) {
                    error_log('alokasi VA gagal: ' . $e->getMessage());
                    $alok = 0;
                }
            }
            $sisaTak += (int) $it['jumlah'] - $alok;
        }
        if ($sisaTak > 0) { Db::exec('UPDATE va_billing SET kelebihan = ? WHERE id = ?', [$sisaTak, $v['id']]); }
        Db::audit(null, 'bayar_va', 'va_billing', (int) $v['id'], "$trx $jml ntb=$ntb kelebihan=$sisaTak");
        self::catat('masuk', 'callback', $trx, $sisaTak > 0 ? 'ok-kelebihan' : 'ok', $ip, $isi);
        return ['status' => '000'];
    }

    /** Hanya mode simulasi: membangun callback terenkripsi persis seperti BNI lalu memprosesnya. */
    public static function simulasiBayar(int $id, ?int $jumlah = null): array
    {
        if (Bni::mode() !== 'simulasi') { throw new InvalidArgumentException('Simulasi hanya tersedia pada mode simulasi.'); }
        $v = self::find($id);
        if (!$v) { throw new InvalidArgumentException('VA tidak ditemukan.'); }
        [$cid, $secret] = Bni::kunci();
        $jml = $jumlah ?? (int) $v['jumlah'];
        $raw = json_encode(['client_id' => $cid, 'data' => BniEnc::encrypt([
            'trx_id' => $v['trx_id'], 'virtual_account' => $v['virtual_account'], 'trx_amount' => (string) $v['jumlah'],
            'payment_amount' => (string) $jml, 'cumulative_payment_amount' => (string) $jml,
            'payment_ntb' => 'SIM' . random_int(100000, 999999), 'datetime_payment' => date('Y-m-d H:i:s'), 'customer_name' => $v['nama'],
        ], $cid, $secret)]);
        return self::proses($raw, 'simulasi');
    }

    public static function daftar(?string $status, ?string $scope, int $limit = 200): array
    {
        self::kedaluwarsa();
        $sql = 'SELECT v.*, s.nama, s.nis, s.unit, s.jenjang FROM va_billing v JOIN santri s ON s.id = v.santri_id WHERE 1=1';
        $p = [];
        if ($status) { $sql .= ' AND v.status = ?'; $p[] = $status; }
        if ($scope !== null) { $sql .= ' AND s.unit = ?'; $p[] = $scope; }
        return Db::all($sql . ' ORDER BY v.id DESC LIMIT ' . (int) $limit, $p);
    }

    public static function ringkas(?string $scope): array
    {
        self::kedaluwarsa();
        $w = $scope !== null ? ' AND s.unit = ?' : '';
        $p = $scope !== null ? [$scope] : [];
        $q = static fn(string $st) => Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(v.jumlah),0) AS t FROM va_billing v JOIN santri s ON s.id = v.santri_id WHERE v.status = '$st'$w", $p);
        return ['menunggu' => $q('menunggu'), 'dibayar' => $q('dibayar'), 'kedaluwarsa' => $q('kedaluwarsa')];
    }

    public static function log(int $limit = 60): array
    {
        return Db::all('SELECT * FROM bank_log ORDER BY id DESC LIMIT ' . (int) $limit);
    }

    public static function pesanWa(array $v): string
    {
        $c = Settings::all();
        return "Assalamu'alaikum {$v['nama_wali']}, tagihan SPP ananda {$v['nama']} sebesar " . rp($v['jumlah']) . ' dapat dibayar lewat Virtual Account BNI: ' . $v['virtual_account']
            . ' (a.n. ' . $v['nama'] . '), berlaku sampai ' . substr($v['expired_at'], 0, 16) . '. Bisa lewat ATM BNI, BNI Mobile, atau transfer bank lain. Jazakumullahu khairan. - ' . $c['nama_lembaga'];
    }
}
