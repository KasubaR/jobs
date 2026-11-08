"""The shape of an ad handed to the app (the same fields as `collect:import` reads)."""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

MAX_CONTENT = 100_000


@dataclass
class Ad:
    title: str
    content: str
    url: str = ""
    external_id: str = ""
    # Facts only (employer, locations, dates, pay...). Never contact details.
    structured: dict[str, Any] = field(default_factory=dict)

    def cleaned(self) -> "Ad":
        """Whitespace tidied and sizes capped; the app validates again, this just avoids sending junk."""
        return Ad(
            title=re.sub(r"\s+", " ", self.title or "").strip()[:500],
            content=(self.content or "").strip()[:MAX_CONTENT],
            url=(self.url or "").strip()[:500],
            external_id=(self.external_id or "").strip()[:100],
            structured=self.structured or {},
        )

    def to_dict(self) -> dict[str, Any]:
        out: dict[str, Any] = {"title": self.title, "content": self.content}
        if self.url:
            out["url"] = self.url
        if self.external_id:
            out["external_id"] = self.external_id
        if self.structured:
            out["structured"] = self.structured
        return out


def write_json(ads: list[Ad], path: Path) -> None:
    """The file `php artisan collect:import` reads: a list of ads."""
    cleaned = [a.cleaned().to_dict() for a in ads]
    Path(path).write_text(json.dumps(cleaned, ensure_ascii=False), encoding="utf-8")
