#!/usr/bin/env python3
import os
import subprocess

import check


def top_cpu_processes(limit: int = 3) -> list[tuple[str, float]]:
    """Return [(command, cpu_percent)] for the busiest processes, grouped by name."""
    try:
        result = subprocess.run(
            ['ps', '-eo', 'pcpu=,comm='], capture_output=True, text=True, timeout=10,
        )
    except Exception:
        return []

    totals: dict[str, float] = {}
    for line in result.stdout.splitlines():
        parts = line.split(None, 1)
        if len(parts) < 2:
            continue
        try:
            cpu = float(parts[0])
        except ValueError:
            continue
        command = parts[1].strip()
        name = os.path.basename(command) or command
        totals[name] = totals.get(name, 0.0) + cpu

    ranked = sorted(totals.items(), key=lambda item: item[1], reverse=True)
    return ranked[:limit]


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

    cores = os.cpu_count() or 1
    loads = f"{load1}/{load5}/{load15}"

    if load1 < warn:
        check.exit_with_status(
            "OK",
            f"CPU OK: load average {loads} on {cores} cores "
            f"(warn {warn:g}, crit {crit:g}) | {perfdata}",
        )

    status    = "CRIT" if load1 >= crit else "WARN"
    threshold = crit if status == "CRIT" else warn
    reason = (
        f"CPU {status}: 1-min load average {load1} on {cores} cores exceeds "
        f"{status.lower()} threshold {threshold:g} "
        f"(5-min {load5}, 15-min {load15})"
    )

    busiest = top_cpu_processes()
    if busiest:
        reason += "; busiest processes: " + check.summarize(
            f"{name} {cpu:.0f}%" for name, cpu in busiest
        )

    check.exit_with_status(status, f"{reason} | {perfdata}")


if __name__ == '__main__':
    main()
