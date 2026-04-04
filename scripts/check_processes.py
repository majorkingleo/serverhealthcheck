#!/usr/bin/env python3
import os
import sys

import check


def count_processes():
    """Count running processes by listing /proc entries."""
    return sum(1 for entry in os.scandir('/proc') if entry.name.isdigit())


def main():
    warn, crit = check.parse_args("check_processes.py <warn_count> <crit_count>", [int, int])

    try:
        count = count_processes()
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to count processes: {exc}")

    perfdata = f"processes={count};{warn};{crit};0;"

    if count >= crit:
        check.exit_with_status("CRIT", f"PROCESSES CRIT: {count} processes running | {perfdata}")
    elif count >= warn:
        check.exit_with_status("WARN", f"PROCESSES WARN: {count} processes running | {perfdata}")
    else:
        check.exit_with_status("OK", f"PROCESSES OK: {count} processes running | {perfdata}")


if __name__ == '__main__':
    main()
