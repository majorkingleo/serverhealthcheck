#!/usr/bin/env python3
import sys
import subprocess
import glob

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
    try:
        result = subprocess.run(['smartctl', '-H', disk], capture_output=True, text=True, timeout=10)
        output = result.stdout + result.stderr
        if result.returncode == 0 and 'PASSED' in output:
            return 'OK', f"{disk}: SMART health PASSED"
        elif 'FAILED' in output or result.returncode != 0:
            return 'ERROR', f"{disk}: SMART health FAILED"
        else:
            return 'UNKNOWN', f"{disk}: Unable to determine SMART health"
    except subprocess.TimeoutExpired:
        return 'UNKNOWN', f"{disk}: SMART check timed out"
    except Exception as e:
        return 'UNKNOWN', f"{disk}: Error checking SMART: {str(e)}"

def main():
    disks = get_disks()
    if not disks:
        print("UNKNOWN: No physical disks found")
        sys.exit(3)

    overall_status = 'OK'
    messages = []
    perfdata = []

    for disk in disks:
        status, message = check_smart(disk)
        messages.append(message)
        if status == 'ERROR':
            overall_status = 'ERROR'
        elif status == 'UNKNOWN' and overall_status == 'OK':
            overall_status = 'UNKNOWN'

        # Try to get temperature for perfdata
        try:
            temp_result = subprocess.run(['smartctl', '-A', disk], capture_output=True, text=True, timeout=5)
            for line in temp_result.stdout.split('\n'):
                if 'Temperature' in line and 'Celsius' in line:
                    parts = line.split()
                    if len(parts) > 9:
                        temp = parts[9]
                        try:
                            temp_val = int(temp)
                            label = disk.replace('/dev/', '').replace('/', '_')
                            perfdata.append(f"{label}_temp={temp_val}C")
                        except ValueError:
                            pass
                    break
        except:
            pass

    if messages:
        msg = f"DISK SMART {overall_status} - " + ', '.join(messages)
    else:
        msg = f"DISK SMART {overall_status}"

    perf_str = ' | ' + ' '.join(perfdata) if perfdata else ''
    print(msg + perf_str)

    if overall_status == 'ERROR':
        sys.exit(2)
    elif overall_status == 'UNKNOWN':
        sys.exit(3)
    else:
        sys.exit(0)

if __name__ == "__main__":
    main()