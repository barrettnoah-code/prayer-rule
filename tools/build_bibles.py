#!/usr/bin/env python3
"""Build the Scripture files from the archives fetched by fetch_bibles.py.

    python3 tools/build_bibles.py

Writes data/bible-<id>.json.gz (the text, loaded into the site's database)
and data/bibles.json (the list of books and chapters). Each translation keeps
its own book order, book names, chapter and verse numbering. A check report
is printed; nothing is written if a check fails.
"""
import gzip
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCES = ROOT / "sources" / "bibles"
DATA = ROOT / "data"
FORMAT = "prayer-rule-bible/1"

OT = ("GEN EXO LEV NUM DEU JOS JDG RUT 1SA 2SA 1KI 2KI 1CH 2CH EZR NEH EST JOB PSA PRO ECC SNG "
      "ISA JER LAM EZK DAN HOS JOL AMO OBA JON MIC NAM HAB ZEP HAG ZEC MAL").split()
NT = ("MAT MRK LUK JHN ACT ROM 1CO 2CO GAL EPH PHP COL 1TH 2TH 1TI 2TI TIT PHM HEB "
      "JAS 1PE 2PE 1JN 2JN 3JN JUD REV").split()
DEUTERO = ("TOB JDT ESG WIS SIR BAR LJE S3Y SUS BEL 1MA 2MA 3MA 4MA 1ES 2ES MAN PS2 DAG").split()
GOSPELS = {"MAT", "MRK", "LUK", "JHN"}

# Each translation's own order, as printed. Books in the archive that are
# not named here are added at the end of the Old Testament, in archive order.
ORDERS = {
    # KJV 1611: the Apocrypha between the Testaments, in its own order.
    "kjv": OT + "1ES 2ES TOB JDT ESG WIS SIR BAR S3Y SUS BEL MAN 1MA 2MA".split() + NT,
    # Douay-Rheims: the Vulgate order, the deuterocanonical books in place.
    "dra": ("GEN EXO LEV NUM DEU JOS JDG RUT 1SA 2SA 1KI 2KI 1CH 2CH EZR NEH TOB JDT EST "
            "JOB PSA PRO ECC SNG WIS SIR ISA JER LAM BAR EZK DAN HOS JOL AMO OBA JON MIC "
            "NAM HAB ZEP HAG ZEC MAL 1MA 2MA").split() + NT,
    # Brenton: as eBible arranges it (the English order, the other books after
    # Malachi), with the Greek Esther and Daniel in their places.
    "brenton": ("GEN EXO LEV NUM DEU JOS JDG RUT 1SA 2SA 1KI 2KI 1CH 2CH EZR NEH EST ESG JOB "
                "PSA PRO ECC SNG ISA JER LAM EZK DAN DAG HOS JOL AMO OBA JON MIC NAM HAB ZEP "
                "HAG ZEC MAL TOB JDT WIS SIR BAR LJE SUS BEL 1MA 2MA 1ES MAN 3MA 4MA").split(),
}
NAMES = {
    "kjv": "King James Version (with the Apocrypha)",
    "dra": "Douay-Rheims (Challoner, 1899)",
    "brenton": "Brenton Septuagint (1851)",
}
SCRIPTURE = set(OT) | set(NT) | set(DEUTERO)

