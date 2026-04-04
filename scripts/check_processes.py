#!/usr/bin/env python3
import sys
import os


def count_processes():
    """Count running processes by listing /proc entries."""
    return sum(1 for entry in os.scandir('/proc') if entry.name.isdigit())


def main():
    if len(sys.argv) != 3:
        print("UNKNOWN: Usage: check_processes.py <warn_count> <crit_count>")
        sys.exit(3)

    try:
        warn = int(sys.argv[1])
        crit = int(sys.argv[2])
    except ValueError:
        print("UNKNOWN: Invalid parameters")
        sys.exit(3)

    try:
        count = count_processes()
    except Exception as exc:
        print(f"UNKNOWN: Failed to count processes: {exc}")
        sys.exit(3)

    perfdata = f"processes={count};{warn};{crit};0;"

    if count >= crit:
        print(f"PROCESSES CRIT: {count} processes running | {perfdata}")
        sys.exit(2)
    elif count >= warn:
        print(f"PROCESSES WARN: {count} processes running | {perfdata}")
        sys.exit(1)
    else:
        print(f"PROCESSES OK: {count} processes running | {perfdata}")
        sys.exit(0)


if __name__ == '__main__':
    main()
