#!/usr/bin/env python3
import os
import re
import subprocess

import check


def parse_simulation(stdout: str) -> tuple[list[tuple[str, str, str]], list[str]]:
    """Return (upgradeable, kept_back) from `apt-get --just-print upgrade` output.

    upgradeable: [(package, old_version, new_version)]
    kept_back:   [package]  (held back, so apt will not install them)
    """
    upgrades = []
    kept_back = []
    in_kept_back = False

    for line in stdout.splitlines():
        if line.startswith('Inst '):
            in_kept_back = False
            # Inst <name> [<old>] (<new> <repo> [<arch>])
            m = re.match(
                r'Inst\s+(\S+)\s+(?:\[(\S+)\]\s+)?\((\S+)\s+(\S+?)[\s\[]',
                line,
            )
            if m:
                upgrades.append((m.group(1), m.group(2) or '', m.group(3)))
            else:
                upgrades.append((line.split()[1], '', ''))
            continue

        if 'have been kept back' in line:
            in_kept_back = True
            continue

        if in_kept_back:
            stripped = line.strip()
            if not stripped or stripped.startswith(('The following', '0 upgraded', 'Use ')):
                in_kept_back = False
                continue
            kept_back.extend(stripped.split())

    return upgrades, kept_back


def pending_updates() -> tuple[list[tuple[str, str, str]], list[str]]:
    """Run the apt simulation and return its pending upgrades and kept-back packages."""
    env = {**os.environ, 'DEBIAN_FRONTEND': 'noninteractive', 'LANG': 'C'}
    result = subprocess.run(
        ['apt-get', '--just-print', 'upgrade'],
        capture_output=True,
        text=True,
        timeout=120,
        env=env,
    )
    return parse_simulation(result.stdout)


def main():
    warn, crit = check.parse_args("check_updates.py <warn_count> <crit_count>", [int, int])

    try:
        upgrades, kept_back = pending_updates()
    except FileNotFoundError:
        check.exit_with_status("UNKNOWN", "UNKNOWN: apt-get not found — unsupported package manager")
    except subprocess.TimeoutExpired:
        check.exit_with_status("UNKNOWN", "UNKNOWN: apt-get timed out")
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Failed to check for updates: {exc}")

    count = len(upgrades)
    perfdata = f"pending_updates={count};{warn};{crit};0;"

    extra = ''
    if upgrades:
        extra = "; packages: " + check.summarize(
            (f"{name} {old} -> {new}" if old and new else name for name, old, new in upgrades),
            limit=15,
        )
    if kept_back:
        extra += f"; {len(kept_back)} not upgraded (kept back): " + check.summarize(
            kept_back, limit=15
        )

    if count < warn:
        check.exit_with_status(
            "OK",
            f"UPDATES OK: {count} pending updates (warn {warn}, crit {crit}){extra} | {perfdata}",
        )

    status    = "CRIT" if count >= crit else "WARN"
    threshold = crit if status == "CRIT" else warn
    reason = (
        f"UPDATES {status}: {count} pending updates exceeds "
        f"{status.lower()} threshold {threshold}{extra}"
    )

    check.exit_with_status(status, f"{reason} | {perfdata}")


if __name__ == '__main__':
    main()
