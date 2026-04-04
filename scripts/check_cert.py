#!/usr/bin/env python3
import datetime
import glob
import os
import re
import socket
import ssl
import sys

import check

SITES_ENABLED = '/etc/apache2/sites-enabled'


def get_ssl_hostnames(sites_dir: str = SITES_ENABLED) -> list[str]:
    """Return deduplicated hostnames from all <VirtualHost *:443> blocks in sites-enabled."""
    hostnames: list[str] = []
    seen: set[str] = set()

    for conf_file in sorted(glob.glob(os.path.join(sites_dir, '*.conf'))):
        try:
            with open(conf_file) as f:
                content = f.read()
        except OSError:
            continue

        for block in re.finditer(
            r'<VirtualHost[^>]*:443[^>]*>(.*?)</VirtualHost>',
            content, re.DOTALL | re.IGNORECASE,
        ):
            block_text = block.group(1)
            # ServerName
            m = re.search(r'^\s*ServerName\s+(\S+)', block_text, re.MULTILINE | re.IGNORECASE)
            if m:
                host = m.group(1).lower()
                if host not in seen:
                    seen.add(host)
                    hostnames.append(host)
            # ServerAlias (space-separated, may appear multiple times)
            for alias_line in re.finditer(r'^\s*ServerAlias\s+(.+)', block_text, re.MULTILINE | re.IGNORECASE):
                for alias in alias_line.group(1).split():
                    alias = alias.lower()
                    if alias not in seen:
                        seen.add(alias)
                        hostnames.append(alias)

    return hostnames


def get_cert_expiry(host: str, port: int) -> datetime.datetime:
    """Return the notAfter datetime (naive UTC) of the TLS certificate at host:port."""
    ctx = ssl.create_default_context()
    with socket.create_connection((host, port), timeout=10) as raw:
        with ctx.wrap_socket(raw, server_hostname=host) as tls:
            cert = tls.getpeercert()
    return datetime.datetime.strptime(cert['notAfter'], '%b %d %H:%M:%S %Y %Z')


def main():
    # Usage: check_cert.py <warn_days> <crit_days>
    if len(sys.argv) != 3:
        print("UNKNOWN: Usage: check_cert.py <warn_days> <crit_days>")
        sys.exit(3)

    try:
        warn_days = int(sys.argv[1])
        crit_days = int(sys.argv[2])
    except ValueError:
        print("UNKNOWN: warn_days and crit_days must be integers")
        sys.exit(3)

    hostnames = get_ssl_hostnames()
    if not hostnames:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: No SSL vhosts found in {SITES_ENABLED}")

    _priority = {"OK": 0, "WARN": 1, "CRIT": 2, "UNKNOWN": 3}
    now = datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None)
    results = []
    worst = "OK"

    for host in hostnames:
        port = 443
        try:
            expiry = get_cert_expiry(host, port)
            days_left = (expiry - now).days
            if days_left <= crit_days:
                status = "CRIT"
            elif days_left <= warn_days:
                status = "WARN"
            else:
                status = "OK"
            results.append((host, port, status, days_left, str(expiry.date())))
        except ssl.SSLCertVerificationError as exc:
            results.append((host, port, "CRIT", None, f"Verification failed: {exc}"))
            status = "CRIT"
        except Exception as exc:
            results.append((host, port, "UNKNOWN", None, f"Could not retrieve certificate: {exc}"))
            status = "UNKNOWN"

        if _priority[status] > _priority[worst]:
            worst = status

    details = []
    perfdata_parts = []
    for host, port, status, days_left, detail in results:
        if days_left is not None:
            details.append(f"{host}:{port}={days_left}d {status} (expires {detail})")
            perfdata_parts.append(f"days_left[{host}:{port}]={days_left};{warn_days};{crit_days};0;")
        else:
            details.append(f"{host}:{port} {status}: {detail}")

    message = f"CERT {worst}: {'; '.join(details)}"
    if perfdata_parts:
        message += " | " + " ".join(perfdata_parts)

    check.exit_with_status(worst, message)


if __name__ == '__main__':
    main()


def main():
    # Usage: check_cert.py <host1[:port]> [host2[:port] ...] <warn_days> <crit_days>
    if len(sys.argv) < 4:
        print("UNKNOWN: Usage: check_cert.py <host1[:port]> [host2[:port] ...] <warn_days> <crit_days>")
        sys.exit(3)

    try:
        crit_days = int(sys.argv[-1])
        warn_days = int(sys.argv[-2])
    except ValueError:
        print("UNKNOWN: warn_days and crit_days must be integers")
        sys.exit(3)

    host_args = sys.argv[1:-2]
    if not host_args:
        print("UNKNOWN: at least one host is required")
        sys.exit(3)

    _priority = {"OK": 0, "WARN": 1, "CRIT": 2, "UNKNOWN": 3}
    now = datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None)
    results = []
    worst = "OK"

    for host_port in host_args:
        if ':' in host_port:
            host, port_str = host_port.rsplit(':', 1)
            try:
                port = int(port_str)
            except ValueError:
                results.append((host, 443, "UNKNOWN", None, f"Invalid port '{port_str}'"))
                worst = "UNKNOWN"
                continue
        else:
            host = host_port
            port = 443

        try:
            expiry = get_cert_expiry(host, port)
            days_left = (expiry - now).days
            if days_left <= crit_days:
                status = "CRIT"
            elif days_left <= warn_days:
                status = "WARN"
            else:
                status = "OK"
            results.append((host, port, status, days_left, str(expiry.date())))
        except ssl.SSLCertVerificationError as exc:
            results.append((host, port, "CRIT", None, f"Verification failed: {exc}"))
            status = "CRIT"
        except Exception as exc:
            results.append((host, port, "UNKNOWN", None, f"Could not retrieve certificate: {exc}"))
            status = "UNKNOWN"

        if _priority[status] > _priority[worst]:
            worst = status

    details = []
    perfdata_parts = []
    for host, port, status, days_left, detail in results:
        if days_left is not None:
            details.append(f"{host}:{port}={days_left}d {status} (expires {detail})")
            perfdata_parts.append(f"days_left[{host}:{port}]={days_left};{warn_days};{crit_days};0;")
        else:
            details.append(f"{host}:{port} {status}: {detail}")

    message = f"CERT {worst}: {'; '.join(details)}"
    if perfdata_parts:
        message += " | " + " ".join(perfdata_parts)

    check.exit_with_status(worst, message)


if __name__ == '__main__':
    main()
