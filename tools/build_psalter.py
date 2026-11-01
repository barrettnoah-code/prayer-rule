#!/usr/bin/env python3
"""Build data/psalter.json from the downloaded sources.

    python3 tools/build_psalter.py

Every translation gets the Greek and Slavonic (LXX) psalm numbering, all
titles are dropped, and each psalm's verses are numbered from 1. A check
report is printed at the end; nothing is written if a check fails.
"""
import html
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCES = ROOT / "sources"
OUTPUT = ROOT / "data" / "psalter.json"


# ---------------------------------------------------------------- helpers

def clean(text):
    """Plain text: no tags, entities decoded, single spaces."""
    text = re.sub(r"<[^>]+>", " ", text)
    text = html.unescape(text).replace("\u00a0", " ")
    text = re.sub(r"\s+", " ", text).strip()
    text = re.sub(r"\s+([,;.?!])", r"\1", text)   # no space before , ; . ? !
    text = re.sub(r"\s+:(?=\S)", ":", text)          # "word :x" -> "word:x"
    return text


def find_verse(verses, start, label):
    """Index of the first verse beginning with `start` (for the splits)."""
    for index, verse in enumerate(verses):
        if verse.lower().startswith(start.lower()):
            return index
    raise SystemExit(f"{label}: cannot find the verse beginning '{start}'")


def hebrew_to_lxx(psalms, label, split_116, split_147):
    """Renumber a {hebrew number: [verses]} Psalter to the LXX numbering.

    split_116 / split_147 are the first words of the verse where the LXX
    begins Psalm 115 (inside Hebrew 116) and Psalm 147 (inside Hebrew 147).
    """
    lxx = {}
    for number in range(1, 9):
        lxx[number] = psalms[number]
    lxx[9] = psalms[9] + psalms[10]
    for number in range(11, 114):
        lxx[number - 1] = psalms[number]
    lxx[113] = psalms[114] + psalms[115]
    cut = find_verse(psalms[116], split_116, f"{label} Psalm 116")
    lxx[114], lxx[115] = psalms[116][:cut], psalms[116][cut:]
    for number in range(117, 147):
        lxx[number - 1] = psalms[number]
    cut = find_verse(psalms[147], split_147, f"{label} Psalm 147")
    lxx[146], lxx[147] = psalms[147][:cut], psalms[147][cut:]
    for number in range(148, 151):
        lxx[number] = psalms[number]
    return lxx


# ---------------------------------------------- Coverdale (1662, CofE site)

# Slips in the web text: (Hebrew psalm, verse, wrong, right).
COVERDALE_FIXES = [
    (9, 1, "I Will give", "I will give"),
    (47, 1, ": O Sing unto God", ": O sing unto God"),
]


def read_coverdale():
    """Hebrew-numbered Psalter from the 60 Church of England pages."""
    psalms = {}
    current, expected = None, 1
    for page in sorted((SOURCES / "coe").glob("*.html")):
        text = page.read_text(encoding="utf-8")
        start = text.index('<div class="prayerContainer">')
        end = text.index("</article>", start)
        for match in re.finditer(
                r'<p class="(vlitemheading|vlpsalm)">(.*?)</p>',
                text[start:end], re.S):
            kind, body = match.groups()
            if kind == "vlitemheading":
                found = re.match(r"Psalm (\d+)\.?\s*(\d+)?", clean(body))
                current = int(found.group(1))
                expected = int(found.group(2) or 1)
                psalms.setdefault(current, {})
                continue
            number = re.search(r'class="vlversenumber">\s*(\d+)', body)
            body = re.sub(r'<span class="vlversenumber">.*?</span>',
                          "", body, count=1, flags=re.S)
            words = clean(body)
            if not words or words.startswith("Text from The Book of Common"):
                continue                      # empty line, copyright line
            if number:
                expected = int(number.group(1))
            else:
                typed = re.match(r"(\d+)\s+(.*)", words)  # "19 Until ..."
                if typed:
                    expected, words = int(typed.group(1)), typed.group(2)
                elif words[0].islower() and expected - 1 in psalms[current]:
                    # the rest of the previous verse, split onto a new line
                    psalms[current][expected - 1] += " " + words
                    continue
            if expected in psalms[current]:
                raise SystemExit(f"Coverdale {current}:{expected} twice")
            psalms[current][expected] = words
            expected += 1
    result = {}
    for number, verses in psalms.items():
        numbers = sorted(verses)
        if numbers != list(range(1, len(numbers) + 1)):
            raise SystemExit(f"Coverdale Psalm {number}: verses {numbers}")
        result[number] = [verses[n] for n in numbers]
    for number, index, wrong, right in COVERDALE_FIXES:
        verse = result[number][index - 1]
        if wrong not in verse:
            raise SystemExit(f"Coverdale fix not found: {wrong!r}")
        result[number][index - 1] = verse.replace(wrong, right)
    return hebrew_to_lxx(result, "Coverdale",
                         "I believed", "Praise the Lord, O Jerusalem")


