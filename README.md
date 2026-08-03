# Quizgeist

Quizgeist is a live quiz activity for Moodle. A teacher hosts a round, learners
join with a code on their own devices, and everyone sees the same question at
the same time. Content, answers and scoring stay inside the Moodle instance —
there is no external service, no extra account, and no data leaves the server.

This repository holds the free base plugin, licensed under GPL v3. It is
complete for the core loop **author → host a live round → review results**.
There are no participant limits, no question limits, no time limits, no
watermarks and no online activation.

- **Version:** 1.2.0-rc.1
- **Requires:** Moodle 5.2 (`2026042000`) or newer
- **Languages:** English and German, 1618 strings each

## Screenshots

<!--
Screenshots follow with the first stable release. Shipping them now would mean
shipping stale ones: the interface still moves between release candidates.
-->

## Features

**Authoring.** A Moodle-native question editor with six question types in the
base plugin: multiple choice, true/false, short answer, poll, word cloud and
content slide. Questions carry local media, tags, a per-question time limit and
a scoring mode. A quiz can be saved as a template and reused across courses.

**Hosting a live round.** The host opens a lobby; learners join with a six-digit
code or by scanning a QR code. The host advances the round question by question.
There is a scoreboard, a podium at the end, a reconnect path so a dropped device
rejoins where it left off, and a fullscreen mode for both the host view (for
projection) and the player view.

**Reviewing.** A session report per round with a per-question breakdown, plus
CSV export. Results reach the Moodle gradebook through the standard grading API.

**Kahoot import.** Kahoot JSON files and ZIP exports — single quizzes or a full
account archive — are imported with questions, answers, time limits and local
media. Files are validated locally; nothing is fetched from the network. Every
run produces a report listing what was accepted, adapted or skipped.

**Moodle-native throughout.** Enrolments, capabilities, groups, visibility and
availability restrictions apply as usual. The plugin ships 16 capabilities,
backup and restore, course reset, a Privacy API provider, scheduled tasks, and
56 PHPUnit test files.

## Requirements

| | |
|---|---|
| Moodle | 5.2 (`2026042000`) or newer |
| PHP extensions | DOM, Fileinfo, Zip |
| Browsers | Any current browser with JavaScript. Tested on Chrome, Firefox and Safari, including iPhone, iPad and macOS |
| Cron | Moodle cron must run for reminders and cleanup tasks |

Large Kahoot archive imports additionally need `upload_max_filesize`,
`post_max_size`, the web server body limit and Moodle's site and course file
limits to allow for the archive size.

## Installation

**From the ZIP, through the web interface**

1. Download the release ZIP.
2. Open **Site administration → Plugins → Install plugins** and upload it.
3. Follow the upgrade prompt.

**By hand**

1. Unpack into `mod/quizgeist` in your Moodle root.
2. Open **Site administration → Notifications** to create the database tables,
   capabilities, cache definitions and scheduled tasks.

Then add a **Quizgeist** activity to a course. No other quiz plugin is required.

Install the base plugin before any separately distributed add-on, and never
upgrade the base after the add-ons.

## How a live round works

The host starts a session and receives a join code. Learners open the activity —
or scan the QR code — enter the code, pick a display name, and wait in the lobby.
The host advances the round; every device follows.

State lives in Moodle. Every client asks the server for it, and the server
decides what each participant may see; answers are scored server-side. The
current release carries this over HTTP polling, with an adaptive interval that
backs off while a tab is hidden. A load test with 27 simultaneous players
measured a median server response of 21 ms with no failures.

## Privacy and data protection

Quizgeist sends no telemetry and makes no activation calls. It loads no scripts,
fonts or images from third-party CDNs — everything a live round needs is served
from the Moodle instance itself.

Participation, answer and learning data stay in Moodle and are declared through
Moodle's Privacy API, so the standard export and deletion requests cover them.

## Optional add-ons

Six optional add-ons are sold separately by Panomity and are **not** part of
this repository. They are Moodle subplugins that drop into
`mod/quizgeist/addon/` and only ever add capabilities:

| Add-on | What it adds |
|---|---|
| Question Types Plus | Seven more question types: puzzle, slider, scale, pin-on-image, image reveal, brainstorm, open response |
| Game Modes | Team play, accuracy mode, confidence mode, seasonal themes |
| Self-study | Assignments, deadlines, reminders, flashcards, practice tests, weekly goals |
| Reports Pro | Combined and course-level reports, XLSX export, difficulty analysis, moderation trail |
| AI Workshop | Generate quizzes from a topic, PDF or PPTX; text to speech |
| Stage Check | Presentation practice with body-language feedback, entirely in the browser |

The base plugin never advertises what is not installed — a missing add-on leaves
no locked teasers in the interface.

