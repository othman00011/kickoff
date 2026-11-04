<?php

declare(strict_types=1);

require_once __DIR__ . '/Controller.php';

final class App
{
    public function run(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path)) {
            $this->notFound();
            return;
        }

        $trimmedPath = trim($path, '/');
        $segments = $trimmedPath === '' ? [] : array_map('rawurldecode', explode('/', $trimmedPath));
        foreach ($segments as $segment) {
            if (str_contains($segment, '/') || str_contains($segment, "\0")) {
                $this->notFound();
                return;
            }
        }

        $controllerSlug = strtolower($segments[0] ?? 'home');
        $action = $segments[1] ?? 'index';
        $arguments = array_slice($segments, 2);

        if (!preg_match('/^[a-z][a-z0-9]*$/', $controllerSlug)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $action)) {
            $this->notFound();
            return;
        }

        $controllerClass = ucfirst($controllerSlug) . 'Controller';
        $controllerFile = dirname(__DIR__) . '/app/Controllers/' . $controllerClass . '.php';
        if (!is_file($controllerFile)) {
            $this->notFound();
            return;
        }

        require_once $controllerFile;
        if (!class_exists($controllerClass, false) || !is_subclass_of($controllerClass, Controller::class)) {
            $this->notFound();
            return;
        }

        if (!method_exists($controllerClass, $action)) {
            $this->notFound();
            return;
        }

        $method = new ReflectionMethod($controllerClass, $action);
        if (!$method->isPublic()
            || $method->isStatic()
            || $method->isConstructor()
            || $method->getDeclaringClass()->getName() === Controller::class) {
            $this->notFound();
            return;
        }

        if (!self::argumentsAreValid($method, $arguments)) {
            $this->notFound();
            return;
        }

        $controller = new $controllerClass();
        try {
            $method->invokeArgs($controller, $arguments);
        } catch (ArgumentCountError|TypeError $exception) {
            if ($exception->getFile() === __FILE__) {
                $this->notFound();
                return;
            }

            throw $exception;
        }
    }

    private function notFound(): void
    {
        $notFoundController = new class extends Controller {
            public function showNotFound(): void
            {
                $this->notFound();
            }
        };
        $notFoundController->showNotFound();
    }

    /** @param list<string> $arguments */
    public static function argumentsAreValid(ReflectionMethod $method, array $arguments): bool
    {
        $required = $method->getNumberOfRequiredParameters();
        $maximum = $method->isVariadic() ? PHP_INT_MAX : $method->getNumberOfParameters();
        if (count($arguments) < $required || count($arguments) > $maximum) {
            return false;
        }

        $parameters = $method->getParameters();
        foreach ($arguments as $index => $argument) {
            if (!is_string($argument)
                || strlen($argument) > 200
                || preg_match('/[\x00-\x1F\x7F]/', $argument) === 1) {
                return false;
            }
            $parameter = $parameters[$index] ?? null;
            if ($parameter === null || $parameter->isVariadic()) {
                continue;
            }

            $type = $parameter->getType();
            if ($type !== null && !($type instanceof ReflectionNamedType)) {
                return false;
            }
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                if ($type->getName() !== 'string') {
                    return false;
                }
            }
            if ($type instanceof ReflectionNamedType && $type->getName() !== 'string') {
                return false;
            }
        }

        return true;
    }
}
