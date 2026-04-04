#!/usr/bin/env python3
import subprocess

import check


def list_service_states():
    """Return (counts_dict, failed_names_list) from systemctl."""
    result = subprocess.run(
        ['systemctl', 'list-units', '--type=service', '--all',
         '--no-legend', '--no-pager', '--plain'],
        capture_output=True, text=True, timeout=30,
    )
    counts = {'active': 0, 'inactive': 0, 'failed': 0}
    failed_names = []
    for line in result.stdout.splitlines():
        parts = line.split()
        # columns: UNIT  LOAD  ACTIVE  SUB  DESCRIPTION...
        if len(parts) < 3:
            continue
        state = parts[2].lower()
        if state in counts:
            counts[state] += 1
        if state == 'failed':
            failed_names.append(parts[0])
    return counts, failed_names


def main():
    warn, crit = check.parse_args(
        "check_services.py <warn_failed> <crit_failed>", [int, int]
    )

    try:
        counts, failed = list_service_states()
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to query services: {exc}")

    n_failed   = counts['failed']
    n_active   = counts['active']
    n_inactive = counts['inactive']

    perfdata = (
        f"failed={n_failed};{warn};{crit};0; "
        f"active={n_active};; "
        f"inactive={n_inactive};;"
    )

    if n_failed >= crit:
        detail = ', '.join(failed[:5])
        check.exit_with_status("CRIT", f"SERVICES CRIT: {n_failed} failed ({detail}) | {perfdata}")
    elif n_failed >= warn:
        detail = ', '.join(failed[:5])
        check.exit_with_status("WARN", f"SERVICES WARN: {n_failed} failed ({detail}) | {perfdata}")
    else:
        check.exit_with_status(
            "OK",
            f"SERVICES OK: {n_active} active, {n_inactive} inactive, {n_failed} failed | {perfdata}",
        )


if __name__ == '__main__':
    main()
