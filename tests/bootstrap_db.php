<?php
// Membuat basis data SQLite memori dari tests/sqlite_schema.sql dan memasangnya ke Db.
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
date_default_timezone_set('Asia/Jakarta');

function test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec((string) file_get_contents(__DIR__ . '/sqlite_schema.sql'));
    Db::set($pdo);
    Settings::reset();
    return $pdo;
}
