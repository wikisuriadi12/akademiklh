<?php
// Cron (tiap jam): php tools/bni_expire.php  -- menandai VA yang lewat masa berlaku.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
date_default_timezone_set('Asia/Jakarta');
echo Va::kedaluwarsa() . " VA ditandai kedaluwarsa.\n";
