"""Shared helpers for health-check scripts."""
import sys


def parse_args(usage: str, types: list | None = None):
    """
    Parse and type-cast positional CLI arguments.

    :param usage:  Usage string shown on error, e.g. "check_cpu.py <warn> <crit>"
    :param types:  List of callables to cast each arg (e.g. [float, float]).
                   Defaults to [float, float] when not provided.
    :returns:      Tuple of cast argument values.
    :raises SystemExit(3): on wrong argument count or invalid values.
    """
    if types is None:
        types = [float, float]

    expected = len(types)
    if len(sys.argv) != expected + 1:
        print(f"UNKNOWN: Usage: {usage}")
        sys.exit(3)

    results = []
    for i, cast in enumerate(types):
        try:
            results.append(cast(sys.argv[i + 1]))
        except ValueError:
            print(f"UNKNOWN: Invalid parameter #{i + 1} — expected {cast.__name__}")
            sys.exit(3)

    return tuple(results)


# Exit-code mapping consistent with Nagios/check_mk convention
_EXIT_CODES = {"OK": 0, "WARN": 1, "ERROR": 2, "UNKNOWN": 3, "CRIT": 2}


def exit_with_status(status: str, message: str) -> None:
    """Print *message* and exit with the code matching *status*."""
    print(message)
    sys.exit(_EXIT_CODES.get(status.upper(), 3))


def exit_code(status: str) -> int:
    """Return the exit code for *status* without printing anything."""
    return _EXIT_CODES.get(status.upper(), 3)
