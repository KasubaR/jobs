from __future__ import annotations

from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit

from bs4 import BeautifulSoup


@dataclass
class Page:
    url: str          # the address that finally answered (after redirects)
    status: int
    text: str         # the HTML (for a browser: after the page's JavaScript ran)

    _soup: BeautifulSoup | None = None

    @property
    def soup(self) -> BeautifulSoup:
        if self._soup is None:
            self._soup = BeautifulSoup(self.text, "lxml")
        return self._soup

    def links(self, selector: str, limit: int = 100) -> list[tuple[str, str]]:
        """(absolute URL, link text) for each match, same site only, no repeats, fragments dropped."""
        host = urlsplit(self.url).netloc.lower()
        seen: set[str] = set()
        out: list[tuple[str, str]] = []

        for a in self.soup.select(selector):
            href = (a.get("href") or "").strip()
            if not href or href.startswith(("#", "javascript:", "mailto:", "tel:")):
                continue
            absolute = urljoin(self.url, href).split("#")[0]
            parts = urlsplit(absolute)
            if parts.scheme not in ("http", "https") or parts.netloc.lower() != host or absolute in seen:
                continue
            seen.add(absolute)
            out.append((absolute, a.get_text(" ", strip=True)))
            if len(out) >= limit:
                break

        return out
