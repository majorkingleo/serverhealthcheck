# Database schema reference

All tables live in one database (`serverhealthcheck` by default). Schema is defined in
`conf/create_tables.sql`. Timestamps are `TIMESTAMP DEFAULT CURRENT_TIMESTAMP`; history
tables carry `run_at` (or `timestamp` for `health_checks_stats`).

## Core tables

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Web UI accounts | `username` (unique), `password_hash` (PHP `password_hash`), `is_admin` |
| `checks` | Check configuration | `script_name` (unique), `title`, `interval_minutes`, `parameters`, `enabled`, `sudo`, `last_run`, `next_run` |
| `health_checks` | Latest status per check (upserted) | `check_name` (unique), `status`, `message`, `timestamp` |
| `health_checks_stats` | Full run history (append-only) | `check_name`, `status`, `timestamp` |

`health_checks.status` and `health_checks_stats.status` are
`ENUM('OK','WARN','ERROR','UNKNOWN')`. `CRIT` is collapsed to `ERROR` by `run_checks.py`;
`TIMEOUT` is a PHP-side presentation state only.

## Detail (history) tables

Written by `run_checks.py` `_parse_and_store_*` functions; read by `www/stats.php`.

| Table | Columns |
|---|---|
| `cpu_stats` | `load1`, `load5`, `load15`, `run_at` |
| `ram_stats` | `used_mb`, `total_mb`, `run_at` |
| `process_stats` | `process_count`, `run_at` |
| `disk_usage` | `mountpoint`, `used_mb`, `total_mb`, `warn_mb`, `crit_mb`, `run_at` |
| `smart_results` | `device`, `health` (`PASSED`/`FAILED`/`UNKNOWN`), `run_at` |
| `smart_metrics` | `device`, `metric`, `value`, `unit`, `run_at` |
| `mariadb_table_stats` | `table_schema`, `table_name`, `row_count`, `run_at` |
| `service_stats` | `failed_count`, `active_count`, `inactive_count`, `run_at` |
| `service_unit_states` | `unit_name` (unique), `state`, `run_at` (upsert-only, no history) |
| `cert_stats` | `host`, `port`, `days_left`, `run_at` |
| `update_stats` | `pending_count`, `run_at` |
| `zombie_stats` | `zombie_count`, `run_at` |

`check_reboot.py` and `check_fs_mirror.py` write no detail tables (status-only).

## Credentials

`conf/db.php` (gitignored) contains:

```php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'serverhealthcheck');
define('DB_PASS', 'serverhealthcheck');
define('DB_NAME', 'serverhealthcheck');
```

`scripts/db.py` parses these `define()` lines with a regex and connects with the
`mariadb` Python driver (`?` placeholders). `www/config.php` uses the same constants
through PHP PDO.

## Default checks seeded by `create_tables.sql`

| script_name | parameters | sudo |
|---|---|---|
| `check_disk.py` | `80 90` | 0 |
| `check_fs_mirror.py` | *(empty)* | 1 |
| `check_smart.py` | *(empty)* | 1 |
| `check_mariadb.py` | `1000 5000` | 0 |
| `check_cpu.py` | `2.0 4.0` | 0 |
| `check_ram.py` | `80 90` | 0 |
| `check_processes.py` | `500 800` | 0 |
| `check_services.py` | `1 1` | 0 |
| `check_cert.py` | `14 7` | 0 |
| `check_cert_files.py` | `14 7` | 1 |
| `check_updates.py` | `10 50` | 0 |
| `check_reboot.py` | *(empty)* | 0 |
| `check_zombies.py` | `5 10` | 0 |

## Migrations

`conf/migrate_add_is_admin.sql` is a standalone, idempotent migration that adds the
`users.is_admin` column and flags `admin`. Apply it only for pre-existing databases that
predate the column.
