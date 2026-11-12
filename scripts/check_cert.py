#!/usr/bin/env python3
"""Check TLS certificate expiry for hosts given on the command line.

Usage: check_cert.py <host1[:port]> [host2[:port] ...] <warn_days> <crit_days>
"""
import datetime
import socket
import ssl
import sys

import check

_PRIORITY = {"OK": 0, "WARN": 1, "CRIT": 2, "UNKNOWN": 3}


def get_cert_expiry(host: str, port: int) -> datetime.datetime:
    """Return the notAfter datetime (naive UTC) of the TLS certificate at host:port."""
    ctx = ssl.create_default_context()
    with socket.create_connection((host, port), timeout=10) as raw:
        with ctx.wrap_socket(raw, server_hostname=host) as tls:
            cert = tls.getpeercert()
    return datetime.datetime.strptime(cert['notAfter'], '%b %d %H:%M:%S %Y %Z')


def check_host(host: str, port: int, warn_days: int, crit_days: int) -> tuple[str, int, str | None, str]:
    """Return (status, days_left, expiry_date, detail) for one host."""
    now = datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None)
    try:
        expiry = get_cert_expiry(host, port)
    except ssl.SSLCertVerificationError as exc:
        return "CRIT", None, None, f"verification failed: {exc}"
    except Exception as exc:
        return "UNKNOWN", None, None, f"could not retrieve certificate: {exc}"

    days_left = (expiry - now).days
    if days_left <= crit_days:
        status = "CRIT"
    elif days_left <= warn_days:
        status = "WARN"
    else:
        status = "OK"
    return status, days_left, str(expiry.date()), ''


def main():
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

    worst   = "OK"
    details = []
    perfdata_parts = []

    for host_port in host_args:
        host, port = host_port, 443
        if ':' in host_port:
            host, port_str = host_port.rsplit(':', 1)
            try:
                port = int(port_str)
            except ValueError:
                details.append(f"{host}:{port_str} UNKNOWN: invalid port '{port_str}'")
                worst = "UNKNOWN"
                continue

        status, days_left, expiry_date, detail = check_host(host, port, warn_days, crit_days)

        if days_left is not None:
            details.append(f"{host}:{port} expires in {days_left}d ({expiry_date}, {status})")
            perfdata_parts.append(f"days_left[{host}:{port}]={days_left};{warn_days};{crit_days};0;")
        else:
            details.append(f"{host}:{port} {status}: {detail}")

        if _PRIORITY[status] > _PRIORITY[worst]:
            worst = status

    reason = f"CERT {worst} (warn <= {warn_days}d, crit <= {crit_days}d): " + "; ".join(details)

    if perfdata_parts:
        reason += " | " + " ".join(perfdata_parts)

    check.exit_with_status(worst, reason)


if __name__ == '__main__':
    main()
