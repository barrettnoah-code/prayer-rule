#!/usr/bin/env python3
"""Build data/ustav.json from tools/ustav/home-ustav.txt, feasts.txt and fasting.txt.

Run from the plugin folder:  python3 tools/build_ustav.py
The plugin adds any prayers and orders it does not have yet; it never
overwrites ones already on the site, except that a prayer or service order
still exactly as an earlier version shipped it is brought up to date.
"""
import hashlib
import html
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'tools' / 'ustav' / 'home-ustav.txt'
FEASTS = ROOT / 'tools' / 'ustav' / 'feasts.txt'
FASTING = ROOT / 'tools' / 'ustav' / 'fasting.txt'
ZAPEVY = ROOT / 'tools' / 'ustav' / 'zapevy.txt'
WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']
PERIODS = ['lent', 'holy-week', 'bright-week', 'pentecost-season', 'trinity-week', 'apostles', 'dormition',
           'nativity-1', 'nativity-2', 'twelve-days', 'after-theophany', 'publican-week', 'prodigal-week',
           'cheesefare', 'ordinary']
SERVICES = ['vespers', 'compline', 'midnight', 'matins', 'hours', 'cell-rule']
DEFAULT_FEAST_SERVICES = ['vespers', 'matins', 'hours']
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

# Daily additions (an "additions" line in an order), by liturgical day.
ADDITIONS = {
    'vespers':   ['additions-vespers-sunday'] + ['additions-vespers'] * 6,
    'compline':  ['additions-compline'] * 6 + ['additions-compline-friday'],   # Saturday's Compline is read on Friday evening
    'midnight':  ['additions-midnight-sunday'] + ['additions-midnight'] * 6,  # Sunday: to the Trinity (zapevy, fol. 209)
    'matins':    ['additions-matins-sunday', 'additions-matins-monday', 'additions-matins-tuesday', 'additions-matins-cross',
                  'additions-matins-thursday', 'additions-matins-cross', 'additions-matins-saturday'],
    'hours':     ['additions-hours-sunday'] + ['additions-hours'] * 6,
    'cell-rule': [''] * 7,
}
# On a day with a feast dismissal: what replaces the above ('' = none).
# "sunday" only for a great feast of the Lord that falls on a Sunday.
ADDITIONS_FEAST = {
    'any':    {'vespers': 'additions-vespers-feast', 'compline': 'additions-compline', 'midnight': ''},
    'sunday': {'matins': 'additions-matins-feast', 'hours': 'additions-hours-feast'},
}


# Orders used in place of a service's own order, by days from Pascha (the
# liturgical day: Vespers of Pascha is read on the evening of Great Saturday).
SEASONS = [
    {'from': 0, 'to': 0, 'orders': {'vespers': 'vespers-pascha-eve', 'compline': 'compline-pascha-eve',
                                    'midnight': 'midnight-pascha-eve', 'matins': 'matins-pascha',
                                    'hours': 'hours-bright', 'cell-rule': 'cell-rule-bright'}},
    {'from': 1, 'to': 6, 'orders': {'vespers': 'vespers-bright', 'compline': 'compline-bright',
                                    'midnight': 'compline-bright', 'matins': 'matins-bright',
                                    'hours': 'hours-bright', 'cell-rule': 'cell-rule-bright'}},
]
# From Thomas Sunday (its Vespers on Bright Saturday evening) to the leave-taking
# of Pascha: "Christ is risen" thrice in place of "O Heavenly King" (fol. 208v).
SUBSTITUTIONS = [
    {'from': 7, 'to': 38, 'replace': 'heavenly-king', 'with': 'christ-is-risen-thrice'},
]


