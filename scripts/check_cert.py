#!/usr/bin/env python3
import datetime
import socket
import ssl

import check


def get_cert_expiry(host: str, port: int) -> datetime.datetime:
    """Return the notAfter datetime (naive UTC) of the TLS certificate at host:port."""
    ctx = ssl.create_default_context()
    with socket.create_connection((host, port), timeout=10) as raw:
        with ctx.wrap_socket(raw, server_hostname=host) as tls:
            cert = tls.getpeercert()
    # notAfter format: 'Apr  4 12:00:00 2026 GMT'
    return datetime.datetime.strptime(cert['notAfter'], '%b %d %H:%M:%S %Y %Z')


def main():
    host_port, warn_days, crit_days = check.parse_args(
        "check_cert.py <host[:port]> <warn_days> <crit_days>", [str, int, int]
    )

    if ':' in host_port:
        host, port_str = host_port.rsplit(':', 1)
        try:
            port = int(port_str)
        except ValueError:
            check.exit_with_status("UNKNOWN", f"UNKNOWN: Invalid port '{port_str}'")
    else:
        host = host_port
        port = 443

    try:
        expiry = get_cert_expiry(host, port)
    except ssl.SSLCertVerificationError as exc:
        check.exit_with_status("CRIT", f"CERT CRIT: Certificate verification failed for {host}:{port}: {exc}")
    except Exception as exc:
        check.exit_with_status("UNKNOWN", f"UNKNOWN: Could not retrieve certificate for {host}:{port}: {exc}")

    now = datetime.datetime.utcnow()
    days_left = (expiry - now).days
    perfdata = f"days_left={days_left};{warn_days};{crit_days};0;"

    if days_left <= crit_days:
        check.exit_with_status(
            "CRIT",
            f"CERT CRIT: {host}:{port} expires in {days_left} days ({expiry.date()}) | {perfdata}",
        )
    elif days_left <= warn_days:
        check.exit_with_status(
            "WARN",
            f"CERT WARN: {host}:{port} expires in {days_left} days ({expiry.date()}) | {perfdata}",
        )
    else:
        check.exit_with_status(
            "OK",
            f"CERT OK: {host}:{port} expires in {days_left} days ({expiry.date()}) | {perfdata}",
        )


if __name__ == '__main__':
    main()
