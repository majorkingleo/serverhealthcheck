#!/usr/bin/env python3
import datetime
import argparse
import os
import re
import shlex
import subprocess
import sys
from pathlib import Path

import mariadb

SCRIPT_DIR = Path(__file__).resolve().parent
DB_CONFIG_PATH = SCRIPT_DIR.parent / "conf" / "db.php"


def load_php_db_config(config_path: Path):
    if not config_path.exists():
        raise RuntimeError(f"DB config file not found: {config_path}")

    text = config_path.read_text(encoding="utf-8")

    def extract(name: str) -> str:
        pattern = re.compile(
            rf"define\(\s*['\"]{name}['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)\s*;"
        )
        match = pattern.search(text)
        if not match:
            raise RuntimeError(f"Missing {name} in {config_path}")
        return match.group(1)

    return {
        "host": extract("DB_HOST"),
        "user": extract("DB_USER"),
        "password": extract("DB_PASS"),
        "name": extract("DB_NAME"),
    }


DB_CONFIG = load_php_db_config(DB_CONFIG_PATH)


def get_connection():
    return mariadb.connect(
        host=DB_CONFIG["host"],
        user=DB_CONFIG["user"],
        password=DB_CONFIG["password"],
        database=DB_CONFIG["name"],
    )


def get_due_checks(conn):
    cur = conn.cursor()
    cur.execute(
        "SELECT script_name, title, interval_minutes, parameters, sudo "
        "FROM checks "
        "WHERE enabled = 1 AND (next_run IS NULL OR next_run <= NOW())"
    )
    checks = []
    for script_name, title, interval_minutes, parameters, use_sudo in cur.fetchall():
        checks.append(
            {
                "script_name": script_name,
                "title": title or script_name,
                "interval_minutes": int(interval_minutes or "5"),
                "parameters": parameters or "",
                "use_sudo": str(use_sudo).strip() in ("1", "true", "TRUE", "yes", "YES"),
            }
        )
    return checks


def get_all_enabled_checks(conn):
    cur = conn.cursor()
    cur.execute(
        "SELECT script_name, title, interval_minutes, parameters, sudo "
        "FROM checks "
        "WHERE enabled = 1"
    )
    checks = []
    for script_name, title, interval_minutes, parameters, use_sudo in cur.fetchall():
        checks.append(
            {
                "script_name": script_name,
                "title": title or script_name,
                "interval_minutes": int(interval_minutes or "5"),
                "parameters": parameters or "",
                "use_sudo": str(use_sudo).strip() in ("1", "true", "TRUE", "yes", "YES"),
            }
        )
    return checks



def map_exit_code_to_status(exit_code: int) -> str:
    if exit_code == 0:
        return "OK"
    if exit_code == 1:
        return "WARN"
    if exit_code == 2:
        return "ERROR"
    return "UNKNOWN"


def execute_check(check):
    script_path = SCRIPT_DIR / check["script_name"]
    if not script_path.exists():
        return "UNKNOWN", f"Script not found: {script_path}"

    args = shlex.split(check["parameters"])

    if os.access(script_path, os.X_OK):
        cmd = [str(script_path)] + args
    else:
        cmd = [sys.executable, str(script_path)] + args

    if check.get("use_sudo"):
        cmd = ["sudo", "-n"] + cmd

    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
        status = map_exit_code_to_status(result.returncode)
        message = (result.stdout or "").strip()
        if result.stderr.strip():
            if message:
                message = f"{message} | stderr: {result.stderr.strip()}"
            else:
                message = f"stderr: {result.stderr.strip()}"
        if not message:
            message = f"No output (exit {result.returncode})"
        return status, message
    except subprocess.TimeoutExpired:
        return "UNKNOWN", "Execution timed out after 300 seconds"
    except FileNotFoundError as exc:
        return "UNKNOWN", f"Execution failed: {exc}"
    except Exception as exc:
        return "UNKNOWN", f"Execution failed: {exc}"


def _parse_and_store_smart(cur, message: str):
    """Parse check_smart.py stdout and insert per-disk rows into smart_results / smart_metrics."""
    parts = message.split(" | ", 1)
    body = parts[0]
    perfdata = parts[1] if len(parts) > 1 else ""

    # Per-disk health: "/dev/sda: SMART health PASSED"
    for m in re.finditer(r'(/dev/\w+): SMART health (PASSED|FAILED|UNKNOWN)', body):
        cur.execute(
            "INSERT INTO smart_results (device, health, run_at) VALUES (?, ?, NOW())",
            (m.group(1), m.group(2)),
        )

    # Perfdata metrics: "sde_temp=40C"
    for m in re.finditer(r'(\w+?)_(\w+)=(\d+(?:\.\d+)?)([A-Za-z%]*)', perfdata):
        device = "/dev/" + m.group(1)
        metric = m.group(2)
        value = float(m.group(3))
        unit = m.group(4) or None
        cur.execute(
            "INSERT INTO smart_metrics (device, metric, value, unit, run_at) VALUES (?, ?, ?, ?, NOW())",
            (device, metric, value, unit),
        )


def write_result(conn, check, status: str, message: str):
    cur = conn.cursor()

    cur.execute(
        "UPDATE health_checks SET status = ?, message = ?, timestamp = NOW() WHERE check_name = ?",
        (status, message, check["script_name"]),
    )
    if cur.rowcount == 0:
        cur.execute(
            "INSERT INTO health_checks (check_name, status, message, timestamp) VALUES (?, ?, ?, NOW())",
            (check["script_name"], status, message),
        )

    # Append a stats row for every run.
    cur.execute(
        "INSERT INTO health_checks_stats (check_name, status, timestamp) VALUES (?, ?, NOW())",
        (check["script_name"], status),
    )

    # For the SMART check, also persist per-disk detail rows.
    if check["script_name"] == "check_smart.py":
        _parse_and_store_smart(cur, message)


def update_schedule(conn, check):
    interval_minutes = max(1, int(check["interval_minutes"]))
    next_run = datetime.datetime.now() + datetime.timedelta(minutes=interval_minutes)
    cur = conn.cursor()
    cur.execute(
        "UPDATE checks SET last_run = NOW(), next_run = ? WHERE script_name = ?",
        (next_run, check["script_name"]),
    )


def main():
    parser = argparse.ArgumentParser(description="Run configured health checks")
    parser.add_argument("-all", action="store_true", dest="run_all", help="Run all enabled checks immediately")
    args = parser.parse_args()

    try:
        conn = get_connection()
    except mariadb.Error as exc:
        print(f"Failed to connect to database: {exc}", file=sys.stderr)
        return 1

    try:
        if args.run_all:
            checks = get_all_enabled_checks(conn)
        else:
            checks = get_due_checks(conn)
    except Exception as exc:
        print(f"Failed to load checks: {exc}", file=sys.stderr)
        conn.close()
        return 1

    if not checks:
        now = datetime.datetime.now().isoformat(timespec="seconds")
        print(f"[{now}] No checks due")
        conn.close()
        return 0

    for check in checks:
        try:
            status, message = execute_check(check)
            write_result(conn, check, status, message)
            update_schedule(conn, check)
            conn.commit()
            print(f"{check['script_name']}: {status} - {message}")
        except Exception as exc:
            conn.rollback()
            print(f"{check['script_name']}: runner error: {exc}", file=sys.stderr)

    conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
