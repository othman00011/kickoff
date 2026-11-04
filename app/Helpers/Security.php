<?php

declare(strict_types=1);

final class Security
{
    private const CSRF_SESSION_KEY = '_csrf_token';

    public static function csrfToken(): string
    {
        self::requireActiveSession();

        if (!isset($_SESSION[self::CSRF_SESSION_KEY]) || !is_string($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_SESSION_KEY];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf_token" value="'
            . self::escapeHtml(self::csrfToken())
            . '">';
    }

    public static function validateCsrfToken(mixed $token): bool
    {
        self::requireActiveSession();

        return is_string($token)
            && isset($_SESSION[self::CSRF_SESSION_KEY])
            && is_string($_SESSION[self::CSRF_SESSION_KEY])
            && hash_equals($_SESSION[self::CSRF_SESSION_KEY], $token);
    }

    public static function validatePostToken(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        return self::validateCsrfToken($_POST['_csrf_token'] ?? null);
    }

    public static function escapeHtml(string|int|float|null $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function requireActiveSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An active session is required for CSRF token handling.');
        }
    }
}
