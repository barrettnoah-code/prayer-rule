# Prayer Rule

A WordPress plugin for keeping a private Old Believer prayer rule: the daily
cycle of services kept by kathismas and Jesus Prayers, a built-in Psalter, and
a record of what was kept, with reports and optional sharing with a chosen
member (a nastavnik).

**Status:** early development. The admin side works (Psalter, prayers,
orders, dismissals, fasting, saints, kathismas, preview). Members read any
service on the reading page, keep a private encrypted rule, and mark each
service as kept, with weekly and monthly reports. Sharing with a nastavnik
comes next.

## The reading page

Put `[prayer_rule]` on any page. A member chooses the day and the service
and reads it, with the day's church date, commemorations and fasting, and
their own kathismas or ladders in place.

A member can set up a private rule there: which services they keep, the
portion for each, the next kathisma, the Psalter translation. They then
mark each service as kept; the next kathisma moves on by itself, and a
change made more than 7 days after the day is marked "corrected later".
Under "My record" they see reports by week or month: each service kept on
how many days, the days with the whole rule kept, kathismas read (and how
many times the whole Psalter), ladders of the Jesus Prayer, and a month
calendar. They can print a report or download their whole record as a CSV
file. All of this is worked out in their browser from the decrypted record.
Without a private rule, the settings are kept only in that browser.

## Privacy

Each member's rule and record are encrypted in their own browser
(`assets/reader.js`, WebCrypto) before they are saved:

- a random 256-bit key encrypts them (AES-GCM);
- that key is stored only wrapped, once under the member's passphrase and
  once under a one-time recovery code (PBKDF2-SHA-256, 600,000 iterations);
- the passphrase, the recovery code and the key never leave the browser.

The site keeps one row of ciphertext per member (`includes/class-vault.php`)
and cannot read it; nor can its administrators or its host. They can see
only that a member has set up a rule, its size and when it last changed. A
lost passphrase can be replaced with the recovery code; if both are lost,
no one can open the rule. Deleting the plugin keeps these rows.

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
services, the cell rule, the dismissals for the week and the daily
additions. `tools/ustav/feasts.txt` holds the dismissals for the feasts of
the year and of the Triodion and Pentecostarion, each with the dates it
applies to. `tools/ustav/fasting.txt` holds chapter 1, on the fasts: for
each period the general rule, the other typika, and a short line for each
day of the week.
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
  `dismissal` for the day's dismissal, `additions` for the daily additions, `> Lord, have mercy. (12 times)` for
  a short line said as written, and `! …` for a rubric.
  Inside a prayer, `[psalm 50]` gives the psalm in the reader's translation.
- **Services** – the six services (Vespers, Compline, Midnight Office,
  Matins with the 1st Hour, Hours, Cell Rule): which order each uses, the
  suggested amounts, and the prayers inside the rule (after each stasis,
  after each kathisma in Great Lent, with the ladders).
- **Dismissals** – which dismissal ends each service on each day of the
  week, and which daily additions follow the kathismas (the day begins at
  sundown: Sunday Vespers is read on Saturday evening); and the list of
  feast dismissals, which take the weekday one's place on their days. In a
  dismissal, `[saint]` stands for the saint of the day.
- **Fasting** – the fasting periods of the year, with the line shown for
  each day of the week (editable) and links to the rule texts.
- **Saints** – the saint each day's dismissal names, taken from the
  Orthodox Liturgical Calendar plugin (its highest-ranked commemoration,
  worded for the dismissal), with the wording editable per church date.
  The calendar itself is never changed; without it, dismissals say
  "Saint N.".
- **Kathismas** – the twenty kathismas and their stases, editable.
- **Preview** – any service on any date (the day of the week, Great Lent,
  the saint, the feast and the fast follow from the date), with any portion
  and translation.
- **Settings** – who may use it, the default translation, Coverdale's
  colons, verse numbers, and the hour from which the reading page opens on
  the next day.

## Making the plugin zip

```
cd ~ && rm -f prayer-rule.zip && zip -r prayer-rule.zip prayer-rule \
  -x 'prayer-rule/.git/*' 'prayer-rule/sources/*' 'prayer-rule/tools/*' \
     'prayer-rule/*/__pycache__/*'
```

## License

GPL-2.0-or-later. See LICENSE.