One nuance worth knowing about the two question type lists: the base plugin can
**play** all thirteen types, so imported and existing content always stays
usable, but only the six base types can be **created** without the add-on. If an
add-on is installed and its licence has lapsed, existing content stays playable,
editable and exportable; only creating new premium content is blocked.

## Licensing

Two different things, easily confused:

- **This code is GPL v3 or later.** Use it, study it, modify it, redistribute
  it. See [LICENSE](LICENSE).
- **The optional add-ons are commercial.** Separate downloads with their own
  terms, verified locally against an Ed25519 signature. The base plugin needs no
  licence file and never contacts a licence server.

## Support

- **Bugs and feature requests:** the
  [issue tracker](https://github.com/atxcowboy/moodle-mod_quizgeist/issues)
- **Security reports:** privately, see [SECURITY.md](SECURITY.md) — not in a
  public issue
- **Commercial support and add-ons:**
  [panomity.de/software/quizgeist](https://panomity.de/software/quizgeist/)

Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

## Credits

Developed by [Panomity GmbH](https://panomity.de), built and tested in daily
teaching at a German Montessori vocational school. Third-party components and
their licences are listed in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

---

## Deutsch

Quizgeist ist eine Live-Quiz-Aktivität für Moodle. Eine Lehrkraft eröffnet eine
Runde, die Klasse tritt mit einem Code auf den eigenen Geräten bei, und alle
sehen dieselbe Frage zur selben Zeit. Inhalte, Antworten und Wertung bleiben in
der Moodle-Instanz — kein externer Dienst, kein zusätzliches Konto, keine Daten,
die den Server verlassen.

Dieses Repository enthält das **kostenlose Basisplugin** unter GPL v3. Es deckt
den vollständigen Kernkreislauf ab: **erstellen → live spielen → auswerten** —
ohne Teilnehmerbegrenzung, ohne Fragenbegrenzung, ohne Zeitbegrenzung, ohne
Wasserzeichen, ohne Online-Aktivierung.

**Enthalten:** Frageneditor mit sechs Fragetypen (Quiz, Richtig/Falsch,
Kurzantwort, Umfrage, Wortwolke, Inhaltsfolie), Medien, Schlagwörter, Zeitlimit
und Punktmodus je Frage, Vorlagen; Live-Runden mit Beitrittscode und QR-Code, Rangliste,
Podium, Wiedereinstieg nach Verbindungsabbruch, Vollbildmodus für Moderation und
Spielende; Sitzungsbericht und CSV-Export; vollständiger Kahoot-Import (JSON und
ZIP, lokal geprüft, mit Importbericht).

**Moodle-nativ:** Einschreibungen, Capabilities, Gruppen, Sichtbarkeit und
Verfügbarkeiten gelten wie gewohnt. 16 Capabilities, Sicherung und
Wiederherstellung, Kurs-Reset, Privacy-API, Hintergrundtasks, 56 PHPUnit-Dateien.

**Voraussetzungen:** Moodle 5.2 (`2026042000`) oder neuer, PHPs DOM-, Fileinfo-
und Zip-Erweiterungen, JavaScript im Browser, regelmäßig laufender Moodle-Cron.

**Installation:** ZIP über **Website-Administration → Plugins → Plugins
installieren** hochladen, oder nach `mod/quizgeist` entpacken und
**Website-Administration → Mitteilungen** öffnen.

**Live-Betrieb:** Der Zustand liegt in Moodle; der Server entscheidet, wer was
sieht, und wertet die Antworten. Die Übertragung läuft derzeit über
HTTP-Abrufe mit angepasstem Takt. Ein Lasttest mit 27 gleichzeitig Spielenden
ergab 21 ms im Median, keine Fehler.

**Datenschutz:** keine Telemetrie, keine Aktivierungsanfragen, keine externen
CDNs. Personenbezogene Daten sind über Moodles Privacy-API deklariert.

**Sechs kostenpflichtige Zusatzmodule** (Fragetypen Plus, Spielmodi,
Selbstlernen, Berichte Pro, KI-Werkstatt, Bühnen-Check) sind nicht Teil dieses
Repositorys. Nicht installierte Zusatzmodule erscheinen nirgends als gesperrte
Köder. Die Basis *spielt* alle dreizehn Fragetypen, damit importierte Inhalte
nie unspielbar werden; *neu anlegen* lassen sich ohne Zusatzmodul die sechs
Basistypen.

**Fehler melden:** über den
[Issue-Tracker](https://github.com/atxcowboy/moodle-mod_quizgeist/issues).
Sicherheitslücken bitte nicht öffentlich, sondern nach [SECURITY.md](SECURITY.md).
Kommerzieller Support und Zusatzmodule:
[panomity.de/software/quizgeist](https://panomity.de/software/quizgeist/).
