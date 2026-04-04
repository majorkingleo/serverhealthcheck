"""Shared database helpers for health-check scripts."""
import re
from pathlib import Path

import mariadb

SCRIPT_DIR = Path(__file__).resolve().parent
DB_CONFIG_PATH = SCRIPT_DIR.parent / "conf" / "db.php"


def load_php_db_config(config_path: Path = DB_CONFIG_PATH) -> dict:
    """Parse DB credentials from a PHP config file containing define() calls."""
    if not config_path.exists():
        raise RuntimeError(f"DB config file not found: {config_path}")

    text = config_path.read_text(encoding="utf-8")

    def extract(name: str) -> str:
        pattern = re.compile(
            rf"define\(\s*['\"{name}\"']\s*,\s*['\"]([^'\"]*)['\"]\s*\)\s*;".replace(
                f"['\"{name}\"']", f"['\"]{{name}}['\"]"
            ).replace("{name}", name)
        )
        match = re.search(
            rf"define\(\s*['\"]{re.escape(name)}['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)\s*;",
            text,
        )
        if not match:
            raise RuntimeError(f"Missing {name} in {config_path}")
        return match.group(1)

    return {
        "host":     extract("DB_HOST"),
        "user":     extract("DB_USER"),
        "password": extract("DB_PASS"),
        "name":     extract("DB_NAME"),
    }


def get_connection(config: dict | None = None, connect_timeout: int = 10) -> mariadb.Connection:
    """Return an open MariaDB connection using the given config (or the default PHP config)."""
    if config is None:
        config = load_php_db_config()
    return mariadb.connect(
        host=config["host"],
        user=config["user"],
        password=config["password"],
        database=config["name"],
        connect_timeout=connect_timeout,
    )
