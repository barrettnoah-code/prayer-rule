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

- **Prayers** – texts written on the site (the usual beginning, troparia,
  the prayers between stases, prayers for the departed …). Each has a
  *short name*, e.g. `usual-beginning`, used by orders.
- **Orders** – a reading order, one item per line: a prayer's short name,
  `stasis 1`/`2`/`3`, or `kathisma` (all three stases, each followed by the
  "after each stasis" prayer). `{n}` in a name becomes the kathisma number,
  so `troparia-{n}` reads `troparia-7` with the seventh kathisma.
- **Kathismas** – the twenty kathismas and their stases, editable.
- **Preview** – any order with any kathisma and translation.
- **Settings** – who may use it (anyone logged in, or chosen Paid
  Memberships Pro levels), the default translation, Coverdale's colons.

## Making the plugin zip

```
cd ~ && rm -f prayer-rule.zip && zip -r prayer-rule.zip prayer-rule \
  -x 'prayer-rule/.git/*' 'prayer-rule/sources/*' 'prayer-rule/tools/*' \
     'prayer-rule/*/__pycache__/*'
```

## License

GPL-2.0-or-later. See LICENSE.
