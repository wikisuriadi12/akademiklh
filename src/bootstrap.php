<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $c): void {
    $f = APP_ROOT . '/src/' . $c . '.php';
    if (is_file($f)) {
        require $f;
    }
});
require APP_ROOT . '/src/helpers.php';
