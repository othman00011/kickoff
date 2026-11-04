<?php

declare(strict_types=1);

abstract class Controller
{
    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = [], int $code = 200): void
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException('HTTP status code must be between 100 and 599.');
        }
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~', $view)) {
            throw new InvalidArgumentException('View path contains invalid characters.');
        }
        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf('Cannot render a view; headers were already sent in %s:%d.', $file, $line));
        }

        $viewsDirectory = dirname(__DIR__) . '/views';
        $header = $viewsDirectory . '/layouts/header.php';
        $viewFile = $viewsDirectory . '/' . $view . '.php';
        $footer = $viewsDirectory . '/layouts/footer.php';

        foreach ([$header, $viewFile, $footer] as $filePath) {
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new RuntimeException(sprintf('Required view file is missing or unreadable: %s.', $filePath));
            }
        }

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            extract($data, EXTR_SKIP);
            require $header;
            require $viewFile;
            require $footer;
            $html = ob_get_clean();
            if (!is_string($html)) {
                throw new RuntimeException('Unable to collect rendered view output.');
            }
        } catch (Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $exception;
        }

        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
    }

    /**
     * @param 'success'|'error'|'info' $type
     */
    protected function flash(string $type, string $message): void
    {
        if (!in_array($type, ['success', 'error', 'info'], true)) {
            throw new InvalidArgumentException('Unsupported flash message type.');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An active session is required for flash messages.');
        }

        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type: string, message: string}|null */
    protected function consumeFlash(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An active session is required for flash messages.');
        }

        $message = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        if (!is_array($message)
            || !isset($message['type'], $message['message'])
            || !is_string($message['type'])
            || !is_string($message['message'])) {
            return null;
        }

        return $message;
    }

    protected function redirect(string $location, int $code = 303): never
    {
        if (!preg_match('~^/(?!/)[^\r\n]*$~', $location)) {
            throw new InvalidArgumentException('Redirect location must be a local absolute path.');
        }
        if (!in_array($code, [302, 303, 307, 308], true)) {
            throw new InvalidArgumentException('Unsupported redirect status code.');
        }
        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf('Cannot redirect; headers were already sent in %s:%d.', $file, $line));
        }

        http_response_code($code);
        header('Location: ' . $location);
        exit;
    }

    protected function parsePositiveId(string $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $label));
        }

        return $id;
    }

    protected function notFound(string $message = ''): void
    {
        $this->render('errors/404', ['errorMessage' => $message], 404);
    }

    /**
     * @param list<string> $allowed
     */
    protected function methodNotAllowed(array $allowed): void
    {
        if ($allowed === []) {
            throw new InvalidArgumentException('At least one allowed HTTP method is required.');
        }

        foreach ($allowed as $method) {
            if (!is_string($method) || !preg_match('/^[A-Z]+$/', $method)) {
                throw new InvalidArgumentException('Allowed HTTP methods must be uppercase method names.');
            }
        }
        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf('Cannot send a 405 response; headers were already sent in %s:%d.', $file, $line));
        }

        header('Allow: ' . implode(', ', $allowed));
        http_response_code(405);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '405 Method Not Allowed';
    }
}
