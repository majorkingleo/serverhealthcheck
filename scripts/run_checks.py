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
        "SELECT script_name, title, interval_minutes, parameters, target_table, sudo "
        "FROM checks "
        "WHERE enabled = 1 AND (next_run IS NULL OR next_run <= NOW())"
    )
    checks = []
    for script_name, title, interval_minutes, parameters, target_table, use_sudo in cur.fetchall():
        checks.append(
            {
                "script_name": script_name,
                "title": title or script_name,
                "interval_minutes": int(interval_minutes or "5"),
                "parameters": parameters or "",
                "target_table": target_table or "health_checks",
                "use_sudo": str(use_sudo).strip() in ("1", "true", "TRUE", "yes", "YES"),
            }
        )
    return checks


def get_all_enabled_checks(conn):
    cur = conn.cursor()
    cur.execute(
        "SELECT script_name, title, interval_minutes, parameters, target_table, sudo "
        "FROM checks "
        "WHERE enabled = 1"
    )
    checks = []
    for script_name, title, interval_minutes, parameters, target_table, use_sudo in cur.fetchall():
        checks.append(
            {
                "script_name": script_name,
                "title": title or script_name,
                "interval_minutes": int(interval_minutes or "5"),
                "parameters": parameters or "",
                "target_table": target_table or "health_checks",
                "use_sudo": str(use_sudo).strip() in ("1", "true", "TRUE", "yes", "YES"),
            }
        )
    return checks


def sanitize_table_name(name: str) -> str:
    safe = "".join(ch for ch in name if ch.isalnum() or ch == "_")
    return safe if safe else "health_checks"


def map_exit_code_to_status(exit_code: int) -> str:
    if exit_code == 0:
        return "OK"
    if exit_code == 1:
        return "WARN"
    if exit_code == 2:
        return "ERROR"
    return "UNKNOWN"


def parse_main_state(output: str, fallback_status: str) -> str:
    upper = (output or "").upper()
    if "WARNIING" in upper or "WARNING" in upper or " WARN " in f" {upper} ":
        return "WARNING"
    if "ERROR" in upper:
        return "ERROR"
    if "OK" in upper:
        return "OK"

    if fallback_status == "WARN":
        return "WARNING"
    if fallback_status in ("OK", "ERROR", "UNKNOWN"):
        return fallback_status
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


def write_result(conn, check, status: str, message: str):
    table_name = sanitize_table_name(check["target_table"])
    cur = conn.cursor()

    def update_then_insert(target_table: str):
        cur.execute(
            f"UPDATE `{target_table}` SET status = ?, message = ?, timestamp = NOW() WHERE check_name = ?",
            (status, message, check["script_name"]),
        )
        if cur.rowcount == 0:
            cur.execute(
                f"INSERT INTO `{target_table}` (check_name, status, message, timestamp) VALUES (?, ?, ?, NOW())",
                (check["script_name"], status, message),
            )

    # Always keep the latest real check result in health_checks.
    update_then_insert("health_checks")

    # Optionally mirror into a configured target table without changing health_checks on failure.
    if table_name != "health_checks":
        try:
            update_then_insert(table_name)
        except mariadb.Error as exc:
            print(
                f"{check['script_name']}: could not write to target table {table_name}: {exc}",
                file=sys.stderr,
            )


def upsert_health_state(conn, check, state: str, message: str):
    cur = conn.cursor()
    cur.execute(
        "INSERT INTO HEALTH (check_name, title, state, message, updated_at) "
        "VALUES (?, ?, ?, ?, NOW()) "
        "ON DUPLICATE KEY UPDATE title = VALUES(title), state = VALUES(state), message = VALUES(message), updated_at = NOW()",
        (check["script_name"], check["title"], state, message),
    )


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
            main_state = parse_main_state(message, status)
            write_result(conn, check, status, message)
            upsert_health_state(conn, check, main_state, message)
            update_schedule(conn, check)
            conn.commit()
            print(f"{check['script_name']}: {main_state} ({status}) - {message}")
        except Exception as exc:
            conn.rollback()
            print(f"{check['script_name']}: runner error: {exc}", file=sys.stderr)

    conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
