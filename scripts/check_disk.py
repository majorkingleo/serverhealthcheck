#!/usr/bin/env python3
import sys
import subprocess

def main():
    if len(sys.argv) != 3:
        print("UNKNOWN: Usage: check_disk.py <warn_percent> <crit_percent>")
        sys.exit(3)

    try:
        warn_percent = float(sys.argv[1])
        crit_percent = float(sys.argv[2])
    except ValueError:
        print("UNKNOWN: Invalid parameters")
        sys.exit(3)

    try:
        # Get disk usage for physical partitions
        result = subprocess.run(['df', '-P'], capture_output=True, text=True, check=True)
        lines = result.stdout.strip().split('\n')[1:]  # Skip header
    except subprocess.CalledProcessError:
        print("UNKNOWN: Failed to run df command")
        sys.exit(3)

    physical_partitions = []
    for line in lines:
        parts = line.split()
        if len(parts) >= 6 and parts[0].startswith('/dev/sd') or parts[0].startswith('/dev/nvme') or parts[0].startswith('/dev/hd'):
            device, total_kb, used_kb, free_kb, percent_str, mountpoint = parts[:6]
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
            if overall_status != 'ERROR':
                overall_status = 'ERROR'
            messages.append(f"{mountpoint} {percent_used:.1f}% used")
        elif percent_used >= warn_percent and overall_status == 'OK':
            overall_status = 'WARN'
            messages.append(f"{mountpoint} {percent_used:.1f}% used")

        label = mountpoint.replace('/', '_').strip('_') or 'root'
        perfdata.append(f"{label}={used_mb:.0f}MB;{warn_mb:.0f};{crit_mb:.0f};0;{total_mb:.0f}")

    if messages:
        msg = f"DISK {overall_status} - " + ', '.join(messages)
    else:
        msg = f"DISK {overall_status}"

    print(f"{msg} | {' '.join(perfdata)}")

    if overall_status == 'ERROR':
        sys.exit(2)
    elif overall_status == 'WARN':
        sys.exit(1)
    else:
        sys.exit(0)

if __name__ == "__main__":
    main()