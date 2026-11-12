#!/usr/bin/env python3
import os

import check


def find_zombies() -> list[tuple[int, str, str]]:
    """Return [(pid, name, ppid)] for every zombie process."""
    zombies = []
    for entry in os.scandir('/proc'):
        if not entry.name.isdigit():
            continue
        try:
            with open(f'/proc/{entry.name}/status') as f:
                fields = {}
                for line in f:
                    key, _, value = line.partition(':')
                    fields[key] = value.strip()
        except OSError:
            continue
        # State is reported as "Z (zombie)" for zombie processes
        if fields.get('State', '').startswith('Z'):
            zombies.append((int(entry.name), fields.get('Name', '?'), fields.get('PPid', '?')))
    return zombies


def main():
    warn, crit = check.parse_args("check_zombies.py <warn_count> <crit_count>", [int, int])

    try:
        zombies = find_zombies()
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to count zombie processes: {exc}")

    count = len(zombies)
    perfdata = f"zombies={count};{warn};{crit};0;"

    if count < warn:
        check.exit_with_status(
            "OK",
            f"ZOMBIES OK: {count} zombie processes (warn {warn}, crit {crit}) | {perfdata}",
        )

    status    = "CRIT" if count >= crit else "WARN"
    threshold = crit if status == "CRIT" else warn
    reason = (
        f"ZOMBIES {status}: {count} zombie processes exceeds "
        f"{status.lower()} threshold {threshold}"
    )
    if zombies:
        reason += "; " + check.summarize(
            f"pid {pid} {name} (parent {ppid})" for pid, name, ppid in zombies
        )

    check.exit_with_status(status, f"{reason} | {perfdata}")


if __name__ == '__main__':
    main()
