"""Example collector: plain HTML pages, read with BeautifulSoup.

Copy this file, rename it (lowercase letters, digits, underscores), edit the selectors for one
website, and name it in the source's "Python worker" settings. It runs only for a source you have
approved. Facts go in `structured` (employer, place, dates, pay): never contact details.

Options (all optional), set on the source as JSON:
    {"list_selector": "li.job a", "description_selector": "div.description", "max_ads": 20}
"""

from zamtrends_collect import Ad
from zamtrends_collect.errors import CollectError
from zamtrends_collect.helpers import labelled_values, parse_date


def collect(ctx):
    options = ctx.options
    listing = ctx.fetch(ctx.source["listing_url"])

    links = listing.links(options.get("list_selector", "li.job a"), limit=int(options.get("max_ads", 20)))
    if not links:
        # A layout change looks like this. Say so instead of reporting an empty, "successful" day.
        raise CollectError("No ads matched the list selector. The site's layout may have changed.")

    ads = []
    for url, _text in links:
        page = ctx.fetch(url)
        soup = page.soup

        heading = soup.select_one("h1")
        title = heading.get_text(" ", strip=True) if heading else ""
        body = soup.select_one(options.get("description_selector", "div.description"))
        if not title or body is None:
            ctx.log(f"skipped {url}: no title or description found")
            continue

        labels = labelled_values(soup)
        facts = {}
        if labels.get("location"):
            facts["locations"] = [{"locality": labels["location"]}]
        if parse_date(labels.get("closing date")):
            facts["valid_through"] = parse_date(labels["closing date"])
        if labels.get("employment type"):
            facts["employment_type"] = [labels["employment type"].upper().replace(" ", "_")]

        ads.append(Ad(title=title, url=page.url, content=body.get_text("\n", strip=True), structured=facts))

    return ads
