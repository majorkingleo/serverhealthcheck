#!/usr/bin/env python3
import sys


def main():
    if len(sys.argv) != 3:
        print("UNKNOWN: Usage: check_cpu.py <warn_load> <crit_load>")
        sys.exit(3)

    try:
        warn = float(sys.argv[1])
        crit = float(sys.argv[2])
    except ValueError:
        print("UNKNOWN: Invalid parameters")
        sys.exit(3)

    try:
        with open('/proc/loadavg') as f:
            parts = f.read().split()
        load1  = float(parts[0])
        load5  = float(parts[1])
        load15 = float(parts[2])
    except Exception as exc:
        print(f"UNKNOWN: Failed to read load average: {exc}")
        sys.exit(3)

    perfdata = (
        f"load1={load1};{warn};{crit};0; "
        f"load5={load5};{warn};{crit};0; "
        f"load15={load15};{warn};{crit};0;"
    )

    if load1 >= crit:
        print(f"CPU CRIT: Load avg {load1}/{load5}/{load15} | {perfdata}")
        sys.exit(2)
    elif load1 >= warn:
        print(f"CPU WARN: Load avg {load1}/{load5}/{load15} | {perfdata}")
        sys.exit(1)
    else:
        print(f"CPU OK: Load avg {load1}/{load5}/{load15} | {perfdata}")
        sys.exit(0)


if __name__ == '__main__':
    main()
