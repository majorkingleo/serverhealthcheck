#!/usr/bin/env python3
import os

import check


def count_processes() -> int:
    """Count running processes by listing /proc entries."""
    return sum(1 for entry in os.scandir('/proc') if entry.name.isdigit())


def count_by_name(limit: int = 3) -> list[tuple[str, int]]:
    """Return [(command, process_count)] for the most common process names."""
    totals: dict[str, int] = {}
    for entry in os.scandir('/proc'):
        if not entry.name.isdigit():
            continue
        try:
            with open(f'/proc/{entry.name}/comm') as f:
                name = f.read().strip()
        except OSError:
            continue
        if not name:
            continue
        totals[name] = totals.get(name, 0) + 1
    ranked = sorted(totals.items(), key=lambda item: item[1], reverse=True)
    return ranked[:limit]


def main():
    warn, crit = check.parse_args("check_processes.py <warn_count> <crit_count>", [int, int])

    try:
        count = count_processes()
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to count processes: {exc}")

    perfdata = f"processes={count};{warn};{crit};0;"

    if count < warn:
        check.exit_with_status(
            "OK",
            f"PROCESSES OK: {count} processes running (warn {warn}, crit {crit}) | {perfdata}",
        )

    status    = "CRIT" if count >= crit else "WARN"
    threshold = crit if status == "CRIT" else warn
    reason = (
        f"PROCESSES {status}: {count} processes running exceeds "
        f"{status.lower()} threshold {threshold}"
    )

    common = count_by_name()
    if common:
        reason += "; most common: " + check.summarize(
            f"{name} {amount}" for name, amount in common
        )

    check.exit_with_status(status, f"{reason} | {perfdata}")


if __name__ == '__main__':
    main()
