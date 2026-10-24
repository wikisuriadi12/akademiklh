<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = require APP_ROOT . '/config.php';
            $dsn = "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $p = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $p = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $p = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $p = []): int
    {
        self::exec($sql, $p);
        return (int) self::pdo()->lastInsertId();
    }

    /** Tambahan penguncian baris; hanya untuk MySQL. */
    public static function forUpdate(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function audit(?int $userId, string $aksi, string $objek, ?int $objekId, string $detail = ''): void
    {
        self::exec(
            'INSERT INTO audit_log (user_id, aksi, objek, objek_id, detail, created_at) VALUES (?,?,?,?,?,?)',
            [$userId, $aksi, $objek, $objekId, $detail, date('Y-m-d H:i:s')]
        );
    }
}