def parse_feasts(slugs_out):
    """feasts.txt => (prayers, rules)."""
    prayers, rules = [], []
    current = None
    for number, raw in enumerate(FEASTS.read_text(encoding='utf-8').splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        if line.startswith('==='):
            slug, title = [p.strip() for p in line[3:].split('|', 1)]
            current = {'slug': slug, 'title': title, 'lines': [], 'rule': {
                'slug': slug, 'title': title.replace('Dismissal: ', ''), 'menaion': [], 'sunday': [], 'pascha': [],
                'services': DEFAULT_FEAST_SERVICES, 'on_sunday': 'weekly', 'lordly': False, 'additions': 'feast'}}
            prayers.append(current)
            continue
        if current is None:
            sys.exit(f'feasts.txt line {number}: text before the first ===')
        if line.startswith('@ '):
            words = line[2:].split()
            rule = current['rule']
            if words[0] == 'menaion':
                m, d = words[1].split('-')
                rule['menaion'].append([int(m), int(d)])
            elif words[0] == 'sunday':
                a, b = words[1].split('..')
                (m1, d1), (m2, d2) = a.split('-'), b.split('-')
                rule['sunday'].append([int(m1), int(d1), int(m2), int(d2)])
            elif words[0] == 'pascha':
                lo, _, hi = words[1].partition('..')
                limit = [w for w in words[2:] if w in SERVICES]
                rule['pascha'].append([int(lo), int(hi or lo), limit])
            elif words[0] == 'services':
                rule['services'] = SERVICES if words[1:] == ['all'] else [w for w in words[1:] if w in SERVICES]
            elif words[0] == 'on-sunday':
                rule['on_sunday'] = words[1]
            elif words[0] == 'lordly':
                rule['lordly'] = True
            elif words[0] == 'additions':
                rule['additions'] = words[1]
            else:
                sys.exit(f'feasts.txt line {number}: unknown "@ {words[0]}"')
        else:
            current['lines'].append(line)
    for p in prayers:
        r = p['rule']
        if r['menaion'] or r['sunday'] or r['pascha']:
            rules.append(r)
    return prayers, rules


def weekdays(spec, number):
    days = WEEKDAYS if spec == 'all' else spec.split(',')
    for d in days:
        if d not in WEEKDAYS:
            sys.exit(f'fasting.txt line {number}: unknown weekday "{d}"')
    return days


def plain(line):
    return '<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->' % inline(line)


def rubric(line):
    return paragraph('! ' + line)


def parse_fasting():
    """fasting.txt => (prayers, periods)."""
    periods, current, block = [], None, None
    for number, raw in enumerate(FASTING.read_text(encoding='utf-8').splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        if line.startswith('==='):
            key, title = [x.strip() for x in line[3:].split('|', 1)]
            current = {'key': key, 'title': title, 'when': '', 'text': key, 'day': {}, 'on': [], 'feast': None,
                       'general': [], 'other': []}
            periods.append(current)
            block = None
        elif current is None:
            sys.exit(f'fasting.txt line {number}: text before the first ===')
        elif line.startswith('== '):
            kind, source = [x.strip() for x in line[3:].split('|', 1)]
            if kind not in ('general', 'other'):
                sys.exit(f'fasting.txt line {number}: "general" or "other"')
            block = {'source': source, 'lines': []}
            current[kind].append(block)
        elif line.startswith('@ '):
            head, _, text = line[2:].partition('|')
            words, text = head.split(), text.strip()
            if words[0] == 'when':
                if words[1] not in PERIODS:
                    sys.exit(f'fasting.txt line {number}: unknown period "{words[1]}"')
                current['when'] = words[1]
            elif words[0] == 'text':
                current['text'] = words[1]
            elif words[0] == 'day':
                for d in weekdays(words[1], number):
                    current['day'][d] = text
            elif words[0] == 'feast':
                current['feast'] = {'days': weekdays(words[1], number), 'text': text}
            elif words[0] == 'on' and words[1] == 'week1':
                current['on'].append({'week1': weekdays(words[2], number), 'text': text})
            elif words[0] == 'on' and words[1] == 'date':
                m, d = words[2].split('-')
                current['on'].append({'date': [int(m), int(d)], 'text': text})
            elif words[0] == 'on' and words[1] == 'pascha':
                current['on'].append({'pascha': int(words[2]), 'text': text})
            else:
                sys.exit(f'fasting.txt line {number}: unknown "@ {words[0]}"')
        elif block is None:
            sys.exit(f'fasting.txt line {number}: text before "== general" or "== other"')
        else:
            block['lines'].append(line)
    prayers = []
    for p in periods:
        for kind, suffix, label in (('general', '', 'Fasting'), ('other', '-other', 'Fasting, other typika')):
            if not p[kind]:
                continue
            content = []
            for b in p[kind]:
                content.append(rubric(b['source']))
                content += [plain(l) for l in b['lines']]
            prayers.append({'slug': f'fasting-{p["key"]}{suffix}', 'title': f'{label}: {p["title"]}', 'content': '\n\n'.join(content)})
        del p['general'], p['other']
    keys = {p['key'] for p in periods}
    for p in periods:
        if not p['when'] or p['text'] not in keys:
            sys.exit(f'fasting.txt: {p["key"]} needs "@ when" and a known "@ text"')
    return prayers, periods


def parse_zapevy():
    """zapevy.txt: {'fixed': {key: text}, 'dated': [{kind, text, menaion|sunday|pascha}]}."""
    fixed, dated = {}, []
    md = r'(1[0-2]|[1-9])-([1-9]|[12]\d|3[01])'
    for number, raw in enumerate(ZAPEVY.read_text(encoding='utf-8').splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        if '|' not in line:
            sys.exit(f'zapevy.txt line {number}: no "|"')
        left, text = [x.strip() for x in line.split('|', 1)]
        if not text:
            sys.exit(f'zapevy.txt line {number}: no text')
        if left.startswith('='):
            key = left[1:].strip()
            if not re.fullmatch(r'[a-z-]+', key) or key in ('saint',) or key in fixed:
                sys.exit(f'zapevy.txt line {number}: bad or repeated key "{key}"')
            fixed[key] = text
            continue
        m = re.fullmatch(r'(.+?)\s+(feast|season|saint)', left)
        if not m:
            sys.exit(f'zapevy.txt line {number}: needs WHEN KIND')
        when, kind = m.group(1).strip(), m.group(2)
        item = {'kind': kind, 'text': text}
        if re.fullmatch(md, when):
            a = [int(x) for x in when.split('-')]
            item['menaion'] = a + a
        elif re.fullmatch(md + r'\.\.' + md, when):
            item['menaion'] = [int(x) for x in re.split(r'-|\.\.', when)]
        elif re.fullmatch(r'sunday\s+' + md + r'\.\.' + md, when):
            item['sunday'] = [int(x) for x in re.split(r'-|\.\.', when.split(None, 1)[1])]
        elif re.fullmatch(r'pascha\s+-?\d+(\.\.-?\d+)?', when):
            nums = [int(x) for x in when.split(None, 1)[1].split('..')]
            item['pascha'] = [nums[0], nums[-1]]
            if nums[0] > nums[-1] or (nums[0] < 0) != (nums[-1] < 0):
                sys.exit(f'zapevy.txt line {number}: bad Pascha range')
        else:
            sys.exit(f'zapevy.txt line {number}: when not understood: "{when}"')
        dated.append(item)
    return {'fixed': fixed, 'dated': dated}


def inline(text):
    """Escape, then {red}, *italic*."""
    out = html.escape(text, quote=False)
    out = re.sub(r'\{(.+?)\}', r'<span class="pr-red">\1</span>', out)
    out = re.sub(r'\*(.+?)\*', r'<em>\1</em>', out)
    return out


def paragraph(line):
    if re.fullmatch(r'\[zapev [a-z-]+\]', line):
        return '<!-- wp:shortcode -->\n%s\n<!-- /wp:shortcode -->' % line
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

    feast_prayers, feast_rules = parse_feasts(None)
    prayers += feast_prayers
    slugs = {p['slug'] for p in prayers}
    if len(slugs) != len(prayers):
        sys.exit('a short name is used twice')
    out_prayers = [{'slug': p['slug'], 'title': p['title'],
                    'content': '\n\n'.join(paragraph(l) for l in p['lines'])} for p in prayers]
    fasting_prayers, fasting = parse_fasting()
    zapevy = parse_zapevy()
    for p in prayers:
        for line in p['lines']:
            for key in re.findall(r'\[zapev ([a-z-]+)\]', line):
                if key not in zapevy['fixed'] and key != 'saint':
                    sys.exit(f'{p["slug"]}: no zapev "{key}"')
    out_prayers += fasting_prayers
    if len({p['slug'] for p in out_prayers}) != len(out_prayers):
        sys.exit('a short name is used twice')
    out_orders = []
    for o in orders:
        for line in o['lines']:
            if re.fullmatch(r'[a-z0-9-]+', line) and line not in ('rule', 'dismissal', 'additions') and line not in slugs:
                sys.exit(f'order {o["service"]}: no prayer "{line}"')
        out_orders.append({'service': o['service'], 'title': o['title'], 'items': '\n'.join(o['lines'])})
    order_keys = {o['service'] for o in out_orders}
    for season in SEASONS:
        for service, key in season['orders'].items():
            if service not in SERVICES or key not in order_keys:
                sys.exit(f'seasons: bad {service} -> {key}')
    for sub in SUBSTITUTIONS:
        if sub['replace'] not in slugs or sub['with'] not in slugs:
            sys.exit(f'substitutions: no prayer {sub}')
    for o in out_orders:
        if o['service'] not in SERVICES:
            o['season'] = True
    for table in (DISMISSALS, ADDITIONS, ADDITIONS_FEAST['any'], ADDITIONS_FEAST['sunday']):
        for service, row in table.items():
            for slug in (row if isinstance(row, list) else [row]):
                if slug and slug not in slugs:
                    sys.exit(f'{service}: no prayer "{slug}"')
    for r in feast_rules:
        if r['on_sunday'] not in ('feast', 'weekly') and r['on_sunday'] not in slugs:
            sys.exit(f'{r["slug"]}: no prayer "{r["on_sunday"]}"')

    # Earlier versions of each order (md5 of its lines), so that an order
    # nobody has edited can be brought up to date.
    md5 = lambda text: hashlib.md5(text.strip().encode('utf-8')).hexdigest()
    previous, previous_prayers = {}, {}
    if TARGET.exists():
        old = json.loads(TARGET.read_text(encoding='utf-8'))
        for o in old.get('orders', []):
            previous[o['service']] = set(o.get('previous', [])) | {md5(o['items'])}
        for p in old.get('prayers', []):
            previous_prayers[p['slug']] = set(p.get('previous', [])) | {md5(p['content'])}
    for o in out_orders:
        o['previous'] = sorted(previous.get(o['service'], set()) - {md5(o['items'])})
    for p in out_prayers:
        prev = sorted(previous_prayers.get(p['slug'], set()) - {md5(p['content'])})
        if prev:
            p['previous'] = prev

    data = {
        'format': 'prayer-rule-ustav/1',
        'source': 'Home Ustav (1910): fasts (fol. 9-30), prayer at home (ch. 4), the zapevy (fol. 209-225) and the dismissals for the whole year (fol. 226-253); English translation',
        'prayers': out_prayers,
        'orders': out_orders,
        'dismissals': {k: dict(zip(DAYS, v)) for k, v in DISMISSALS.items()},
        'additions': {k: dict(zip(DAYS, v)) for k, v in ADDITIONS.items()},
        'additions_feast': ADDITIONS_FEAST,
        'feasts': feast_rules,
        'fasting': fasting,
        'zapevy': zapevy,
        'seasons': SEASONS,
        'substitutions': SUBSTITUTIONS,
        'paschal_prefix': {'from': 8, 'to': 38, 'services': ['vespers', 'matins', 'hours'],
                           'text': 'O Thou who didst rise from the dead: '},
    }
    TARGET.write_text(json.dumps(data, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    print(f'{TARGET.name}: {len(out_prayers)} prayers, {len(out_orders)} orders, {len(feast_rules)} feast rules, {len(fasting)} fasting periods, {len(zapevy["dated"])} dated zapevy')


if __name__ == '__main__':
    main()
