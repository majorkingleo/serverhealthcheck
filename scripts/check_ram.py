#!/usr/bin/env python3
import sys

import check


def read_meminfo():
    """Return a dict of /proc/meminfo key → kB values."""
    info = {}
    with open('/proc/meminfo') as f:
        for line in f:
            parts = line.split()
            if len(parts) >= 2:
                key = parts[0].rstrip(':')
                info[key] = int(parts[1])
    return info


def main():
    warn_pct, crit_pct = check.parse_args("check_ram.py <warn_percent> <crit_percent>")

    try:
        info     = read_meminfo()
        total_kb = info['MemTotal']
        avail_kb = info.get('MemAvailable', info.get('MemFree', 0))
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to read memory info: {exc}")

    used_kb  = total_kb - avail_kb
    total_mb = round(total_kb / 1024)
    used_mb  = round(used_kb  / 1024)
    used_pct = round(used_kb / total_kb * 100, 1) if total_kb else 0.0

    warn_mb  = round(total_mb * warn_pct / 100)
    crit_mb  = round(total_mb * crit_pct / 100)
    perfdata = f"ram={used_mb}MB;{warn_mb};{crit_mb};0;{total_mb}"

    if used_pct >= crit_pct:
        check.exit_with_status("CRIT", f"RAM CRIT: {used_mb}/{total_mb} MB used ({used_pct}%) | {perfdata}")
    elif used_pct >= warn_pct:
        check.exit_with_status("WARN", f"RAM WARN: {used_mb}/{total_mb} MB used ({used_pct}%) | {perfdata}")
    else:
        check.exit_with_status("OK", f"RAM OK: {used_mb}/{total_mb} MB used ({used_pct}%) | {perfdata}")


if __name__ == '__main__':
    main()
