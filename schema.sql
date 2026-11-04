-- =====================================================================
-- Football Tournament Manager - PostgreSQL schema (PostgreSQL 10+)
-- Run once on an empty database:  psql -d tournaments -f schema.sql
-- Tables are created in dependency order:
--   tournaments -> tournament_groups -> teams -> matches
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- 1) tournaments
-- One row per tournament. Also holds the tournament settings and the
-- admin login (tournament name + password hash).
-- ---------------------------------------------------------------------
CREATE TABLE tournaments (
    id                   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                 VARCHAR(100) NOT NULL,
    -- Never store the plain password. Store the result of PHP password_hash().
    password_hash        VARCHAR(255) NOT NULL,
    -- Competition system: league, direct knockout, or groups then knockout.
    format               VARCHAR(20)  NOT NULL
                         CHECK (format IN ('league', 'knockout', 'groups_knockout')),
    -- Points rules (used for league and group tables). Defaults need approval.
    points_win           SMALLINT NOT NULL DEFAULT 3 CHECK (points_win  >= 0),
    points_draw          SMALLINT NOT NULL DEFAULT 1 CHECK (points_draw >= 0),
    points_loss          SMALLINT NOT NULL DEFAULT 0 CHECK (points_loss >= 0),
    -- 1 = each pair plays once, 2 = home and away.
    -- Used by 'league' and by the group stage of 'groups_knockout'.
    round_robin_legs     SMALLINT NOT NULL DEFAULT 1
                         CHECK (round_robin_legs IN (1, 2)),
    -- Only for 'groups_knockout': number of groups and teams that qualify per group.
    groups_count         SMALLINT,
    qualifiers_per_group SMALLINT,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- groups_count and qualifiers_per_group are required for 'groups_knockout'
    -- and must be empty for the other formats.
    CONSTRAINT ck_tournaments_group_settings CHECK (
        (format = 'groups_knockout'
            AND groups_count IS NOT NULL AND groups_count >= 2
            AND qualifiers_per_group IS NOT NULL AND qualifiers_per_group >= 1)
        OR
        (format <> 'groups_knockout'
            AND groups_count IS NULL AND qualifiers_per_group IS NULL)
    )
);

-- The tournament name is the login, so it must be unique (case-insensitive).
CREATE UNIQUE INDEX uq_tournaments_name ON tournaments (lower(name));

-- ---------------------------------------------------------------------
-- 2) tournament_groups
-- Groups of the group stage (A, B, C...). Empty for other formats.
-- The table is not called "groups" to avoid a keyword clash.
-- ---------------------------------------------------------------------
CREATE TABLE tournament_groups (
    id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tournament_id INTEGER NOT NULL
                  REFERENCES tournaments (id) ON DELETE CASCADE,
    name          VARCHAR(20) NOT NULL,
    -- A group name is unique inside one tournament.
    CONSTRAINT uq_groups_tournament_name UNIQUE (tournament_id, name),
    -- Needed so other tables can point to (tournament_id, id) and stay
    -- inside the same tournament.
    CONSTRAINT uq_groups_tournament_id UNIQUE (tournament_id, id)
);

-- ---------------------------------------------------------------------
-- 3) teams
-- Teams of a tournament. group_id is filled only in the group stage.
-- ---------------------------------------------------------------------
CREATE TABLE teams (
    id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tournament_id INTEGER NOT NULL
                  REFERENCES tournaments (id) ON DELETE CASCADE,
    name          VARCHAR(100) NOT NULL,
    group_id      INTEGER,
    -- The group must belong to the same tournament as the team.
    -- (If group_id is NULL, this check is skipped.)
    CONSTRAINT fk_teams_group FOREIGN KEY (tournament_id, group_id)
        REFERENCES tournament_groups (tournament_id, id) ON DELETE CASCADE,
    CONSTRAINT uq_teams_tournament_id UNIQUE (tournament_id, id)
);

-- A team name is unique inside one tournament (case-insensitive).
CREATE UNIQUE INDEX uq_teams_tournament_name ON teams (tournament_id, lower(name));

