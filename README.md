# Prayer Rule

A WordPress plugin for keeping a private Old Believer prayer rule: the daily
cycle of services kept by kathismas and Jesus Prayers, a built-in Psalter, and
a record of what was kept, with reports and optional sharing with a chosen
member (a nastavnik).

**Status:** early development. The admin side works (Psalter, prayers,
orders, dismissals, kathismas, preview); the members' pages come next.

## Privacy

Each member's rule and record are encrypted in their own browser with a
passphrase before they are saved. The site, its administrators and its host
store only scrambled data and cannot read it. A lost passphrase cannot be
reset; a one-time recovery code is given at setup.

The code is public so anyone can check this claim.

## Psalter

Four English translations, all with the Greek and Slavonic (LXX) psalm
numbering and without titles:

- Coverdale, from the 1662 Book of Common Prayer (default)
- King James Version
- Douay-Rheims (Challoner)
- Brenton's English Septuagint

`tools/fetch_sources.py` downloads the source texts into `sources/` (not kept
in git); `tools/build_psalter.py` turns them into `data/psalter.json`, which
the plugin loads into its own table on activation (and again whenever the
file changes).

## Home Ustav

`tools/ustav/home-ustav.txt` holds the English translation of the Home
Ustav (1910) chapter on prayer at home: the openings and endings of the six
services, the cell rule, and the dismissals for the week.
`tools/build_ustav.py` turns it into `data/ustav.json`. The plugin adds
these as ordinary Prayers and Orders the first time an administrator opens
the admin, and again (missing ones only) whenever the file changes. Nothing
already on the site is overwritten.

## In the admin (Prayer Rule menu)

- **Prayers** – texts written on the site, each with a *short name*
  (e.g. `vespers-beginning`) used by orders. Red lettering (#921a0a):
  paragraph style *Prayer* (red first letter), paragraph style *Rubric*
  (all red), and the *Red* button for words such as bows or the speaker.
- **Orders** – a service's opening and ending, one item per line: a
  prayer's short name, `psalm 50`, and `rule` where the member's kathismas
  or ladders are read (`rule first-hour` for the 1st Hour inside Matins),
  `dismissal` for the day's dismissal, `> Lord, have mercy. (12 times)` for
  a short line said as written, and `! …` for a rubric.
  Inside a prayer, `[psalm 50]` gives the psalm in the reader's translation.
- **Services** – the six services (Vespers, Compline, Midnight Office,
  Matins with the 1st Hour, Hours, Cell Rule): which order each uses, the
  suggested amounts, and the prayers inside the rule (after each stasis,
  after each kathisma in Great Lent, with the ladders).
- **Dismissals** – which dismissal ends each service on each day of the
  week (the day begins at sundown: Sunday Vespers is read on Saturday
  evening). In a dismissal, `[saint]` stands for the saint of the day.
- **Kathismas** – the twenty kathismas and their stases, editable.
- **Preview** – any service with any portion, translation, day of the
  week, and Great Lent.
- **Settings** – who may use it, the default translation, Coverdale's
  colons, verse numbers.

## Making the plugin zip

```
cd ~ && rm -f prayer-rule.zip && zip -r prayer-rule.zip prayer-rule \
  -x 'prayer-rule/.git/*' 'prayer-rule/sources/*' 'prayer-rule/tools/*' \
     'prayer-rule/*/__pycache__/*'
```

## License

GPL-2.0-or-later. See LICENSE.
