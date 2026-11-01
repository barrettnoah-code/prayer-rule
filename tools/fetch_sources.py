#!/usr/bin/env python3
"""Download the four Psalter sources into sources/ (run once; safe to re-run).

    python3 tools/fetch_sources.py

Already downloaded files are skipped, so an interrupted run just continues.
Nothing here is sent anywhere; it only downloads public pages.
"""
import io
import sys
import time
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCES = ROOT / "sources"
AGENT = "Mozilla/5.0 (X11; Linux x86_64) prayer-rule psalter import"

COE = ("https://www.churchofengland.org/prayer-and-worship/"
       "worship-texts-and-resources/book-common-prayer/psalter/")
COE_PAGES = [
    "psalms-1-5", "psalms-6-8", "psalms-9-11", "psalms-12-14", "psalms-15-17",
    "psalm-18", "psalm-19-21", "psalms-22-23", "psalms-24-26", "psalms-27-29",
    "psalms-30-31", "psalms-32-34", "psalms-35-36", "psalm-37", "psalms-38-40",
    "psalms-41-43", "psalms-44-46", "psalms-47-49", "psalms-50-52",
    "psalms-53-55", "psalms-56-58", "psalms-59-61", "psalms-62-64",
    "psalms-65-67", "psalm-68", "psalms-69-70", "psalms-71-72", "psalms-73-74",
    "psalms-75-77", "psalm-78", "psalms-79-81", "psalms-82-85", "psalms-86-88",
    "psalm-89", "psalms-90-92", "psalms-93-94", "psalms-95-97",
    "psalms-98-101", "psalms-102-103", "psalm-104", "psalm-105", "psalm-106",
    "psalm-107", "psalms-108-109", "psalms-110-113", "psalms-114-115",
    "psalms-116-118", "psalm-119-1-32", "psalm-119-33-72", "psalm-119-73-104",
    "psalm-119-105-144", "psalm-119-145-176", "psalms-120-125",
    "psalms-126-131", "psalms-132-135", "psalms-136-138", "psalms-139-141",
    "psalms-142-143", "psalms-144-146", "psalms-147-150",
]
BRENTON = ("https://www.divinerevelations.info/documents/bible/all_html2/"
           "brenton_english_septuagint/PSA{:03d}.htm")
KJV_ZIP = "https://ebible.org/Scriptures/eng-kjv_vpl.zip"
DOUAY = "https://www.gutenberg.org/cache/epub/8321/pg8321.txt"


def get(url):
    request = urllib.request.Request(url, headers={"User-Agent": AGENT})
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read()


def save(url, path, problems):
    if path.exists() and path.stat().st_size > 0:
        return False
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        data = get(url)
    except Exception as error:          # keep going; report at the end
        problems.append(f"{url}\n    {error}")
        return False
    path.write_bytes(data)
    time.sleep(0.5)                     # be polite to the sites
    return True


def fetch_kjv(problems):
    target = SOURCES / "kjv" / "kjv-psalms.txt"
    if target.exists() and target.stat().st_size > 0:
        return
    try:
        archive = zipfile.ZipFile(io.BytesIO(get(KJV_ZIP)))
    except Exception as error:
        problems.append(f"{KJV_ZIP}\n    {error}")
        return
    lines = []
    for name in archive.namelist():
        if name.lower().endswith(".txt"):
            text = archive.read(name).decode("utf-8-sig")
            lines += [l for l in text.splitlines() if l.startswith("PSA ")]
    if not lines:
        problems.append(f"{KJV_ZIP}\n    no PSA lines found in the zip")
        return
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main():
    problems = []
    print("Church of England 1662 Psalter (60 pages) ...")
    for number, slug in enumerate(COE_PAGES, 1):
        save(COE + slug, SOURCES / "coe" / f"{number:02d}-{slug}.html", problems)
    print("Brenton Septuagint Psalms (151 pages) ...")
    for psalm in range(1, 152):
        save(BRENTON.format(psalm),
             SOURCES / "brenton" / f"PSA{psalm:03d}.htm", problems)
    print("KJV (eBible) ...")
    fetch_kjv(problems)
    print("Douay-Rheims (Project Gutenberg) ...")
    save(DOUAY, SOURCES / "douay-rheims" / "pg8321.txt", problems)

    counts = {d.name: len(list(d.iterdir()))
              for d in sorted(SOURCES.iterdir()) if d.is_dir()}
    print("\nFiles now in sources/:", counts)
    if problems:
        print(f"\n{len(problems)} download(s) failed; run again to retry:")
        print("\n".join(problems))
        return 1
    print("All downloads done.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
