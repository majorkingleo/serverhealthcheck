#!/usr/bin/env python3
import os

import check


def count_zombies() -> int:
    """Count zombie processes by reading State from /proc/<pid>/status."""
    count = 0
    for entry in os.scandir('/proc'):
        if not entry.name.isdigit():
            continue
        try:
            with open(f'/proc/{entry.name}/status') as f:
                for line in f:
                    if line.startswith('State:'):
                        if 'Z' in line:
                            count += 1
                        break
        except OSError:
            pass
    return count


def main():
    warn, crit = check.parse_args("check_zombies.py <warn_count> <crit_count>", [int, int])

    try:
        count = count_zombies()
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to count zombie processes: {exc}")

    perfdata = f"zombies={count};{warn};{crit};0;"

    if count >= crit:
        check.exit_with_status("CRIT", f"ZOMBIES CRIT: {count} zombie processes | {perfdata}")
    elif count >= warn:
        check.exit_with_status("WARN", f"ZOMBIES WARN: {count} zombie processes | {perfdata}")
    else:
        check.exit_with_status("OK", f"ZOMBIES OK: {count} zombie processes | {perfdata}")


if __name__ == '__main__':
    main()
