#!/usr/bin/env python3
"""Build data/ustav.json from tools/ustav/home-ustav.txt.

Run from the plugin folder:  python3 tools/build_ustav.py
The plugin adds any prayers and orders it does not have yet; it never
overwrites ones already on the site.
"""
import html
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'tools' / 'ustav' / 'home-ustav.txt'
TARGET = ROOT / 'data' / 'ustav.json'

DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']

# Which dismissal ends each service, by liturgical day (the day begins at
# sundown, so "Sunday Vespers" is read on Saturday evening).
WEEKLY = ['dismissal-sunday', 'dismissal-bodiless-powers', 'dismissal-forerunner',
          'dismissal-cross', 'dismissal-apostles', 'dismissal-cross', 'dismissal-saturday']
DISMISSALS = {
    'vespers':   ['dismissal-apostles', 'dismissal-bodiless-powers', 'dismissal-forerunner',
                  'dismissal-cross', 'dismissal-apostles', 'dismissal-cross', 'dismissal-saturday'],
    'compline':  ['dismissal-fathers'] * 7,
    'midnight':  ['dismissal-fathers-sunday'] + ['dismissal-fathers'] * 6,
    'matins':    WEEKLY,
    'hours':     WEEKLY,
    'cell-rule': ['dismissal-fathers'] * 7,
}


def inline(text):
    """Escape, then {red}, *italic*."""
    out = html.escape(text, quote=False)
    out = re.sub(r'\{(.+?)\}', r'<span class="pr-red">\1</span>', out)
    out = re.sub(r'\*(.+?)\*', r'<em>\1</em>', out)
    return out


def paragraph(line):
    if line.startswith('! '):
        style, text = 'is-style-rubric', line[2:]
    else:
        style, text = 'is-style-prayer', line
    return ('<!-- wp:paragraph {"className":"%s"} -->\n<p class="%s">%s</p>\n<!-- /wp:paragraph -->'
            % (style, style, inline(text)))


def main():
    prayers, orders = [], []
    current = None
    for number, raw in enumerate(SOURCE.read_text(encoding='utf-8').splitlines(), 1):
        line = raw.strip()
        if line.startswith('==='):
            slug, title = [p.strip() for p in line[3:].split('|', 1)]
            current = {'slug': slug, 'title': title, 'lines': []}
            prayers.append(current)
        elif line.startswith('@@@'):
            key, title = [p.strip() for p in line[3:].split('|', 1)]
            current = {'service': key, 'title': title, 'lines': []}
            orders.append(current)
        elif not line or (line.startswith('#') and (current is None or 'slug' in current)):
            continue
        elif current is None:
            sys.exit(f'line {number}: text before the first === or @@@')
        else:
            current['lines'].append(line)

    slugs = {p['slug'] for p in prayers}
    if len(slugs) != len(prayers):
        sys.exit('a short name is used twice')
    out_prayers = [{'slug': p['slug'], 'title': p['title'],
                    'content': '\n\n'.join(paragraph(l) for l in p['lines'])} for p in prayers]
    out_orders = []
    for o in orders:
        for line in o['lines']:
            if re.fullmatch(r'[a-z0-9-]+', line) and line not in ('rule', 'dismissal') and line not in slugs:
                sys.exit(f'order {o["service"]}: no prayer "{line}"')
        out_orders.append({'service': o['service'], 'title': o['title'], 'items': '\n'.join(o['lines'])})
    for service, row in DISMISSALS.items():
        for slug in row:
            if slug not in slugs:
                sys.exit(f'dismissals {service}: no prayer "{slug}"')

    data = {
        'format': 'prayer-rule-ustav/1',
        'source': 'Home Ustav (1910), On Prayer at Home, ch. 4, and the dismissals for the week; English translation',
        'prayers': out_prayers,
        'orders': out_orders,
        'dismissals': {k: dict(zip(DAYS, v)) for k, v in DISMISSALS.items()},
    }
    TARGET.write_text(json.dumps(data, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    print(f'{TARGET.name}: {len(out_prayers)} prayers, {len(out_orders)} orders')


if __name__ == '__main__':
    main()
