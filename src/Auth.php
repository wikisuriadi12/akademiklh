<?php
declare(strict_types=1);

final class Auth
{
    private const PERMS = [
        'santri.view' => ['admin', 'tu', 'bendahara', 'pimpinan', 'ustadz', 'pembina'],
        'santri.edit' => ['admin', 'tu'],
        'tarif.view' => ['admin', 'bendahara', 'pimpinan'],
        'tarif.edit' => ['admin', 'bendahara'],
        'tagihan.view' => ['admin', 'bendahara', 'pimpinan'],
        'tagihan.generate' => ['admin', 'bendahara'],
        'bayar' => ['admin', 'bendahara'],
        'laporan' => ['admin', 'bendahara', 'pimpinan'],
        'users' => ['admin'],
        'pendaftar' => ['admin', 'tu'],
        'naik' => ['admin', 'tu'],
        'impor' => ['admin', 'tu'],
        'pengaturan' => ['admin'],
        'audit' => ['admin'],
        'pos' => ['admin', 'kasir'],
        'gudang.view' => ['admin', 'gudang', 'kasir', 'pimpinan'],
        'gudang.edit' => ['admin', 'gudang'],
        'dompet' => ['admin', 'kasir', 'bendahara'],
        'dompet.koreksi' => ['admin', 'bendahara'],
        'tahfidz.view' => ['admin', 'ustadz', 'pembina', 'pimpinan', 'tu'],
        'tahfidz.edit' => ['admin', 'ustadz'],
        'hadir.view' => ['admin', 'ustadz', 'pembina', 'pimpinan', 'tu'],
        'hadir.edit' => ['admin', 'ustadz', 'pembina'],
        'izin.view' => ['admin', 'pembina', 'ustadz', 'pimpinan', 'tu'],
        'izin.edit' => ['admin', 'pembina'],
        'va' => ['admin', 'bendahara', 'pimpinan'],
        'va.edit' => ['admin', 'bendahara'],
        'penjualan' => ['admin', 'kasir', 'bendahara', 'pimpinan'],
    ];

    public const IDLE = 7200;

    public static bool $ipBlocked = false;

    private static ?array $cache = null;

    public static function user(): ?array
    {
        if (self::$cache !== null) {
            return self::$cache ?: null;
        }
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            self::$cache = [];
            return null;
        }
        $u = Db::one('SELECT id, username, nama, role, unit_akses, aktif, session_version FROM users WHERE id = ?', [$id]);
        // Sesi tanpa aktivitas lebih dari 2 jam berakhir otomatis.
        if (isset($_SESSION['last']) && time() - (int) $_SESSION['last'] > self::IDLE) {
            $u = null;
        } else {
            $_SESSION['last'] = time();
        }
        if (!$u || !(int) $u['aktif'] || (int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? 0)) {
            unset($_SESSION['uid'], $_SESSION['sv'], $_SESSION['sistem'], $_SESSION['last']);
            self::$cache = [];
            return null;
        }
        self::$cache = $u;
        return $u;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    /** Cakupan unit akun: null = semua unit. */
    public static function scope(): ?string
    {
        $u = self::user();
        return $u && $u['unit_akses'] !== 'semua' ? $u['unit_akses'] : null;
    }

    public static function can(string $perm): bool
    {
        $u = self::user();
        return $u !== null && in_array($u['role'], self::PERMS[$perm] ?? [], true);
    }

    public static function require(string $perm): void
    {
        if (!self::user()) {
            redirect(Host::masuk());
        }
        if (!self::can($perm)) {
            http_response_code(403);
            render('Akses ditolak', '<div class="card"><h2>Akses ditolak</h2><p>Peran akun Anda tidak boleh membuka halaman ini.</p></div>');
            exit;
        }
    }

