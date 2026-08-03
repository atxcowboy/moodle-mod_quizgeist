# Quizgeist 1.1: Basisplugin, Addons und Lizenzgrenze

Dieses Dokument beschreibt die technische Paketgrenze. Die verbindlichen
Produktregeln stehen in `PRODUKT.md`, das signierte Dateiformat in
`LIZENZ_VERTRAG.md`.

## Komponenten

`mod_quizgeist` besitzt den Moodle-Subplugin-Typ `quizgeistaddon`. Moodle
entdeckt installierte Komponenten unter `mod/quizgeist/addon/<name>`. Eine
Komponente wird erst aktiv, wenn Code und installierte Versionszeile vorhanden
sind. Jede Komponente stellt genau einen Provider bereit; die Basis-Registry
führt Providerdeklarationen deterministisch zusammen und lehnt doppelte
Registrierungen ab.

| Feature-Key | Komponente | Providergrenze |
|---|---|---|
| `qtypes` | `quizgeistaddon_qtypes` | Premium-Fragetypkennungen |
| `modes` | `quizgeistaddon_modes` | Premium-Modi und Saisonmotive |
| `selfstudy` | `quizgeistaddon_selfstudy` | Selfstudy-AJAX-Handler |
| `reports` | `quizgeistaddon_reports` | Pro-Berichtsfähigkeiten und Einstellungen |
| `ai` | `quizgeistaddon_ai` | KI-Handler, Quellen, Gateway und Werkstatt |

Ein Provider beschreibt vorhandenen Code, er erteilt keine Berechtigung.
`feature_gate` ist die einzige Stelle, die installierten Code und signiertes
Entitlement kombiniert. Alle sichtbaren Kataloge werden aus seiner Entscheidung
abgeleitet; alle schreibenden Serverpfade prüfen dieselbe Entscheidung erneut.

## Zustände

Für Neuanlage gilt:

| Addon-Code | Entitlement | Ergebnis |
|---|---|---|
| fehlt | beliebig | Fähigkeit fehlt aus UI und Registry |
| installiert | fehlt, ungültig oder abgelaufen | gesperrt mit lokaler Diagnose |
| installiert | aktiv oder Kulanz | Neuanlage erlaubt |

`play_existing`, `view_existing`, `edit_existing` und `export_existing` bleiben
bei installiertem Code unabhängig vom Lizenzfehler erlaubt. Damit kann eine
Schule ihre Daten auch nach Ablauf oder bei einer beschädigten Datei
weiterverwenden. Das ist eine bewusste, serverseitig getestete
Sicherheitsgrenze und kein reiner UI-Zustand.

Die Lizenzprüfung liest ein streng begrenztes JSON-Envelope, prüft zuerst die
Ed25519-Signatur über die unveränderte kanonische Payload und validiert danach
Schema, Instanzbindung, Revision und Zeitgrenzen. Angenommene Dateien werden
über die Moodle File API atomar versioniert; widersprüchliche Bytes derselben
Revision und unbeabsichtigte Rollbacks werden abgewiesen. Installationen sind
durch ein globales Moodle-Lock serialisiert. Pointer und Anti-Rollback-Ledger
werden direkt aus dem committed `config_plugins`-Stand gelesen; frühere
unveränderliche Dateigenerationen bleiben erhalten, damit parallele Leser
garantiert entweder die alte oder die neue Datei erhalten. MUC wird höchstens
bis zum nächsten signierten Zustandswechsel verwendet.

## Bewusst gemeinsam im Basisplugin

Ein vollständiges physisches Herausschneiden wäre an einigen Stellen teuer und
würde entweder doppelten Code oder riskante Datenmigrationen erzeugen. Diese
Bausteine bleiben deshalb bewusst in der Basis:

- Die sieben Premium-Fragetyp-Strategien und ihr gemeinsames Fragenschema
  bleiben als reine Kompatibilitäts-Laufzeit in der Basis. Der Addon-Provider
  veröffentlicht ihre Kennungen für Neuanlage. Das hält bestehende Fragen und
  den dauerhaft kostenlosen Kahoot-Import spielbar, ohne Strategie-Duplikate.
- Team-, Genauigkeits- und Sicherheitswertung teilen sich Sessionzustand,
  Reconnect und serverautoritative Punkteauflösung mit Klassik. Ihre
  Laufzeitpfade bleiben für Bestands-Sessions in der Basis; nur Auswahl und
  Neuanlage kommen vom Modes-Provider.
- Tabellen, Repositories, Backup/Restore, Kursreset und Privacy-Metadaten für
  Selfstudy und Berichte bleiben Eigentum der Basis. So bleiben Daten lesbar,
  exportierbar und löschbar, selbst wenn ein Addon-Codepaket fehlt.
- Bericht-Repository, Projektor und Export-Pipeline sind gemeinsame
  Infrastruktur. Ohne Reports-Addon werden serverseitig nur Sessionbericht und
  CSV ausgegeben; Pro-Quellen und Pro-Felder werden am Katalog und an der
  Distributionsgrenze entfernt.
- Gemeinsame Frontend-Bundles und Styles bleiben in der Basis. Nicht
  installierte Features erhalten keine Bootstrap-Konfiguration und erzeugen
  daher keine Premium-Anfragen.
- TTS-Konfiguration und der Moodle-Dateizugriff bleiben gemeinsame,
  fail-closed Integrationspunkte. KI-Klassen und KI-AJAX-Handler selbst liegen
  im AI-Addon. Das generische Moodle-Uploadformular, die bestehende
  `aidrafts`-Cachedefinition und der bereits persistierbare Cleanup-Task
  bleiben in der Basis, damit Upgrade und später eintreffende Aufräumjobs auch
  nach Entfernen des Addon-Codes sicher funktionieren.

Diese Schnitte sind keine zweite Implementierung: Jede Fähigkeit hat genau
einen Laufzeitpfad, und Neuanlage hat genau einen zentralen Gate-Entscheid.

## Paketierung und Abnahme

Das Basispaket enthält `mod_quizgeist` ohne die fünf
`addon/<name>`-Verzeichnisse. Jedes Addonpaket enthält genau sein
Unterverzeichnis und wird erst nach einer kompatiblen Basisversion installiert.
Eine Vollauslieferung darf alle sechs Verzeichnisse enthalten.

`checks/P10_check.sh` prüft den Sourcezustand ohne Moodle-Änderung und bietet
explizite, vom Orchestrator vorbereitete Live-Stufen. Es führt selbst weder
Deploy noch Moodle-Upgrade aus. `checks/P9_check.sh --live` bleibt mit allen
installierten und berechtigten Addons die Regressionsversicherung.

Der Produktions-Keyring ist ein Release-Artefakt. Der Testschlüssel aus dem
Lizenzvertrag darf ausschließlich in Tests vorkommen. Fehlt der echte
Trust-Anchor, muss die Abnahme trotz sonst grüner Quellprüfungen mit einem
Release-Blocker enden.
