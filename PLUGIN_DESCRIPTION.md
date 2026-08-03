# Quizgeist

Quizgeist is a Moodle activity for classroom quizzes. Version 1.2 provides a
base plugin and six optional Moodle subplugins. The base covers authoring,
live play and review without a licence file, participant or question limits,
watermarks, or online activation.

## Highlights

- Classic live sessions with a local join code, QR code, host display,
  reconnect, scoreboard and podium.
- Six question and content types: multiple choice, true/false, short
  answer, poll, word cloud and presentation slide.
- Session report, student view and CSV export.
- JSON and ZIP migration for teacher-owned Kahoot exports, including local
  media, idempotent source tracking, and a transparent report of adapted or
  unsupported content. Import remains fully available in the base package.
- German and English language packs.

## Optional addons

- **Question Types Plus:** puzzle, slider, scale, pin/heatmap, image reveal,
  brainstorming and teacher-reviewed open response.
- **Game Modes & Motivation:** team, accuracy and security modes, seasonal
  themes and course motivation features.
- **Self-study & Practice:** assignments, deadlines, reminders, flashcards,
  practice tests and weekly goals.
- **Reports Pro:** combined and course reports, XLSX export, difficulty
  analysis and moderation trail.
- **AI Workshop:** topic/PDF/PPTX to quiz and text-to-speech.
- **Bühnen-Check:** presentation practice with body-language and duration
  feedback, fully in the browser.

Uninstalled capabilities are absent from navigation and authoring controls.
Installed addons use an offline signed entitlement for new premium content.
When an entitlement expires, existing questions, sessions, assignments,
reports and exports remain playable, visible, editable and exportable. A
missing or rejected licence file does not block access to existing data.

## Moodle integration

Quizgeist uses Moodle capabilities, enrolments, activity visibility, groups,
completion, gradebook, events, scheduled tasks, backup and restore, course
reset, and the Privacy API. Student and report access is checked server-side.
Live sessions update the current state through HTTP polling.
Correct answers and scoring targets are withheld from player payloads until the
authoritative reveal.

## Requirements and optional integrations

- Moodle 5.2 or later.
- PHP DOM, Fileinfo, and Zip extensions.
- JavaScript enabled in participating browsers.
- Moodle cron configured for reminders and finalisation tasks.
- The AI Workshop works with the managed AI service included in its package. If
  that service component is absent, Quizgeist explains the requirement and
  retains its rule-based draft fallback. Sites operating their own AI
  infrastructure can use Moodle's built-in AI settings as Moodle's open
  standard path.
- An optional site-local text-to-speech provider. Quizgeist hides unavailable
  voice controls cleanly.
- No font files are bundled or fetched. All themes use local system-font
  stacks. The optional site-local families Sora, Poppins, Inter, Rubik, and
  Pathway Gothic One retain the intended typography when the site configures
  matching local fonts.

The plugin does not require an external CDN, analytics service, image service,
or public AI endpoint.

### Outbound connections

The AI workshop has two optional server-side outbound source paths. A teacher
with `mod/quizgeist:manage` can request a freely entered public HTTP(S) URL or
a German-language article from `de.wikipedia.org`. Quizgeist sends only the
requested URL and adds no Moodle user data, answers, or grades. Server-side
guards constrain the URL scheme, target, port, redirects, response size, and
content type and reject internal network targets.

The site setting `mod_quizgeist | allowoutboundfetch` defaults to enabled to
preserve existing behaviour. An administrator can disable it to make both
paths fail closed; the workshop then shows an explanatory notice while local
document sources and the rest of the drafting workflow remain available.
Runtime fonts, scripts, images, tracking, and AI generation do not use these
connections.

For large Kahoot ZIP migrations, PHP, the web server, and Moodle's site/course
upload limits must all allow the archive size. Quizgeist caps archives at
128 MB compressed, 160 MB uncompressed, 64 MB combined Kahoot JSON and
individual media files at 25 MB. Each Kahoot is limited to 150 questions.

## Performance evidence and deliberate boundaries

A current permitted performance record covers 27 simultaneous players, with a
median response time of 21 ms and zero errors.

Marketplaces, subscriber channels, standalone apps, offline app mode, and
third-party learning apps are outside the Moodle activity scope. The
image-reveal effect transfers the complete protected Moodle image
after the server-authoritative question start and then masks it in the browser;
it should therefore be used for recognition and engagement, not to protect a
security-critical image.

The Kahoot migration feature reads teacher-provided export files only. It does
not connect to Kahoot services, reuse Kahoot branding, or reproduce its visual
design, sounds, or assets. Quizgeist is an independent project and is not
affiliated with or endorsed by Kahoot.

## Installation

Install or upgrade the base at `mod/quizgeist` first and visit Moodle's Site
administration notifications page. Then copy each selected component to
`mod/quizgeist/addon/<name>` and run notifications again. Finally upload the
signed file on the Quizgeist licence administration page. The file is verified
locally with an instance-bound Ed25519 signature; installing and using the base
requires no licence. Ensure that Moodle cron runs normally.

## Privacy and licensing

Quizgeist stores participation, answers, attempts, reports, learning goals,
rewards, and import provenance in the Moodle installation. Moodle's Privacy API
supports metadata declaration, user export, and deletion. The plugin is
licensed under the GNU GPL v3 or later.

For operational details, installation order, licence handling, limitations and
metric definitions, see `README.md`. The deliberately shared base/addon
boundaries are recorded in `ARCHITECTURE.md`; release changes are in
`CHANGELOG.md`.
