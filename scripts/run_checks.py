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

from db import get_connection

SCRIPT_DIR = Path(__file__).resolve().parent

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


def _parse_and_store_disk(cur, message: str):
    """Parse check_disk.py perfdata and insert per-mountpoint rows into disk_usage."""
    if " | " not in message:
        return
    perfdata = message.split(" | ", 1)[1]
    # format: label=usedMB;warnMB;critMB;0;totalMB
    for m in re.finditer(r'(\S+?)=(\d+)MB;(\d+);(\d+);0;(\d+)', perfdata):
        label, used, warn, crit, total = m.group(1), int(m.group(2)), int(m.group(3)), int(m.group(4)), int(m.group(5))
        # label is the mountpoint with leading '/' stripped and inner '/' replaced by '_'
        # Store as-is so round-trips with different path separators are unambiguous
        mountpoint = '/' + label if not label.startswith('/') else label
        cur.execute(
            "INSERT INTO disk_usage (mountpoint, used_mb, total_mb, warn_mb, crit_mb, run_at) VALUES (?, ?, ?, ?, ?, NOW())",
            (mountpoint, used, total, warn, crit),
        )


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


def _parse_and_store_cpu(cur, message: str):
    """Parse check_cpu.py perfdata and insert a row into cpu_stats."""
    if " | " not in message:
        return
    m1  = re.search(r'load1=([0-9.]+)',  message)
    m5  = re.search(r'load5=([0-9.]+)',  message)
    m15 = re.search(r'load15=([0-9.]+)', message)
    if m1 and m5 and m15:
        cur.execute(
            "INSERT INTO cpu_stats (load1, load5, load15, run_at) VALUES (?, ?, ?, NOW())",
            (float(m1.group(1)), float(m5.group(1)), float(m15.group(1))),
        )


def _parse_and_store_ram(cur, message: str):
    """Parse check_ram.py perfdata and insert a row into ram_stats."""
    m = re.search(r'ram=(\d+)MB;\d+;\d+;0;(\d+)', message)
    if m:
        cur.execute(
            "INSERT INTO ram_stats (used_mb, total_mb, run_at) VALUES (?, ?, NOW())",
            (int(m.group(1)), int(m.group(2))),
        )


def _parse_and_store_processes(cur, message: str):
    """Parse check_processes.py perfdata and insert a row into process_stats."""
    m = re.search(r'processes=(\d+)', message)
    if m:
        cur.execute(
            "INSERT INTO process_stats (process_count, run_at) VALUES (?, NOW())",
            (int(m.group(1)),),
        )


def _parse_and_store_mariadb(cur, message: str):
    """Parse check_mariadb.py perfdata and insert per-table rows into mariadb_table_stats."""
    if " | " not in message:
        return
    perfdata = message.split(" | ", 1)[1]
    # format: schema__table=rowcount;warn;crit;0;
    for m in re.finditer(r'(\w+)__(\w+)=(\d+)', perfdata):
        cur.execute(
            "INSERT INTO mariadb_table_stats (table_schema, table_name, row_count, run_at) VALUES (?, ?, ?, NOW())",
            (m.group(1), m.group(2), int(m.group(3))),
        )


def _parse_and_store_services(cur, message: str):
    """Parse check_services.py perfdata and insert a row into service_stats."""
    m_failed   = re.search(r'failed=(\d+)',    message)
    m_active   = re.search(r'\bactive=(\d+)',  message)
    m_inactive = re.search(r'inactive=(\d+)',  message)
    if m_failed and m_active and m_inactive:
        cur.execute(
            "INSERT INTO service_stats (failed_count, active_count, inactive_count, run_at) VALUES (?, ?, ?, NOW())",
            (int(m_failed.group(1)), int(m_active.group(1)), int(m_inactive.group(1))),
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

    # For the disk check, persist per-mountpoint usage rows.
    if check["script_name"] == "check_disk.py":
        _parse_and_store_disk(cur, message)

    # For the CPU check, persist load average rows.
    if check["script_name"] == "check_cpu.py":
        _parse_and_store_cpu(cur, message)

    # For the RAM check, persist RAM usage rows.
    if check["script_name"] == "check_ram.py":
        _parse_and_store_ram(cur, message)

    # For the process check, persist process count rows.
    if check["script_name"] == "check_processes.py":
        _parse_and_store_processes(cur, message)

    # For the MariaDB check, persist per-table row count snapshots.
    if check["script_name"] == "check_mariadb.py":
        _parse_and_store_mariadb(cur, message)

    # For the service check, persist service status counts.
    if check["script_name"] == "check_services.py":
        _parse_and_store_services(cur, message)


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