    public static function assertUnit(string $unit): void
    {
        $s = self::scope();
        if ($s !== null && $s !== $unit) {
            http_response_code(403);
            render('Akses ditolak', '<div class="card"><h2>Akses ditolak</h2><p>Data ini di luar cakupan unit akun Anda.</p></div>');
            exit;
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $_SESSION['try'] = $_SESSION['try'] ?? ['n' => 0, 'until' => 0];
        if ($_SESSION['try']['until'] > time()) {
            return false;
        }
        // Batas per alamat IP (tidak bisa dihindari dengan membuang cookie sesi).
        self::$ipBlocked = !RateLimit::hit('login', RateLimit::ip(), 20, 600);
        if (self::$ipBlocked) {
            return false;
        }
        $u = Db::one('SELECT id, password_hash, aktif, session_version FROM users WHERE username = ?', [$username]);
        // Tetap verifikasi hash walau user tidak ada agar waktu respons seragam.
        $hash = $u['password_hash'] ?? password_hash('dummy', PASSWORD_DEFAULT);
        $ok = password_verify($password, $hash) && $u && (int) $u['aktif'] === 1;
        if (!$ok) {
            $_SESSION['try']['n']++;
            if ($_SESSION['try']['n'] >= 5) {
                $_SESSION['try'] = ['n' => 0, 'until' => time() + 60];
            }
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['try'] = ['n' => 0, 'until' => 0];
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['sv'] = (int) $u['session_version'];
        $_SESSION['last'] = time();
        self::$cache = null;
        Db::audit((int) $u['id'], 'login', 'users', (int) $u['id']);
        return true;
    }

    /** Sistem yang sedang dimasuki (dipilih saat login). */
    public static function sistem(): ?string
    {
        $s = $_SESSION['sistem'] ?? null;
        $u = self::user();
        return ($u && is_string($s) && isset(Domain::SISTEM[$s]) && Domain::sistemBoleh($s, $u['role'])) ? $s : null;
    }

    public static function lockedSeconds(): int
    {
        return max(0, (int) (($_SESSION['try']['until'] ?? 0) - time()));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        self::$cache = null;
    }

    public static function changePassword(int $uid, string $old, string $new): void
    {
        $u = Db::one('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!$u || !password_verify($old, $u['password_hash'])) {
            throw new InvalidArgumentException('Password lama salah.');
        }
        self::validatePassword($new);
        Db::exec('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
        $sv = (int) Db::val('SELECT session_version FROM users WHERE id = ?', [$uid]);
        $_SESSION['sv'] = $sv; // sesi lain otomatis tidak berlaku
        self::$cache = null;
        Db::audit($uid, 'ganti_password', 'users', $uid);
    }

    public static function validatePassword(string $p): void
    {
        if (mb_strlen($p) < 10) {
            throw new InvalidArgumentException('Password minimal 10 karakter.');
        }
    }

    public static function createUser(string $username, string $nama, string $role, string $unit, string $password, ?int $by): int
    {
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            throw new InvalidArgumentException('Username 3-50 karakter: huruf kecil, angka, titik, strip, garis bawah.');
        }
        if ($nama === '' || !isset(Domain::ROLE[$role]) || !in_array($unit, ['semua', 'ikhwan', 'akhwat'], true)) {
            throw new InvalidArgumentException('Data pengguna tidak lengkap.');
        }
        self::validatePassword($password);
        if (Db::val('SELECT id FROM users WHERE username = ?', [$username])) {
            throw new InvalidArgumentException('Username sudah dipakai.');
        }
        $id = Db::insert(
            'INSERT INTO users (username, password_hash, nama, role, unit_akses, aktif, session_version, created_at) VALUES (?,?,?,?,?,1,1,?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $nama, $role, $unit, date('Y-m-d H:i:s')]
        );
        Db::audit($by, 'tambah', 'users', $id, "$username $role $unit");
        return $id;
    }

    /** Admin mengganti data akun. Tidak boleh mengunci diri sendiri atau menghapus admin aktif terakhir. */
    public static function updateUser(int $id, string $nama, string $role, string $unit, ?int $by): void
    {
        $u = Db::one('SELECT id, role, aktif FROM users WHERE id = ?', [$id]);
        if (!$u) { throw new InvalidArgumentException('Akun tidak ditemukan.'); }
        if ($nama === '' || !isset(Domain::ROLE[$role]) || !in_array($unit, ['semua', 'ikhwan', 'akhwat'], true)) {
            throw new InvalidArgumentException('Data pengguna tidak lengkap.');
        }
        if ($u['role'] === 'admin' && $role !== 'admin') {
            if ($id === $by) { throw new InvalidArgumentException('Tidak dapat menurunkan peran akun sendiri.'); }
            if (self::adminAktifLain($id) < 1) { throw new InvalidArgumentException('Harus tersisa minimal satu admin aktif.'); }
        }
        Db::exec('UPDATE users SET nama = ?, role = ?, unit_akses = ?, session_version = session_version + 1 WHERE id = ?', [$nama, $role, $unit, $id]);
        if ($id === $by) { $_SESSION['sv'] = (int) Db::val('SELECT session_version FROM users WHERE id = ?', [$id]); self::$cache = null; }
        Db::audit($by, 'ubah', 'users', $id, "$role $unit");
    }

    /** Admin mereset password petugas; semua sesi akun itu berakhir. */
    public static function resetPassword(int $id, string $baru, ?int $by): void
    {
        if (!Db::val('SELECT id FROM users WHERE id = ?', [$id])) { throw new InvalidArgumentException('Akun tidak ditemukan.'); }
        self::validatePassword($baru);
        Db::exec('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), $id]);
        if ($id === $by) { $_SESSION['sv'] = (int) Db::val('SELECT session_version FROM users WHERE id = ?', [$id]); self::$cache = null; }
        Db::audit($by, 'reset_password', 'users', $id);
    }

    public static function adminAktifLain(int $exceptId): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND aktif = 1 AND id <> ?", [$exceptId]);
    }

    public static function lastLogin(int $id): ?string
    {
        $v = Db::val("SELECT MAX(created_at) FROM audit_log WHERE aksi = 'login' AND objek = 'users' AND objek_id = ?", [$id]);
        return $v ?: null;
    }
}
