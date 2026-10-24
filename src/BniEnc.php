<?php
declare(strict_types=1);

/**
 * Enkripsi/dekripsi payload BNI e-Collection (algoritma resmi pustaka BNI: dua lapis
 * penjumlahan karakter dengan client_id dan secret key, plus cap waktu yang dibalik).
 * PENTING: cocokkan dengan dokumen teknis dan contoh kode dari BNI sebelum produksi.
 */
final class BniEnc
{
    public const TIME_DIFF_LIMIT = 480;

    public static function encrypt(array $data, string $clientId, string $secret): string
    {
        $plain = strrev((string) time()) . '.' . json_encode($data, JSON_UNESCAPED_SLASHES);
        $r = self::enc(self::enc($plain, $clientId), $secret);
        return strtr(rtrim(base64_encode($r), '='), '+/', '-_');
    }

    /** @return array|null null bila rusak, salah kunci, atau cap waktu kedaluwarsa */
    public static function decrypt(string $hashed, string $clientId, string $secret): ?array
    {
        $b = base64_decode(strtr(str_pad($hashed, (int) (ceil(strlen($hashed) / 4) * 4), '=', STR_PAD_RIGHT), '-_', '+/'), true);
        if ($b === false || $b === '') { return null; }
        $plain = self::dec(self::dec($b, $secret), $clientId);
        $parts = explode('.', $plain, 2);
        if (count($parts) !== 2 || !ctype_digit(strrev($parts[0]))) { return null; }
        if (abs((int) strrev($parts[0]) - time()) > self::TIME_DIFF_LIMIT) { return null; }
        $json = json_decode($parts[1], true);
        return is_array($json) ? $json : null;
    }

    private static function enc(string $s, string $key): string
    {
        $out = ''; $kl = strlen($key);
        if ($kl === 0) { throw new InvalidArgumentException('Kunci BNI kosong.'); }
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $out .= chr((ord($s[$i]) + ord($key[($i % $kl) - 1 < 0 ? $kl - 1 : ($i % $kl) - 1])) % 128);
        }
        return $out;
    }

    private static function dec(string $s, string $key): string
    {
        $out = ''; $kl = strlen($key);
        if ($kl === 0) { throw new InvalidArgumentException('Kunci BNI kosong.'); }
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $out .= chr(((ord($s[$i]) - ord($key[($i % $kl) - 1 < 0 ? $kl - 1 : ($i % $kl) - 1])) + 256) % 128);
        }
        return $out;
    }
}
