<?php
namespace Tests;

// Тонкая обёртка над mysqli для сидинга и проверок в тестах
class Db
{
    private static ?\mysqli $conn = null;

    public static function connect(): void
    {
        if (self::$conn instanceof \mysqli) {
            return;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        self::$conn = new \mysqli(
            getenv('DB_HOST') ?: 'db',
            getenv('DB_USER') ?: 'testdb',
            getenv('DB_PASSWORD') ?: '',
            getenv('DB_NAME') ?: 'testdb'
        );
        if (self::$conn->connect_errno) {
            throw new \RuntimeException('Test DB connect failed: ' . self::$conn->connect_error);
        }
        self::$conn->set_charset('utf8mb4');
    }

    public static function conn(): \mysqli
    {
        self::connect();
        return self::$conn;
    }

    public static function exec(string $sql): void
    {
        if (!self::conn()->query($sql)) {
            throw new \RuntimeException('SQL failed: ' . self::conn()->error . ' | ' . $sql);
        }
    }

    /** Первое значение первой строки или null */
    public static function scalar(string $sql)
    {
        $res = self::conn()->query($sql);
        if (!$res) {
            throw new \RuntimeException('SQL failed: ' . self::conn()->error . ' | ' . $sql);
        }
        $row = $res->fetch_row();
        return $row ? $row[0] : null;
    }

    public static function quote(string $v): string
    {
        return "'" . self::conn()->real_escape_string($v) . "'";
    }

    public static function userId(string $login): ?int
    {
        $id = self::scalar('SELECT ID_NL_USER FROM NL_USER WHERE NL_USER_LOGIN = ' . self::quote($login));
        return $id === null ? null : (int) $id;
    }

    // Создаёт/пересоздаёт пользователя с известным паролем в формате приложения
    public static function seedUser(string $login, string $password, int $permission, string $short): int
    {
        $existing = self::userId($login);
        if ($existing !== null) {
            self::exec('DELETE FROM NL_LOG WHERE ID_NL_USER = ' . $existing);
            self::exec('DELETE FROM NL_USER WHERE ID_NL_USER = ' . $existing);
        }
        $pwSql = db_password_sql($password); // из php/functions.php
        self::exec(
            'INSERT INTO NL_USER (ID_NL_USER_PERMISSION, NL_USER_LOGIN, NL_USER_PASSWORD, NL_USER_SHORT, NL_USER_FULL) VALUES ('
            . $permission . ', ' . self::quote($login) . ', ' . $pwSql . ', ' . self::quote($short) . ', ' . self::quote($short) . ')'
        );
        return (int) self::scalar('SELECT LAST_INSERT_ID()');
    }
}
