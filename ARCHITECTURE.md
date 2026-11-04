# Architecture

This document describes how Kickoff is organized internally: the directory layout, the way a request travels through the application, how the database is accessed, which security mechanisms are in place, and how the code is tested.

## Table of Contents

1. [Design Principles](#design-principles)
2. [Directory Structure](#directory-structure)
3. [Request Lifecycle and Routing](#request-lifecycle-and-routing)
4. [Controllers](#controllers)
5. [Models](#models)
6. [Helpers](#helpers)
7. [Views and Front End](#views-and-front-end)
8. [Database Connection Management](#database-connection-management)
9. [Security Architecture](#security-architecture)
10. [Error Handling](#error-handling)
11. [Testing Suite](#testing-suite)

## Design Principles

- **No framework.** The application is a small MVC implementation with a front controller, a convention-based router, controllers, models and PHP templates.
- **Strict typing.** Every PHP file declares `strict_types=1`. Inputs are validated at the boundary (controllers and model entry points) and rejected with `InvalidArgumentException`, which controllers translate into user-facing flash messages.
- **Database-enforced integrity.** Business rules that can be expressed as constraints (unique names, valid scores, penalties only for drawn knockout matches, same-tournament foreign keys) are enforced by PostgreSQL in addition to PHP validation.
- **Pure logic is isolated.** Fixture generation (`Scheduler`) and table ordering (`StandingsCalculator`) do not touch the database or the request, which makes them directly unit testable.
- **Fail closed.** Unknown routes return 404, invalid methods return 405, unexpected exceptions return a generic 500 page, and details are written only to the error log.

## Directory Structure

```text
kickoff/
|-- app/
|   |-- Controllers/
|   |   |-- HomeController.php
|   |   |-- MatchController.php
|   |   `-- TournamentController.php
|   |-- Helpers/
|   |   |-- Scheduler.php
|   |   |-- Security.php
|   |   `-- StandingsCalculator.php
|   `-- Models/
|       |-- LoginThrottle.php
|       |-- MatchModel.php
|       |-- Team.php
|       |-- Tournament.php
|       `-- TournamentGroup.php
|-- config/
|   |-- database.php
|   `-- limits.php
|-- core/
|   |-- App.php
|   |-- Controller.php
|   `-- Database.php
|-- public/
|   |-- .htaccess
|   |-- css/style.css
|   |-- js/main.js
|   `-- index.php
|-- tests/
|   `-- run.php
|-- views/
|   |-- errors/        (404.php, 429.php, 500.php)
|   |-- home/          (index.php)
|   |-- layouts/       (header.php, footer.php)
|   `-- tournament/    (admin.php, create.php, login.php, show.php)
|-- .env.example
|-- schema.sql
`-- snapshot.php
```

### `app/Controllers`

Controllers receive a validated request, call models and helpers, and either render a view or redirect.

| File | Responsibility |
| --- | --- |
| `HomeController` | Public landing page. Validates the search text (`q`), page and page size, builds a parameterized `ILIKE` query with escaped wildcards, and renders paginated tournament cards. |
| `TournamentController` | Tournament creation (inside a single database transaction), public view, admin control room, login and logout, knockout generation and reset, and tournament deletion. |
| `MatchController` | Receives score submissions, verifies CSRF and the admin session for the specific tournament, parses scores and penalties, and delegates to `MatchModel::updateScore`. |

### `app/Models`

Models contain the SQL. They validate their own arguments and manage transactions when they own them.

| File | Responsibility |
| --- | --- |
| `Tournament` | Create, find by id or name, delete, verify admin password, validate name and password rules, escape LIKE input. |
| `Team` | Bulk insert of teams, name validation, case-insensitive duplicate detection, lookups by tournament and group. |
| `TournamentGroup` | Creates all groups of a groups tournament under a tournament row lock and lists them. |
| `MatchModel` | Bulk insert of fixtures, fixture listing, score updates with winner propagation, standings retrieval, group qualifier computation, knockout generation and reset, rematch avoidance. |
| `LoginThrottle` | Records failed logins, evaluates blocking rules, resolves the client IP address behind trusted proxies, purges old records. |

### `app/Helpers`

| File | Responsibility |
| --- | --- |
| `Scheduler` | Pure functions that generate round-robin fixtures, group-stage fixtures, unseeded knockout brackets with byes, and seeded knockout brackets. |
| `StandingsCalculator` | Pure function that orders raw standing rows using points, goal difference, goals scored and recursive head-to-head resolution, and flags unresolved ties. |
| `Security` | CSRF token creation and validation, and HTML escaping. |

### `core`

| File | Responsibility |
| --- | --- |
| `App` | Router and dispatcher. |
| `Controller` | Abstract base class: view rendering, flash messages, safe redirects, ID parsing, 404 and 405 responses. |
| `Database` | Singleton PDO wrapper with a typed-binding `query()` method. |

### `config`

| File | Responsibility |
| --- | --- |
| `database.php` | Reads `DB_*` variables from the environment. |
| `limits.php` | Central numeric limits for teams, names, passwords, groups and input size. Used by models, controllers and mirrored in the client-side validation. |

### `public`

The only directory that should be exposed by the web server. `index.php` is the front controller. `css/style.css` and `js/main.js` are the static assets. `.htaccess` rewrites non-file requests to `index.php` on Apache.

### `tests`

`run.php` is a self-contained test runner. See [Testing Suite](#testing-suite).

### `views`

PHP templates. `layouts/header.php` defines the `$escape` helper and the document shell; each controller action renders the header, one view and the footer in sequence.

## Request Lifecycle and Routing

The URL scheme is convention based: `/{controller}/{action}/{argument...}`. An empty path maps to `HomeController::index`.

### 1. Bootstrap (`public/index.php`)

1. Error reporting is set to `E_ALL`; displaying errors is allowed only when `APP_ENV` is `development`. PHP errors and warnings are converted into `ErrorException` so that they flow through the central exception handler.
2. The `.env` file, if present, is parsed line by line. Entries must be `NAME=value`; surrounding quotes are stripped; variables already defined in the real environment are not overridden.
3. HTTPS is detected from the server variable, from `APP_FORCE_HTTPS`, or from `X-Forwarded-Proto` when the direct peer is a trusted proxy.
4. Security headers are sent (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy`, and `Strict-Transport-Security` over HTTPS).
5. The session `KICKOFFSESSID` is started with strict mode, cookies only, no transparent session IDs, `HttpOnly`, `SameSite=Lax` and `Secure` when HTTPS is active.
6. Session idle time is checked. If more than two hours have passed since the last activity (or the stored timestamp is invalid), the session data is cleared and the session ID is regenerated.
7. `App::run()` is invoked inside a `try` block whose `catch` handles anything unexpected.

### 2. Routing (`core/App.php`)

1. The path is extracted from `REQUEST_URI` and split into segments, each URL-decoded. A segment containing a slash or a null byte results in a 404.
2. The first segment (lower-cased) selects the controller, the second selects the action (default `index`), and the remaining segments are positional arguments.
3. The controller slug must match `^[a-z][a-z0-9]*$` and the action `^[A-Za-z][A-Za-z0-9_]*$`. The controller class name is `ucfirst(slug) . 'Controller'`, and its file must exist in `app/Controllers`.
4. The class must be loaded successfully and extend `Controller`. The action must be a public, non-static, non-constructor method that is not declared on the base `Controller` class. These checks use reflection, so helper methods in the base class can never be reached through a URL.
5. `App::argumentsAreValid()` checks the number of arguments against the method signature, limits each argument to 200 bytes, rejects control characters, and accepts only `string`-typed (or untyped) parameters. Numeric conversion is therefore always done explicitly inside the controller, with `parsePositiveId()`.
6. The action is invoked with `invokeArgs`. An `ArgumentCountError` or `TypeError` raised by the dispatcher itself becomes a 404; the same errors raised deeper in application code are re-thrown as genuine bugs.

### 3. Controller execution

A typical state-changing action follows this order:

1. Check the HTTP method (405 with an `Allow` header otherwise).
2. Validate the CSRF token.
3. Parse and validate identifiers and fields.
4. Verify that the session contains an admin flag for that specific tournament.
5. Call the model. Domain-level validation problems surface as `InvalidArgumentException` and become a flash message plus a redirect.
6. Redirect with HTTP 303 (Post/Redirect/Get) to a local path.

Redirect targets are validated to be local absolute paths, which prevents open redirects. Flash messages are stored in the session and consumed on the next page render.

### 4. Rendering

`Controller::render()` validates the view name, checks that the header, view and footer files exist, renders into an output buffer, and only then sends the status code, content type and body. A failure during rendering therefore never produces a half-written page.

## Controllers

### Tournament creation

`TournamentController::create` validates all fields, then performs these steps in one transaction:

1. Insert the tournament row (the password is hashed here).
2. For a groups tournament, create the groups, distribute the teams across them in round-robin order of entry, insert the teams, and generate the group-stage fixtures per group.
3. For league and knockout formats, insert the teams and generate either a round-robin or a knockout bracket.
4. Insert all fixtures in bulk.

Any exception rolls the transaction back. After a successful commit the session ID is regenerated and the creator is marked as administrator of the new tournament. A unique-violation on `uq_tournaments_name` is reported as a duplicate name; other database errors are logged and reported generically.

### Score updates and bracket propagation

`MatchModel::updateScore` runs in a transaction and takes row locks on the tournament and the match. It then:

- Rejects edits of group matches once a knockout stage exists.
- Rejects scores for matches whose teams are not yet known.
- Requires a non-tied penalty result for a drawn knockout match, and forbids penalties otherwise.
- Updates the row, compares the previous and new winner, and if the winner changed, calls `replaceAdvancingWinner`, which writes the winner into the correct slot of the next round and recursively clears any later results that depended on the previous winner.

### Knockout generation from groups

`generateKnockoutFromGroups` locks the tournament row and refuses to run if a knockout stage already exists or any group match lacks a result. It computes the qualifiers of each group from the standings, orders them into seeds (group rank first, then points, goal difference, goals scored and group order), builds a seeded bracket, runs the same-group rematch avoidance step, and inserts the bracket. If the qualification cutoff in any group is decided only by registration order, a notice is returned so the organizer is informed.

## Models

Models never read request data. They receive typed arguments, validate them, and use `Database::query()` exclusively for SQL execution.

Bulk inserts are chunked below the PostgreSQL limit of bound parameters per statement. Models that insert several rows own a transaction only when none is already active, so they can be composed into the transaction opened by the controller.

## Helpers

### `Scheduler`

- `generateRoundRobin(teamIds, legs, stage, groupId)`: circle method. For an odd team count a null placeholder is added and its matches are skipped. Home and away alternate by round to keep the schedule balanced. With two legs, the second half repeats the first with venues swapped and round numbers offset.
- `generateGroupStage(teamsByGroup, legs)`: runs the round-robin for each group and renumbers matches so that match numbers are unique inside each round across all groups (required by the unique position index).
- `generateKnockoutBracket(teamIds)`: builds all rounds up to the final for the next power of two. Teams that receive a bye are copied into their second-round slot immediately.
- `generateSeededKnockoutBracket(seededTeamIds)`: builds the standard seed order so seeds 1 and 2 can only meet in the final, and gives byes to the best seeds.

### `StandingsCalculator`

Input is the raw rows of the `v_standings` view plus the played matches. The calculator sorts by points, goal difference and goals scored, groups rows that are identical on all three, and resolves each group with a head-to-head mini league (points, goal difference and goals scored in the matches between the tied teams only). If the mini league separates only part of the group, the procedure is applied again to the remaining subset. If every team in a subset is still equal, the subset is returned in team-ID order and marked `tie_unresolved`.

### `Security`

Provides `csrfToken()`, `csrfField()`, `validateCsrfToken()`, `validatePostToken()` and `escapeHtml()`. All of them require an active session and throw otherwise.

## Views and Front End

Templates receive their data through `extract()` with the `EXTR_SKIP` flag, so a data key can never overwrite a variable that the layout has already defined. Every dynamic value is passed through `$escape()` (or cast to `int`) before output. Because the Content Security Policy allows only same-origin scripts and styles, the templates contain no inline script or style blocks.

`public/js/main.js` provides progressive enhancement only: the multi-step creation form, deletion dialog, confirmation prompts and flash message dismissal. The server repeats every validation, so the application remains functional and safe if scripts are disabled or bypassed.

## Database Connection Management

### Singleton

`Database::getInstance()` returns one shared instance per request. The constructor reads `config/database.php`, validates every value (non-empty host, numeric port in range, database name and user), and stores the configuration. The PDO connection itself is created lazily on the first call to `getConnection()`, so requests that never touch the database (for example, a 404) do not open a connection.

### PDO options

| Option | Value | Effect |
| --- | --- | --- |
| `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | Database errors become `PDOException`. |
| `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | Rows are associative arrays. |
| `ATTR_EMULATE_PREPARES` | `false` | Native server-side prepared statements; values are never interpolated into SQL. |
| `ATTR_STRINGIFY_FETCHES` | `false` | Integers and booleans keep their native PHP types. |

### Typed binding

`Database::query(string $sql, array $parameters)` accepts named parameters (with or without a leading colon) or a positional list. Each value is bound with an explicit PDO type: `PARAM_NULL`, `PARAM_BOOL`, `PARAM_INT` or `PARAM_STR`. Floats are converted to strings, and any other type raises an exception. The method returns the executed `PDOStatement`.

### Exception handling

If the connection fails, the real cause is logged without credentials and a generic `RuntimeException` is thrown, so connection details are never displayed to users. Transactions are managed explicitly by the code that begins them, always with a `rollBack()` in the `catch` branch, and nested usage is guarded with `inTransaction()`.

## Security Architecture

### CSRF protection

A 256-bit random token (`bin2hex(random_bytes(32))`) is generated on demand and stored in the session. It is rendered as a hidden `_csrf_token` field in every form that changes state: tournament creation, login, logout, score updates, knockout generation and reset, and deletion. `Security::validatePostToken()` requires a `POST` request and compares the submitted value with `hash_equals`. A failed check redirects with an error message and performs no action. Because the session cookie uses `SameSite=Lax`, cross-site POST requests are additionally prevented from carrying the cookie in modern browsers.

### Authentication and authorization

There are no user accounts. Authorization is per tournament: after a successful login or creation, the session stores `admin_tournament_ids[<id>] = true`. Every admin action checks the flag for the exact tournament ID it operates on, so access to one tournament never grants access to another. Admin pages send `Cache-Control: no-store`. Deleting a tournament requires the password again, even for an already authenticated administrator.

### Password handling

- Passwords are 8 to 72 bytes (the effective input limit of bcrypt) and must not contain null bytes.
- They are hashed with `password_hash($password, PASSWORD_DEFAULT)` and verified with `password_verify`. Only the hash is stored.
- For a name that does not exist, verification runs against a fixed dummy hash, so response time does not reveal whether a tournament name exists.
- The session ID is regenerated on login, logout and tournament creation to prevent session fixation.

### Login throttling

Failed attempts are stored in the `login_attempts` table. Before a password is checked, `LoginThrottle::isBlocked()` counts attempts inside a sliding 15-minute window on three scopes:

| Scope | Limit | Purpose |
| --- | --- | --- |
| IP address and tournament name | 5 failures | Stops guessing of one tournament from one client. |
| IP address | 30 failures | Stops one client from probing many tournaments. |
| Tournament name | 100 failures | Limits distributed guessing against one tournament. |

When any limit is reached, the response is HTTP 429 with a `Retry-After` header; the wait time is computed from the oldest attempt that still counts. A successful login clears the attempts for the IP and name pair. Rows older than 24 hours are purged on roughly 1 percent of login-related requests. The same throttle protects the deletion confirmation, which also verifies the admin password.

Client IP resolution uses `REMOTE_ADDR` unless the peer is listed in `TRUSTED_PROXIES`. For a trusted proxy, `X-Forwarded-For` is read from right to left and the first valid address that is not itself a trusted proxy is used. This prevents a client from spoofing its address through the header when the application is reachable directly.

### Input validation and output encoding

- Text input must be valid UTF-8, free of control characters, and within configured length limits. Numeric inputs are accepted only as canonical decimal strings inside an explicit range.
- Route arguments are limited in length and character set before reaching a controller.
- The search term is length-limited and its `%`, `_` and backslash characters are escaped before being used in an `ILIKE ... ESCAPE` clause.
- All output is HTML-escaped with `ENT_QUOTES | ENT_SUBSTITUTE` for UTF-8.

### HTTP hardening

| Header or setting | Value |
| --- | --- |
| `Content-Security-Policy` | `default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'` |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `same-origin` |
| `Strict-Transport-Security` | `max-age=31536000` when HTTPS is detected |
| Session cookie | `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, session-lifetime cookie |

## Error Handling

- User mistakes are handled with `InvalidArgumentException`, flash messages and redirects.
- Missing resources return the custom 404 view; wrong HTTP methods return 405.
- Throttled requests return the 429 view.
- Unexpected exceptions are caught in `public/index.php`: the message, file and line are written to the error log, any partial output buffer is discarded, and the generic 500 view is rendered. If rendering the 500 view fails, a minimal plain-text response is sent.

## Testing Suite

`tests/run.php` is a framework-free runner. Tests are registered with a small `addTest(name, callback)` closure, executed in order, and reported as `PASS` or `FAIL` with the failure message. The process exits with status 0 only if all tests pass. No database is required, because the covered code is pure logic or static validation.

Run it from the project root:

```bash
php tests/run.php
```

### Covered areas

| Area | What is verified |
| --- | --- |
| Round-robin scheduler | Golden fixtures for 4, 5 and 6 teams, including rest slots for odd counts and home/away alternation. |
| Knockout scheduler | Golden brackets for 4, 5 and 6 teams, including first-round byes and their advancement into round two. |
| Seeded brackets | For many team counts (2 to 64): every seed appears exactly once in round one, byes go to the best seeds, byes advance correctly, and seeds 1 and 2 meet only in the final. |
| Rematch avoidance | Same-group first-round pairings are swapped only with teams of the same seed tier, protected top seeds keep their bracket path, and representative layouts (4, 6 and 8 qualifiers) produce no same-group first-round matches. |
| Standings | Head-to-head resolution of otherwise equal teams, cyclic head-to-head ties marked as unresolved with a team-ID fallback, recalculation of head-to-head for a remaining tied subset, and teams with no played matches. |
| Validation and input safety | Team and tournament name rules (trimming, control characters, invalid UTF-8, length), duplicate team detection that is case-insensitive, team count limits, admin password length and null-byte rules. |
| Search escaping | LIKE wildcard and backslash escaping. |
| Router | Argument count, string-only parameter typing, oversized segments and control characters. |

### Not covered by automated tests

CSRF validation, login throttling, session handling and the SQL-backed model methods depend on a session or a live PostgreSQL instance and are not part of this suite. They are best verified with integration tests against a disposable database; the scheduler and calculator tests above are the regression safety net for the core domain logic.
