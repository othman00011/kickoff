<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Database.php';

final class Team
{
    /**
     * Each team must have a name and may have a group_id.
     *
     * @param list<array{name: string, group_id?: ?int}> $teams
     * @return list<int> Inserted team IDs in input order.
     */
    public function insertMany(int $tournamentId, array $teams): array
    {
        $this->validateId($tournamentId, 'Tournament ID');
        if (!array_is_list($teams)) {
            throw new InvalidArgumentException('Teams must be provided as a list.');
        }

        $validatedTeams = [];
        $names = [];
        foreach ($teams as $team) {
            if (!is_array($team) || !isset($team['name']) || !is_string($team['name'])) {
                throw new InvalidArgumentException('Each team must include a string name.');
            }

            $name = self::validateName($team['name']);
            $groupId = $team['group_id'] ?? null;
            if ($groupId !== null && (!is_int($groupId) || $groupId < 1)) {
                throw new InvalidArgumentException('Team group IDs must be positive integers or null.');
            }

            $names[] = $name;
            $validatedTeams[] = ['name' => $name, 'group_id' => $groupId];
        }
        self::assertUniqueNames($names);

        $pdo = Database::getInstance()->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $insertedIds = [];
            foreach (array_chunk($validatedTeams, intdiv(60_000, 3)) as $chunk) {
                $values = [];
                $parameters = [];
                foreach ($chunk as $team) {
                    $suffix = count($values);
                    $values[] = sprintf('(:tournament_id_%1$d, :name_%1$d, :group_id_%1$d)', $suffix);
                    $parameters['tournament_id_' . $suffix] = $tournamentId;
                    $parameters['name_' . $suffix] = $team['name'];
                    $parameters['group_id_' . $suffix] = $team['group_id'];
                }

                $insertedRows = Database::getInstance()->query(
                    'INSERT INTO teams (tournament_id, name, group_id)
                     VALUES ' . implode(', ', $values) . '
                     RETURNING id, name',
                    $parameters
                )->fetchAll();
                $idsByName = [];
                foreach ($insertedRows as $insertedRow) {
                    if (!isset($insertedRow['id'], $insertedRow['name']) || !is_numeric($insertedRow['id'])) {
                        throw new RuntimeException('The database did not return the inserted team IDs and names.');
                    }
                    $idsByName[(string) $insertedRow['name']] = (int) $insertedRow['id'];
                }
                foreach ($chunk as $team) {
                    if (!isset($idsByName[$team['name']])) {
                        throw new RuntimeException('The database did not return a team ID for every inserted team.');
                    }
                    $insertedIds[] = $idsByName[$team['name']];
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $insertedIds;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param list<string> $names
     * @return list<string> Trimmed and validated team names.
     */
    public static function validateNames(array $names): array
    {
        $limits = require dirname(__DIR__, 2) . '/config/limits.php';
        if (!array_is_list($names)) {
            throw new InvalidArgumentException('Team names must be provided as a list.');
        }
        if (count($names) < $limits['min_teams']) {
            throw new InvalidArgumentException(sprintf(
                'A tournament must have at least %d teams.',
                $limits['min_teams']
            ));
        }
        if (count($names) > $limits['max_teams']) {
            throw new InvalidArgumentException(sprintf(
                'A tournament can have at most %d teams.',
                $limits['max_teams']
            ));
        }

        $validatedNames = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Each team name must be text.');
            }
            $validatedNames[] = self::validateName($name);
        }
        self::assertUniqueNames($validatedNames);

        return $validatedNames;
    }

    public static function validateName(string $name): string
    {
        $limits = require dirname(__DIR__, 2) . '/config/limits.php';
        if (preg_match('//u', $name) !== 1) {
            throw new InvalidArgumentException('Team names must be valid UTF-8.');
        }
        if (preg_match('/\p{Cc}/u', $name) === 1) {
            throw new InvalidArgumentException('Team names must not contain control characters.');
        }

        $name = trim($name);
        $length = preg_match_all('/./us', $name);
        if ($length === false) {
            throw new InvalidArgumentException('Team names must be valid UTF-8.');
        }
        if ($length < $limits['min_name_chars'] || $length > $limits['max_name_chars']) {
            throw new InvalidArgumentException(sprintf(
                'Team names must contain between %d and %d characters.',
                $limits['min_name_chars'],
                $limits['max_name_chars']
            ));
        }
        return $name;
    }

    /** @param list<string> $names */
    private static function assertUniqueNames(array $names): void
    {
        $seen = [];
        foreach ($names as $name) {
            foreach ($seen as $existingName) {
                if (preg_match('/\A' . preg_quote($existingName, '/') . '\z/iu', $name) === 1) {
                    throw new InvalidArgumentException('Duplicate team name: ' . $name);
                }
            }
            $seen[] = $name;
        }
    }

    /** @return list<array<string, mixed>> */
    public function findByTournamentId(int $tournamentId): array
    {
        $this->validateId($tournamentId, 'Tournament ID');
        return Database::getInstance()->query(
            'SELECT id, tournament_id, name, group_id
             FROM teams
             WHERE tournament_id = :tournament_id
             ORDER BY name, id',
            ['tournament_id' => $tournamentId]
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function findByGroupId(int $tournamentId, int $groupId): array
    {
        $this->validateId($tournamentId, 'Tournament ID');
        $this->validateId($groupId, 'Group ID');

        return Database::getInstance()->query(
            'SELECT id, tournament_id, name, group_id
             FROM teams
             WHERE tournament_id = :tournament_id AND group_id = :group_id
             ORDER BY name, id',
            [
                'tournament_id' => $tournamentId,
                'group_id' => $groupId,
            ]
        )->fetchAll();
    }

    private function validateId(int $id, string $label): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $label));
        }
    }
}