# Books left out: Brenton's archive has "Ezra and Nehemiah" (2 Esdras, 23
# chapters) and Nehemiah again on its own; the first already contains it.
EXCLUDE = {"brenton": {"NEH"}}
# In Brenton, Esther and Daniel exist only in their Greek form: they are the
# Old Testament books themselves, not additions.
GROUPS = {"brenton": {"ESG": "ot", "DAG": "ot"}}
# Book names as each edition prints them (eBible gives the Douay-Rheims the
# names of the English Bible).
BOOK_NAMES = {
    "kjv": {"ESG": "The Rest of Esther", "WIS": "The Wisdom of Solomon", "SIR": "Ecclesiasticus",
            "S3Y": "The Song of the Three Holy Children", "SUS": "The History of Susanna",
            "MAN": "The Prayer of Manasses"},
    "dra": {"JOS": "Josue", "1SA": "1 Kings", "2SA": "2 Kings", "1KI": "3 Kings", "2KI": "4 Kings",
            "1CH": "1 Paralipomenon", "2CH": "2 Paralipomenon", "EZR": "1 Esdras", "NEH": "2 Esdras (Nehemias)",
            "TOB": "Tobias", "SNG": "Canticle of Canticles", "WIS": "Wisdom", "SIR": "Ecclesiasticus",
            "ISA": "Isaias", "JER": "Jeremias", "EZK": "Ezechiel", "HOS": "Osee", "OBA": "Abdias",
            "JON": "Jonas", "MIC": "Micheas", "HAB": "Habacuc", "ZEP": "Sophonias", "HAG": "Aggeus",
            "ZEC": "Zacharias", "MAL": "Malachias", "1MA": "1 Machabees", "2MA": "2 Machabees",
            "MAT": "St. Matthew", "MRK": "St. Mark", "LUK": "St. Luke", "JHN": "St. John",
            "ACT": "Acts of the Apostles", "REV": "Apocalypse"},
    "brenton": {"EZR": "Esdras II (Ezra and Nehemiah)", "ESG": "Esther", "DAG": "Daniel"},
}


def strip_markers(text):
    """USFM text of a verse as plain text."""
    text = re.sub(r"\\(f|fe|x|va|fig|rq)\s.*?\\\1\*", " ", text, flags=re.S)   # notes, references
    text = re.sub(r"\\\+?w\s+([^|\\]*?)(\|[^\\]*)?\\\+?w\*", r"\1", text)       # words with attributes
    text = re.sub(r"\\\+?[a-z]+\d*\*?", " ", text)                               # all other markers
    text = text.replace("¶", " ")
    text = re.sub(r"\s+", " ", text).strip()
    text = re.sub(r"\s+([,;:.?!)\]])", r"\1", text)
    text = re.sub(r"([(\[])\s+", r"\1", text)
    return text


def parse_book(source):
    """One USFM book: (code, names, [{n, title, verses: [[label, text]]}])."""
    code, names, chapters = None, {}, []
    chapter, verse, title = None, None, []

    def close_verse():
        nonlocal verse
        if verse is not None and chapter is not None:
            text = strip_markers(verse[1])
            if text:
                chapter["verses"].append([verse[0], text])
        verse = None

    for line in source.splitlines():
        line = line.strip()
        if not line.startswith("\\"):
            if verse is not None:
                verse[1] += " " + line
            continue
        marker = re.match(r"\\(\S+)\s?(.*)", line)
        tag, rest = marker.group(1), marker.group(2)
        if tag == "id":
            code = rest.split()[0].upper()
        elif tag in ("h", "toc1", "toc2", "toc3"):
            names[tag] = strip_markers(rest)
        elif tag == "c":
            close_verse()
            chapter = {"n": int(re.match(r"\d+", rest).group()), "title": "", "verses": []}
            chapters.append(chapter)
        elif tag == "d" and chapter is not None:                 # a psalm's title
            close_verse()
            chapter["title"] = strip_markers(rest)
        elif tag in ("s", "s1", "s2", "s3", "ms", "ms1", "mr", "r", "sr", "mt", "mt1", "mt2", "mt3",
                     "imt", "ip", "is", "io", "rem", "cl", "sts", "usfm", "ide"):
            continue                                              # headings and introductions
        else:
            # Paragraph or poetry markers, possibly with verses on the line.
            for part in re.split(r"(?=\\v\s)", line):
                found = re.match(r"\\v\s+(\d+[a-z]?(?:-\d+[a-z]?)?)\s*(.*)", part, flags=re.S)
                if found:
                    close_verse()
                    label, body = found.group(1), found.group(2)
                    shown = re.match(r"\\vp\s+(\S+?)\\vp\*\s*(.*)", body, flags=re.S)
                    if shown:
                        label, body = shown.group(1), shown.group(2)
                    verse = [label, body]
                elif verse is not None:
                    verse[1] += " " + re.sub(r"^\\\S+\s?", "", part)
    close_verse()
    return code, names, [c for c in chapters if c["verses"]]