# ------------------------------------------------------- KJV (eBible USFM)

def read_kjv():
    path = SOURCES / "kjv" / "kjv-psalms.usfm"
    if not path.exists():
        raise SystemExit("KJV: sources/kjv/kjv-psalms.usfm is missing; "
                         "run tools/fetch_sources.py again")
    text = path.read_text(encoding="utf-8")
    text = re.sub(r"\\f .*?\\f\*", "", text, flags=re.S)       # footnotes
    text = re.sub(r"\\x .*?\\x\*", "", text, flags=re.S)       # cross refs
    text = re.sub(r"\\\+?w ([^|\\]*)(\|[^\\]*)?\\\+?w\*", r"\1", text)
    text = re.sub(r"\\\+?(add|nd|wj|qs|sc|it|bd)\*?", "", text)
    psalms, chapter, verse = {}, None, None
    for line in text.splitlines():
        marker = re.match(r"\\(\w+)\s?(.*)", line.strip())
        if not marker:
            if verse is not None and line.strip():
                psalms[chapter][verse] += " " + line.strip()
            continue
        tag, rest = marker.groups()
        if tag == "c":
            chapter, verse = int(rest.split()[0]), None
            psalms[chapter] = {}
        elif tag in ("d", "qa", "s", "s1", "s2", "ms", "mt", "mt1", "h",
                     "toc1", "toc2", "toc3", "id", "ide", "rem", "cl"):
            if tag == "d" and verse is not None:
                raise SystemExit(f"KJV Psalm {chapter}: title after verse")
            continue                                   # titles, headings
        else:
            # \v may follow another marker on the same line: \q1 \v 3 ...
            for part in re.split(r"(?=\\v \d)", line.strip()):
                found = re.match(r"\\v (\d+)\s*(.*)", part)
                if found:
                    verse = int(found.group(1))
                    psalms[chapter][verse] = found.group(2)
                elif verse is not None:
                    psalms[chapter][verse] += " " + re.sub(
                        r"^\\\w+\d?\s?", "", part)
    result = {}
    for number, verses in psalms.items():
        numbers = sorted(verses)
        if numbers != list(range(1, len(numbers) + 1)):
            raise SystemExit(f"KJV Psalm {number}: verses {numbers}")
        result[number] = [clean(re.sub(r"\\\w+\d?\*?", " ", verses[n]))
                          for n in numbers]
    return hebrew_to_lxx(result, "KJV",
                         "I believed", "Praise the LORD, O Jerusalem")


# ----------------------------------------------- Brenton (divinerevelations)

def _drop_title(match):
    """Remove a title's words but keep its verse markers (\u0001 = gap)."""
    marks = re.findall(r'<span class="v" id="V\d+">.*?</span>',
                       match.group(0), re.S)
    return " \u0001 " + " \u0001 ".join(marks) + " \u0001 "


