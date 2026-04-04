#!/usr/bin/env python3
import re
import sys
import subprocess

import check


def get_physical_mountpoints():
    """Return mountpoints for physical volumes only, skipping btrfs subvolume mounts."""
    result = subprocess.run(
        ['findmnt', '-rno', 'SOURCE,TARGET,FSTYPE'],
        capture_output=True, text=True, check=True,
    )
    seen_devices = set()
    mountpoints = []
    for line in result.stdout.strip().split('\n'):
        parts = line.split(None, 2)
        if len(parts) < 3:
            continue
        source, target, _fstype = parts
        # Strip subvolume path, e.g. /dev/sdc1[/@home_martin] -> /dev/sdc1
        base_device = re.sub(r'\[.*?\]', '', source)
        if not any(base_device.startswith(p) for p in ('/dev/sd', '/dev/nvme', '/dev/hd')):
            continue
        # Source still has [...] -> this is a subvolume mount, skip
        if '[' in source:
            continue
        # Deduplicate: only first mount per block device
        if base_device in seen_devices:
            continue
        seen_devices.add(base_device)
        mountpoints.append(target)
    return mountpoints


def main():
    warn_percent, crit_percent = check.parse_args("check_disk.py <warn_percent> <crit_percent>")

    try:
        mountpoints = get_physical_mountpoints()
    except Exception as exc:
        print(f"UNKNOWN: Failed to detect mountpoints: {exc}")
        sys.exit(3)

    if not mountpoints:
        print("UNKNOWN: No physical partitions found")
        sys.exit(3)

    try:
        result = subprocess.run(
            ['df', '-P'] + mountpoints,
            capture_output=True, text=True, check=True,
        )
        lines = result.stdout.strip().split('\n')[1:]  # skip header
    except subprocess.CalledProcessError:
        print("UNKNOWN: Failed to run df command")
        sys.exit(3)

    physical_partitions = []
    for line in lines:
        parts = line.split()
        if len(parts) < 6:
            continue
        _device, total_kb, used_kb, _free_kb, percent_str, mountpoint = parts[:6]
        try:
            percent_used = float(percent_str.rstrip('%'))
            total_mb = int(total_kb) / 1024
            used_mb = int(used_kb) / 1024
            physical_partitions.append((mountpoint, percent_used, used_mb, total_mb))
        except ValueError:
            continue

    if not physical_partitions:
        print("UNKNOWN: No physical partitions found")
        sys.exit(3)

    overall_status = 'OK'
    messages = []
    perfdata = []

    for mountpoint, percent_used, used_mb, total_mb in physical_partitions:
        warn_mb = total_mb * (warn_percent / 100)
        crit_mb = total_mb * (crit_percent / 100)

        if percent_used >= crit_percent:
            overall_status = 'ERROR'
            messages.append(f"{mountpoint} {percent_used:.1f}% used")
        elif percent_used >= warn_percent and overall_status == 'OK':
            overall_status = 'WARN'
            messages.append(f"{mountpoint} {percent_used:.1f}% used")

        label = mountpoint.replace('/', '_').strip('_') or 'root'
        perfdata.append(f"{label}={used_mb:.0f}MB;{warn_mb:.0f};{crit_mb:.0f};0;{total_mb:.0f}")

    msg = f"DISK {overall_status}"
    if messages:
        msg += ' - ' + ', '.join(messages)

    print(f"{msg} | {' '.join(perfdata)}")

    sys.exit(check.exit_code(overall_status))


if __name__ == "__main__":
    main()
