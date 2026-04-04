#!/usr/bin/env python3
import sys

import check


def main():
    warn, crit = check.parse_args("check_cpu.py <warn_load> <crit_load>")

    try:
        with open('/proc/loadavg') as f:
            parts = f.read().split()
        load1  = float(parts[0])
        load5  = float(parts[1])
        load15 = float(parts[2])
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to read load average: {exc}")

    perfdata = (
        f"load1={load1};{warn};{crit};0; "
        f"load5={load5};{warn};{crit};0; "
        f"load15={load15};{warn};{crit};0;"
    )

    if load1 >= crit:
        check.exit_with_status("CRIT", f"CPU CRIT: Load avg {load1}/{load5}/{load15} | {perfdata}")
    elif load1 >= warn:
        check.exit_with_status("WARN", f"CPU WARN: Load avg {load1}/{load5}/{load15} | {perfdata}")
    else:
        check.exit_with_status("OK", f"CPU OK: Load avg {load1}/{load5}/{load15} | {perfdata}")


if __name__ == '__main__':
    main()
