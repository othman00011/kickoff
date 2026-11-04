<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Database.php';

final class LoginThrottle
{
    private const PAIR_LIMIT = 5;
    private const IP_LIMIT = 30;
    private const NAME_LIMIT = 100;

    public static function clientIpAddress(): string
    {
        $peer = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('The request does not have a valid remote IP address.');
        }

        $trustedProxies = array_filter(
            array_map('trim', explode(',', getenv('TRUSTED_PROXIES') ?: '')),
            static fn (string $proxy): bool => $proxy !== ''
        );
        if (in_array($peer, $trustedProxies, true)) {
            $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwardedFor) && $forwardedFor !== '') {
                foreach (array_reverse(explode(',', $forwardedFor)) as $forwardedIp) {
                    $clientIp = trim($forwardedIp);
                    if (
                        filter_var($clientIp, FILTER_VALIDATE_IP) !== false
                        && !in_array($clientIp, $trustedProxies, true)
                    ) {
                        return $clientIp;
                    }
                }
            }
        }

        return $peer;
    }

    public static function normalizeNameKey(string $name): string
    {
        $name = trim($name);
        if (preg_match('//u', $name) !== 1) {
            return '';
        }

        $characters = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return '';
        }

        return implode('', array_slice($characters, 0, 100));
    }

    public function isBlocked(string $ipAddress, string $nameKey): ?int
    {
        $this->validateIpAddress($ipAddress);
        $nameKey = self::normalizeNameKey($nameKey);

        $scopes = [
            [
                'where' => 'ip_address = :ip AND name_key = left(lower(:name_key), 100)',
                'parameters' => ['ip' => $ipAddress, 'name_key' => $nameKey],
                'limit' => self::PAIR_LIMIT,
            ],
            [
                'where' => 'ip_address = :ip',
                'parameters' => ['ip' => $ipAddress],
                'limit' => self::IP_LIMIT,
            ],
            [
                'where' => 'name_key = left(lower(:name_key), 100)',
                'parameters' => ['name_key' => $nameKey],
                'limit' => self::NAME_LIMIT,
            ],
        ];
        $waitSeconds = [];

        foreach ($scopes as $scope) {
            $where = $scope['where'];
            $parameters = $scope['parameters'];
            $count = (int) Database::getInstance()->query(
                'SELECT COUNT(*)
                 FROM login_attempts
                 WHERE attempted_at >= now() - INTERVAL \'15 minutes\'
                   AND ' . $where,
                $parameters
            )->fetchColumn();
            if ($count < $scope['limit']) {
                continue;
            }

            $parameters['offset'] = $count - $scope['limit'];
            $wait = Database::getInstance()->query(
                'SELECT CEIL(EXTRACT(EPOCH FROM (attempted_at + INTERVAL \'15 minutes\' - now())))
                 FROM login_attempts
                 WHERE attempted_at >= now() - INTERVAL \'15 minutes\'
                   AND ' . $where . '
                 ORDER BY attempted_at ASC
                 OFFSET :offset
                 LIMIT 1',
                $parameters
            )->fetchColumn();
            if (is_numeric($wait)) {
                $waitSeconds[] = max(1, (int) ceil((float) $wait));
            }
        }

        return $waitSeconds === [] ? null : max($waitSeconds);
    }

    public function recordFailure(string $ipAddress, string $nameKey): void
    {
        $this->validateIpAddress($ipAddress);
        $nameKey = self::normalizeNameKey($nameKey);
        Database::getInstance()->query(
            'INSERT INTO login_attempts (ip_address, name_key)
             VALUES (:ip, left(lower(:name_key), 100))',
            [
                'ip' => $ipAddress,
                'name_key' => $nameKey,
            ]
        );
    }

    public function clear(string $ipAddress, string $nameKey): void
    {
        $this->validateIpAddress($ipAddress);
        $nameKey = self::normalizeNameKey($nameKey);
        Database::getInstance()->query(
            'DELETE FROM login_attempts
             WHERE ip_address = :ip AND name_key = left(lower(:name_key), 100)',
            [
                'ip' => $ipAddress,
                'name_key' => $nameKey,
            ]
        );
    }

    public function purgeOld(): int
    {
        $statement = Database::getInstance()->query(
            'DELETE FROM login_attempts
             WHERE attempted_at < now() - INTERVAL \'24 hours\''
        );

        return $statement->rowCount();
    }

    private function validateIpAddress(string $ipAddress): void
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('A valid IP address is required for login throttling.');
        }
    }
}
