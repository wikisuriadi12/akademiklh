<?php
declare(strict_types=1);

final class Domain
{
    public const JENJANG = ['TKQ', 'Ula', 'Wustha', 'Ulya'];
    public const UNIT = ['ikhwan' => 'Ikhwan', 'akhwat' => 'Akhwat'];
    public const STATUS = ['calon' => 'Calon', 'aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'lulus' => 'Lulus', 'pindah' => 'Pindah', 'ditolak' => 'Ditolak'];
    public const ROLE = ['admin' => 'Admin', 'tu' => 'Tata Usaha (data santri)', 'bendahara' => 'Bendahara (SPP)', 'pimpinan' => 'Pimpinan (baca saja)', 'kasir' => 'Kasir koperasi (POS)', 'gudang' => 'Petugas gudang', 'ustadz' => 'Ustadz / Muallim', 'pembina' => 'Pembina asrama (musyrif)'];
    public const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** Sistem terpisah: masing-masing punya pintu masuk (login) dan menu sendiri. */
    public const SISTEM = [
        'pendidikan' => ['nama' => 'Sistem Pendidikan', 'desc' => 'Data santri, tahfidz, kehadiran, perizinan, pendaftaran, tagihan SPP, dan laporan untuk TKQ, Ula, Wustha, Ulya.', 'icon' => 'book', 'home' => 'dashboard', 'roles' => ['admin', 'tu', 'bendahara', 'pimpinan', 'ustadz', 'pembina'], 'untuk' => 'Admin, TU, Bendahara, Pimpinan, Ustadz, Pembina'],
        'pos' => ['nama' => 'LH Mart & Gudang', 'desc' => 'Kasir LH Mart, dompet uang saku santri, penjualan, dan stok gudang yang terhubung otomatis.', 'icon' => 'cart', 'home' => 'pos', 'roles' => ['admin', 'kasir', 'gudang', 'bendahara', 'pimpinan'], 'untuk' => 'Kasir, Petugas gudang, Bendahara, Pimpinan, Admin'],
    ];

    /** Halaman milik tiap sistem (dipakai untuk membatasi subdomain). */
    public const HALAMAN = [
        'pendidikan' => ['dashboard', 'santri', 'foto', 'riwayat', 'dokumen', 'santri_form', 'santri_detail', 'santri_lengkap', 'pendaftar', 'naik', 'impor', 'tarif', 'tagihan', 'bayar', 'kuitansi', 'laporan',
            'tahfidz', 'kehadiran', 'izin', 'va', 'va_baru', 'va_detail', 'pengaturan', 'audit', 'users', 'setup'],
        'pos' => ['pos', 'pos_cari', 'struk', 'penjualan', 'dompet', 'gudang', 'gudang_item', 'barang', 'akun_pos', 'label', 'impor_stok', 'barang_masuk', 'laporan_pos', 'bukti_qris', 'qris_buat', 'qris_status'],
        'wali' => ['cek', 'daftar'],
    ];
    public const HALAMAN_UMUM = ['login', 'logout', 'akun', 'bni_callback', 'qris_callback', 'captcha'];

    /** Halaman awal sesuai sistem dan peran (petugas gudang langsung ke stok). */
    public static function home(string $sistem, string $role): string
    {
        if ($sistem === 'pos' && $role === 'gudang') { return 'gudang'; }
        if ($sistem === 'pos' && $role === 'pimpinan') { return 'penjualan'; }
        return self::SISTEM[$sistem]['home'] ?? 'dashboard';
    }

    public static function sistemBoleh(string $sistem, string $role): bool
    {
        return in_array($role, self::SISTEM[$sistem]['roles'] ?? [], true);
    }

    public static function validPeriode(string $p): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p);
    }

    /** Tahun ajaran berjalan Juli-Juni: 2026-10 => 2026/2027, 2027-03 => 2026/2027. */
    public static function tahunAjaran(string $periode): string
    {
        [$y, $m] = array_map('intval', explode('-', $periode));
        return $m >= 7 ? $y . '/' . ($y + 1) : ($y - 1) . '/' . $y;
    }

    public static function namaPeriode(string $periode): string
    {
        [$y, $m] = array_map('intval', explode('-', $periode));
        return self::BULAN[$m] . ' ' . $y;
    }

    public static function namaHari(string $tgl): string
    {
        $t = strtotime($tgl);
        return ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w', $t)] . ', ' . (int) date('j', $t) . ' ' . self::BULAN[(int) date('n', $t)] . ' ' . date('Y', $t);
    }

    public static function validDate(string $d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}
