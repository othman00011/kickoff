# Kickoff

Kickoff is a web application for organizing and following local football tournaments. An organizer creates a tournament, enters the participating teams, and the system generates the complete fixture list. Results are entered through a password-protected control room, and league tables, group tables and knockout brackets update from those results. Every tournament has a public page that anyone can open without an account.

The application is built with plain PHP and PostgreSQL. It uses a small custom MVC structure and has no framework or third-party runtime dependencies.

## Table of Contents

1. [Key Features](#key-features)
2. [Tech Stack](#tech-stack)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Running Tests](#running-tests)
6. [Usage Overview](#usage-overview)
7. [Configuration Reference](#configuration-reference)
8. [Deployment Notes](#deployment-notes)
9. [Further Documentation](#further-documentation)

## Key Features

### Tournament Formats

- **League**: every team plays every other team. The organizer chooses a single round-robin (one leg) or a double round-robin (home and away).
- **Knockout**: a single-elimination bracket. When the number of teams is not a power of two, the bracket is padded and first-round byes are advanced automatically. Drawn matches require a penalty shootout result.
- **Groups + knockout**: teams are distributed across 2 to 16 groups, each group plays a round-robin, and the best teams of every group qualify for a seeded knockout stage.

### Automated Scheduling

The `Scheduler` helper generates all fixtures when a tournament is created:

- Round-robin fixtures use the circle (rotation) method, so every team plays exactly once per matchday, and an odd number of teams is handled with a rest slot.
- Two-leg schedules mirror the first set of rounds with home and away reversed.
- Knockout brackets are created in full at the start. Slots of later rounds stay empty until the upstream match has a winner, and the winner is moved into the next round automatically when a result is saved or corrected.
- The group-to-knockout bracket is seeded with the classic "best versus worst" placement. The two top seeds are kept on opposite halves, and a post-processing step swaps same-tier qualifiers to avoid first-round rematches between teams that already met in the group stage.

### Standings and Tie-Breaking

League and group tables are computed from played matches. The ranking order is:

1. Points (using the win, draw and loss values configured for the tournament)
2. Goal difference
3. Goals scored
4. Head-to-head record between the tied teams (points, goal difference, goals scored), applied recursively to any subset that remains tied
5. Team identifier as a deterministic final fallback

When a tie cannot be resolved by these rules, the affected rows are flagged in the interface so the organizer is aware that the order is based on registration order.

### Organizer Control Room

- Sign in with the tournament name and its admin password. Each tournament has its own password.
- Enter, correct or clear match results. Penalty fields are available for knockout matches.
- For group tournaments, preview the qualifying teams and their seeds, then generate the knockout bracket once all group matches are played.
- Reset an unplayed knockout bracket to unlock group results again.
- Delete a tournament and all of its data after confirming the admin password.

### Security Measures

- CSRF tokens on every state-changing form, validated with a constant-time comparison.
- Admin passwords stored only as `password_hash()` digests; login verification performs a dummy hash comparison for unknown tournaments to reduce user enumeration through timing.
- Login throttling by IP address, by tournament name, and by the pair of both, backed by the database.
- Session hardening: strict mode, HTTP-only and SameSite cookies, a `Secure` flag over HTTPS, session ID regeneration on login, logout and tournament creation, and a two-hour idle timeout.
- Prepared statements with typed parameter binding for all SQL; LIKE wildcards in the search box are escaped.
- Strict input validation (UTF-8 checks, control character rejection, length and range limits) and output escaping in every view.
- Security response headers, including a restrictive Content Security Policy that permits only same-origin scripts and styles.
- Production mode hides PHP errors from the browser and writes them to the error log.

### User Interface

- Public home page with search and pagination of tournaments.
- Multi-step creation form with client-side validation and a no-JavaScript fallback.
- Responsive layout written in plain CSS.

## Tech Stack

| Layer | Technology |
| --- | --- |
| Language | PHP 8.1 or newer, with `declare(strict_types=1)` throughout |
| Architecture | Custom MVC (front controller, router, controllers, models, views) |
| Database | PostgreSQL 10 or newer, accessed through PDO (`pdo_pgsql`) |
| Front end | HTML5, CSS3, vanilla JavaScript (no frameworks, no build step) |
| Web server | PHP built-in server for development; Apache with `mod_rewrite` is supported through `public/.htaccess` |

## Requirements

- PHP 8.1 or newer with the following extensions enabled: `pdo_pgsql`, `ctype`, `pcre` (with UTF-8 support). These are enabled in standard PHP builds except for `pdo_pgsql`, which often has to be installed separately.
- PostgreSQL 10 or newer, with a database and a user that can create tables and views.
- A modern browser.

Verify your PHP installation:

```bash
php -v
php -m | grep -i -E "pdo_pgsql|ctype|pcre"
```

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/othman00011/kickoff.git
cd kickoff
```

### 2. Create the database

Create an empty PostgreSQL database and a user. The names below are examples.

```bash
psql -U postgres -c "CREATE USER kickoff WITH PASSWORD 'choose-a-strong-password';"
psql -U postgres -c "CREATE DATABASE tournaments OWNER kickoff;"
```

### 3. Load the schema

Run the schema once against the empty database. It creates all tables, indexes and the `v_standings` view inside a single transaction.

```bash
psql -U kickoff -d tournaments -f schema.sql
```

If the command fails, the transaction is rolled back and the database remains empty, so it is safe to fix the problem and run it again.

### 4. Configure the environment

Copy the example file and fill in your values.

```bash
cp .env.example .env
```

Edit `.env`:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=tournaments
DB_USER=kickoff
DB_PASSWORD=choose-a-strong-password

# Use "development" only on a trusted local machine to display PHP errors.
APP_ENV=development
APP_FORCE_HTTPS=0

# Leave empty unless the application runs behind a reverse proxy.
TRUSTED_PROXIES=
```

The `.env` file is read by `public/index.php` at startup. Variables that already exist in the real process environment take precedence over the file. Never commit `.env` to version control.

### 5. Start the development server

Serve the `public` directory with the PHP built-in server:

```bash
php -S 127.0.0.1:8000 -t public
```

Open `http://127.0.0.1:8000` in a browser. Requests for paths that do not correspond to a static file (for example `/tournament/create`) are handled by the front controller `public/index.php`.

### 6. Create your first tournament

1. Click "Create tournament" in the navigation.
2. Enter a name and choose a format.
3. Enter the team names, one per line (2 to 64 teams).
4. For a groups tournament, set the number of groups and qualifiers per group.
5. Set the points rules, the number of legs, and the admin password (8 to 72 bytes).
6. Submit the form. You are signed in as the administrator of the new tournament and redirected to its public page.

## Running Tests

The test suite is a dependency-free script and does not need a database connection:

```bash
php tests/run.php
```

The script prints `PASS` or `FAIL` for each test, followed by a summary, and exits with a non-zero status code when any test fails, which makes it suitable for continuous integration. See `ARCHITECTURE.md` for a description of what is covered.

## Usage Overview

| Route | Method | Purpose |
| --- | --- | --- |
| `/` | GET | Home page, tournament search and pagination (`q`, `page`, `limit`) |
| `/tournament/create` | GET, POST | Tournament creation form and handler |
| `/tournament/show/{id}` | GET | Public tournament page: standings, fixtures, results |
| `/tournament/login` | GET, POST | Admin login by tournament name and password |
| `/tournament/admin/{id}` | GET | Control room (requires an authenticated admin session) |
| `/tournament/generateKnockout` | POST | Generate the knockout bracket from group results |
| `/tournament/resetKnockout` | POST | Remove an unplayed knockout bracket |
| `/tournament/logout` | POST | End the admin session |
| `/tournament/delete` | POST | Delete a tournament after password confirmation |
| `/match/updateScore` | POST | Save, correct or clear a match result |

## Configuration Reference

### Environment variables

| Variable | Description | Default |
| --- | --- | --- |
| `DB_HOST` | PostgreSQL host | `127.0.0.1` |
| `DB_PORT` | PostgreSQL port | `5432` |
| `DB_NAME` | Database name (required) | none |
| `DB_USER` | Database user (required) | none |
| `DB_PASSWORD` | Database password | empty |
| `APP_ENV` | `development` displays PHP errors; any other value hides them | `production` behavior |
| `APP_FORCE_HTTPS` | Set to `1` to mark cookies as secure and send HSTS when TLS is terminated before PHP | `0` |
| `TRUSTED_PROXIES` | Comma-separated list of direct proxy IP addresses whose forwarded headers are trusted | empty |

### Application limits

Limits are defined in `config/limits.php`:

| Limit | Value |
| --- | --- |
| Teams per tournament | 2 to 64 |
| Tournament, team name length | 1 to 100 characters |
| Admin password length | 8 to 72 bytes |
| Groups per tournament | 2 to 16 |
| Team list input | 20 KB and 500 lines |

## Deployment Notes

- Set `APP_ENV=production` and make sure PHP error output is disabled. Errors are written to the PHP error log.
- Serve the application over HTTPS. When TLS is terminated by a reverse proxy, set `TRUSTED_PROXIES` to the proxy address so that `X-Forwarded-Proto` and `X-Forwarded-For` are honored; otherwise set `APP_FORCE_HTTPS=1`.
- Point the web server document root at the `public` directory only. The `app`, `core`, `config`, `views`, `tests` directories and the `.env` file must not be web accessible.
- With Apache, enable `mod_rewrite` and allow `.htaccess` overrides for the `public` directory.
- Use a database account with the minimum privileges required for the application tables.
- Back up the database regularly; all application state lives in PostgreSQL.

## Further Documentation

- `ARCHITECTURE.md`: directory structure, request lifecycle, database access layer, security architecture and test suite.
- `DATABASE.md`: schema, constraints, standings view and data model.
