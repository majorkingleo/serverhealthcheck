#!/usr/bin/env python3
import os
import sys

import check

REBOOT_REQUIRED_FILE = '/run/reboot-required'
REBOOT_PKGS_FILE = '/run/reboot-required.pkgs'


def main():
    if len(sys.argv) != 1:
        print("UNKNOWN: Usage: check_reboot.py  (no arguments)")
        sys.exit(3)

    if not os.path.exists(REBOOT_REQUIRED_FILE):
        check.exit_with_status("OK", "REBOOT OK: No reboot required")

    pkgs: list[str] = []
    if os.path.exists(REBOOT_PKGS_FILE):
        try:
            with open(REBOOT_PKGS_FILE) as f:
                pkgs = [line.strip() for line in f if line.strip()]
        except OSError:
            pass

    if pkgs:
        detail = ', '.join(pkgs[:5])
        suffix = f" (+{len(pkgs) - 5} more)" if len(pkgs) > 5 else ""
        check.exit_with_status("WARN", f"REBOOT WARN: Reboot required — updated packages: {detail}{suffix}")
    else:
        check.exit_with_status("WARN", "REBOOT WARN: Reboot required")


if __name__ == '__main__':
    main()
