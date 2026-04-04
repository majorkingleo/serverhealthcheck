# Server Health Check

A self-hosted server monitoring dashboard built with PHP, Python 3, and MariaDB.
It runs configurable check scripts on a schedule, stores the results in a database,
and displays them as a live web dashboard with interactive Chart.js timeline widgets.

## Features

- **Dashboard** (`index.php`) — status overview with one widget per check, colour-coded
  OK / WARN / ERROR / UNKNOWN / TIMEOUT; pie chart and 7-day status timeline
- **Stats page** (`stats.php`) — per-check detail page with 30-day timeline charts and
  check-specific data widgets (disk usage per mount, SMART health & temperature, CPU
  load, RAM usage, process count, MariaDB row counts, service unit states)
- **Job configuration** (`job_config.php`) — web UI to enable/disable checks, adjust
  intervals, parameters, and sudo flag; trigger immediate re-runs
- **User management** — login, per-user passwords, admin-managed accounts

## Architecture

```
scripts/run_checks.py       orchestrator — reads due checks from DB, runs them,
                            stores results and per-check detail rows
scripts/check_*.py          individual check scripts (Nagios-style exit codes)
scripts/check.py            shared argument parsing and exit helpers
scripts/db.py               shared DB connection (reads conf/db.php for credentials)
conf/create_tables.sql      full schema + default check configuration
www/                        PHP web application (Apache + mod_php)
```

## Check Scripts

| Script | Description | Default Parameters |
|---|---|---|
| `check_cpu.py` | CPU load average (1/5/15 min) | `2.0 4.0` (warn/crit) |
| `check_disk.py` | Disk usage per mount point | `80 90` (% warn/crit) |
| `check_fs_mirror.py` | Filesystem mirror consistency | *(none)* |
| `check_mariadb.py` | MariaDB row-count growth per table | `1000 5000` (rows/day) |
| `check_processes.py` | Running process count | `500 800` (warn/crit) |
| `check_ram.py` | RAM usage percentage | `80 90` (% warn/crit) |
| `check_services.py` | systemd service unit states | `1 1` (failed warn/crit) |
| `check_smart.py` | SMART disk health and temperature | *(none, needs sudo)* |

All scripts follow the Nagios plugin convention: exit 0 = OK, 1 = WARN, 2 = CRIT/ERROR,
3 = UNKNOWN. Output format: `STATUS: message | perfdata`.

## Requirements

- PHP 8.0+ with PDO/MySQL extension
- Python 3.9+ with `mariadb` connector (`pip install mariadb`)
- MariaDB / MySQL
- Apache with `mod_php` (or any PHP-capable web server)
- `smartmontools` for SMART checks (optional)

## Setup

1. **Database** — create the database and user, then import the schema:
   ```bash
   mysql -u root -p < conf/create_tables.sql
   ```

2. **DB credentials** — copy and edit the PHP config:
   ```bash
   cp conf/db.php.example conf/db.php
   # edit conf/db.php and set DB_HOST, DB_USER, DB_PASS, DB_NAME
   ```

3. **Web root** — point your web server document root at `www/` or create a symlink.

4. **Scheduler** — run `run_checks.py` regularly via cron or a systemd timer:
   ```
   * * * * * /usr/bin/python3 /var/www/html/serverhealthcheck/scripts/run_checks.py
   ```
   Use `-all` to run every enabled check immediately regardless of schedule.

5. **sudo** (optional) — allow the web/cron user to run SMART and mirror checks:
   ```
   www-data ALL=(ALL) NOPASSWD: /var/www/html/serverhealthcheck/scripts/check_smart.py
   www-data ALL=(ALL) NOPASSWD: /var/www/html/serverhealthcheck/scripts/check_fs_mirror.py
   ```

6. **Login** — default credentials are `admin` / `admin123`. Change the password
   immediately after first login.

## Database Schema

| Table | Description |
|---|---|
| `health_checks` | Latest status per check (one row per check, upserted) |
| `health_checks_stats` | Full history — one row per run |
| `checks` | Check configuration (script, title, interval, parameters, sudo) |
| `users` | Web UI accounts |
| `cpu_stats` | CPU load history |
| `ram_stats` | RAM usage history |
| `process_stats` | Process count history |
| `disk_usage` | Disk usage per mount point per run |
| `smart_results` | SMART health per disk per run |
| `smart_metrics` | SMART numeric metrics (temperature, etc.) |
| `mariadb_table_stats` | MariaDB row counts per table per run |
| `service_stats` | Aggregated service counts (active/inactive/failed) per run |
| `service_unit_states` | Latest state per systemd unit (upserted) |

## License

GNU General Public License v3.0 — see [LICENSE](LICENSE).
