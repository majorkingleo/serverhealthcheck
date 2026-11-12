#!/usr/bin/env python3
"""Run a single health-check script and report its Nagios-style result.

Use this to validate a check script's exit code and output format without going
through the orchestrator.

Usage:
    python3 validate_check.py <check_script> [args...]

Examples:
    python3 validate_check.py check_disk.py 80 90
    python3 validate_check.py check_cpu.py 2.0 4.0
"""
import subprocess
import sys
from pathlib import Path

EXIT_STATUS = {0: "OK", 1: "WARN", 2: "ERROR/CRIT", 3: "UNKNOWN"}


def find_script(name: str):
    """Resolve the check script relative to the repo root or current directory."""
    cwd = Path.cwd()
    candidates = [
        cwd / "scripts" / name,
        cwd / name,
        Path(name),
    ]
    for candidate in candidates:
        if candidate.is_file():
            return candidate
    return None


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: validate_check.py <check_script> [args...]", file=sys.stderr)
        return 2

    script = find_script(sys.argv[1])
    if script is None:
        print(f"Script not found: {sys.argv[1]}", file=sys.stderr)
        return 2

    cmd = [sys.executable, str(script)] + sys.argv[2:]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
    except subprocess.TimeoutExpired:
        print("TIMEOUT (120s)")
        return 3

    status = EXIT_STATUS.get(result.returncode, "UNKNOWN")
    stdout = (result.stdout or "").strip()

    print(f"exit code : {result.returncode} -> {status}")
    print(f"stdout    : {stdout}")
    if result.stderr.strip():
        print(f"stderr    : {result.stderr.strip()}")

    if " | " in stdout:
        print("perfdata  : present")
    else:
        print("perfdata  : MISSING (most checks must emit `| perfdata`)")

    return result.returncode


if __name__ == "__main__":
    sys.exit(main())
