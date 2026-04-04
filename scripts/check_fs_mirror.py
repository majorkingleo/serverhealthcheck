#!/usr/bin/env python3
import sys
import subprocess

def check_mirroring():
    import shutil
    if not shutil.which('btrfs'):
        return 'OK', ['Btrfs tools not available']
    try:
        # Find mounted btrfs filesystems
        result = subprocess.run(['mount', '-t', 'btrfs'], capture_output=True, text=True, check=True)
        lines = result.stdout.strip().split('\n')
        if not lines or not lines[0]:
            return 'OK', ['No Btrfs filesystems detected']
        
        status = 'OK'
        messages = []
        for line in lines:
            if line:
                parts = line.split()
                mountpoint = parts[2] if len(parts) > 2 else ''
                if mountpoint:
                    # Check device stats
                    try:
                        stats_result = subprocess.run(['btrfs', 'device', 'stats', mountpoint], capture_output=True, text=True, check=True)
                        stats_lines = stats_result.stdout.strip().split('\n')
                        for stat_line in stats_lines:
                            if stat_line and not stat_line.startswith('['):
                                key, value = stat_line.split('\t')
                                if int(value) > 0:
                                    status = 'ERROR'
                                    messages.append(f"Btrfs {mountpoint}: {key} errors ({value})")
                    except subprocess.CalledProcessError:
                        status = 'UNKNOWN'
                        messages.append(f"Failed to get device stats for {mountpoint}")
                    
                    # Check filesystem show for missing devices
                    try:
                        show_result = subprocess.run(['btrfs', 'filesystem', 'show', mountpoint], capture_output=True, text=True, check=True)
                        if 'missing' in show_result.stdout.lower():
                            status = 'ERROR'
                            messages.append(f"Btrfs {mountpoint}: missing device(s)")
                    except subprocess.CalledProcessError:
                        status = 'UNKNOWN'
                        messages.append(f"Failed to show filesystem for {mountpoint}")
        
        return status, messages
    except Exception as e:
        return 'UNKNOWN', [f'Failed to check Btrfs mirroring: {str(e)}']

def check_fs_health():
    try:
        result = subprocess.run(['mount'], capture_output=True, text=True, check=True)
        lines = result.stdout.split('\n')
        ro_mounts = []
        for line in lines:
            if line and (' ro,' in line or ' ro ' in line):
                parts = line.split()
                if len(parts) > 2:
                    ro_mounts.append(parts[2])
        if ro_mounts:
            return 'ERROR', [f"Read-only filesystems: {', '.join(ro_mounts)}"]
        else:
            return 'OK', []
    except Exception as e:
        return 'UNKNOWN', [f'Failed to check filesystem health: {str(e)}']

def main():
    mirror_status, mirror_msgs = check_mirroring()
    fs_status, fs_msgs = check_fs_health()

    statuses = [mirror_status, fs_status]
    overall_status = 'OK'
    if 'ERROR' in statuses:
        overall_status = 'ERROR'
    elif 'WARN' in statuses:
        overall_status = 'WARN'
    elif 'UNKNOWN' in statuses:
        overall_status = 'UNKNOWN'

    all_messages = mirror_msgs + fs_msgs

    if all_messages:
        msg = f"FS/MIRROR {overall_status} - " + ', '.join(all_messages)
    else:
        msg = f"FS/MIRROR {overall_status}"

    print(msg)

    if overall_status == 'ERROR':
        sys.exit(2)
    elif overall_status == 'WARN':
        sys.exit(1)
    elif overall_status == 'UNKNOWN':
        sys.exit(3)
    else:
        sys.exit(0)

if __name__ == "__main__":
    main()