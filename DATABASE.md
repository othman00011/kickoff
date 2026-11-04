# Database

Kickoff stores all of its state in PostgreSQL (version 10 or newer). The complete definition lives in `schema.sql`, which creates five tables and one view inside a single transaction.

## Table of Contents

1. [Setup](#setup)
2. [Overview and Dependency Order](#overview-and-dependency-order)
3. [Table Reference](#table-reference)
4. [Index Summary](#index-summary)
5. [Referential Integrity and Cascade Behavior](#referential-integrity-and-cascade-behavior)
6. [The `v_standings` View](#the-v_standings-view)
7. [Data Modeling Overview (MCD and MLD)](#data-modeling-overview-mcd-and-mld)
8. [Data Isolation and Tournament Integrity](#data-isolation-and-tournament-integrity)
9. [Common Queries](#common-queries)
10. [Maintenance](#maintenance)

## Setup

Run the schema once against an empty database:

```bash
psql -U kickoff -d tournaments -f schema.sql
```

The script starts with `BEGIN` and ends with `COMMIT`; if any statement fails, nothing is created. Identity columns (`GENERATED ALWAYS AS IDENTITY`) require PostgreSQL 10 or later, and the aggregate `FILTER` clause used in the view requires 9.4 or later.

## Overview and Dependency Order

Tables are created in dependency order:

```text
tournaments
   |-- tournament_groups   (tournament_id -> tournaments.id)
   |-- teams               (tournament_id -> tournaments.id, group_id -> tournament_groups)
   `-- matches             (tournament_id, group_id, home_team_id, away_team_id)

login_attempts             (independent: used for rate limiting)
v_standings                (view over teams, matches, tournaments)
```

`tournaments` is the root entity. Everything that belongs to a tournament carries its `tournament_id`, which is what makes it possible to isolate one tournament from another with composite foreign keys.

## Table Reference

### `tournaments`

One row per tournament. It stores the tournament settings and the admin credential.

| Column | Type | Constraints and notes |
| --- | --- | --- |
| `id` | `INTEGER` identity | Primary key. |
| `name` | `VARCHAR(100)` | Not null. Doubles as the admin login name, so it is unique case-insensitively (see index below). |
| `password_hash` | `VARCHAR(255)` | Not null. Output of PHP `password_hash()`; the plain password is never stored. |
| `format` | `VARCHAR(20)` | Not null. `CHECK` in (`league`, `knockout`, `groups_knockout`). |
| `points_win` | `SMALLINT` | Not null, default 3, `CHECK >= 0`. |
| `points_draw` | `SMALLINT` | Not null, default 1, `CHECK >= 0`. |
| `points_loss` | `SMALLINT` | Not null, default 0, `CHECK >= 0`. |
| `round_robin_legs` | `SMALLINT` | Not null, default 1, `CHECK` in (1, 2). One leg or home and away. Used by the league and by the group stage. |
| `groups_count` | `SMALLINT` | Nullable. Number of groups, only for `groups_knockout`. |
| `qualifiers_per_group` | `SMALLINT` | Nullable. Teams that advance from each group, only for `groups_knockout`. |
| `created_at` | `TIMESTAMPTZ` | Not null, default `now()`. |

Constraints:

- `ck_tournaments_group_settings`: for `groups_knockout`, `groups_count` must be at least 2 and `qualifiers_per_group` at least 1; for every other format both must be `NULL`. This prevents a league from carrying stray group settings and a groups tournament from being created without them.
- `uq_tournaments_name`: a unique index on `lower(name)`. Because the tournament name is the login identifier, two names that differ only by case are treated as the same name.

### `tournament_groups`

Groups of the group stage (A, B, C, and so on). The table is empty for other formats. It is not named `groups` to avoid a clash with the SQL keyword.

| Column | Type | Constraints and notes |
| --- | --- | --- |
| `id` | `INTEGER` identity | Primary key. |
| `tournament_id` | `INTEGER` | Not null. Foreign key to `tournaments(id)` with `ON DELETE CASCADE`. |
| `name` | `VARCHAR(20)` | Not null. |

Constraints:

- `uq_groups_tournament_name` unique (`tournament_id`, `name`): a group name is unique inside a tournament, while different tournaments can reuse "A", "B" and so on.
- `uq_groups_tournament_id` unique (`tournament_id`, `id`): this redundant-looking key exists so that other tables can reference the pair (`tournament_id`, `id`) in a composite foreign key. That is how a team or a match is forced to point to a group of the same tournament.

### `teams`

Teams that take part in a tournament. `group_id` is filled only in the group stage.

| Column | Type | Constraints and notes |
| --- | --- | --- |
| `id` | `INTEGER` identity | Primary key. |
| `tournament_id` | `INTEGER` | Not null. Foreign key to `tournaments(id)` with `ON DELETE CASCADE`. |
| `name` | `VARCHAR(100)` | Not null. |
| `group_id` | `INTEGER` | Nullable. Part of the composite foreign key below. |

Constraints:

- `fk_teams_group` foreign key (`tournament_id`, `group_id`) references `tournament_groups (tournament_id, id)` with `ON DELETE CASCADE`. The group must belong to the same tournament as the team. When `group_id` is `NULL` the check is skipped (default `MATCH SIMPLE` behavior), which is what league and knockout teams use.
- `uq_teams_tournament_id` unique (`tournament_id`, `id`): target for the composite foreign keys of `matches`.
- `uq_teams_tournament_name`: unique index on (`tournament_id`, `lower(name)`). Team names are unique per tournament, case-insensitively, but may repeat across tournaments.

### `matches`

One row per match. The result is stored in the same row; `NULL` scores mean the match has not been played. For knockout tournaments the entire bracket is created at the start, and the team columns of later rounds stay `NULL` until the previous match is decided.

| Column | Type | Constraints and notes |
| --- | --- | --- |
| `id` | `INTEGER` identity | Primary key. |
| `tournament_id` | `INTEGER` | Not null. Foreign key to `tournaments(id)` with `ON DELETE CASCADE`. |
| `stage` | `VARCHAR(10)` | Not null. `CHECK` in (`league`, `group`, `knockout`). |
| `group_id` | `INTEGER` | Nullable. Set only for group-stage matches. |
| `round_number` | `SMALLINT` | Not null, `CHECK >= 1`. Matchday for league and group matches; round of the bracket for knockout (1 is the first round). |
| `match_number` | `SMALLINT` | Not null, `CHECK >= 1`. Position inside the round. |
| `home_team_id` | `INTEGER` | Nullable (unknown future bracket slot or a bye). |
| `away_team_id` | `INTEGER` | Nullable (same reason). |
| `home_score` | `SMALLINT` | Nullable, `CHECK >= 0`. |
| `away_score` | `SMALLINT` | Nullable, `CHECK >= 0`. |
| `home_penalties` | `SMALLINT` | Nullable, `CHECK >= 0`. Shootout score. |
| `away_penalties` | `SMALLINT` | Nullable, `CHECK >= 0`. Shootout score. |

Foreign keys (all composite and all `ON DELETE CASCADE`):

| Constraint | Columns | References |
| --- | --- | --- |
| `fk_matches_group` | (`tournament_id`, `group_id`) | `tournament_groups (tournament_id, id)` |
| `fk_matches_home_team` | (`tournament_id`, `home_team_id`) | `teams (tournament_id, id)` |
| `fk_matches_away_team` | (`tournament_id`, `away_team_id`) | `teams (tournament_id, id)` |

Because every reference includes `tournament_id`, a match cannot use a team or a group that belongs to a different tournament.

Check constraints:

| Constraint | Rule |
| --- | --- |
| `ck_matches_different_teams` | `home_team_id <> away_team_id`. A team cannot play itself. (A comparison with `NULL` is not false, so unknown slots are allowed.) |
| `ck_matches_group_stage` | `(stage = 'group') = (group_id IS NOT NULL)`. A group ID is mandatory for group matches and forbidden for all others. |
| `ck_matches_teams_known` | Non-knockout matches must have both teams. Only knockout matches may contain unknown slots. |
| `ck_matches_scores_pair` | Home and away score are both set or both `NULL`. |
| `ck_matches_score_needs_teams` | A score can exist only if both teams are known. |
| `ck_matches_penalties` | Penalties are either both `NULL`, or the match is a knockout match with a drawn score, both penalty values present and different. |
| `ck_matches_knockout_winner` | A drawn knockout result must carry a penalty result, so a winner always exists. |

Indexes:

- `uq_matches_position` unique (`tournament_id`, `stage`, `round_number`, `match_number`): each position is unique within its tournament, stage and round. It also serves lookups that list the matches of a round.
- `idx_matches_home_team` and `idx_matches_away_team`: speed up standings aggregation and cascade deletes, since PostgreSQL does not create indexes on foreign key columns automatically.

### `login_attempts`

Failed tournament-admin password attempts, used by the application-level rate limiter. Successful logins clear the relevant rows, and old rows are purged by the application.

| Column | Type | Constraints and notes |
| --- | --- | --- |
| `id` | `BIGINT` identity | Primary key. |
| `ip_address` | `VARCHAR(45)` | Not null. Long enough for a textual IPv6 address. |
| `name_key` | `VARCHAR(100)` | Not null. The normalized, lower-cased tournament name that was attempted. |
| `attempted_at` | `TIMESTAMPTZ` | Not null, default `now()`. |

Indexes: `idx_login_attempts_ip_time` on (`ip_address`, `attempted_at`) and `idx_login_attempts_name_time` on (`name_key`, `attempted_at`). They support the three counting scopes used by the throttle (IP and name, IP only, name only) within a time window.

This table intentionally has no foreign key to `tournaments`: attempts against names that do not exist must also be recorded, and deleting a tournament must not interfere with throttling.

## Index Summary

| Index | Table | Columns | Purpose |
| --- | --- | --- | --- |
| `uq_tournaments_name` | `tournaments` | `lower(name)` (unique) | Case-insensitive unique login name. |
| `uq_groups_tournament_name` | `tournament_groups` | `tournament_id`, `name` (unique) | Unique group names per tournament. |
| `uq_groups_tournament_id` | `tournament_groups` | `tournament_id`, `id` (unique) | Composite foreign key target. |
| `uq_teams_tournament_id` | `teams` | `tournament_id`, `id` (unique) | Composite foreign key target. |
| `uq_teams_tournament_name` | `teams` | `tournament_id`, `lower(name)` (unique) | Case-insensitive unique team names per tournament. |
| `uq_matches_position` | `matches` | `tournament_id`, `stage`, `round_number`, `match_number` (unique) | Unique match position, fast round listing. |
| `idx_matches_home_team` | `matches` | `home_team_id` | Standings and cascade deletes. |
| `idx_matches_away_team` | `matches` | `away_team_id` | Standings and cascade deletes. |
| `idx_login_attempts_ip_time` | `login_attempts` | `ip_address`, `attempted_at` | Throttle lookups by IP. |
| `idx_login_attempts_name_time` | `login_attempts` | `name_key`, `attempted_at` | Throttle lookups by name. |

## Referential Integrity and Cascade Behavior

All relationships that point to a tournament use `ON DELETE CASCADE`. Deleting a single row from `tournaments` therefore removes, in one statement, its groups, teams and matches. This is exactly what the application relies on for "Delete tournament", which performs only `DELETE FROM tournaments WHERE id = :id`.

```text
DELETE tournaments row
   -> tournament_groups rows
   -> teams rows
   -> matches rows
```

Deleting a group also removes the teams assigned to it and the matches of that group through the composite foreign keys. The application does not delete groups individually.

## The `v_standings` View

`v_standings` computes the raw league or group table directly from the matches. It stores no data and always reflects the current results. The final ordering and head-to-head tie-breaks are intentionally not computed in SQL; they are applied afterwards by the PHP class `StandingsCalculator`, because recursive head-to-head resolution is difficult to express in a single view.

### Definition summary

A common table expression, `team_results`, produces one row per team per played match, seen from the team's own side:

- Home rows: `home_team_id` with `home_score` as goals for and `away_score` as goals against.
- Away rows: `away_team_id` with `away_score` as goals for and `home_score` as goals against.

Only matches of the stages `league` and `group` that have been played (a non-null score) are included. Knockout matches never affect standings.

The main query starts from `teams` and left-joins `team_results`, so every team appears in the table even before it has played. It joins `tournaments` to read the points rules, then groups by team and tournament.

### Output columns

| Column | Meaning |
| --- | --- |
| `tournament_id`, `group_id` | Scope of the table. `group_id` is `NULL` for a league. |
| `team_id`, `team_name` | Team identity. |
| `played` | Number of played matches (`COUNT(r.team_id)`, which ignores the null row of an unplayed team). |
| `wins`, `draws`, `losses` | Counts computed with `FILTER` clauses on the goals comparison. |
| `goals_for`, `goals_against` | Sums, with `COALESCE` to 0 for teams without matches. |
| `goal_difference` | Sum of `goals_for - goals_against`. |
| `points` | `wins * points_win + draws * points_draw + losses * points_loss`, using the tournament's own rules. |

Because the points rules are read from `tournaments` at query time, they are applied consistently to every team of that tournament.

### Reading the view

```sql
SELECT team_name, played, wins, draws, losses, goals_for, goals_against, goal_difference, points
FROM v_standings
WHERE tournament_id = 1
ORDER BY points DESC, goal_difference DESC, goals_for DESC;
```

The SQL above gives the basic order. The application additionally applies head-to-head rules and flags ties that cannot be resolved.

## Data Modeling Overview (MCD and MLD)

### Conceptual Data Model (MCD)

The conceptual model describes the entities, their attributes and the cardinalities of the relationships, independent of any database technology.

Entities:

- **Tournament**: identity, name, password hash, format, points rules, legs, group settings, creation time.
- **TournamentGroup**: identity and name; exists only inside a tournament.
- **Team**: identity and name; exists only inside a tournament and optionally inside a group.
- **Match**: identity, stage, round and match numbers, optional home and away teams, scores and penalties.

Relationships, written as (minimum, maximum) cardinalities:

| Relationship | Reading |
| --- | --- |
| Tournament **has** TournamentGroup | A tournament has (0,N) groups; a group belongs to exactly (1,1) tournament. |
| Tournament **has** Team | A tournament has (0,N) teams; a team belongs to exactly (1,1) tournament. |
| TournamentGroup **contains** Match | A group contains (0,N) matches; a group-stage match belongs to one group, while league and knockout matches belong to none. |
| Team **plays in** Match | A team plays in (0,N) matches, as home or away side; a match has up to two teams (fewer for unresolved knockout slots). |
| Team **belongs to** TournamentGroup | Optional (0,1) from the team side: only group-stage teams have a group. |

### Logical Data Model (MLD)

The logical model maps the concepts to relational tables for PostgreSQL:

- Each entity becomes a table with a surrogate integer primary key.
- Each one-to-many relationship becomes a foreign key on the "many" side. Since tournament membership is part of every relationship, the foreign keys are composite and carry `tournament_id`.
- The two roles of a team in a match (home and away) become two foreign key columns in `matches`.
- `login_attempts` has no relationship; it is an operational table.
- `v_standings` is a derived view and not a table.

```text
tournaments (id PK, ...)
tournament_groups (id PK, tournament_id FK, name)
teams (id PK, tournament_id FK, name, group_id)
       FK (tournament_id, group_id) -> tournament_groups (tournament_id, id)
matches (id PK, tournament_id FK, stage, group_id, round_number, match_number,
         home_team_id, away_team_id, home_score, away_score, home_penalties, away_penalties)
       FK (tournament_id, group_id)     -> tournament_groups (tournament_id, id)
       FK (tournament_id, home_team_id) -> teams (tournament_id, id)
       FK (tournament_id, away_team_id) -> teams (tournament_id, id)
login_attempts (id PK, ip_address, name_key, attempted_at)
```

## Data Isolation and Tournament Integrity

Several tournaments share the same tables, so the schema is designed to make cross-tournament contamination impossible rather than merely unlikely.

1. **Composite foreign keys.** `teams` and `matches` reference `(tournament_id, id)` of their parents instead of only `id`. A match that belongs to tournament 5 cannot reference a team or a group from tournament 6, even if the application has a bug.
2. **Per-tournament uniqueness.** Team names, group names and match positions are unique within a tournament, not globally, so tournaments are fully independent namespaces.
3. **Format consistency.** `ck_tournaments_group_settings` guarantees that group settings exist exactly when the format needs them.
4. **Stage consistency.** `ck_matches_group_stage` and `ck_matches_teams_known` tie the match stage to the presence of a group and of teams.
5. **Result consistency.** The score, penalty and winner constraints make invalid results (a single score, penalties on a league match, a drawn knockout match without a shootout winner, a tied shootout) impossible to store.
6. **Atomic lifecycle.** Creation and deletion are single-transaction operations: a tournament is created together with all of its teams, groups and fixtures or not at all, and deletion cascades from the root row.
7. **Concurrency control.** Score updates and bracket operations lock the tournament and match rows with `SELECT ... FOR UPDATE` inside a transaction, so two administrators cannot corrupt the bracket by editing simultaneously.

## Common Queries

List the fixtures of a tournament with team and group names:

```sql
SELECT m.stage, g.name AS group_name, m.round_number, m.match_number,
       home.name AS home_team, away.name AS away_team,
       m.home_score, m.away_score, m.home_penalties, m.away_penalties
FROM matches m
LEFT JOIN teams home ON home.id = m.home_team_id
LEFT JOIN teams away ON away.id = m.away_team_id
LEFT JOIN tournament_groups g ON g.id = m.group_id
WHERE m.tournament_id = 1
ORDER BY m.stage, m.round_number, m.match_number;
```

Count the unplayed group matches (a prerequisite for generating the knockout stage):

```sql
SELECT COUNT(*) FROM matches
WHERE tournament_id = 1 AND stage = 'group' AND home_score IS NULL;
```

Search tournaments by name with literal wildcards escaped:

```sql
SELECT id, name, format, created_at
FROM tournaments
WHERE name ILIKE '%cup%' ESCAPE '\'
ORDER BY created_at DESC, id DESC
LIMIT 4 OFFSET 0;
```

## Maintenance

- **Backups.** Use `pg_dump -U kickoff -d tournaments -Fc -f kickoff.dump` and restore with `pg_restore`.
- **Throttle table growth.** The application purges rows older than 24 hours occasionally during login requests. For high-traffic installations, the same statement can be scheduled externally: `DELETE FROM login_attempts WHERE attempted_at < now() - INTERVAL '24 hours';`.
- **Schema changes.** `schema.sql` targets an empty database. Changes to an existing installation should be applied as separate migration scripts rather than by re-running the file.
- **Privileges.** The application role needs `SELECT`, `INSERT`, `UPDATE` and `DELETE` on the tables and `SELECT` on the view; it does not need superuser or schema-modification rights at runtime.
