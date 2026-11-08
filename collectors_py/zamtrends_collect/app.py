"""The worker's only connection to the app: three artisan commands on the same computer.

    php artisan python:due --json          which sources are due, and the rules to follow
    php artisan collect:import FILE ...    hand over the ads found
    php artisan python:fail ...            say the collection failed or was blocked

There is no database access and no network path into the app.
"""

from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path
from typing import Any


class AppError(Exception):
    """The app could not be reached or answered something unexpected."""


class AppClient:
    def __init__(self, app_dir: Path, php: str = "php", timeout: float = 180.0, env: dict[str, str] | None = None):
        self.app_dir = Path(app_dir)
        self.php = php
        self.timeout = timeout
        self.env = env

    def _artisan(self, *args: str) -> subprocess.CompletedProcess:
        if not (self.app_dir / "artisan").is_file():
            raise AppError(f"No Laravel app found at {self.app_dir} (no 'artisan' file).")
        try:
            return subprocess.run(
                [self.php, "artisan", *args], cwd=self.app_dir, capture_output=True, text=True, encoding="utf-8",
                timeout=self.timeout, env={**os.environ, **(self.env or {})},
            )
        except FileNotFoundError as e:
            raise AppError(f"PHP was not found ('{self.php}'). Set ZAMTRENDS_PHP to its full path.") from e
        except subprocess.TimeoutExpired as e:
            raise AppError(f"artisan {args[0]} took longer than {self.timeout:.0f} seconds.") from e

    @staticmethod
    def _json(result: subprocess.CompletedProcess, what: str) -> dict[str, Any]:
        # artisan may print notices before the JSON line: take the last line that parses.
        for line in reversed(result.stdout.strip().splitlines()):
            line = line.strip()
            if line.startswith("{"):
                try:
                    return json.loads(line)
                except json.JSONDecodeError:
                    continue
        raise AppError(f"{what}: the app answered something unreadable: {result.stdout.strip()[:300]} {result.stderr.strip()[:300]}")

    def due(self) -> dict[str, Any]:
        """{'user_agent', 'max_response_bytes', 'http_timeout', 'sources': [...]}"""
        result = self._artisan("python:due", "--json")
        data = self._json(result, "python:due")
        if "error" in data:
            raise AppError(data["error"])
        return data

    def import_file(self, path: Path, source_id: int) -> dict[str, Any]:
        """Hand over a JSON file of ads. The app answers with the run's summary (or an error)."""
        result = self._artisan("collect:import", str(path), f"--source={source_id}", "--trigger=python", "--json")
        data = self._json(result, "collect:import")
        data["exit_code"] = result.returncode
        return data

    def fail(self, source_id: int, message: str, blocked: bool = False) -> str:
        args = ["python:fail", str(source_id), message[:800]]
        if blocked:
            args.append("--blocked")
        result = self._artisan(*args)
        if result.returncode != 0:
            raise AppError(f"python:fail: {(result.stdout + result.stderr).strip()[:300]}")
        return result.stdout.strip()