def read_brenton():
    """LXX-numbered; titles are the text inside <div class="d">."""
    psalms = {}
    for number in range(1, 152):
        page = SOURCES / "brenton" / f"PSA{number:03d}.htm"
        text = page.read_text(encoding="utf-8", errors="replace")
        start = text.index('<article class="chapter"')
        body = text[start:text.index("</article>", start)]
        body = re.sub(r'<p class="chapter-open">.*?</p>', "", body, flags=re.S)
        body = re.sub(r'<button[^>]*class="note-ref".*?</button>', "",
                      body, flags=re.S)
        body = re.sub(r'<div class="d">.*?</div>', _drop_title, body,
                      flags=re.S)
        verses = {}
        for found in re.finditer(
                r'<span class="v" id="V(\d+)">.*?</span>(.*?)'
                r'(?=<span class="v" id="V\d+">|$)', body, re.S):
            words = clean(found.group(2)).replace("\u0001", "").strip()
            if words:
                verses[int(found.group(1))] = words
        # Text after a title inside the same verse survives; whole title
        # verses vanish. Renumber what is left from 1.
        psalms[number] = [verses[n] for n in sorted(verses)]
    return psalms


# --------------------------------------------- Douay-Rheims (Gutenberg 8321)

# Places where the Gutenberg text itself is faulty: (psalm, wrong, right).
DOUAY_FIXES = [
    (6, "\n5:5. For there is no one", "\n6:6. For there is no one"),
    (112, "dwelleth on high: 6 and looketh", "dwelleth on high: 112:6. and looketh"),
    (2, "and the prople devised", "and the people devised"),
    (9, "I will give lpraise", "I will give praise"),
]
# "12:3." or "12:3" or "118: 41." -- and a bare "   10  " inside a line,
# which the Gutenberg text uses for a verse that starts mid-line.
DOUAY_MARK = re.compile(r"(?<![\d:])(\d+):\s?(\d+)\.?(?=\s)|(?<=   )(\d+)(?=  )")


def read_douay(brenton):
    """Vulgate (= LXX) numbered. Titles are dropped by Brenton's count."""
    text = (SOURCES / "douay-rheims" / "pg8321.txt").read_text(
        encoding="utf-8")
    text = text[text.index("Psalms Chapter 1\n"):text.index("*** END")]
    for _psalm, wrong, right in DOUAY_FIXES:
        if wrong not in text:
            raise SystemExit(f"Douay-Rheims fix not found: {wrong!r}")
        text = text.replace(wrong, right)
    chapters = re.findall(r"^Psalms Chapter (\d+)\n(.*?)(?=^Psalms Chapter |\Z)",
                          text, re.M | re.S)
    psalms = {}
    for number, body in chapters:
        number = int(number)
        verses = []                       # in reading order
        for paragraph in re.split(r"\n\s*\n", body):
            marks = list(DOUAY_MARK.finditer(paragraph))
            if not marks or marks[0].group(1) is None or \
                    int(marks[0].group(1)) != number:
                continue                  # heading, summary, or note
            if paragraph[:marks[0].start()].strip() and \
                    marks[0].start() > 20:
                continue                  # a note that cites a verse
            for index, mark in enumerate(marks):
                end = marks[index + 1].start() if index + 1 < len(marks) \
                    else len(paragraph)
                verses.append(clean(paragraph[mark.end():end]))
        psalms[number] = verses
    # Psalm 9 restarts at 1 for its second half; reading order is kept, so
    # the list is already 9:1-39. Drop titles: Brenton's psalm and the
    # Douay-Rheims psalm have the same verse total, so the difference
    # between the two Brenton lists tells how many title verses to drop.
    return psalms


