#!/usr/bin/env python3
import os
import subprocess

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


def top_memory_consumers(limit: int = 3) -> list[tuple[str, int]]:
    """Return [(command, used_mb)] for the processes using the most RAM, grouped by name."""
    try:
        result = subprocess.run(
            ['ps', '-eo', 'rss=,comm='], capture_output=True, text=True, timeout=10,
        )
    except Exception:
        return []

    totals: dict[str, int] = {}
    for line in result.stdout.splitlines():
        parts = line.split(None, 1)
        if len(parts) < 2:
            continue
        try:
            rss_kb = int(parts[0])
        except ValueError:
            continue
        command = parts[1].strip()
        name = os.path.basename(command) or command
        totals[name] = totals.get(name, 0) + rss_kb

    ranked = sorted(totals.items(), key=lambda item: item[1], reverse=True)
    return [(name, round(rss_kb / 1024)) for name, rss_kb in ranked[:limit]]


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

    usage = f"{used_mb}/{total_mb} MB used ({used_pct}%)"

    if used_pct < warn_pct:
        check.exit_with_status(
            "OK",
            f"RAM OK: {usage} (warn {warn_pct:g}%, crit {crit_pct:g}%) | {perfdata}",
        )

    status    = "CRIT" if used_pct >= crit_pct else "WARN"
    threshold = crit_pct if status == "CRIT" else warn_pct
    reason = (
        f"RAM {status}: {usage} exceeds {status.lower()} threshold {threshold:g}% "
        f"({used_mb} of {total_mb} MB used, {total_mb - used_mb} MB free)"
    )

    consumers = top_memory_consumers()
    if consumers:
        reason += "; top consumers: " + check.summarize(
            f"{name} {mb} MB" for name, mb in consumers
        )

    check.exit_with_status(status, f"{reason} | {perfdata}")


if __name__ == '__main__':
    main()
