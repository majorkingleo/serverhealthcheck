#!/usr/bin/env python3
import sys
import subprocess
import glob

import check


def get_disks():
    disks = []
    candidates = []
    candidates.extend(glob.glob('/dev/sd*'))
    candidates.extend(glob.glob('/dev/nvme*'))
    candidates.extend(glob.glob('/dev/hd*'))
    for disk in candidates:
        basename = disk.split('/')[-1]
        # For sd/hd, if ends with digit, it's partition
        if basename.startswith(('sd', 'hd')) and basename[-1].isdigit():
            continue
        # For nvme, if contains 'p', it's partition
        if 'nvme' in basename and 'p' in basename:
            continue
        disks.append(disk)
    return disks


def check_smart(disk):
    """Return (status, message) for a single disk's overall SMART health."""
    try:
        result = subprocess.run(['smartctl', '-H', disk], capture_output=True, text=True, timeout=10)
        output = result.stdout + result.stderr
        if result.returncode == 0 and 'PASSED' in output:
            return 'OK', f"{disk}: SMART health PASSED"
        elif 'FAILED' in output or result.returncode != 0:
            failure = [line.strip() for line in output.splitlines() if 'FAILED' in line]
            detail = f" ({check.summarize(failure, limit=2, separator='; ')})" if failure else ''
            return 'ERROR', f"{disk}: SMART health FAILED{detail}"
        else:
            return 'UNKNOWN', f"{disk}: unable to determine SMART health"
    except subprocess.TimeoutExpired:
        return 'UNKNOWN', f"{disk}: SMART check timed out"
    except Exception as e:
        return 'UNKNOWN', f"{disk}: error checking SMART: {str(e)}"


def get_disk_temperature(disk):
    """Return the disk temperature in °C, or None when it cannot be read."""
    try:
        temp_result = subprocess.run(['smartctl', '-A', disk], capture_output=True, text=True, timeout=5)
        for line in temp_result.stdout.split('\n'):
            if 'Temperature' in line and 'Celsius' in line:
                parts = line.split()
                if len(parts) > 9:
                    try:
                        return int(parts[9])
                    except ValueError:
                        pass
                break
    except Exception:
        pass
    return None


def main():
    disks = get_disks()
    if not disks:
        check.exit_with_status("UNKNOWN", "UNKNOWN: No physical disks found")

    overall_status = 'OK'
    messages = []
    perfdata = []

    for disk in disks:
        status, message = check_smart(disk)
        if status == 'ERROR':
            overall_status = 'ERROR'
        elif status == 'UNKNOWN' and overall_status == 'OK':
            overall_status = 'UNKNOWN'

        temp = get_disk_temperature(disk)
        if temp is not None:
            label = disk.replace('/dev/', '').replace('/', '_')
            perfdata.append(f"{label}_temp={temp}C")
            message += f", {temp}\u00b0C"

        messages.append(message)

    msg = f"DISK SMART {overall_status}"
    if messages:
        msg += ' - ' + check.summarize(messages, limit=10, separator='; ')

    if overall_status == 'OK':
        msg += f" ({len(disks)} disks checked)"

    perf_str = ' | ' + ' '.join(perfdata) if perfdata else ''
    print(msg + perf_str)

    sys.exit(check.exit_code(overall_status))


if __name__ == "__main__":
    main()
