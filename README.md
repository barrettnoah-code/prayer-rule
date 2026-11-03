# Prayer Rule

A WordPress plugin for keeping a private Old Believer prayer rule: the daily
cycle of services kept by kathismas and Jesus Prayers, a built-in Psalter, and
a record of what was kept, with reports and optional sharing with a chosen
member (a nastavnik).

**Status:** early development. The admin side works (Psalter, prayers,
orders, dismissals, fasting, saints, kathismas, preview). Members read any
service on the reading page, keep a private encrypted rule, and mark each
service as kept, with weekly and monthly reports, and share their rule
and record with a nastavnik.

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

Under "Nastavnik" a member can share their rule and record with any
number of people (each must have set up a private rule of their own). The
nastavnik sees the rule, the reports and the record, and can send messages
and suggested changes to the rule; the member accepts or declines each
suggestion. Either side can end a share.

## Privacy

Each member's rule and record are encrypted in their own browser
(`assets/reader.js`, WebCrypto) before they are saved:

- a random 256-bit key encrypts them (AES-GCM);
- that key is stored only wrapped, once under the member's passphrase and
  once under a one-time recovery code (PBKDF2-SHA-256, 600,000 iterations);
- the passphrase, the recovery code and the key never leave the browser.

For sharing, each member also has an ECDH (P-256) key pair: the public
half is stored on the site, the private half sealed with the member's key.
A member and a nastavnik derive the same key from their two key pairs
(ECDH, then HKDF-SHA-256) and use it to encrypt the shared copy of the rule
and record and every message (`includes/class-sharing.php` stores only
ciphertext). Both see the same short safety code; comparing it in person or
by phone shows that no one has swapped a key.

The site keeps one row of ciphertext per member (`includes/class-vault.php`)
and cannot read it; nor can its administrators or its host. They can see
only that a member has set up a rule, its size and when it last changed,
and who shares with whom and when they write — never what. A
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
day of the week. `tools/ustav/zapevy.txt` holds the zapevy for the whole
year (the refrains said with the prostrations in the daily additions): for
the days of the week, for dated feasts and saints, and for the Triodion.
From the evening of Great Saturday to Bright Saturday, each service uses
its own Pascha or Bright Week order (the "… (Home Ustav)" orders for Pascha and
Bright Week on the Orders screen, from the Home Ustav fol. 184v-205); from
Thomas Sunday to the leave-taking of Pascha, "Christ is risen" thrice is said
in place of "O Heavenly King".
`tools/build_ustav.py` turns it into `data/ustav.json`. The plugin adds
these as ordinary Prayers and Orders the first time an administrator opens
the admin, and again whenever the file changes: missing ones are added,
and ones nobody has edited since an earlier version are brought up to
date. Anything edited on the site is left alone.

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
  Inside a prayer, `[psalm 50]` gives the psalm in the reader's translation,
  and a line `[zapev god-bearer]` (also `trinity`, `resurrection`,
  `cross-resurrection`, `angels`, `forerunner`, `cross`, `apostles`,
  `nicholas`, `all-saints`, `departed`, `feast`, `saint`) gives a zapev:
  `feast` and `saint` follow the date.
  After a line, `[bow]` shows "(prostration)" or "(bow)" (a waist bow) as
  the day requires: waist bows on Saturdays and Sundays (not Great
  Saturday), on great feasts and feasts with a vigil or polyeleos, in the
  twelve days after the Nativity and from Pascha to All Saints; in Holy Week
  from the Hours of Great Wednesday the service has waist bows while the
  openings and the rule stay to the ground; Vespers and Compline read on a
  Sunday or feast evening begin with waist bows. `[bow entrance]`,
  `[bow departing]`, `[bow rule]` and `[bow great]` (always "great
  prostration") mark the other kinds; `[bow 3]` gives "(three prostrations)", and `[bow thrice]`
  after a short prayer said three times gives "(thrice, with prostrations)".
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
  "Saint N.". Also the saint's zapev for each date: the Home Ustav's where
  it gives one, otherwise made from the saint's name after the General
  Menaion ("O holy martyr N., pray to God for us"), editable per date.
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
