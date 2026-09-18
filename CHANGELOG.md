# Changelog

All notable, user-visible changes to Quizgeist are documented here. The
changelog format follows [Keep a Changelog](https://keepachangelog.com/en/),
and version numbers follow [Semantic Versioning](https://semver.org/).

## [1.2.0-rc.3] - 2026-09-18

### Fixed

- The Bühnen-Check bundle (`app_stage.js`) now lives in
  `addon/buehne/bundles/` instead of `addon/buehne/amd/build/`. Moodle folds
  every file under `amd/build` without a `.min.js` sibling into its site-wide
  RequireJS bundle, so the 229 KB stage bundle was executed on every page of
  the installation. The bundle is still loaded on demand only; the build
  configuration, the loader path and the static gate follow the new location.

## [1.2.0-rc.2] - 2026-08-03

### Added

- Optional WebSocket signal relay for live sessions. The relay carries only
  wake-up signals, never session data; when it is unavailable, play continues
  over the existing adaptive polling.

## [1.2.0-rc.1] - 2026-08-01

### Added

- Low-stress live-play controls: speed-independent scoring, private progress
  leaderboards, and optional timer and sound.
- Optional written or spoken reasoning, configurable solution disclosure, and
  end-of-run solution summaries.
- Question tags, spaced repetition, and interleaved mixed review for
  self-study.
- Competence reports, misconception follow-up, and a parent-facing progress
  view.
- A curated learner question workshop with comments, returns, release
  controls, and per-question ratings.
- Spoken answers, a German speaking trainer, and card mode with confirmation
  before photographed answers are recorded.
- Curriculum-linked AI question generation where supported, with unlinked
  years clearly identified.
- The sixth optional add-on, Bühnen-Check, for camera-based presentation
  practice with aggregate body-language feedback.

### Changed

- The optional add-on family grew from five to six components. Authoring,
  classic live play, Kahoot import, session reports, and CSV export remain a
  compatible free loop without an add-on or licence.
- Worked solutions stay hidden during live play unless a teacher opts in.

### Fixed

- Parent progress digests now load reliably and explain genuine failures.
- Learner submissions stay out of play until a curator releases them.
- Deleting an activity also removes its associated stage reports, and broken
  clips no longer interrupt playback.
- Translated placeholders render correctly, and AI/vision availability checks
  report the actual result.

### Security

- Invalid, oversized, revoked, incorrectly signed, or site-mismatched licence
  files are rejected without replacing the last valid licence.

## [1.1.0-rc.1] - 2026-08-01

### Added

- Five optional add-on components introduced through a modular sub-plugin
  architecture for advanced question types, game modes, self-study, reports,
  and AI-assisted authoring.
- Offline entitlement management with site binding, revision checks, and an
  administrator status page.

### Changed

- The complete free loop—authoring, classic live play, Kahoot import, session
  reports, and CSV export—works without add-ons or a licence.
- Unavailable add-on features are hidden from authoring and navigation while
  existing content remains playable, editable, visible, and exportable.

### Fixed

- Imports, restored activities, report totals, scores, and exports now retain
  their content and provenance without duplication.
- Responsive layouts, report thresholds, generated-question validation, and
  licence administration are more reliable and readable.
- AI workshop fallback and status messages clearly explain unavailable
  optional integrations.

### Security

- Ambiguous, tampered, expired, or revision-conflicting licence files are
  rejected while the last accepted file is preserved.

## [1.0.0-rc.1] - 2026-07-29

The first public release candidate.

### Changed

- Added local system-font fallbacks, clearer installation and reminder
  guidance, and an explicit control for optional public web sources in the AI
  workshop.
- Localised CSV and XLSX column headings for German and English sites.

### Fixed

- Improved live-host contrast and restored correct-answer markers in protected
  teacher reports without exposing solutions in exports.
- Corrected singular course-star wording in both language packs.

## [0.x] - 2026-07-28 to 2026-07-29

The development series leading to the first public release candidate.

### Added

- Moodle activity foundation with authoring, capabilities, completion,
  backup/restore, privacy support, and local media.
- A validated editor with autosave, previews, themes, templates, search, and
  import, plus a broad set of question types and interactive modes.
- Live sessions with lobby and join codes, host controls, reconnect support,
  scoring, scoreboards, podiums, and adaptive HTTP polling.
- Self-study assignments, solo practice, practice tests, flashcards, deadlines,
  reminders, learning goals, completion, and gradebook integration.
- Session, assignment, combined, and course reports with authorised CSV/XLSX
  exports and review views for open responses.
- An AI workshop with teacher review, deterministic fallback, document/slide
  import, URL and German Wikipedia sources, question explanations, optional
  text-to-speech, and handwriting recognition.
- Kahoot JSON/ZIP migration with media checks, repeat-safe imports, provenance,
  and per-quiz results.

### Changed

- Responsive, accessible, German/English learner and administration views now
  respect activity, group, and report access scope.

### Fixed

- Imports, media validation, backups, restores, report privacy, exports, and
  ordering now fail safely and preserve their intended data.
- Narrow-screen live play, contrast, localisation, and reminder behaviour are
  consistent across supported views.
