# PARENT_API — die Lese-Schnittstelle von mod_quizgeist

Gilt ab `mod_quizgeist` 1.1.

Diese Datei ist der **Vertrag**. Wer sie liest, kann eine Eltern-, Tutoren-
oder Beratungsoberfläche bauen, ohne in die Quizgeist-Tabellen zu greifen und
ohne eine Abhängigkeit zu erklären.

---

## Warum eine Lese-API und keine Lieferung

`local_elternkompass` besitzt **keine** Lieferschnittstelle für Fremdplugins:
kein Callback, kein Hook, kein Interface; `lib.php` enthält null Funktionen,
`db/hooks.php` registriert nur `primary_extend` in die Gegenrichtung. Sein
faktisches Muster ist **Pull** — `local_elternkompass\local\dashboard_service`
liest fremde Tabellen selbst, und andere Plugins konsumieren umgekehrt den
`dashboard_service`.

Deshalb liefert mod_quizgeist nichts aus, sondern **stellt bereit**. Damit gilt
in beide Richtungen:

- mod_quizgeist hat **keine** `$plugin->dependencies` auf Elternkompass und
  erwähnt ihn in `version.php` mit keinem Wort.
- Elternkompass funktioniert ohne Quizgeist unverändert; die Kachel verschwindet
  einfach.

---

## Signatur

```php
\mod_quizgeist\local\parent\digest::for_user(int $userid, int $courseid = 0): array
```

- **Viewer-agnostisch.** Die Methode prüft **keine** Berechtigung und trifft
  keine Annahme darüber, wer fragt. **Der Aufrufer prüft seine eigene
  Berechtigung**, bevor er eine Nutzer-ID übergibt.
- `$courseid = 0` bedeutet „alle Kurse, in denen dieser Mensch geübt hat".
- Die Methode **wirft nie**. Fehlende Tabelle, fehlendes Addon, fehlende Daten,
  gelöschter Nutzer: alles ergibt die Leerform.

## Rückgabeform (DTO)

```php
[
    'hasData'      => bool,     // false = Leerform, nichts zu zeigen
    'weeklyGoal'   => ?int,     // Summe der gesetzten Wochenziele, null = keines
    'weeklyDone'   => int,      // in dieser Woche beantwortete Fragen-Wurzeln
    'weeklyPercent'=> int,      // 0..100; 0, wenn kein Ziel gesetzt ist
    'streakDays'   => int,      // aufeinanderfolgende Übungstage
    'dueCount'     => int,      // heute fällige Wiederholungen
    'nextDue'      => ?int,     // Unix-Zeit der nächsten Fälligkeit
    'competences'  => [         // leer ohne reports-Addon
        [
            'key'     => string,   // stabiler Maschinenschlüssel des Merkmals
            'label'   => string,   // menschenlesbare Beschriftung
            'color'   => ?string,  // schulweiter Farbcode, oder null
            'percent' => int,      // 0..100 Richtigquote
            'sample'  => int,      // Anzahl bewerteter Antworten
        ],
    ],
    'generatedAt'  => int,      // Unix-Zeit der Berechnung
]
```

### Leerform

```php
[
    'hasData' => false, 'weeklyGoal' => null, 'weeklyDone' => 0,
    'weeklyPercent' => 0, 'streakDays' => 0, 'dueCount' => 0,
    'nextDue' => null, 'competences' => [], 'generatedAt' => time(),
]
```

Die Leerform ist **formgleich** zur befüllten Antwort. Eine Oberfläche braucht
also keine zweite Codebahn für „noch nichts da".

---

## Guard-Beispiel (so und nicht anders)

```php
$digest = null;
if (class_exists('\\mod_quizgeist\\local\\parent\\digest')) {
    // Die eigene Berechtigungsprüfung ist hier bereits erfolgt.
    $digest = \mod_quizgeist\local\parent\digest::for_user($childid);
}
if ($digest !== null && $digest['hasData']) {
    // Kachel zeichnen.
}
```

Kein `require_once`, kein Pfad, keine Version. `class_exists()` genügt, weil der
Moodle-Autoloader die Klasse findet, sobald das Plugin installiert ist.

---

## Stabilitätszusage

1. **Signatur und Schlüsselmenge sind stabil.** Innerhalb von 1.x wird kein
   Schlüssel entfernt und keiner umbenannt. Neue Schlüssel werden **angehängt**.
2. **Typen sind stabil.** Ein `int` bleibt `int`; `?int` wird nie zu `int`.
3. **Die Methode wirft nie.** Ein Fehler wird protokolliert und als Leerform
   beantwortet — zusätzlich meldet er sich über `debugging(…, DEBUG_DEVELOPER)`.
   Die Leerform ist die **Zusage nach außen**, nie die Diagnose: von außen sind
   „hat noch nicht geübt" und „hier ist etwas kaputt" ununterscheidbar, deshalb
   nennt ein interner Fehler im Entwicklermodus seinen Grund.
4. **Kein Gate auf Lesen.** `for_user()` ist **nicht** lizenzgesteuert: Es liest
   nur. Eine abgelaufene Lizenz sperrt Neuanlagen, niemals den Blick auf bereits
   Gelerntes (LIZENZ_VERTRAG.md, Datengeiselverbot).
5. **`competences` ist die einzige Ausnahme** und zwar nach oben, nicht nach
   unten: ohne installiertes `reports`-Addon bleibt die Liste leer, alle anderen
   Kennzahlen liefern trotzdem.
6. **Keine Fremddaten ohne Prüfung.** Die API liefert genau die Daten der
   übergebenen Nutzer-ID. Wer sie ohne eigene Berechtigungsprüfung aufruft, baut
   die Lücke selbst — das ist bewusst so dokumentiert und nicht abgefangen.

---

## Was die API **nicht** tut

- Sie schreibt nichts.
- Sie kennt weder Eltern noch Rollen noch Kurskontexte einer Oberfläche.
- Sie liefert keine Antworttexte, keine Fragen, keine Namen, keine
  Sitzungs-Kennungen — nur Aggregate.
- Sie ruft `local_elternkompass` nicht auf. Die Richtung ist immer: Oberfläche
  fragt, Quizgeist antwortet.