-- ---------------------------------------------------------------------
-- 4) matches
-- One row per match. The result is stored in the same row
-- (NULL scores = match not played yet).
-- stage: 'league' | 'group' | 'knockout'
-- round_number: matchday (league/group) or knockout round (1 = first round)
-- match_number: position of the match inside its round (starts at 1)
-- For knockout, the whole bracket can be created at the start; teams of
-- later rounds stay NULL until the previous matches are decided.
-- ---------------------------------------------------------------------
CREATE TABLE matches (
    id             INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tournament_id  INTEGER NOT NULL
                   REFERENCES tournaments (id) ON DELETE CASCADE,
    stage          VARCHAR(10) NOT NULL
                   CHECK (stage IN ('league', 'group', 'knockout')),
    group_id       INTEGER,
    round_number   SMALLINT NOT NULL CHECK (round_number >= 1),
    match_number   SMALLINT NOT NULL CHECK (match_number >= 1),
    home_team_id   INTEGER,
    away_team_id   INTEGER,
    home_score     SMALLINT CHECK (home_score >= 0),
    away_score     SMALLINT CHECK (away_score >= 0),
    -- Penalty shootout score, only for a drawn knockout match.
    home_penalties SMALLINT CHECK (home_penalties >= 0),
    away_penalties SMALLINT CHECK (away_penalties >= 0),

    -- Group, home team and away team must belong to the same tournament.
    CONSTRAINT fk_matches_group FOREIGN KEY (tournament_id, group_id)
        REFERENCES tournament_groups (tournament_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_home_team FOREIGN KEY (tournament_id, home_team_id)
        REFERENCES teams (tournament_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_away_team FOREIGN KEY (tournament_id, away_team_id)
        REFERENCES teams (tournament_id, id) ON DELETE CASCADE,

    -- A team cannot play against itself.
    CONSTRAINT ck_matches_different_teams CHECK (home_team_id <> away_team_id),

    -- group_id is required for the group stage and forbidden otherwise.
    CONSTRAINT ck_matches_group_stage CHECK ((stage = 'group') = (group_id IS NOT NULL)),

    -- Only knockout matches may have unknown teams (future bracket slots).
    CONSTRAINT ck_matches_teams_known CHECK (
        stage = 'knockout' OR (home_team_id IS NOT NULL AND away_team_id IS NOT NULL)
    ),

    -- Both scores are filled together, and only when both teams are known.
    CONSTRAINT ck_matches_scores_pair CHECK ((home_score IS NULL) = (away_score IS NULL)),
    CONSTRAINT ck_matches_score_needs_teams CHECK (
        home_score IS NULL OR (home_team_id IS NOT NULL AND away_team_id IS NOT NULL)
    ),

    -- Penalties: both or none; only for a drawn knockout match; no tie in penalties.
    CONSTRAINT ck_matches_penalties CHECK (
        (home_penalties IS NULL AND away_penalties IS NULL)
        OR
        (stage = 'knockout'
            AND home_score IS NOT NULL AND home_score = away_score
            AND home_penalties IS NOT NULL AND away_penalties IS NOT NULL
            AND home_penalties <> away_penalties)
    ),

    -- A drawn knockout match must have a penalty result (a winner is required).
    CONSTRAINT ck_matches_knockout_winner CHECK (
        stage <> 'knockout'
        OR home_score IS NULL
        OR home_score <> away_score
        OR home_penalties IS NOT NULL
    )
);

-- A match position is unique inside its tournament, stage and round.
-- This index also speeds up listing the matches of a round.
CREATE UNIQUE INDEX uq_matches_position
    ON matches (tournament_id, stage, round_number, match_number);

-- Indexes on team columns: used by standings queries and by cascade deletes.
CREATE INDEX idx_matches_home_team ON matches (home_team_id);
CREATE INDEX idx_matches_away_team ON matches (away_team_id);

-- ---------------------------------------------------------------------
-- 5) login_attempts
-- Failed tournament-admin password attempts only; old rows are purged.
-- ---------------------------------------------------------------------
CREATE TABLE login_attempts (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    name_key     VARCHAR(100) NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_login_attempts_ip_time ON login_attempts (ip_address, attempted_at);
CREATE INDEX idx_login_attempts_name_time ON login_attempts (name_key, attempted_at);

-- ---------------------------------------------------------------------
-- 6) v_standings (a VIEW, it stores no data)
-- Computes the league / group table directly from the matches.
-- Only played matches of stages 'league' and 'group' are counted.
-- The final ordering and head-to-head tie-breaks are calculated in PHP
-- by StandingsCalculator; this view contains raw statistics only.
-- ---------------------------------------------------------------------
CREATE VIEW v_standings AS
WITH team_results AS (
    -- One row per team per played match, seen from that team's side.
    SELECT home_team_id AS team_id, home_score AS goals_for, away_score AS goals_against
    FROM matches
    WHERE stage IN ('league', 'group') AND home_score IS NOT NULL
    UNION ALL
    SELECT away_team_id AS team_id, away_score AS goals_for, home_score AS goals_against
    FROM matches
    WHERE stage IN ('league', 'group') AND away_score IS NOT NULL
)
SELECT
    t.tournament_id,
    t.group_id,
    t.id   AS team_id,
    t.name AS team_name,
    COUNT(r.team_id)                                   AS played,
    COUNT(*) FILTER (WHERE r.goals_for >  r.goals_against) AS wins,
    COUNT(*) FILTER (WHERE r.goals_for =  r.goals_against) AS draws,
    COUNT(*) FILTER (WHERE r.goals_for <  r.goals_against) AS losses,
    COALESCE(SUM(r.goals_for), 0)                      AS goals_for,
    COALESCE(SUM(r.goals_against), 0)                  AS goals_against,
    COALESCE(SUM(r.goals_for - r.goals_against), 0)    AS goal_difference,
    COUNT(*) FILTER (WHERE r.goals_for >  r.goals_against) * tr.points_win
  + COUNT(*) FILTER (WHERE r.goals_for =  r.goals_against) * tr.points_draw
  + COUNT(*) FILTER (WHERE r.goals_for <  r.goals_against) * tr.points_loss
                                                       AS points
FROM teams t
JOIN tournaments tr ON tr.id = t.tournament_id
LEFT JOIN team_results r ON r.team_id = t.id
GROUP BY t.id, tr.id;

COMMIT;
