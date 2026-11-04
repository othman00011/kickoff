<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Database.php';

final class Tournament
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$MSRkPlif9NDwkiK.s067zemK8YzH6EO8cZYncI5FvKyPbi3Lj/m2W';

    public static function escapeLike(string $value): string
    {
        return addcslashes($value, "\\%_");
    }

    public function create(
        string $name,
        string $password,
        string $format,
        int $pointsWin = 3,
        int $pointsDraw = 1,
        int $pointsLoss = 0,
        int $roundRobinLegs = 1,
        ?int $groupsCount = null,
        ?int $qualifiersPerGroup = null
    ): int {
        $name = self::validateName($name);
        self::validateAdminPassword($password);
        if (!in_array($format, ['league', 'knockout', 'groups_knockout'], true)) {
            throw new InvalidArgumentException('Unsupported tournament format.');
        }
        foreach ([$pointsWin, $pointsDraw, $pointsLoss] as $points) {
            if ($points < 0 || $points > 32767) {
                throw new InvalidArgumentException('Points must be between 0 and 32767.');
            }
        }
        if (!in_array($roundRobinLegs, [1, 2], true)) {
            throw new InvalidArgumentException('Round-robin legs must be one or two.');
        }
        if ($format === 'groups_knockout') {
            $limits = require dirname(__DIR__, 2) . '/config/limits.php';
            if ($groupsCount === null || $groupsCount < $limits['min_groups'] || $groupsCount > $limits['max_groups']
                || $qualifiersPerGroup === null || $qualifiersPerGroup < 1 || $qualifiersPerGroup > 32767) {
                throw new InvalidArgumentException('Group tournaments require valid group and qualifier counts.');
            }
        } elseif ($groupsCount !== null || $qualifiersPerGroup !== null) {
            throw new InvalidArgumentException('Group settings are only valid for groups_knockout tournaments.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to securely hash the tournament admin password.');
        }

        $statement = Database::getInstance()->query(
            'INSERT INTO tournaments
                (name, password_hash, format, points_win, points_draw, points_loss,
                 round_robin_legs, groups_count, qualifiers_per_group)
             VALUES
                (:name, :password_hash, :format, :points_win, :points_draw, :points_loss,
                 :round_robin_legs, :groups_count, :qualifiers_per_group)
             RETURNING id',
            [
                'name' => $name,
                'password_hash' => $passwordHash,
                'format' => $format,
                'points_win' => $pointsWin,
                'points_draw' => $pointsDraw,
                'points_loss' => $pointsLoss,
                'round_robin_legs' => $roundRobinLegs,
                'groups_count' => $groupsCount,
                'qualifiers_per_group' => $qualifiersPerGroup,
            ]
        );

        $id = $statement->fetchColumn();
        if (!is_numeric($id)) {
            throw new RuntimeException('The database did not return the new tournament ID.');
        }

        return (int) $id;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $tournamentId): ?array
    {
        $this->validateId($tournamentId, 'Tournament ID');
        $statement = Database::getInstance()->query(
            'SELECT id, name, format, points_win, points_draw, points_loss,
                    round_robin_legs, groups_count, qualifiers_per_group, created_at
             FROM tournaments
             WHERE id = :id',
            ['id' => $tournamentId]
        );
        $tournament = $statement->fetch();

        return is_array($tournament) ? $tournament : null;
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Tournament name must not be empty.');
        }

        $statement = Database::getInstance()->query(
            'SELECT id, name, format, points_win, points_draw, points_loss,
                    round_robin_legs, groups_count, qualifiers_per_group, created_at
             FROM tournaments
             WHERE lower(name) = lower(:name)',
            ['name' => $name]
        );
        $tournament = $statement->fetch();

        return is_array($tournament) ? $tournament : null;
    }

    public function verifyAdminPassword(string $name, string $password): bool
    {
        $name = trim($name);
        $statement = Database::getInstance()->query(
            'SELECT password_hash FROM tournaments WHERE lower(name) = lower(:name)',
            ['name' => $name]
        );
        $passwordHash = $statement->fetchColumn();
        $hasTournament = is_string($passwordHash);
        if (!$hasTournament) {
            $passwordHash = self::DUMMY_PASSWORD_HASH;
        }
        $passwordMatches = password_verify($password, $passwordHash);

        return $hasTournament && $passwordMatches;
    }

    public function deleteById(int $tournamentId): bool
    {
        $this->validateId($tournamentId, 'Tournament ID');
        $statement = Database::getInstance()->query(
            'DELETE FROM tournaments WHERE id = :id',
            ['id' => $tournamentId]
        );

        return $statement->rowCount() === 1;
    }

    private function validateId(int $id, string $label): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $label));
        }
    }

    public static function validateName(string $value): string
    {
        $limits = require dirname(__DIR__, 2) . '/config/limits.php';
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Tournament name must be valid UTF-8.');
        }
        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidArgumentException('Tournament name must not contain control characters.');
        }

        $value = trim($value);
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new InvalidArgumentException('Tournament name must be valid UTF-8.');
        }
        if ($length < $limits['min_name_chars'] || $length > $limits['max_name_chars']) {
            throw new InvalidArgumentException(sprintf(
                'Tournament name must contain between %d and %d characters.',
                $limits['min_name_chars'],
                $limits['max_name_chars']
            ));
        }
        return $value;
    }

    public static function validateAdminPassword(string $password): void
    {
        $limits = require dirname(__DIR__, 2) . '/config/limits.php';
        $length = strlen($password);
        if ($length < $limits['min_admin_password_bytes'] || $length > $limits['max_admin_password_bytes']) {
            throw new InvalidArgumentException(sprintf(
                'Admin password must be between %d and %d bytes.',
                $limits['min_admin_password_bytes'],
                $limits['max_admin_password_bytes']
            ));
        }
        if (str_contains($password, "\0")) {
            throw new InvalidArgumentException('Admin passwords must not contain null bytes.');
        }
    }
}
