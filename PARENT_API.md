# PARENT_API — die Lese-Schnittstelle von mod_quizgeist

Gilt ab `mod_quizgeist` 1.2.

Diese Datei ist der **Vertrag** für Parent-, Tutor- und Beratungsdashboards.
Die Daten werden über diese Methode aggregiert; ein Dashboard muss keine
Quizgeist-Tabellen lesen.

---

## Warum eine Lese-API und keine Lieferung

Die Schnittstelle folgt einem einfachen **Pull**-Muster: Ein Parent- oder
Tutor-Dashboard fragt den Digest für eine Nutzer-ID ab, und Quizgeist ruft
kein Dashboard zurück. Die API ist unabhängig von einer bestimmten
Dashboard-Implementierung. Fehlt das Plugin oder die Klasse, kann das
Dashboard ohne Quizgeist weiterarbeiten; die zugehörige Kachel bleibt leer.

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
    $digest = \mod_quizgeist\local\parent\digest::for_user($userid);
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
   nur. Ein fehlendes oder abgelaufenes Entitlement sperrt Neuanlagen, niemals
   den Blick auf bereits gespeicherte Lernstände.
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
- Sie ruft kein Eltern-Dashboard auf. Die Richtung ist immer: Oberfläche fragt,
  Quizgeist antwortet.
