<?php

declare(strict_types=1);

final class Database
{
    private static ?self $instance = null;

    /** @var array{host: string, port: int, dbname: string, user: string, password: string} */
    private array $config;

    private ?PDO $connection = null;

    private function __construct()
    {
        $config = require dirname(__DIR__) . '/config/database.php';
        if (!is_array($config)) {
            throw new RuntimeException('Database configuration must return an array.');
        }

        foreach (['host', 'port', 'dbname', 'user'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException(sprintf('Missing database configuration value: %s.', $key));
            }
        }

        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $dbname = $config['dbname'] ?? null;
        $user = $config['user'] ?? null;
        $password = $config['password'] ?? '';

        if (!is_string($host) || trim($host) === '') {
            throw new RuntimeException('DB_HOST must be a non-empty string.');
        }
        if (!is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('DB_PORT must be an integer between 1 and 65535.');
        }
        if (!is_string($dbname) || trim($dbname) === '') {
            throw new RuntimeException('DB_NAME must be a non-empty string.');
        }
        if (!is_string($user) || trim($user) === '') {
            throw new RuntimeException('DB_USER must be a non-empty string.');
        }
        if (!is_string($password)) {
            throw new RuntimeException('DB_PASSWORD must be a string.');
        }

        $this->config = [
            'host' => $host,
            'port' => (int) $port,
            'dbname' => $dbname,
            'user' => $user,
            'password' => $password,
        ];
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['dbname']
            );

            try {
                $this->connection = new PDO(
                    $dsn,
                    $this->config['user'],
                    $this->config['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_STRINGIFY_FETCHES => false,
                    ]
                );
            } catch (PDOException $exception) {
                error_log('Database connection failed. Check PostgreSQL availability and DB_* configuration.');
                throw new RuntimeException('Unable to connect to the database.', 0, $exception);
            }
        }

        return $this->connection;
    }

    /**
     * Prepare and execute a statement with explicitly typed bound values.
     *
     * @param array<int|string, scalar|null> $parameters Positional list or named parameters (for example, [':id' => 12]).
     */
    public function query(string $sql, array $parameters = []): PDOStatement
    {
        if (trim($sql) === '') {
            throw new InvalidArgumentException('SQL must not be empty.');
        }

        $isPositional = array_is_list($parameters);
        $bindings = [];
        foreach ($parameters as $key => $value) {
            if (!$isPositional && (!is_string($key) || $key === '')) {
                throw new InvalidArgumentException('Named parameters must use non-empty string keys.');
            }

            $placeholder = $isPositional ? $key + 1 : $key;
            if (is_string($placeholder) && !str_starts_with($placeholder, ':')) {
                $placeholder = ':' . $placeholder;
            }

            if ($value === null) {
                $type = PDO::PARAM_NULL;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_string($value)) {
                $type = PDO::PARAM_STR;
            } elseif (is_float($value)) {
                $type = PDO::PARAM_STR;
                $value = (string) $value;
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported parameter type for %s.',
                    (string) $key
                ));
            }

            $bindings[] = [$placeholder, $value, $type];
        }

        $statement = $this->getConnection()->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('The database could not prepare the SQL statement.');
        }

        foreach ($bindings as [$placeholder, $value, $type]) {
            $statement->bindValue($placeholder, $value, $type);
        }

        $statement->execute();

        return $statement;
    }
}
