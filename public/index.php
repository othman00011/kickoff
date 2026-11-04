<?php

declare(strict_types=1);

$initialAppEnvironment = getenv('APP_ENV');
error_reporting(E_ALL);
ini_set('display_errors', $initialAppEnvironment === 'development' ? '1' : '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$isHttps = false;
$sendSecurityHeaders = static function (bool $https): void {
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'");
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
};

try {
    $envFile = dirname(__DIR__) . '/.env';
    if (is_file($envFile)) {
        $envLines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($envLines === false) {
            throw new RuntimeException('Unable to read the project .env file.');
        }

        foreach ($envLines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new RuntimeException(sprintf('Invalid .env entry on line %d.', $lineNumber + 1));
            }

            $name = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new RuntimeException(sprintf('Invalid .env variable name on line %d.', $lineNumber + 1));
            }

            if (strlen($value) >= 2) {
                $firstCharacter = $value[0];
                $lastCharacter = $value[strlen($value) - 1];
                if (($firstCharacter === '"' && $lastCharacter === '"')
                    || ($firstCharacter === "'" && $lastCharacter === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (getenv($name) === false && !array_key_exists($name, $_ENV) && !array_key_exists($name, $_SERVER)) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    $appEnvironment = getenv('APP_ENV');
    ini_set('display_errors', $appEnvironment === 'development' ? '1' : '0');

    require_once dirname(__DIR__) . '/core/App.php';

    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $trustedProxies = array_filter(
        array_map('trim', explode(',', getenv('TRUSTED_PROXIES') ?: '')),
        static fn (string $proxy): bool => $proxy !== ''
    );
    $isTrustedProxy = is_string($remoteAddress) && in_array($remoteAddress, $trustedProxies, true);
    $forwardedHttps = $isTrustedProxy
        && strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    $serverHttps = strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== ''
        && strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== 'off';
    $isHttps = $serverHttps || getenv('APP_FORCE_HTTPS') === '1' || $forwardedHttps;
    $sendSecurityHeaders($isHttps);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('KICKOFFSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Unable to start the application session.');
        }
    }

    $now = time();
    $lastActivity = $_SESSION['_last_activity'] ?? null;
    if (!is_int($lastActivity) || $now - $lastActivity > 7200 || $lastActivity > $now) {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate an expired session ID.');
        }
    }
    $_SESSION['_last_activity'] = $now;

    (new App())->run();
} catch (Throwable $exception) {
    restore_error_handler();
    error_log(sprintf(
        'Unhandled %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    while (ob_get_level() > 0) {
        if (!ob_end_clean()) {
            break;
        }
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        $sendSecurityHeaders($isHttps);
    }

    try {
        require_once dirname(__DIR__) . '/core/Controller.php';
        $errorController = new class extends Controller {
            public function showError(): void
            {
                $this->render('errors/500', [], 500);
            }
        };
        $errorController->showError();
    } catch (Throwable) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo '500 Internal Server Error';
    }
}
