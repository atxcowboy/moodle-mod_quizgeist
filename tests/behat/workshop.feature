@mod @mod_quizgeist @quizgeistaddon_selfstudy @javascript
Feature: Fragenwerkstatt — eine Schülerfrage wird erst durch eine Lehrkraft spielbar
  Damit Lernende Fragen schreiben, ohne dass ungeprüfte Inhalte im Spiel landen,
  bleibt eine Einreichung ein Entwurf, bis eine Lehrkraft sie freigibt.

  Background:
    Given the following "courses" exist:
      | fullname     | shortname |
      | Werkstattkurs | WK1      |
    And the following "users" exist:
      | username | firstname | lastname |
      | lehrer1  | Lea       | Lehrer   |
      | schuel1  | Sam       | Schüler  |
      | schuel2  | Toni      | Zweit    |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | lehrer1 | WK1    | editingteacher |
      | schuel1 | WK1    | student        |
      | schuel2 | WK1    | student        |
    And the following "activities" exist:
      | activity  | name          | course | idnumber   |
      | quizgeist | Werkstatttest | WK1    | quizgeist1 |

  Scenario: Eine eingereichte Frage bleibt Entwurf, bis die Lehrkraft sie freigibt
    Given I am on the "Werkstatttest" "quizgeist activity" page logged in as "schuel1"
    When I set the field "Deine Frage" to "Warum steigt der Druck bei höherer Temperatur?"
    And I set the field "Antwort 1" to "Die Teilchen bewegen sich schneller"
    And I set the field "Antwort 2" to "Die Masse nimmt zu"
    And I set the field "Warum ist das richtig? (Pflicht)" to "Schnellere Teilchen stoßen häufiger gegen die Wand, und genau das misst der Druck."
    And I click on "Frage einreichen" "button"
    Then I should see "Eingereicht — wartet auf die Lehrkraft."

    # Zweite Rolle: die Frage ist für Mitlernende sichtbar und bewertbar,
    # aber sie ist noch keine spielbare Frage der Aktivität.
    When I am on the "Werkstatttest" "quizgeist activity" page logged in as "schuel2"
    Then I should see "Warum steigt der Druck bei höherer Temperatur?"
    And I should see "Fragen deiner Klasse"

    # Dritte Rolle: die Lehrkraft sieht die Einreichung in der Kuratierung.
    When I am on the "Werkstatttest" "quizgeist activity" page logged in as "lehrer1"
    Then I should see "Fragenwerkstatt"
    And I should see "Warum steigt der Druck bei höherer Temperatur?"
    And I should see "Eingereicht — wartet auf die Lehrkraft."

    When I click on "Freigeben" "button"
    Then I should see "Freigegeben — deine Frage ist im Spiel."
    And I should see "Warum steigt der Druck bei höherer Temperatur?" in the "editor" "quizgeist question list"

  Scenario: Eine Einreichung ohne Erklärung wird serverseitig abgelehnt
    Given I am on the "Werkstatttest" "quizgeist activity" page logged in as "schuel1"
    When I set the field "Deine Frage" to "Was ist ein Mol?"
    And I set the field "Antwort 1" to "Eine Stoffmenge"
    And I set the field "Antwort 2" to "Eine Masse"
    And I click on "Frage einreichen" "button"
    Then I should see "Schreibe dazu, warum die Lösung richtig ist."
    And I should not see "Eingereicht — wartet auf die Lehrkraft."

  Scenario: Die eigene Frage lässt sich nicht selbst bewerten
    Given I am on the "Werkstatttest" "quizgeist activity" page logged in as "schuel1"
    When I set the field "Deine Frage" to "Warum kocht Wasser bei 100 Grad?"
    And I set the field "Antwort 1" to "Weil der Dampfdruck dem Luftdruck entspricht"
    And I set the field "Antwort 2" to "Weil die Masse sinkt"
    And I set the field "Warum ist das richtig? (Pflicht)" to "Bei 100 Grad erreicht der Dampfdruck den Umgebungsdruck, deshalb siedet Wasser."
    And I click on "Frage einreichen" "button"
    Then I should see "Deine Fragen"
    And I should not see "Rückmeldung speichern" in the "Deine Fragen" "section"
