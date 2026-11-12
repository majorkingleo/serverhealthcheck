# Adding or editing a check

Follow this checklist in order. Steps 1, 3, 4, and 5 are required for a check that
persists detail data; a "status-only" check (e.g. `check_reboot.py`) needs only steps 1
and 2.

## 1. Write the check script

Create `scripts/check_<name>.py`. Use `import check` for arg parsing and exits.

```python
#!/usr/bin/env python3
import check

def main():
    warn, crit = check.parse_args("check_<name>.py <warn> <crit>", [int, int])
    # ... gather values ...
    value = 0
    offenders = []
    perfdata = f"<metric>={value};{warn};{crit};0;"

    if value < warn:
        check.exit_with_status(
            "OK",
            f"<NAME> OK: {value} (warn {warn}, crit {crit}) | {perfdata}",
        )

    status    = "CRIT" if value >= crit else "WARN"
    threshold = crit if status == "CRIT" else warn
    reason = (
        f"<NAME> {status}: {value} exceeds {status.lower()} threshold {threshold}"
    )
    if offenders:
        reason += "; " + check.summarize(offenders, limit=10)
    check.exit_with_status(status, f"{reason} | {perfdata}")

if __name__ == "__main__":
    main()
```

Rules:

- Emit exactly one status line on stdout: `STATUS: reason | perfdata`.
- The reason must state the breached threshold **and** the offending items
  (mounts, packages, units, tables, processes). A bare count is not enough.
- Include the thresholds in OK messages as well.
- Cap item lists with `check.summarize(items, limit=10)` → `a, b (+N more)`.
- Exit code must match the status (`0/1/2/3`). Use `check.exit_with_status`.
- Read thresholds from CLI args, never hardcode them.
- Keep perfdata labels and value units stable — `run_checks.py` parsers depend on them.
  Human detail stays *before* the ` | ` separator.
- Multi-item checks (disk, cert, smart, services) build a list of per-item messages and
  perfdata parts, then exit once with the worst status.
- Use a trailing ` ||| ` section only for large structured dumps
  (`check_services.py` unit states); `checkReason()` hides it from the UI.

## 2. Register the check in the `checks` table

Either via SQL or the admin UI (`job_add.php`):

```sql
INSERT INTO checks (script_name, title, interval_minutes, parameters, sudo)
VALUES ('check_<name>.py', 'Human Title', 5, '80 90', 0);
```

- `script_name` must match the filename and is the join key to `health_checks.check_name`.
- `parameters` is the exact CLI argument string (space-separated), parsed with
  `shlex.split()` by the orchestrator.
- Set `sudo = 1` only for checks that must run as root.

## 3. Add a perfdata parser in `run_checks.py`

Add a `_parse_and_store_<name>(cur, message)` function and call it from `write_result`:

```python
def _parse_and_store_<name>(cur, message: str):
    """Parse check_<name>.py perfdata and insert rows into <name>_stats."""
    m = re.search(r'<metric>=(\d+)', message)
    if m:
        cur.execute(
            "INSERT INTO <name>_stats (<col>, run_at) VALUES (?, NOW())",
            (int(m.group(1)),),
        )

# inside write_result():
    if check["script_name"] == "check_<name>.py":
        _parse_and_store_<name>(cur, message)
```

The `cur` object is a `mariadb` cursor; use `?` placeholders and `NOW()` for `run_at`.
Keep the parser tolerant: `return` early when the expected perfdata is absent.

## 4. Add the `*_stats` table to `conf/create_tables.sql`

```sql
CREATE TABLE IF NOT EXISTS <name>_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    <col> INT NOT NULL,
    INDEX idx_<name>_stats_run (run_at)
);
```

- History tables use `run_at` (cleanup.php deletes rows older than 6 months by `run_at`).
- Add an idempotent default `INSERT INTO checks ... ON DUPLICATE KEY UPDATE script_name=script_name;`
  when the check should ship enabled by default.
- Also add the table to the `$run_at_tables` list in `scripts/cleanup.php`.

## 5. Render it in `www/stats.php`

Add a flag near the other `$is_*` booleans and a query + chart section for the new table:

```php
$is_<name> = ($check_name === 'check_<name>.py');
// ... later: if ($is_<name>) { query the <name>_stats table ... }
```

`index.php` needs no change for a new check — the dashboard auto-lists every row in
`health_checks` joined to `checks`, and renders the reason from
`health_checks.message` via `checkReason()`.

The *Latest Result* card at the top of `stats.php` also needs no change: it shows
`checkReason()` for any check, plus the raw output behind a details toggle.

## 6. Test

```bash
python3 .agents/skills/server-health-check/scripts/validate_check.py check_<name>.py <args>
python3 scripts/run_checks.py -all
```

Then confirm in `health_checks`, `health_checks_stats`, and the new `*_stats` table that
rows landed with the expected status and detail values.

## Perfdata format reference

| Script | perfdata | Detail table |
|---|---|---|
| `check_cpu.py` | `load1=;warn;crit;0; load5=... load15=...` | `cpu_stats(load1, load5, load15)` |
| `check_disk.py` | `<label>=usedMB;warnMB;critMB;0;totalMB` per mount | `disk_usage(mountpoint, used_mb, total_mb, warn_mb, crit_mb)` |
| `check_ram.py` | `ram=usedMB;warnMB;critMB;0;totalMB` | `ram_stats(used_mb, total_mb)` |
| `check_processes.py` | `processes=N;warn;crit;0;` | `process_stats(process_count)` |
| `check_mariadb.py` | `<schema>__<table>=rows;warn;crit;0;` per table | `mariadb_table_stats(table_schema, table_name, row_count)` |
| `check_services.py` | `failed=N;warn;crit;0; active=N;; inactive=N;;` + `\|\|\| unit=state ...` | `service_stats(failed_count, active_count, inactive_count)` + `service_unit_states(unit_name, state)` upsert |
| `check_smart.py` | `<dev>_temp=40C` style `<dev>_<metric>=<value><unit>` | `smart_results(device, health)` + `smart_metrics(device, metric, value, unit)` |
| `check_cert.py` / `check_cert_files.py` | `days_left[host:port]=days;warn;crit;0;` | `cert_stats(host, port, days_left)` |
| `check_updates.py` | `pending_updates=N;warn;crit;0;` | `update_stats(pending_count)` |
| `check_zombies.py` | `zombies=N;warn;crit;0;` | `zombie_stats(zombie_count)` |
| `check_reboot.py` | *(none)* | *(status-only)* |
| `check_fs_mirror.py` | *(none)* | *(status-only)* |

Labels use `__` as a schema/table separator (`check_mariadb.py`) and `/` is replaced by
`_` (`check_disk.py`). `check_services.py` appends a ` ||| ` section with `unit=state`
pairs that the parser treats separately from perfdata.
