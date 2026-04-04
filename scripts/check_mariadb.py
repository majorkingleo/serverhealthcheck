#!/usr/bin/env python3
import re
import sys
from pathlib import Path

import mariadb

SCRIPT_DIR = Path(__file__).resolve().parent
DB_CONFIG_PATH = SCRIPT_DIR.parent / "conf" / "db.php"

SYSTEM_SCHEMAS = frozenset({
    'information_schema', 'mysql', 'performance_schema', 'sys',
})


def load_php_db_config(config_path: Path):
    if not config_path.exists():
        raise RuntimeError(f"DB config file not found: {config_path}")
    text = config_path.read_text(encoding="utf-8")

    def extract(name):
        m = re.search(
            rf"define\(\s*['\"]{{name}}['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)\s*;".replace("{name}", name),
            text
        )
        if not m:
            raise RuntimeError(f"Missing {name} in {config_path}")
        return m.group(1)

    return {
        "host":     extract("DB_HOST"),
        "user":     extract("DB_USER"),
        "password": extract("DB_PASS"),
        "name":     extract("DB_NAME"),
    }


def main():
    if len(sys.argv) != 3:
        print("UNKNOWN: Usage: check_mariadb.py <warn_rows_per_day> <crit_rows_per_day>")
        sys.exit(3)

    try:
        warn = int(sys.argv[1])
        crit = int(sys.argv[2])
    except ValueError:
        print("UNKNOWN: Invalid parameters")
        sys.exit(3)

    try:
        cfg = load_php_db_config(DB_CONFIG_PATH)
    except Exception as exc:
        print(f"UNKNOWN: Config error: {exc}")
        sys.exit(3)

    try:
        conn = mariadb.connect(
            host=cfg["host"],
            user=cfg["user"],
            password=cfg["password"],
            database=cfg["name"],
            connect_timeout=10,
        )
    except mariadb.Error as exc:
        print(f"CRIT: Cannot connect to MariaDB: {exc}")
        sys.exit(2)

    try:
        cur = conn.cursor()

        # Current row counts from information_schema
        cur.execute(
            "SELECT table_schema, table_name, COALESCE(table_rows, 0) AS row_count "
            "FROM information_schema.TABLES "
            "WHERE table_type = 'BASE TABLE' "
            "  AND table_schema NOT IN ('information_schema','mysql','performance_schema','sys') "
            "ORDER BY table_schema, table_name"
        )
        current = {f"{r[0]}__{r[1]}": int(r[2]) for r in cur.fetchall()}

        if not current:
            print("OK: MariaDB is up, no user tables found | ")
            sys.exit(0)

        # Previous snapshot: most recent row in mariadb_table_stats older than 20h
        cur.execute(
            "SELECT table_schema, table_name, row_count "
            "FROM mariadb_table_stats "
            "WHERE run_at = ("
            "  SELECT MAX(run_at) FROM mariadb_table_stats "
            "  WHERE run_at <= DATE_SUB(NOW(), INTERVAL 20 HOUR)"
            ")"
        )
        previous = {f"{r[0]}__{r[1]}": int(r[2]) for r in cur.fetchall()}

        # Check growth
        worst         = "OK"
        warn_tables   = []
        crit_tables   = []

        for key, count in current.items():
            if key not in previous:
                continue
            growth = count - previous[key]
            if growth >= crit:
                crit_tables.append((key, growth))
                worst = "CRIT"
            elif growth >= warn:
                warn_tables.append((key, growth))
                if worst != "CRIT":
                    worst = "WARN"

        # Build perfdata  label uses __ as schema/table separator
        perfdata_parts = [f"{k}={v};{warn};{crit};0;" for k, v in sorted(current.items())]
        perfdata = " ".join(perfdata_parts)

        if worst == "CRIT":
            details = ", ".join(f"{t.split('__')[1]} +{g}" for t, g in crit_tables)
            print(f"CRIT: MariaDB table growth exceeded critical threshold: {details} | {perfdata}")
            sys.exit(2)
        elif worst == "WARN":
            details = ", ".join(f"{t.split('__')[1]} +{g}" for t, g in warn_tables)
            print(f"WARN: MariaDB table growth exceeded warning threshold: {details} | {perfdata}")
            sys.exit(1)
        else:
            table_count = len(current)
            print(f"OK: MariaDB is up, {table_count} tables healthy | {perfdata}")
            sys.exit(0)

    except mariadb.Error as exc:
        print(f"UNKNOWN: Query error: {exc}")
        sys.exit(3)
    finally:
        conn.close()


if __name__ == '__main__':
    main()
