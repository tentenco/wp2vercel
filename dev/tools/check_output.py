import json
import pathlib
import re
import sys
import urllib.parse
from html.parser import HTMLParser

site = pathlib.Path(sys.argv[1]).resolve()
forbidden = sys.argv[2:]


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.found = []

    def handle_starttag(self, tag, attrs):
        for name, value in attrs:
            if not value:
                continue
            if name in ("href", "src", "poster"):
                self.found.append(value)
            if name in ("srcset", "imagesrcset"):
                self.found.extend(part.strip().split()[0] for part in value.split(",") if part.strip())


def resolves(page, reference):
    parsed = urllib.parse.urlsplit(reference)
    if parsed.scheme or parsed.netloc or not parsed.path:
        return True
    path = urllib.parse.unquote(parsed.path)
    target = (site / path.lstrip("/")) if path.startswith("/") else (page.parent / path)
    target = target.resolve()
    if site not in target.parents and target != site:
        return False
    return target.is_file() or (target / "index.html").is_file()


broken = {}
leaks = []
pages = 0
for file in sorted(site.rglob("*")):
    if not file.is_file():
        continue
    if file.suffix in (".html", ".css", ".js", ".json", ".xml", ".txt", ".xsl", ".svg"):
        text = file.read_text(encoding="utf-8", errors="replace")
        for needle in forbidden:
            if re.search(needle, text):
                leaks.append((str(file.relative_to(site)), needle))
    if file.suffix != ".html":
        continue
    pages += 1
    parser = Links()
    parser.feed(file.read_text(encoding="utf-8", errors="replace"))
    for reference in parser.found:
        if not resolves(file, reference):
            broken.setdefault(reference, str(file.relative_to(site)))

config = json.loads((site / "vercel.json").read_text())
print(json.dumps({
    "html_pages": pages,
    "files": sum(1 for f in site.rglob("*") if f.is_file()),
    "broken_internal_references": broken,
    "forbidden_pattern_hits": leaks,
    "vercel_json_keys": sorted(config.keys()),
    "redirects": len(config.get("redirects", [])),
}, indent=1, ensure_ascii=False))
sys.exit(1 if broken or leaks else 0)
