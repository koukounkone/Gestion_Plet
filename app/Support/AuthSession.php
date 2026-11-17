<?php
declare(strict_types=1);

namespace MonElevage\Support;

use PDO;

final class AuthSession
{
    private const IDLE_TIMEOUT = 1800;
    private const ABSOLUTE_TIMEOUT = 28800;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::cookiePath(),
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function userId(PDO $pdo): ?int
    {
        self::start();
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $authenticatedAt = filter_var($_SESSION['authenticated_at'] ?? null, FILTER_VALIDATE_INT);
        $lastActivityAt = filter_var($_SESSION['last_activity_at'] ?? null, FILTER_VALIDATE_INT);
        $now = time();

        if ($userId === false || $userId === null
            || $authenticatedAt === false || $authenticatedAt === null
            || $lastActivityAt === false || $lastActivityAt === null
            || $now - $lastActivityAt > self::IDLE_TIMEOUT
            || $now - $authenticatedAt > self::ABSOLUTE_TIMEOUT) {
            if (isset($_SESSION['user_id'])) {
                self::logout();
            }
            return null;
        }

        $statement = $pdo->prepare(
            "SELECT id FROM users WHERE id = :id AND status = 'active' LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
        if ($statement->fetchColumn() === false) {
            self::logout();
            return null;
        }

        $_SESSION['last_activity_at'] = $now;
        return (int) $userId;
    }

    public static function login(int $userId): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION = [
            'user_id' => $userId,
            'authenticated_at' => time(),
            'last_activity_at' => time(),
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
    }

    public static function rotate(): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function csrfToken(): string
    {
        self::start();
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(mixed $candidate): bool
    {
        return is_string($candidate)
            && $candidate !== ''
            && hash_equals(self::csrfToken(), $candidate);
    }

    public static function flash(string $kind, string $message): void
    {
        self::start();
        $_SESSION['_flash'] = ['kind' => $kind, 'message' => $message];
    }

    public static function takeFlash(): ?array
    {
        self::start();
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        return is_array($flash) ? $flash : null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    private static function cookiePath(): string
    {
        if (PHP_SAPI === 'cli') {
            return '/';
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
        $directory = str_replace('\\', '/', dirname($scriptName));
        return $directory === '/' || $directory === '.' ? '/' : $directory . '/';
    }

    private static function isHttps(): bool
    {
        return isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== ''
            && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }
}
