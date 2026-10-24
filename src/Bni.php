<?php
declare(strict_types=1);

/** Klien API BNI e-Collection (Virtual Account). Mode "simulasi" tidak menghubungi jaringan. */
final class Bni
{
    /** Untuk pengujian: timpa konfigurasi tanpa config.php. */
    public static ?array $override = null;

    public static function cfg(): array
    {
        $c = self::$override;
        if ($c === null) {
            $f = APP_ROOT . '/config.php';
            $all = is_file($f) ? (require $f) : [];
            $c = $all['bni'] ?? [];
        }
        return $c + ['mode' => 'simulasi', 'client_id' => '', 'secret' => '', 'prefix' => '8001', 'url' => '', 'ips' => [], 'secret_cb' => ''];
    }

    public static function mode(): string
    {
        $m = self::cfg()['mode'];
        return in_array($m, ['simulasi', 'sandbox', 'produksi'], true) ? $m : 'simulasi';
    }

    /** Kunci simulasi tetap agar callback uji bisa dibuat internal. */
    public static function kunci(): array
    {
        $c = self::cfg();
        if (self::mode() === 'simulasi') { return [$c['client_id'] ?: '001', $c['secret'] ?: 'kunci-simulasi-lokal']; }
        return [$c['client_id'], $c['secret']];
    }

    public static function siap(): bool
    {
        return self::mode() === 'simulasi' || (self::cfg()['client_id'] !== '' && self::cfg()['secret'] !== '' && self::cfg()['url'] !== '');
    }

    public static function nomorVa(string $nis): string
    {
        return self::cfg()['prefix'] . preg_replace('/\D/', '', $nis);
    }

    /** @return array respons data dari BNI (sudah didekripsi) */
    public static function panggil(array $payload): array
    {
        if (self::mode() === 'simulasi') {
            return ['status' => '000', 'data' => ['virtual_account' => $payload['virtual_account'] ?? '', 'trx_id' => $payload['trx_id'] ?? '']];
        }
        [$cid, $secret] = self::kunci();
        $body = json_encode(['client_id' => $cid, 'prefix' => self::cfg()['prefix'], 'data' => BniEnc::encrypt($payload + ['client_id' => $cid], $cid, $secret)]);
        $ch = curl_init(self::cfg()['url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_SSL_VERIFYPEER => true]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        Va::catat('keluar', (string) ($payload['type'] ?? '?'), $payload['trx_id'] ?? null, $res === false ? 'gagal-koneksi' : 'ok', null, json_encode(['req' => $payload['type'] ?? '', 'res' => is_string($res) ? substr($res, 0, 500) : $err]));
        if ($res === false) { throw new InvalidArgumentException('Tidak dapat menghubungi BNI. Coba lagi beberapa saat.'); }
        $j = json_decode((string) $res, true);
        if (!is_array($j)) { throw new InvalidArgumentException('Respons BNI tidak dikenali.'); }
        if (($j['status'] ?? '') !== '000') { throw new InvalidArgumentException('BNI menolak permintaan: ' . ($j['message'] ?? ('kode ' . ($j['status'] ?? '?')))); }
        $data = isset($j['data']) && is_string($j['data']) ? BniEnc::decrypt($j['data'], $cid, $secret) : [];
        return ['status' => '000', 'data' => $data ?? []];
    }

    public static function buatBilling(string $trxId, string $va, int $jumlah, string $nama, string $telp, string $kedaluwarsaIso, string $deskripsi): array
    {
        return self::panggil(['type' => 'createbilling', 'trx_id' => $trxId, 'trx_amount' => (string) $jumlah, 'billing_type' => 'c',
            'customer_name' => mb_substr($nama, 0, 30), 'customer_email' => '', 'customer_phone' => preg_replace('/\D/', '', $telp),
            'virtual_account' => $va, 'datetime_expired' => $kedaluwarsaIso, 'description' => mb_substr($deskripsi, 0, 100)]);
    }

    public static function inquiry(string $trxId): array
    {
        return self::panggil(['type' => 'inquirybilling', 'trx_id' => $trxId]);
    }

    public static function batalBilling(string $trxId): array
    {
        return self::panggil(['type' => 'updatebilling', 'trx_id' => $trxId, 'trx_amount' => '0', 'datetime_expired' => date('c', time() - 60)]);
    }
}
