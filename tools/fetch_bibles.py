#!/usr/bin/env python3
"""Download the three whole Bibles for the Scripture reading (run once).

    python3 tools/fetch_bibles.py

Saves the eBible.org USFM archives into sources/bibles/:
    kjv.zip      King James Version with the Apocrypha (1769)
    dra.zip      Douay-Rheims, Challoner revision (1899 American edition)
    brenton.zip  Brenton's English Septuagint (1851)
All three are in the public domain. Already downloaded files are skipped,
so an interrupted run just continues. Then: python3 tools/build_bibles.py
"""
import sys
import time
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TARGET = ROOT / "sources" / "bibles"
AGENT = "Mozilla/5.0 (X11; Linux x86_64) prayer-rule bible import"

# eBible ids; the first address that works is used.
BIBLES = {
    "kjv": ["https://ebible.org/Scriptures/eng-kjv_usfm.zip"],
    "dra": ["https://ebible.org/Scriptures/engDRA_usfm.zip",
            "https://ebible.org/Scriptures/eng-dra_usfm.zip"],
    "brenton": ["https://ebible.org/Scriptures/eng-Brenton_usfm.zip",
                "https://ebible.org/Scriptures/engBrenton_usfm.zip"],
}


def get(url):
    request = urllib.request.Request(url, headers={"User-Agent": AGENT})
    with urllib.request.urlopen(request, timeout=120) as response:
        return response.read()


def main():
    TARGET.mkdir(parents=True, exist_ok=True)
    problems = []
    for key, urls in BIBLES.items():
        path = TARGET / f"{key}.zip"
        if path.exists() and zipfile.is_zipfile(path):
            print(f"{key}: already here")
            continue
        for url in urls:
            print(f"{key}: {url} ...")
            try:
                data = get(url)
            except Exception as error:      # try the next address
                problems.append(f"{url}\n    {error}")
                continue
            path.write_bytes(data)
            if zipfile.is_zipfile(path):
                books = [n for n in zipfile.ZipFile(path).namelist() if n.lower().endswith((".usfm", ".sfm"))]
                print(f"    {len(data) // 1024} KB, {len(books)} book files")
                break
            path.unlink()
            problems.append(f"{url}\n    not a zip file")
            time.sleep(0.5)
        else:
            print(f"{key}: NOT DOWNLOADED")
    missing = [k for k in BIBLES if not (TARGET / f"{k}.zip").exists()]
    if missing:
        print("\nNot downloaded: " + ", ".join(missing) + ". Messages:")
        print("\n".join(problems))
        return 1
    print("\nAll three downloaded into sources/bibles/. Next: python3 tools/build_bibles.py")
    return 0


if __name__ == "__main__":
    sys.exit(main())
