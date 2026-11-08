"""Example collector: a page that only builds its list of ads with JavaScript, read in a real browser.

Use Playwright only where plain HTML genuinely does not contain the ads. It is slower and heavier
for the site, and it must never be used to get past a block: if the site shows a CAPTCHA, a login
or a bot check, the worker stops and reports it. Copy and edit the selectors for one approved website.

Options (all optional), set on the source as JSON:
    {"wait_for": "ul.job-list li.job a", "list_selector": "li.job a", "max_ads": 10}
"""

from zamtrends_collect import Ad
from zamtrends_collect.errors import CollectError
from zamtrends_collect.helpers import labelled_values, parse_date


def collect(ctx):
    options = ctx.options
    browser = ctx.browser

    # The list is built by JavaScript, so wait for it to appear.
    listing = browser.get(ctx.source["listing_url"], wait_for=options.get("wait_for", "ul.job-list li.job a"))

    links = listing.links(options.get("list_selector", "li.job a"), limit=int(options.get("max_ads", 10)))
    if not links:
        raise CollectError("No ads matched the list selector. The site's layout may have changed.")

    ads = []
    for url, _text in links:
        page = browser.get(url, wait_for="h1")
        soup = page.soup

        title = soup.select_one("h1").get_text(" ", strip=True)
        body = soup.select_one(options.get("description_selector", "div.description"))
        if body is None:
            ctx.log(f"skipped {url}: no description found")
            continue

        labels = labelled_values(soup)
        facts = {}
        if labels.get("location"):
            facts["locations"] = [{"locality": labels["location"]}]
        if parse_date(labels.get("closing date")):
            facts["valid_through"] = parse_date(labels["closing date"])

        ads.append(Ad(title=title, url=page.url, content=body.get_text("\n", strip=True), structured=facts))

    return ads
