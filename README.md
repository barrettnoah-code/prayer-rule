# Prayer Rule

A WordPress plugin for keeping a private Old Believer prayer rule: the daily
cycle of services kept by kathismas and Jesus Prayers, a built-in Psalter, and
a record of what was kept, with reports and optional sharing with a chosen
member (a nastavnik).

**Status:** early development. Nothing usable yet.

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
in git); a build script turns them into the plugin's Psalter file.

## License

GPL-2.0-or-later. See LICENSE.
