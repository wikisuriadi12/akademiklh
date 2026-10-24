<?php
declare(strict_types=1);

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rp($n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

/** Ringkas untuk grafik: 3,3 jt / 450 rb. */
function rp_short($n): string
{
    $n = (float) $n;
    if ($n >= 1000000) { return rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',') . ' jt'; }
    if ($n >= 1000) { return number_format($n / 1000, 0, ',', '.') . ' rb'; }
    return (string) (int) $n;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('Permintaan tidak valid (CSRF). Muat ulang halaman lalu coba lagi.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function in_str(string $k, string $d = ''): string
{
    $v = $_REQUEST[$k] ?? $d;
    return is_array($v) ? $d : trim((string) $v);
}

function options(array $list, string $selected = '', bool $assoc = true): string
{
    $h = '';
    foreach ($list as $k => $v) {
        $val = $assoc ? (string) $k : (string) $v;
        $h .= '<option value="' . e($val) . '"' . ($val === $selected ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $h;
}

function badge(string $status): string
{
    return '<span class="badge b-' . e($status) . '">' . e(Domain::STATUS[$status] ?? ucfirst($status)) . '</span>';
}

function status_tagihan(int $nominal, int $dibayar): string
{
    if ($dibayar >= $nominal) { return '<span class="badge b-lunas">Lunas</span>'; }
    if ($dibayar > 0) { return '<span class="badge b-sebagian">Sebagian</span>'; }
    return '<span class="badge b-belum">Belum</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim(preg_replace('/\([^)]*\)/u', '', $name))) ?: [];
    $s = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    if (count($parts) > 1) { $s .= mb_strtoupper(mb_substr(end($parts), 0, 1)); }
    return $s;
}

function avatar(string $name, string $unit = ''): string
{
    return '<span class="av ' . ($unit === 'akhwat' ? 'av-p' : 'av-l') . '">' . e(initials($name)) . '</span>';
}

/** Tautan WhatsApp (wa.me) dengan pesan; null bila nomor tidak layak. */
function wa_link(?string $hp, string $text): ?string
{
    $d = preg_replace('/\D/', '', (string) $hp);
    if ($d === '' || strlen($d) < 8) { return null; }
    if (str_starts_with($d, '0')) { $d = '62' . substr($d, 1); }
    elseif (str_starts_with($d, '8')) { $d = '62' . $d; }
    return 'https://wa.me/' . $d . '?text=' . rawurlencode($text);
}

/** Ikon SVG garis (gaya Feather). */
function icon(string $name, int $size = 18): string
{
    static $p = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
        'trending-up' => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'credit-card' => '<rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/>',
        'tag' => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.2"/>',
        'bar-chart' => '<path d="M12 20V10M18 20V4M6 20v-4"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'clipboard' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L21 2M16 7l3 3"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
        'printer' => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'message' => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.2A8.4 8.4 0 0 1 3 11.5 8.5 8.5 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>',
        'alert' => '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'wallet' => '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
        'cart' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'receipt' => '<path d="M4 2v20l3-2 2.5 2 2.5-2 2.5 2 2.5-2 3 2V2l-3 2-2.5-2-2.5 2-2.5-2L7 4z"/><path d="M8 9h8M8 13h8"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
        'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M17 11l2 2 4-4"/>',
        'arrow-right' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
    ];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function csv_out(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, $header, ',', '"', '\\');
    foreach ($rows as $r) {
        // Cegah injeksi rumus spreadsheet.
        $r = array_map(static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@") !== false ? "'" . $v : $v, $r);
        fputcsv($o, $r, ',', '"', '\\');
    }
    exit;
}

/** Bungkus konten dengan layout halaman. $public = tampilan tanpa menu (login, pendaftaran, portal wali). */
function render(string $title, string $content, $public = false): void
{
    $cfg = is_file(APP_ROOT . '/config.php') ? require APP_ROOT . '/config.php' : ['app_name' => 'SPP & Data Santri'];
    $user = null;
    try { $user = Auth::user(); } catch (Throwable $e) { $user = null; }
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    $lembaga = Settings::get('nama_lembaga', 'Yayasan Luqmanul Hakim');
    require APP_ROOT . '/pages/_layout.php';
}
