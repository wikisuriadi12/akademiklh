<?php
declare(strict_types=1);

/**
 * QRIS dinamis lewat API bank / payment gateway.
 * Penyedia yang berjalan: "simulasi" (uji alur tanpa jaringan) dan "midtrans" (Core API QRIS, ikuti dokumentasi publik; uji dulu di sandbox).
 * "bni" dan "lainnya" hanya menyimpan kredensial; adapter menyusul setelah spesifikasi API dari bank diterima.
 */
final class QrisApi
{
    public const PENYEDIA = [
        '' => 'Nonaktif (pakai QRIS statis + foto bukti)',
        'simulasi' => 'Simulasi (uji alur, tanpa bank)',
        'midtrans' => 'Midtrans (QRIS dinamis)',
        'bni' => 'BNI (menunggu spesifikasi API bank)',
        'lainnya' => 'Bank / gateway lain (menunggu spesifikasi)',
    ];
    private const BERJALAN = ['simulasi', 'midtrans'];

    /** Untuk pengujian: tiru jawaban HTTP. fn(string $method, string $url, array $headers, ?string $body): array [kode, isi] */
    public static $http = null;

    // ---- penyimpanan kredensial (rahasia dienkripsi AES-256-GCM, kunci diturunkan dari config.php) ----
    private static function kunci(): string
    {
        $f = APP_ROOT . '/config.php';
        return hash('sha256', 'lhpos-qris|' . (is_file($f) ? (string) file_get_contents($f) : 'uji'), true);
    }

    public static function segel(string $plain): string
    {
        if ($plain === '') { return ''; }
        $iv = random_bytes(12); $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', self::kunci(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc1:' . base64_encode($iv . $tag . $c);
    }

