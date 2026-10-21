# HuaweiPV – Prüfung für den Module Store

Erste Prüfung: 09.10.2026 (Version 1.2 Build 15) gegen die Store-Regeln (Symcon-Doku „SDK-PHP → Store → Einreichen“).
Stand dieser Notiz: 21.10.2026, Version 1.3 Build 17.

## Erledigt in Build 17

### 1. Darstellungen
- Keine `IPS_SetVariableCustomPresentation` / `IPS_SetVariableCustomProfile` mehr im normalen Betrieb.
- Variablen werden mit `MaintainVariable()` direkt unter der Instanz angelegt bzw. gepflegt und danach per `IPS_SetParent` in den Themen-Ordner verschoben. ID, Archiv und Verknüpfungen bleiben erhalten.
- Liegt eine Variable schon im Ordner und hat sich ihre Darstellung nicht geändert (Signatur im Attribut `PresentationSigs`), wird sie nicht angefasst. Sonst wird sie kurz an die Wurzel geholt und danach wieder einsortiert.
- Einzige Ausnahme: `ClearLegacyCustomPresentations()` setzt **einmalig** (Attribut `LegacyCustomCleared`) benutzerdefinierte Darstellungen zurück, die Versionen vor Build 17 selbst an Variablen in den eigenen Themen-Ordnern gesetzt hatten. Bei neuen Instanzen tut die Funktion nichts.
- Keine Variablenprofile mehr (auch keine System-Profile), nur Darstellungen.

### 2. CSV-Datei im Symcon-Ordner
- Entfernt. Die Ausreißer-Liste steht nur noch im Formular (Attribut `ArchiveOutliers`). Das Modul greift nicht mehr auf das Dateisystem zu.

### 3. Markenhinweis
- Am Ende der README ergänzt.

### 4. Positionen
- Werden nur beim Anlegen und beim Einschalten der Sortierung bzw. bei geändertem Aufbau (`LAYOUT_VERSION`) gesetzt. Eigene Positionen bleiben.

### 5. Bestätigungstext Archiv-Werkzeug
- „Übernahme starten“ nennt jetzt, dass die Archivierung der neuen Variable eingeschaltet wird (Aggregation „Zähler“ bei Energiezählern).

## Grauzone – beim Einreichen erklären

### Schnittstelle (IO) schließen und öffnen
- **Wo:** Watchdog nach fehlgeschlagenen Durchläufen und `ReopenConnection()`: `IPS_SetProperty($io, 'Open', …)` + `IPS_ApplyChanges($io)`.
- **Warum:** Der Huawei Smart Dongle kann „hängen“: Die TCP-Verbindung bleibt offen, es kommen aber keine Antworten. Der Client Socket merkt das nicht und baut nicht neu auf.
- **Schutz:** Option „Verbindung automatisch neu aufbauen“ ist für neue Instanzen **aus**, im Formular und in der README ausführlich erklärt, höchstens alle 10 Minuten, mit Meldung.
- **Beim Einreichen erwähnen.** Lehnt Symcon das ab, wird die Option entfernt.

### Archiv-Werkzeug schaltet die Archivierung der Zielvariable ein
- `AC_SetLoggingStatus`, `AC_SetAggregationType(…, 1)`, `IPS_ApplyChanges($archive)` – nur nach Knopfdruck mit Bestätigung.

## In Ordnung
- Nur `<?php`, kein `IPS_LogMessage`, keine Ereignisse (nur `RegisterTimer`), kein `IPS_SetProperty` auf eigene Properties, kein Dateizugriff.
- Themen-Ordner sind Dummy-Instanzen **unter** der Instanz, also eigene Objekte.
- Namen werden nur nachgezogen, wenn noch der automatische Name drinsteht. Eigene Namen bleiben.
- Instanz ohne Einrichtung anlegbar, keine Abfragen beim Anlegen.
- Quellvariable des Archiv-Werkzeugs wird per `SelectVariable` ausdrücklich zugewiesen und nur gelesen.

## Einreichen
- account.symcon.de → Entwicklerbereich → neue Einreichung
- Bundle-ID z. B. `io.github.peterchrisben.huaweipv` (bleibt für immer)
- Git: https://github.com/peterchrisben/HuaweiPV, Commit auswählen
- Lokalisierung Deutsch: Name, Beschreibung, Versionsinfo, Doku-Link (README)
- Kategorie: Energie
- Erst Kanal **Beta** (ohne Review), danach **Stable** (Review durch Symcon)
