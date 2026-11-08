"""Small helpers scripts can share. Reading pages only."""

from __future__ import annotations

from datetime import datetime

from bs4 import BeautifulSoup

DATE_FORMATS = ("%d %B %Y", "%d %b %Y", "%Y-%m-%d", "%d/%m/%Y", "%B %d, %Y")


def labelled_values(soup: BeautifulSoup, container: str = "dl") -> dict[str, str]:
    """{'closing date': '30 November 2026', ...} from <dt>label</dt><dd>value</dd> pairs.

    The text next to a label: the kind of thing CSS selectors alone cannot express."""
    out: dict[str, str] = {}
    for block in soup.select(container):
        for dt in block.find_all("dt"):
            dd = dt.find_next_sibling("dd")
            if dd is not None:
                out[dt.get_text(" ", strip=True).rstrip(":").strip().lower()] = dd.get_text(" ", strip=True)
    return out


def parse_date(text: str | None) -> str | None:
    """'30 November 2026' -> '2026-11-30'. None when it cannot be read (never guessed)."""
    if not text:
        return None
    text = text.strip()
    for fmt in DATE_FORMATS:
        try:
            return datetime.strptime(text, fmt).date().isoformat()
        except ValueError:
            continue
    return None