def read_translation(key):
    path = SOURCES / f"{key}.zip"
    if not path.exists():
        raise SystemExit(f"{key}: {path} is missing; run python3 tools/fetch_bibles.py first")
    books = {}
    archive_order = []
    with zipfile.ZipFile(path) as archive:
        for name in sorted(archive.namelist()):
            if not name.lower().endswith((".usfm", ".sfm")):
                continue
            code, names, chapters = parse_book(archive.read(name).decode("utf-8-sig"))
            if code in SCRIPTURE and chapters and code not in EXCLUDE.get(key, set()):
                books[code] = {"names": names, "chapters": chapters}
                archive_order.append(code)
    order = [c for c in ORDERS[key] if c in books]
    extra = [c for c in archive_order if c not in order]
    if extra:
        # Put books not in the printed order before the New Testament.
        first_nt = next((i for i, c in enumerate(order) if c in NT), len(order))
        order = order[:first_nt] + extra + order[first_nt:]
    out = []
    for code in order:
        names = books[code]["names"]
        name = BOOK_NAMES.get(key, {}).get(code) or names.get("toc2") or names.get("h") or names.get("toc1") or code
        group = "gospel" if code in GOSPELS else "apostle" if code in NT else "ot" if code in OT else "deutero"
        group = GROUPS.get(key, {}).get(code, group)
        out.append({
            "code": code,
            "name": name,
            "abbr": names.get("toc3") or name,
            "group": group,
            "chapters": books[code]["chapters"],
        })
    return out, extra


def report(key, books, extra):
    def totals(codes):
        chapters = sum(len(b["chapters"]) for b in books if b["code"] in codes)
        verses = sum(len(c["verses"]) for b in books if b["code"] in codes for c in b["chapters"])
        return chapters, verses
    ot = totals({b["code"] for b in books if b["group"] == "ot"})
    de = totals({b["code"] for b in books if b["group"] == "deutero"})
    nt = totals(set(NT))
    names = ", ".join(b["code"] for b in books if b["group"] == "deutero")
    print(f"{key}: {len(books)} books")
    print(f"   Old Testament {ot[0]} chapters, {ot[1]} verses")
    print(f"   deuterocanonical {de[0]} chapters, {de[1]} verses ({names})")
    print(f"   New Testament {nt[0]} chapters, {nt[1]} verses")
    if extra:
        print(f"   not in the printed order, put before the NT: {', '.join(extra)}")
    problems = []
    has_nt = key != "brenton"
    if has_nt and nt[0] != 260:
        problems.append(f"{key}: the New Testament has {nt[0]} chapters, not 260")
    if key == "kjv" and (ot[0], nt[1]) != (929, 7957):
        problems.append(f"{key}: expected 929 OT chapters and 7957 NT verses, got {ot[0]} and {nt[1]}")
    if ot[0] < 900:
        problems.append(f"{key}: only {ot[0]} Old Testament chapters")
    for book in books:
        for chapter in book["chapters"]:
            if not chapter["verses"]:
                problems.append(f"{key}: {book['code']} {chapter['n']} has no verses")
    return problems


def main():
    DATA.mkdir(exist_ok=True)
    built, problems, index = {}, [], []
    for key in ORDERS:
        books, extra = read_translation(key)
        problems += report(key, books, extra)
        built[key] = books
    if problems:
        print("\nNOT WRITTEN:\n  " + "\n  ".join(problems))
        return 1
    for key, books in built.items():
        payload = json.dumps({"format": FORMAT, "id": key, "name": NAMES[key], "books": books},
                             ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        target = DATA / f"bible-{key}.json.gz"
        target.write_bytes(gzip.compress(payload, compresslevel=9, mtime=0))
        index.append({
            "id": key,
            "name": NAMES[key],
            "file": target.name,
            "md5": hashlib.md5(target.read_bytes()).hexdigest(),
            "testaments": sorted({"nt" if b["code"] in NT else "ot" for b in books}),
            "books": [{"code": b["code"], "name": b["name"], "abbr": b["abbr"], "group": b["group"],
                       "chapters": [c["n"] for c in b["chapters"]]} for b in books],
        })
        print(f"{target.name}: {target.stat().st_size // 1024} KB")
    (DATA / "bibles.json").write_text(json.dumps({"format": "prayer-rule-bibles/1", "translations": index},
                                                 ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print("bibles.json written.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