# How a Douay-Rheims title sentence begins (titles sit at the start of
# verse 1, sometimes followed by the psalm's first words in the same verse).
DOUAY_TITLE = re.compile(
    r"^(Alleluia|Unto the end|In the end|An? psalm|The psalm|A canticle|"
    r"The canticle|A gradual canticle|For David|To David|Of David|"
    r"For the same David|A prayer|The prayer|The inscription|"
    r"Understanding for|For the sons of Core|On the first day|"
    r"The praise of a canticle|Of the sons of Jonadab|of Aggeus)[^.]*?\.\s*"
    r"|^\[[^\]]*\]\s*")                  # a reference: [2 Kings 17.]


def drop_douay_titles(douay, brenton_full, brenton):
    """Douay-Rheims numbers its titles like the Greek does: drop as many
    leading verses as Brenton's title takes, then any title sentences left
    at the start of the first verse (and the verse, if nothing is left)."""
    result = {}
    for number in range(1, 151):
        titles = brenton_full[number] - len(brenton[number])
        verses = list(douay[number][titles:])
        while True:
            first = verses[0]
            stripped = DOUAY_TITLE.sub("", first, count=1)
            if stripped == first:
                break
            if stripped:
                verses[0] = stripped
            else:
                verses.pop(0)
        result[number] = verses
    return result


def brenton_totals():
    """Verse totals per Brenton psalm before titles were dropped."""
    totals = {}
    for number in range(1, 152):
        text = (SOURCES / "brenton" / f"PSA{number:03d}.htm").read_text(
            encoding="utf-8", errors="replace")
        start = text.index('<article class="chapter"')
        body = text[start:text.index("</article>", start)]
        totals[number] = len(re.findall(r'<span class="v" id="V\d+"', body))
    return totals


# ------------------------------------------------------------------ checks

def check(name, psalms, expect_151=False):
    problems = []
    last = 151 if expect_151 else 150
    if sorted(psalms) != list(range(1, last + 1)):
        problems.append(f"{name}: psalms {min(psalms)}-{max(psalms)}, "
                        f"{len(psalms)} in all")
    for number, verses in psalms.items():
        if not verses or any(not v for v in verses):
            problems.append(f"{name} Psalm {number}: empty verse")
    if len(psalms.get(118, [])) != 176:
        problems.append(f"{name} Psalm 118: {len(psalms.get(118, []))} "
                        "verses, not 176")
    return problems


def main():
    coverdale = read_coverdale()
    kjv = read_kjv()
    brenton = read_brenton()
    totals = brenton_totals()
    douay = drop_douay_titles(read_douay(brenton), totals, brenton)

    translations = [
        ("coverdale", "Coverdale (1662 Book of Common Prayer)", coverdale),
        ("kjv", "King James Version", kjv),
        ("douay-rheims", "Douay-Rheims (Challoner)", douay),
        ("brenton", "Brenton's Septuagint", brenton),
    ]
    problems = []
    for key, _name, psalms in translations:
        problems += check(key, psalms, expect_151=(key == "brenton"))

    print(f"{'Translation':<16}{'Psalms':>8}{'Verses':>8}")
    for key, _name, psalms in translations:
        print(f"{key:<16}{len(psalms):>8}{sum(map(len, psalms.values())):>8}")
    if problems:
        print("\nProblems (nothing written):")
        print("\n".join(problems))
        return 1

    data = {
        "format": "prayer-rule-psalter/1",
        "numbering": "LXX psalm numbers; titles dropped; verses from 1",
        "default": "coverdale",
        "translations": [
            {"id": key, "name": name,
             "psalms": {str(n): psalms[n] for n in sorted(psalms)}}
            for key, name, psalms in translations
        ],
    }
    OUTPUT.parent.mkdir(exist_ok=True)
    OUTPUT.write_text(json.dumps(data, ensure_ascii=False, indent=1),
                      encoding="utf-8")
    print(f"\nWrote {OUTPUT.relative_to(ROOT)} "
          f"({OUTPUT.stat().st_size // 1024} KB)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
