---
name: server-health-check
description: Develop and debug the Server Health Check dashboard (PHP + Python 3 + MariaDB server monitoring). Use when adding, editing, or fixing check scripts (scripts/check_*.py), the orchestrator (scripts/run_checks.py), the PHP web UI (www/*.php), or the database schema (conf/*.sql).
license: GPL-3.0
metadata:
  author: serverhealthcheck
---

# Server Health Check

Self-hosted monitoring dashboard: Python check scripts run on a schedule, results are
stored in MariaDB, and a PHP web UI displays them with Chart.js widgets.

## Architecture map (paths are relative to the repo root)

| Path | Purpose |
|---|---|
| `scripts/run_checks.py` | Orchestrator: reads due checks from DB, runs them, stores results + detail rows |
| `scripts/check_*.py` | Individual checks (Nagios-style exit codes and output) |
| `scripts/check.py` | Shared helpers: `parse_args`, `exit_with_status`, `exit_code` |
| `scripts/db.py` | Shared DB connection; parses `conf/db.php` for credentials |
| `scripts/cleanup.php` | Cron cleanup of `*_stats` tables older than 6 months |
| `www/*.php` | PHP web app (`index.php` dashboard, `stats.php` per-check detail, `job_config.php` admin) |
| `conf/create_tables.sql` | Full schema + default check configuration |
| `conf/db.php` | DB credentials (**gitignored**; copy from `conf/db.php.example`) |

## Check-script contract (all `scripts/check_*.py`)

Every check must follow the Nagios convention:

- Exit codes: `0` = OK, `1` = WARN, `2` = CRIT/ERROR, `3` = UNKNOWN.
- Output format: `STATUS: message | perfdata` (the ` | ` separator and perfdata are required
  whenever the orchestrator persists detail rows).
- Thresholds arrive as positional CLI args. Use `check.parse_args("<usage>", [int, int] | [float, float])`
  to parse and type-cast them; it exits `3` (UNKNOWN) on bad input.
- Use `check.exit_with_status("OK"|"WARN"|"CRIT"|"UNKNOWN", msg)` to print and exit.
  `check.exit_code(status)` returns the int without printing (for multi-step aggregation).
- Report the worst state only (e.g. aggregate several sub-results, then exit once).

`run_checks.py` maps exit code `2` → stored status `ERROR` (the DB enum has no `CRIT`),
so scripts are free to print `CRIT` in the message text but must still exit `2`.

## Alert-reason contract (do not drop this)

The message is the only thing a human sees, so every WARN/CRIT message must answer
"why did this fire?":

1. Name the breached threshold and its value, e.g. `exceeds warn 80%`,
   `exceeds crit threshold 5000`.
2. Name the offending items — mounts, packages, units, processes, tables — not just counts.
3. Always include the threshold context in OK messages too, so the operator can see how
   close the check is to the limit.
4. Use `check.summarize(items, limit=10)` to cap long lists into
   `a, b, c (+N more)`; never dump unbounded output.
5. Keep perfdata machine-readable and unchanged — parsers in `run_checks.py` depend on it.
   Human detail goes *before* the ` | ` separator.
6. For very long structured detail (per-unit states) use a trailing ` ||| ` section, which
   `checkReason()` strips from the UI (see `check_services.py`).

Example:

```
UPDATES WARN: 12 pending updates exceeds warn threshold 10; packages: libssl3 3.0.11 -> 3.0.13, openssl 3.0.11 -> 3.0.13 | pending_updates=12;10;50;0;
```

## Orchestrator contract (`scripts/run_checks.py`)

- `get_due_checks` / `get_all_enabled_checks` read the `checks` table
  (`enabled = 1`, `next_run <= NOW()` for due).
- `execute_check` runs `scripts/<script_name>` with `shlex.split(parameters)`, prefixing
  `sudo -n` when `checks.sudo` is true, and captures stdout/stderr with a 300s timeout.
- `write_result` upserts `health_checks` (keyed by `check_name = script_name`), appends a
  row to `health_checks_stats` every run, and then dispatches to a `_parse_and_store_*`
  function based on `script_name` to persist per-check detail rows.
- `update_schedule` sets `last_run = NOW()` and `next_run = NOW() + interval_minutes`.

The per-check parsers are the **single source of truth** for how perfdata maps to detail
tables. When a check's output format changes, update both the check and its parser.

## Adding or editing a check

Read `references/adding-a-check.md` for the full checklist, perfdata format table, and
schema columns before touching any of the per-check machinery. In short, a new check
touches these files:

1. `scripts/check_<name>.py` — the check itself.
2. `checks` table — register `script_name`, `title`, `interval_minutes`, `parameters`, `sudo`.
3. `scripts/run_checks.py` — add a `_parse_and_store_<name>` parser + wire it into `write_result`.
4. `conf/create_tables.sql` — add the `*_stats` table (and a default `INSERT` if it is a default check).
5. `www/stats.php` (and `www/index.php` if needed) — per-check widget/chart.

## PHP web-app conventions

- `www/config.php` is the shared bootstrap: `session_start()`, `require_once '../conf/db.php'`,
  `getDB()` (static PDO), `isLoggedIn()`, `requireLogin()`.
- `config.php` also provides `checkReason(?string $message)` and `checkPerfdata(?string $message)`.
  Stored messages are `STATUS: reason | perfdata [||| detail]`; always render the reason via
  `checkReason()` (never print perfdata in the UI, except behind the *Show raw output* toggle).
- Every page that needs auth calls `requireLogin()` first. Admin-only pages
  (`job_config.php`, `user_management.php`) additionally redirect non-admins:
  `if (empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }`.
- Output escaping: always `htmlspecialchars()` user/db values; `urlencode()` query params.
- `index.php` shows the reason under every WARN/ERROR widget and computes `TIMEOUT` in PHP
  (checks whose `next_run` is overdue > 5 min) — `TIMEOUT` is never written to the DB.
- `stats.php` opens with a *Latest Result* card (status, timestamp, reason, parameters,
  raw output) and keys per-check detail rendering off `$is_<name>` booleans.

## Database

- Schema and default data live in `conf/create_tables.sql`; idempotent
  (`IF NOT EXISTS` / `ON DUPLICATE KEY UPDATE`).
- Credentials come from `conf/db.php` (`define('DB_HOST'/'DB_USER'/'DB_PASS'/'DB_NAME', ...)`),
  parsed by both PHP and `scripts/db.py` (regex over `define()` calls).
- Status enum in `health_checks` / `health_checks_stats` is
  `ENUM('OK','WARN','ERROR','UNKNOWN')` — never write `CRIT` or `TIMEOUT` there.
- See `references/schema.md` for the full table list.

## How to run and test

```bash
# Run a single check (from the repo root) and inspect its exit code + perfdata
python3 .agents/skills/server-health-check/scripts/validate_check.py check_disk.py 80 90

# Run every enabled check immediately (ignore schedule)
python3 scripts/run_checks.py -all

# Run only due checks (what cron does)
python3 scripts/run_checks.py
```

Run the Python scripts with the repo root as the working directory, or from `scripts/`
itself, so `import check` / `from db import get_connection` resolve. The cron entry in the
README uses an absolute path to `run_checks.py`, which works because Python adds the
script's own directory to `sys.path`.

## Gotchas

- `check_cert.py` takes hosts on the command line:
  `check_cert.py <host[:port]> ... <warn_days> <crit_days>`. The default DB parameters
  `14 7` only supply the thresholds (no host), so the check exits UNKNOWN until the
  parameters are set, e.g. `example.com:443 14 7`. `check_cert_files.py` is the file-based
  variant (writes `port=0` into `cert_stats`).
- `check_disk.py` labels the root mount `/` as `root` in perfdata, so `_parse_and_store_disk`
  stores it as `/root` in `disk_usage.mountpoint`. Keep this in mind before "fixing" either side.
- `check_updates.py` counts only `Inst ` lines (packages apt would install). Packages listed
  under *kept back* are reported as context but do not affect the threshold count.
- `service_unit_states` upserts with MySQL `ON DUPLICATE KEY UPDATE` (MariaDB-compatible) —
  do not port this to a different SQL dialect blindly.
- Sudo checks (`check_smart.py`, `check_fs_mirror.py`, `check_cert_files.py`) need matching
  `sudoers` NOPASSWD entries; `run_checks.py` runs them with `sudo -n` (non-interactive).
  Without it they legitimately report `permission denied (check needs root ...)`.
- Default login is `admin` / `admin123` (hash in `create_tables.sql`); change after first login.
- Python DB code uses the `mariadb` connector with `?` placeholders (qmark style), not `%s`.
