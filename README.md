# Prayer Rule

A WordPress plugin for keeping a private Old Believer prayer rule: the daily
cycle of services kept by kathismas and Jesus Prayers, a built-in Psalter, and
a record of what was kept, with reports and optional sharing with a chosen
member (a nastavnik).

**Status:** early development. The admin side works (Psalter, prayers,
orders, kathismas, preview); the members' pages come next.

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

## In the admin (Prayer Rule menu)

- **Prayers** – texts written on the site, each with a *short name*
  (e.g. `vespers-beginning`) used by orders. Red lettering (#921a0a):
  paragraph style *Prayer* (red first letter), paragraph style *Rubric*
  (all red), and the *Red* button for words such as bows or the speaker.
- **Orders** – a service's opening and ending, one item per line: a
  prayer's short name, `psalm 50`, and `rule` where the member's kathismas
  or ladders are read (`rule first-hour` for the 1st Hour inside Matins).
  Inside a prayer, `[psalm 50]` gives the psalm in the reader's translation.
- **Services** – the six services (Vespers, Compline, Midnight Office,
  Matins with the 1st Hour, Hours, Cell Rule): which order each uses, the
  suggested amounts, and the prayers inside the rule (after each stasis,
  after each kathisma in Great Lent, with the ladders).
- **Kathismas** – the twenty kathismas and their stases, editable.
- **Preview** – any service with any portion, translation, and Great Lent.
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
