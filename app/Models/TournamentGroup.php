<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Database.php';

final class TournamentGroup
{
    /**
     * Create all groups configured for a groups_knockout tournament.
     *
     * @param list<string> $names
     * @return list<array{id: int, name: string}>
     */
    public function createMany(int $tournamentId, array $names): array
    {
        if ($tournamentId < 1) {
            throw new InvalidArgumentException('Tournament ID must be a positive integer.');
        }
        if (!array_is_list($names) || $names === []) {
            throw new InvalidArgumentException('At least one group name must be provided as a list.');
        }

        $normalizedNames = [];
        $seenNames = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Group names must be strings.');
            }
            $name = trim($name);
            $length = preg_match_all('/./us', $name);
            if ($length === false || $length < 1 || $length > 20) {
                throw new InvalidArgumentException('Group names must contain between 1 and 20 characters.');
            }

            $normalized = strtolower($name);
            if (isset($seenNames[$normalized])) {
                throw new InvalidArgumentException('Group names must be unique within a tournament.');
            }
            $seenNames[$normalized] = true;
            $normalizedNames[] = $name;
        }

        $pdo = Database::getInstance()->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $tournamentStatement = Database::getInstance()->query(
                'SELECT format, groups_count
                 FROM tournaments
                 WHERE id = :tournament_id
                 FOR UPDATE',
                ['tournament_id' => $tournamentId]
            );
            $tournament = $tournamentStatement->fetch();
            if (!is_array($tournament)) {
                throw new RuntimeException('Cannot create groups for a tournament that does not exist.');
            }
            if ($tournament['format'] !== 'groups_knockout') {
                throw new InvalidArgumentException('Groups can only be created for groups_knockout tournaments.');
            }
            if ((int) $tournament['groups_count'] !== count($normalizedNames)) {
                throw new InvalidArgumentException('The number of group names must match the tournament configuration.');
            }

            $groups = [];
            foreach ($normalizedNames as $name) {
                $statement = Database::getInstance()->query(
                    'INSERT INTO tournament_groups (tournament_id, name)
                     VALUES (:tournament_id, :name)
                     RETURNING id, name',
                    [
                        'tournament_id' => $tournamentId,
                        'name' => $name,
                    ]
                );
                $group = $statement->fetch();
                if (!is_array($group) || !isset($group['id'], $group['name'])) {
                    throw new RuntimeException('The database did not return the new group.');
                }
                $groups[] = ['id' => (int) $group['id'], 'name' => (string) $group['name']];
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $groups;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function findByTournamentId(int $tournamentId): array
    {
        if ($tournamentId < 1) {
            throw new InvalidArgumentException('Tournament ID must be a positive integer.');
        }

        return Database::getInstance()->query(
            'SELECT id, tournament_id, name
             FROM tournament_groups
             WHERE tournament_id = :tournament_id
             ORDER BY name, id',
            ['tournament_id' => $tournamentId]
        )->fetchAll();
    }
}
