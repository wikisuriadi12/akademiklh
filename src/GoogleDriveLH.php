<?php
declare(strict_types=1);

/** OAuth Google Drive untuk satu akun yayasan; tidak memakai koneksi ChatGPT. */
final class GoogleDriveLH
{
    private const SCOPE = 'openid email https://www.googleapis.com/auth/drive.file';
    private const PREFIX = "<?php exit; ?>\n";

    public static function config(): array
    {
        $p = APP_ROOT . '/config_google_drive.php';
        return is_file($p) ? (array) require $p : [];
    }
    public static function siap(): bool
    {
        $c = self::config();
        return !empty($c['client_id']) && !empty($c['client_secret'])
            && !empty($c['redirect_uri']) && str_starts_with($c['redirect_uri'], 'https://')
            && function_exists('curl_init');
    }
    private static function folder(): string
    {
        $dir = Berkas::folder() . '/.google-drive';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new InvalidArgumentException('Penyimpanan koneksi Google Drive belum dapat dibuat.');
        }
        if (!is_file($dir . '/.htaccess') && @file_put_contents($dir . '/.htaccess', "Require all denied\n") === false) {
            throw new InvalidArgumentException('Proteksi penyimpanan koneksi Google Drive gagal.');
        }
        return $dir;
    }
    private static function state(): array
    {
        $p = self::folder() . '/connection.php';
        if (!is_file($p)) { return []; }
        $raw = (string) file_get_contents($p);
        if (!str_starts_with($raw, self::PREFIX)) { throw new InvalidArgumentException('Data koneksi Drive tidak valid.'); }
        return json_decode(substr($raw, strlen(self::PREFIX)), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function save(array $state): void
    {
        $p = self::folder() . '/connection.php';
        $tmp = $p . '.' . bin2hex(random_bytes(6)) . '.php';
        $bytes = self::PREFIX . json_encode($state, JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($tmp); throw new InvalidArgumentException('Koneksi Drive gagal disimpan.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $p)) { @unlink($tmp); throw new InvalidArgumentException('Koneksi Drive gagal disimpan.'); }
    }
    public static function locked(callable $fn)
    {
        $h = @fopen(self::folder() . '/connection.lock', 'c');
        if (!$h || !flock($h, LOCK_EX | LOCK_NB)) {
            if ($h) fclose($h);
            throw new InvalidArgumentException('Google Drive sedang diproses. Coba lagi sebentar.');
        }
        try { return $fn(); } finally { flock($h, LOCK_UN); fclose($h); }
    }
    public static function info(): array
    {
        $s = self::state();
        return ['email' => (string) ($s['email'] ?? ''), 'aktif' => !empty($s['refresh_token']),
            'folder_id' => (string) ($s['folder_id'] ?? '')];
    }
    public static function mulai(int $sid, int $uid): string
    {
        if (!self::siap()) { throw new InvalidArgumentException('Pengaturan Google Drive belum lengkap.'); }
        $c = self::config();
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $_SESSION['lh_drive_oauth'] = ['state' => $state, 'verifier' => $verifier,
            'sid' => $sid, 'uid' => $uid, 'expires' => time() + 600];
        unset($_SESSION['lh_drive_pending']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $c['client_id'], 'redirect_uri' => $c['redirect_uri'],
            'response_type' => 'code', 'scope' => self::SCOPE, 'access_type' => 'offline',
            'prompt' => 'consent select_account', 'state' => $state,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }
    public static function callback(string $code, string $state, int $uid, string $error): int
    {
        $o = $_SESSION['lh_drive_oauth'] ?? [];
        unset($_SESSION['lh_drive_oauth']);
        if (!$o || $state === '' || !hash_equals($o['state'], $state)
            || $o['uid'] !== $uid || $o['expires'] < time()) {
            throw new InvalidArgumentException('Sesi koneksi tidak valid atau kedaluwarsa. Klik Hubungkan Google Drive lagi.');
        }
        if ($error !== '' || $code === '') { throw new InvalidArgumentException('Izin Google Drive dibatalkan atau belum diberikan.'); }
        $c = self::config();
        $token = self::http('POST', 'https://oauth2.googleapis.com/token', [], http_build_query([
            'code' => $code, 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
            'redirect_uri' => $c['redirect_uri'], 'grant_type' => 'authorization_code', 'code_verifier' => $o['verifier'],
        ]), 'application/x-www-form-urlencoded');
        if (empty($token['access_token']) || empty($token['refresh_token'])
            || !in_array('https://www.googleapis.com/auth/drive.file', explode(' ', (string) ($token['scope'] ?? '')), true)) {
            throw new InvalidArgumentException('Izin penyimpanan Drive belum lengkap. Hubungkan ulang dan izinkan akses berkas.');
        }
        $u = self::http('GET', 'https://openidconnect.googleapis.com/v1/userinfo', ['Authorization: Bearer ' . $token['access_token']]);
        if (empty($u['email_verified']) || empty($u['email'])) { throw new InvalidArgumentException('Akun Google belum terverifikasi.'); }
        $allowed = strtolower(trim((string) ($c['email_yayasan'] ?? '')));
        $existing = self::state();
        if (($allowed !== '' && strtolower($u['email']) !== $allowed)
            || (!empty($existing['email']) && strtolower($existing['email']) !== strtolower($u['email']))) {
            throw new InvalidArgumentException('Akun berbeda dari akun yayasan yang ditetapkan. Pilih akun yayasan yang benar.');
        }
        $_SESSION['lh_drive_pending'] = ['uid' => $uid, 'sid' => $o['sid'], 'expires' => time()+600,
            'connection' => ['email' => $u['email'], 'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'], 'expires_at' => time()+(int) $token['expires_in'],
                'folder_id' => $existing['folder_id'] ?? '']];
        return (int) $o['sid'];
    }
    public static function konfirmasi(int $uid): void
    {
        self::locked(function () use ($uid) {
            $p = $_SESSION['lh_drive_pending'] ?? [];
            if (!$p || $p['uid'] !== $uid || $p['expires'] < time()) { throw new InvalidArgumentException('Konfirmasi akun kedaluwarsa. Hubungkan ulang.'); }
            $s = self::state();
            if (!empty($s['email']) && strtolower($s['email']) !== strtolower($p['connection']['email'])) {
                throw new InvalidArgumentException('Akun Drive sudah dihubungkan oleh admin lain.');
            }
            self::save($p['connection']);
            unset($_SESSION['lh_drive_pending']);
        });
    }
    private static function token(): string
    {
        $s = self::state();
        if (empty($s['refresh_token'])) { throw new InvalidArgumentException('Google Drive belum terhubung.'); }
        if (!empty($s['access_token']) && (int) $s['expires_at'] > time()+90) { return $s['access_token']; }
        $c = self::config();
        $r = self::http('POST', 'https://oauth2.googleapis.com/token', [], http_build_query([
            'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
            'refresh_token' => $s['refresh_token'], 'grant_type' => 'refresh_token',
        ]), 'application/x-www-form-urlencoded');
        if (empty($r['access_token'])) { throw new InvalidArgumentException('Koneksi Drive kedaluwarsa. Admin perlu menghubungkan ulang.'); }
        $s['access_token'] = $r['access_token']; $s['expires_at'] = time()+(int) $r['expires_in'];
        if (!empty($r['refresh_token'])) $s['refresh_token'] = $r['refresh_token'];
        self::save($s); return $s['access_token'];
    }
    private static function api(string $method, string $path, ?array $data = null): array
    {
        return self::http($method, 'https://www.googleapis.com/drive/v3/' . $path,
            ['Authorization: Bearer ' . self::token()], $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR));
    }
    private static function id(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { throw new InvalidArgumentException('ID Google Drive tidak valid.'); }
        return $id;
    }
    private static function rootFolder(): string
    {
        $s = self::state();
        if (empty($s['folder_id'])) {
            $q = "trashed = false and mimeType = 'application/vnd.google-apps.folder' and appProperties has { key='lh_root' and value='v1' }";
            $list = self::api('GET', 'files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]));
            $folder = $list['files'][0] ?? self::api('POST', 'files?fields=id', [
                'name' => 'YAYASAN LH - Berkas Santri', 'mimeType' => 'application/vnd.google-apps.folder',
                'appProperties' => ['lh_root' => 'v1'],
            ]);
            // Refresh token mungkin memperbarui state saat panggilan API.
            $s = self::state(); $s['folder_id'] = self::id((string) $folder['id']); self::save($s);
        }
        return self::id($s['folder_id']);
    }
    /** Dipanggil di dalam locked(); ID disimpan sebelum upload agar retry tidak menggandakan file. */
    public static function kirim(array $m, array $santri, callable $simpanMeta): array
    {
        $info = self::info();
        if (!empty($m['drive_email']) && strtolower($m['drive_email']) !== strtolower($info['email'])) {
            throw new InvalidArgumentException('Berkas ini terhubung ke akun Drive lain.');
        }
        if (empty($m['drive_id'])) {
            $ids = self::api('GET', 'files/generateIds?count=1&space=drive');
            $m['drive_id'] = self::id((string) ($ids['ids'][0] ?? ''));
            $m['drive_email'] = $info['email'];
            $simpanMeta($m);
        }
        $id = self::id($m['drive_id']);
        try {
            $exists = self::api('GET', 'files/' . $id . '?fields=id,trashed');
            if (!empty($exists['trashed'])) { throw new InvalidArgumentException('Salinan Drive ada di Sampah. Pulihkan salinan di Drive sebelum mengirim ulang.'); }
        } catch (InvalidArgumentException $ex) {
            if ($ex->getCode() !== 404) { throw $ex; }
            $root = self::rootFolder();
            $sid = (int) $m['santri_id'];
            $q = "trashed = false and '" . $root . "' in parents and mimeType = 'application/vnd.google-apps.folder' and appProperties has { key='lh_santri' and value='" . $sid . "' }";
            $list = self::api('GET', 'files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]));
            $folder = $list['files'][0] ?? self::api('POST', 'files?fields=id', [
                'name' => $santri['nis'] . ' - ' . $santri['nama'], 'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$root], 'appProperties' => ['lh_santri' => (string) $sid],
            ]);
            $file = Berkas::jalur($m['file']);
            if (!$file) { throw new InvalidArgumentException('Berkas lokal tidak ditemukan.'); }
            $boundary = 'lh_' . bin2hex(random_bytes(16));
            $metadata = ['id' => $id, 'name' => Berkas::labelSantri($m) . ' - ' . $m['file'],
                'parents' => [self::id($folder['id'])], 'appProperties' => ['lh_file' => $m['file']]];
            $body = '--' . $boundary . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
                . json_encode($metadata, JSON_THROW_ON_ERROR) . "\r\n--" . $boundary . "\r\nContent-Type: "
                . Berkas::mime($m['file']) . "\r\n\r\n" . file_get_contents($file) . "\r\n--" . $boundary . "--\r\n";
            self::http('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id',
                ['Authorization: Bearer ' . self::token()], $body, 'multipart/related; boundary=' . $boundary);
        }
        $m['drive_status'] = 'terkirim'; $m['drive_waktu'] = date('Y-m-d H:i:s'); unset($m['drive_error']);
        $simpanMeta($m); return $m;
    }
    private static function http(string $method, string $url, array $headers = [], ?string $body = null, string $type = 'application/json'): array
    {
        if (!function_exists('curl_init')) { throw new InvalidArgumentException('Ekstensi PHP cURL belum aktif.'); }
        $h = curl_init($url);
        if ($body !== null) $headers[] = 'Content-Type: ' . $type;
        curl_setopt_array($h, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($body !== null) curl_setopt($h, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($h); $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE); curl_close($h);
        if ($raw === false) { throw new InvalidArgumentException('Google Drive belum dapat dihubungi. Berkas lokal tetap tersimpan.'); }
        if ($code < 200 || $code >= 300) {
            $msg = match ($code) {
                400, 401 => 'Koneksi Google perlu diperiksa atau dihubungkan ulang oleh admin.',
                403 => 'Akses Drive ditolak. Periksa izin, aktivasi Drive API, dan ruang penyimpanan akun.',
                404 => 'Berkas atau folder Drive tidak ditemukan.',
                429 => 'Batas permintaan Drive tercapai. Coba lagi nanti.',
                default => 'Pengiriman Google Drive belum berhasil. Silakan coba lagi.',
            };
            throw new InvalidArgumentException($msg, $code);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) { throw new InvalidArgumentException('Respons Google Drive tidak valid.'); }
        return $data;
    }
}
