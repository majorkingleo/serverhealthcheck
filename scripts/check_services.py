#!/usr/bin/env python3
import subprocess

import check


def get_disabled_units():
    """Return a set of unit names whose unit-file state is disabled or masked."""
    result = subprocess.run(
        ['systemctl', 'list-unit-files', '--type=service',
         '--no-legend', '--no-pager', '--plain'],
        capture_output=True, text=True, timeout=30,
    )
    disabled = set()
    for line in result.stdout.splitlines():
        parts = line.split()
        # columns: UNIT_FILE  STATE  [VENDOR_PRESET]
        if len(parts) < 2:
            continue
        unit, state = parts[0], parts[1].lower()
        if state in ('disabled', 'masked', 'masked-runtime'):
            disabled.add(unit)
    return disabled


def list_service_states():
    """Return (counts_dict, failed_names_list, unit_states_list) from systemctl,
    excluding units whose unit-file state is disabled or masked."""
    disabled = get_disabled_units()

    result = subprocess.run(
        ['systemctl', 'list-units', '--type=service', '--all',
         '--no-legend', '--no-pager', '--plain'],
        capture_output=True, text=True, timeout=30,
    )
    counts = {'active': 0, 'inactive': 0, 'failed': 0}
    failed_names = []
    unit_states = []
    for line in result.stdout.splitlines():
        parts = line.split()
        # columns: UNIT  LOAD  ACTIVE  SUB  DESCRIPTION...
        if len(parts) < 3:
            continue
        unit = parts[0]
        if unit in disabled:
            continue
        state = parts[2].lower()
        if state in counts:
            counts[state] += 1
        if state == 'failed':
            failed_names.append(unit)
        unit_states.append((unit, state))
    return counts, failed_names, unit_states


def main():
    warn, crit = check.parse_args(
        "check_services.py <warn_failed> <crit_failed>", [int, int]
    )

    try:
        counts, failed, unit_states = list_service_states()
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
    units_str = ' '.join(f"{u}={s}" for u, s in unit_states)

    if n_failed >= crit:
        detail = ', '.join(failed[:5])
        check.exit_with_status("CRIT", f"SERVICES CRIT: {n_failed} failed ({detail}) | {perfdata} ||| {units_str}")
    elif n_failed >= warn:
        detail = ', '.join(failed[:5])
        check.exit_with_status("WARN", f"SERVICES WARN: {n_failed} failed ({detail}) | {perfdata} ||| {units_str}")
    else:
        check.exit_with_status(
            "OK",
            f"SERVICES OK: {n_active} active, {n_inactive} inactive, {n_failed} failed | {perfdata} ||| {units_str}",
        )


if __name__ == '__main__':
    main()
