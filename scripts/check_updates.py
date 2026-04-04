#!/usr/bin/env python3
import os
import subprocess

import check


def count_pending_updates() -> int:
    """Return the number of pending package upgrades via apt-get -s upgrade."""
    env = {**os.environ, 'DEBIAN_FRONTEND': 'noninteractive', 'LANG': 'C'}
    result = subprocess.run(
        ['apt-get', '--just-print', 'upgrade'],
        capture_output=True,
        text=True,
        timeout=120,
        env=env,
    )
    return sum(1 for line in result.stdout.splitlines() if line.startswith('Inst '))


def main():
    warn, crit = check.parse_args("check_updates.py <warn_count> <crit_count>", [int, int])

    try:
        count = count_pending_updates()
    except FileNotFoundError:
        check.exit_with_status("UNKNOWN", "UNKNOWN: apt-get not found — unsupported package manager")
    except subprocess.TimeoutExpired:
        check.exit_with_status("UNKNOWN", "UNKNOWN: apt-get timed out")
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to check for updates: {exc}")

    perfdata = f"pending_updates={count};{warn};{crit};0;"

    if count >= crit:
        check.exit_with_status("CRIT", f"UPDATES CRIT: {count} pending updates | {perfdata}")
    elif count >= warn:
        check.exit_with_status("WARN", f"UPDATES WARN: {count} pending updates | {perfdata}")
    else:
        check.exit_with_status("OK", f"UPDATES OK: {count} pending updates | {perfdata}")


if __name__ == '__main__':
    main()
