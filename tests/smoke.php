<?php
// Uji asap tampilan: php tests/smoke.php <halaman> [id=..&k=v] [post]
// Memakai SQLite memori + data contoh, login sebagai admin (kecuali halaman publik).
declare(strict_types=1);
require __DIR__ . '/bootstrap_db.php';
require __DIR__ . '/seed.php';
error_reporting(E_ALL);
ini_set('display_errors', '1');

$page = $argv[1] ?? 'dashboard';
parse_str($argv[2] ?? '', $get);
$post = isset($argv[3]) ? $argv[3] : '';

test_db();
seed_demo();
if (getenv('ROLE')) { Db::exec('UPDATE users SET role = ? WHERE id = 1', [getenv('ROLE')]); }
// Placeholder: {NIS:3} = NIS santri id 3; {HP4:3} = 4 digit terakhir HP wali santri id 3.
$post = preg_replace_callback('/\{(NIS|HP4):(\d+)\}/', static function ($m) {
    $s = Santri::find((int) $m[2]);
    return $m[1] === 'NIS' ? $s['nis'] : substr(preg_replace('/\D/', '', $s['hp_wali']), -4);
}, $post);
if (getenv('HOST')) { Host::$override = ['domain' => 'contoh.sch.id', 'subdomain' => ['pendidikan' => 'akademik', 'pos' => 'pos', 'wali' => 'wali']]; $_SERVER['HTTP_HOST'] = getenv('HOST'); }
$public = in_array($page, ['login', 'cek', 'daftar', 'portal'], true);
$_SESSION = $public ? [] : ['uid' => 1, 'sv' => 1];
if (!$public && getenv('SISTEM')) { $_SESSION['sistem'] = getenv('SISTEM'); }
$_SERVER['REQUEST_METHOD'] = $post !== '' ? 'POST' : 'GET';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_GET = ['p' => $page] + $get;
if ($post !== '') {
    parse_str($post, $_POST);
    $_SESSION['csrf'] = 'tok';
    $_POST['_csrf'] = 'tok';
}
$_REQUEST = array_merge($_GET, $_POST ?? []);
if ($page === 'daftar' && $post !== '') { $_SESSION['daftar_ts'] = time() - 30; }

// Halaman yang berakhir dengan redirect() memanggil exit; tampilkan flash saat shutdown.
register_shutdown_function(static function () use ($page, $post) {
    if ($post === '') { return; }
    while (ob_get_level() > 0) { ob_end_clean(); }
    foreach (($_SESSION['flash'] ?? []) as [$t, $m]) { echo "  [$page flash:$t] $m\n"; }
    if (empty($GLOBALS['__selesai'])) { echo "  [$page] redirect/exit\n"; }
});
// tangkap redirect (header + exit) lewat output buffering
ob_start();
try {
    include APP_ROOT . '/pages/' . $page . '.php';
} catch (Throwable $e) {
    ob_end_clean();
    echo 'FAIL  ' . $page . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
}
$out = ob_get_clean();
$GLOBALS['__selesai'] = true;
if ($post !== '') {
    foreach (($_SESSION['flash'] ?? []) as [$t, $m]) { echo "  [$page flash:$t] $m\n"; }
}
if (getenv("DUMP")) { file_put_contents(getenv("DUMP"), $out); }
$bad = preg_match('/(Warning:|Notice:|Fatal error|Deprecated:|Parse error|Undefined (variable|index|array key|property))/', $out) === 1;
$okHtml = stripos($out, '<html') !== false || $post !== '';
if ($post !== "" && $page === "login") { echo preg_match("/Akun ini tidak punya akses[^<]*/", $out, $mm) ? "  [deny] " . $mm[0] . "\n" : "  [no deny]\n"; }
echo ($bad || !$okHtml ? 'FAIL' : 'PASS') . "  $page" . ($post !== '' ? ' (POST)' : '') . ' (' . strlen($out) . " byte)\n";
if ($bad) { echo substr(strip_tags($out), 0, 700) . "\n"; }
