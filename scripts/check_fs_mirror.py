#!/usr/bin/env python3
"""Check btrfs mirror/device health and read-only filesystems."""
import re
import shutil
import subprocess
import sys

import check


def run(cmd: list[str]) -> tuple[bool, str, str]:
    """Run *cmd*, returning (ok, stdout, stderr-description)."""
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
    except FileNotFoundError:
        return False, '', f"{cmd[0]} not installed"
    except subprocess.TimeoutExpired:
        return False, '', f"{cmd[0]} timed out"
    except Exception as exc:
        return False, '', str(exc)

    if result.returncode != 0:
        stderr = (result.stderr or result.stdout).strip().splitlines()
        return False, result.stdout, stderr[0] if stderr else f"exit {result.returncode}"
    return True, result.stdout, ''


def btrfs_mounts() -> list[str]:
    """Return the mountpoints of mounted btrfs filesystems."""
    ok, stdout, _ = run(['mount', '-t', 'btrfs'])
    if not ok:
        return []
    mountpoints = []
    for line in stdout.splitlines():
        parts = line.split()
        if len(parts) > 2 and parts[2] not in mountpoints:
            mountpoints.append(parts[2])
    return mountpoints


def classify_error(message: str, mountpoints: list[str]) -> str:
    """Collapse a btrfs error message into a short, mount-independent reason."""
    lowered = message.lower()
    if ('operation not permitted' in lowered or 'permission denied' in lowered
            or 'must be run as root' in lowered):
        return 'permission denied (check needs root - enable sudo for this check)'
    if 'not a btrfs' in lowered:
        return 'not a btrfs filesystem'

    # Remove paths so identical errors on different mounts collapse into one entry
    cleaned = message
    for mountpoint in mountpoints:
        cleaned = cleaned.replace(mountpoint, '<mount>')
    cleaned = re.sub(r'/dev/\S+', '<device>', cleaned)
    return cleaned.strip() or 'unknown error'


def group_errors(errors: dict[str, list[str]]) -> str:
    """Format {error message: [mountpoints]} as a compact, readable reason."""
    parts = []
    for message, mounts in errors.items():
        parts.append(f"{message} ({len(mounts)} mounts: {check.summarize(mounts, limit=5)})")
    return "; ".join(parts)


def check_mirroring() -> tuple[str, list[str]]:
    if not shutil.which('btrfs'):
        return 'OK', ['btrfs tools not available, skipping mirror check']

    mountpoints = btrfs_mounts()
    if not mountpoints:
        return 'OK', ['no btrfs filesystems detected']

    status = 'OK'
    problems: list[str] = []
    stat_errors: dict[str, list[str]] = {}
    show_errors: dict[str, list[str]] = {}

    for mountpoint in mountpoints:
        # Device error counters
        ok, stdout, error = run(['btrfs', 'device', 'stats', mountpoint])
        if not ok:
            stat_errors.setdefault(classify_error(error, mountpoints), []).append(mountpoint)
            status = 'UNKNOWN' if status == 'OK' else status
        else:
            for stat_line in stdout.strip().splitlines():
                if not stat_line or stat_line.startswith('['):
                    continue
                key, _, value = stat_line.partition('\t')
                try:
                    count = int(value.strip())
                except ValueError:
                    continue
                if count > 0:
                    status = 'ERROR'
                    problems.append(f"{mountpoint}: {key.strip()} errors = {count}")

        # Missing devices
        ok, stdout, error = run(['btrfs', 'filesystem', 'show', mountpoint])
        if not ok:
            show_errors.setdefault(classify_error(error, mountpoints), []).append(mountpoint)
            status = 'UNKNOWN' if status == 'OK' else status
        elif 'missing' in stdout.lower():
            status = 'ERROR'
            problems.append(f"{mountpoint}: missing device(s)")

    if stat_errors:
        problems.append('btrfs device stats failed - ' + group_errors(stat_errors))
    if show_errors:
        problems.append('btrfs filesystem show failed - ' + group_errors(show_errors))

    if not problems:
        problems = [f'{len(mountpoints)} btrfs filesystems healthy']
    return status, problems


def check_fs_health() -> tuple[str, list[str]]:
    ok, stdout, error = run(['mount'])
    if not ok:
        return 'UNKNOWN', [f'could not read mount table: {error}']

    ro_mounts = []
    for line in stdout.splitlines():
        if ' ro,' in line or ' ro ' in line:
            parts = line.split()
            if len(parts) > 2:
                ro_mounts.append(parts[2])

    if ro_mounts:
        return 'ERROR', [
            f"{len(ro_mounts)} read-only filesystems (usually a sign of I/O errors): "
            + check.summarize(ro_mounts)
        ]
    return 'OK', []


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
    msg = f"FS/MIRROR {overall_status}"
    if all_messages:
        msg += ' - ' + check.summarize(all_messages, limit=10, separator='; ')

    print(msg)

    sys.exit(check.exit_code(overall_status))


if __name__ == "__main__":
    main()
