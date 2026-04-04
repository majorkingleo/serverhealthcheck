#!/usr/bin/env python3
"""Check TLS certificates from PEM files on disk (e.g. Mailu certs)."""
import datetime
import glob
import os
import re
import subprocess
import sys

import check

CERTS_DIR = '/home/mailu/certs'


def find_cert_files(certs_dir: str) -> list[tuple[str, str]]:
    """Return (label, path) for every fullchain.pem found under certs_dir."""
    results = []
    for path in sorted(glob.glob(os.path.join(certs_dir, '**/fullchain.pem'), recursive=True)):
        label = os.path.basename(os.path.dirname(path))
        results.append((label, path))
    return results


def get_cert_expiry(pem_path: str) -> datetime.datetime:
    """Return the notAfter datetime (naive UTC) by invoking openssl."""
    result = subprocess.run(
        ['openssl', 'x509', '-in', pem_path, '-noout', '-enddate'],
        capture_output=True, text=True, timeout=10,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or f"openssl exited with {result.returncode}")
    m = re.search(r'notAfter=(.+)', result.stdout)
    if not m:
        raise RuntimeError("Could not parse notAfter from openssl output")
    return datetime.datetime.strptime(m.group(1).strip(), '%b %d %H:%M:%S %Y %Z')


def main():
    if len(sys.argv) != 3:
        print("UNKNOWN: Usage: check_cert_files.py <warn_days> <crit_days>")
        sys.exit(3)

    try:
        warn_days = int(sys.argv[1])
        crit_days = int(sys.argv[2])
    except ValueError:
        print("UNKNOWN: warn_days and crit_days must be integers")
        sys.exit(3)

    certs = find_cert_files(CERTS_DIR)
    if not certs:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: No fullchain.pem files found in {CERTS_DIR}")

    _priority = {"OK": 0, "WARN": 1, "CRIT": 2, "UNKNOWN": 3}
    now = datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None)
    results = []
    worst = "OK"

    for label, path in certs:
        try:
            expiry = get_cert_expiry(path)
            days_left = (expiry - now).days
            if days_left <= crit_days:
                status = "CRIT"
            elif days_left <= warn_days:
                status = "WARN"
            else:
                status = "OK"
            results.append((label, status, days_left, str(expiry.date())))
        except Exception as exc:
            results.append((label, "UNKNOWN", None, str(exc)))
            status = "UNKNOWN"

        if _priority[status] > _priority[worst]:
            worst = status

    details = []
    perfdata_parts = []
    for label, status, days_left, detail in results:
        if days_left is not None:
            details.append(f"{label}={days_left}d {status} (expires {detail})")
            # port=0 marks these as file-based in cert_stats
            perfdata_parts.append(f"days_left[{label}:0]={days_left};{warn_days};{crit_days};0;")
        else:
            details.append(f"{label} {status}: {detail}")

    message = f"CERT {worst}: {'; '.join(details)}"
    if perfdata_parts:
        message += " | " + " ".join(perfdata_parts)

    check.exit_with_status(worst, message)


if __name__ == '__main__':
    main()