    public static function buka(string $v): string
    {
        if (strncmp($v, 'enc1:', 5) !== 0) { return ''; }
        $raw = base64_decode(substr($v, 5), true);
        if ($raw === false || strlen($raw) < 29) { return ''; }
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::kunci(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? '' : $p;
    }

    public static function provider(): string
    {
        $p = Settings::get('qris_api_provider');
        return isset(self::PENYEDIA[$p]) ? $p : '';
    }

    public static function mode(): string { return Settings::get('qris_api_mode') === 'produksi' ? 'produksi' : 'sandbox'; }
    public static function secret(): string { return self::buka(Settings::get('qris_api_secret')); }
    public static function idMerchant(): string { return Settings::get('qris_api_id'); }
    public static function token(): string { return Settings::get('qris_api_token'); }
    public static function adaSecret(): bool { return self::secret() !== ''; }

    public static function baseUrl(): string
    {
        $u = trim(Settings::get('qris_api_url'));
        if ($u !== '') { return rtrim($u, '/'); }
        if (self::provider() === 'midtrans') { return self::mode() === 'produksi' ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com'; }
        return '';
    }

    /** Dinamis aktif: penyedia berjalan dan kredensial lengkap. */
    public static function siap(): bool
    {
        $p = self::provider();
        if ($p === 'simulasi') { return true; }
        if ($p === 'midtrans') { return self::secret() !== '' && self::baseUrl() !== ''; }
        return false;
    }

    public static function pesanStatus(): string
    {
        $p = self::provider();
        if ($p === '') { return 'Nonaktif. Kasir memakai QRIS statis dan foto bukti.'; }
        if (!in_array($p, self::BERJALAN, true)) { return 'Kredensial tersimpan, tetapi adapter untuk penyedia ini belum tersedia. Kirim dokumentasi API dari bank agar bisa dipasang. Sementara kasir memakai QRIS statis.'; }
        if (!self::siap()) { return 'Kredensial belum lengkap (isi Server Key / rahasia API).'; }
        return $p === 'simulasi' ? 'Aktif: mode simulasi (tidak ada uang sungguhan).' : 'Aktif: ' . self::PENYEDIA[$p] . ' (' . self::mode() . ').';
    }

    // ---- transaksi ----
    public static function mulai(int $jumlah, ?int $userId): array
    {
        if (!self::siap()) { throw new InvalidArgumentException('QRIS dinamis belum aktif.'); }
        if ($jumlah < 1) { throw new InvalidArgumentException('Total tidak valid.'); }
        $ref = 'LH' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
        $p = self::provider(); $qr = null;
        if ($p === 'midtrans') {
            [$kode, $isi] = self::kirim('POST', self::baseUrl() . '/v2/charge', json_encode([
                'payment_type' => 'qris',
                'transaction_details' => ['order_id' => $ref, 'gross_amount' => $jumlah],
                'qris' => ['acquirer' => 'gopay'],
            ]));
            $j = json_decode($isi, true);
            if ($kode < 200 || $kode >= 300 || !is_array($j) || !in_array((string) ($j['status_code'] ?? ''), ['200', '201'], true)) {
                throw new InvalidArgumentException('Penyedia menolak pembuatan QR: ' . mb_substr((string) ($j['status_message'] ?? ('HTTP ' . $kode)), 0, 120));
            }
            foreach ((array) ($j['actions'] ?? []) as $a) { if (($a['name'] ?? '') === 'generate-qr-code' && !empty($a['url'])) { $qr = (string) $a['url']; } }
            if ($qr === null || !preg_match('#^https://#', $qr)) { throw new InvalidArgumentException('Penyedia tidak mengirim gambar QR.'); }
        }
        Db::exec('INSERT INTO qris_trx (ref, provider, jumlah, status, qr_url, user_id, created_at) VALUES (?,?,?,?,?,?,?)', [$ref, $p, $jumlah, 'menunggu', $qr, $userId, date('Y-m-d H:i:s')]);
        return ['ref' => $ref, 'jumlah' => $jumlah, 'qr_url' => $qr, 'provider' => $p];
    }

    public static function trx(string $ref): ?array
    {
        return preg_match('/^LH[0-9A-Z]{10,30}$/', $ref) ? Db::one('SELECT * FROM qris_trx WHERE ref = ?', [$ref]) : null;
    }

    /** Status terkini; untuk transaksi menunggu, tanyakan ke penyedia. */
    public static function status(string $ref): string
    {
        $t = self::trx($ref);
        if (!$t) { return 'tidak_ada'; }
        if ($t['status'] === 'menunggu') {
            if (strtotime((string) $t['created_at']) < time() - 900) { Db::exec("UPDATE qris_trx SET status = 'kedaluwarsa' WHERE id = ? AND status = 'menunggu'", [$t['id']]); return 'kedaluwarsa'; }
            if ($t['provider'] === 'midtrans' && self::siap()) {
                try {
                    [$kode, $isi] = self::kirim('GET', self::baseUrl() . '/v2/' . rawurlencode($ref) . '/status', null);
                    $j = json_decode($isi, true);
                    if (is_array($j) && isset($j['transaction_status'])) { self::terapkan($ref, (string) $j['transaction_status'], (string) ($j['gross_amount'] ?? '')); }
                } catch (Throwable $e) { /* coba lagi pada polling berikutnya */ }
                $t = self::trx($ref);
            }
        }
        return (string) $t['status'];
    }

    /** Terapkan status dari penyedia. Lunas hanya bila nominal sama dengan yang dibuat. */
    public static function terapkan(string $ref, string $statusPenyedia, string $nominal): bool
    {
        $t = self::trx($ref);
        if (!$t || $t['status'] !== 'menunggu') { return false; }
        if (in_array($statusPenyedia, ['settlement', 'capture'], true)) {
            if ($nominal !== '' && (int) round((float) $nominal) !== (int) $t['jumlah']) { Db::exec("UPDATE qris_trx SET status = 'gagal' WHERE id = ?", [$t['id']]); return false; }
            Db::exec("UPDATE qris_trx SET status = 'lunas', paid_at = ? WHERE id = ? AND status = 'menunggu'", [date('Y-m-d H:i:s'), $t['id']]);
            return true;
        }
        if (in_array($statusPenyedia, ['expire', 'cancel', 'deny', 'failure'], true)) { Db::exec("UPDATE qris_trx SET status = ? WHERE id = ?", [$statusPenyedia === 'expire' ? 'kedaluwarsa' : 'gagal', $t['id']]); }
        return false;
    }

    /** Tandai lunas secara manual pada mode simulasi. */
    public static function simulasiLunas(string $ref): bool
    {
        $t = self::trx($ref);
        if (!$t || $t['provider'] !== 'simulasi') { return false; }
        return self::terapkan($ref, 'settlement', '');
    }

    /** Callback penyedia (Midtrans: tanda tangan SHA-512 dari order_id+status_code+gross_amount+ServerKey). */
    public static function callback(string $raw): array
    {
        $j = json_decode($raw, true);
        if (!is_array($j) || self::provider() !== 'midtrans') { return [400, ['status' => 'error']]; }
        $sig = hash('sha512', ($j['order_id'] ?? '') . ($j['status_code'] ?? '') . ($j['gross_amount'] ?? '') . self::secret());
        if (!hash_equals($sig, (string) ($j['signature_key'] ?? ''))) { return [403, ['status' => 'tanda tangan salah']]; }
        self::terapkan((string) ($j['order_id'] ?? ''), (string) ($j['transaction_status'] ?? ''), (string) ($j['gross_amount'] ?? ''));
        return [200, ['status' => 'ok']];
    }

    /** Dipakai kasir saat menyelesaikan penjualan: harus lunas, nominal sama, belum terpakai. */
    public static function periksaUntukJual(string $ref, int $total): void
    {
        $t = self::trx($ref);
        if (!$t) { throw new InvalidArgumentException('Transaksi QRIS tidak ditemukan.'); }
        if ($t['status'] !== 'lunas' && self::status($ref) !== 'lunas') { throw new InvalidArgumentException('Pembayaran QRIS belum lunas.'); }
        $t = self::trx($ref);
        if ($t['penjualan_id'] !== null) { throw new InvalidArgumentException('Pembayaran QRIS ini sudah dipakai untuk transaksi lain.'); }
        if ((int) $t['jumlah'] !== $total) { throw new InvalidArgumentException('Nominal QRIS (' . rp($t['jumlah']) . ') tidak sama dengan total belanja (' . rp($total) . ').'); }
    }

    public static function pakai(string $ref, int $penjualanId): void
    {
        Db::exec('UPDATE qris_trx SET penjualan_id = ? WHERE ref = ? AND penjualan_id IS NULL', [$penjualanId, $ref]);
    }

    private static function kirim(string $method, string $url, ?string $body): array
    {
        $h = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Basic ' . base64_encode(self::secret() . ':')];
        if (self::$http !== null) { return (self::$http)($method, $url, $h, $body); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $h, CURLOPT_SSL_VERIFYPEER => true]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $res = curl_exec($ch); $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($res === false) { throw new InvalidArgumentException('Tidak dapat menghubungi penyedia QRIS. Coba lagi.'); }
        return [$kode, (string) $res];
    }
}
