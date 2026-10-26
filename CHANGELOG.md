# Changelog

## Version 1.4

### Build 18 (26.10.2026)

Für Energiemanager: zeitgleiche Leistungswerte. Bestehende Einstellungen, Variablen und Funktionen bleiben unverändert.

#### Neu
- **Schnappschuss:** PV-Leistung, AC-Leistung, Akku und Netzleistung werden in jedem Durchlauf als Erstes und direkt nacheinander gelesen (3 statt bisher 5 Anfragen), ohne andere Abfragen dazwischen. Die Werte passen damit zeitlich zusammen; der Hausverbrauch wird aus genau diesen Werten berechnet.
- Funktion `HPV_GetSnapshot` liefert den letzten Schnappschuss als JSON (Zeit, PV, AC-Leistung, Netz, Akku, Ladestand, Haus, Bilanz plausibel, Dauer) – immer im Huawei-Vorzeichen
- Diagnose: Anzahl Schnappschüsse, mittlere Dauer, Zähler „Bilanz unplausibel“ mit letzter Abweichung
- Steuerung: Steht der Wert schon im Wechselrichter, wird nichts gesendet (Diagnose zählt „übersprungen“)

#### Dokumentation
- Was PV-Leistung (DC-Eingang) und AC-Leistung sind und wie der Hausverbrauch berechnet wird
- Abfragetakt 5 s für Energiemanager, Fehlererkennung beim Schreiben, Hinweis zur Häufigkeit von Schreibvorgängen

## Version 1.3

### Build 17 (21.10.2026)

Vorbereitung für den Symcon Module Store.

#### Geändert
- Darstellungen: Das Modul setzt nur noch seine eigene Darstellung (MaintainVariable), nie die benutzerdefinierte. Variablen in Themen-Ordnern werden dafür nur bei geänderter Darstellung kurz zurückgeholt und wieder einsortiert (gleiche ID, Archiv bleibt). Eigene Darstellungen des Benutzers bleiben erhalten.
- Einmalig beim Update: benutzerdefinierte Darstellungen, die ältere Versionen an Variablen in den Themen-Ordnern gesetzt hatten, werden zurückgesetzt
- Keine Variablenprofile mehr: „Laden aus dem Netz“ und „Wechselrichter eingeschaltet“ als Schalter (mit Steuerung) bzw. Wertanzeige An/Aus, „Verbindung OK“ als Wertanzeige Ja/Nein, „Letzte erfolgreiche Abfrage“ mit Darstellung Datum/Uhrzeit, „Zwangsladen endet spätestens“ als Text
- Positionen werden nur beim Anlegen und beim Einschalten der Sortierung (bzw. geändertem Aufbau) gesetzt – eigene Positionen bleiben erhalten
- „Verbindung automatisch neu aufbauen“ ist für neue Instanzen standardmäßig aus und im Formular/README genau erklärt (bestehende Instanzen behalten ihre Einstellung)
- Archiv-Werkzeug: keine CSV-Datei mehr im Symcon-Ordner; die Ausreißer-Liste steht im Formular
- Bestätigung „Übernahme starten“ nennt, dass die Archivierung der neuen Variable eingeschaltet wird

#### Dokumentation
- Markenhinweis, Erklärung „hängender“ Smart Dongle, Tipp Benachrichtigung auf „Verbindung OK“

### Build 16 (21.10.2026)

#### Neu
- Option „Phasenwerte“: Spannung und Strom je Phase L1–L3 sowie Netzfrequenz vom Smartmeter (Register 37101–37112, 37118) – liefert auch nachts Werte, wenn der Wechselrichter schläft
- Strom je Phase folgt dem gewählten Vorzeichen der Netzleistung

#### Geändert
- Abfrageblöcke fassen nur noch Register derselben Option zusammen: Kennt ein Gerät ein optionales Register nicht, fällt nur dieser Block aus, nicht z. B. die Netzleistung

## Version 1.2

### Build 15 (08.10.2026)

#### Behoben
- Akku im Ruhemodus/Standby/Offline: Huawei meldet für Akku-Werte „kein Wert“. Das wurde die ganze Nacht als verworfener Wert gezählt (über 2000 pro Nacht) und die Akku-Leistung behielt ihren letzten Wert. Jetzt: Akku-Leistung = 0 W, andere Akku-Werte behalten ihren letzten Wert, nichts wird als verworfen gezählt. Meldet der Akku „In Betrieb“, gilt die Kennung weiterhin als Fehler.
- Keine „−0 W“ bei umgedrehtem Vorzeichen

### Build 14 (07.10.2026)

#### Geändert
- Sortierung: Erst alle Themen-Ordner (Geräteinfo und Diagnose jetzt direkt nach Wechselrichter), danach die schaltbaren Variablen ohne Ordner ganz unten

### Build 13 (07.10.2026)

#### Geändert
- Archiv-Werkzeug steht über den Buttons „Alle Werte jetzt lesen“ / „Diagnose anzeigen“ – die Buttons sind ganz unten
- Fette Zwischenüberschriften (Übernahme, Ergebnis, Ausreißer, Kontrolle); Warnungen und Bedienhinweise rot, Erfolg grün unter dem Ergebnis
- „Alte Variable“ und „Neue Variable“ in eigenen, breiteren Zeilen; Auswahl bei der Kontrolle breiter und mit Platz zum Aufklappen
- CSV-Adresse mit der IP des Symcon-Servers als anklickbarer Link

### Build 12 (07.10.2026)

#### Geändert
- Archiv-Werkzeug: Übernahme jetzt Variable für Variable direkt bei den Aktionen (alte Variable, neue Variable, Umrechnung, Vorzeichen) statt Zuordnungsliste in den Einstellungen; Felder werden nach der Übernahme zurückgesetzt
- Auswahl der neuen Variable zeigt Einheit und „Archiv vorhanden“
- Umrechnung als Auswahl (gleiche Einheit, W → kW / Wh → kWh, kW → W / MWh → kWh, Rohwert ÷ 10/100/1000, eigener Faktor)
- Vorschau zeigt ein Beispiel „alt → neu“ mit Einheit und warnt bei auffällig vielen Werten außerhalb der Grenzen
- Hintergrund-Etappen von 8 auf 2 Sekunden verkürzt – die Instanz bleibt bei großen Archiven bedienbar
- Funktion HPV_ArchiveMigrate ersetzt durch HPV_ArchiveTransfer; Eigenschaft „Zuordnung“ entfällt

### Build 11 (06.10.2026)

#### Behoben
- Archiv-Werkzeug: Werte genau auf der Grenze mit Rundungsrest aus dem Altarchiv (z. B. Ladestand 100,0000015 % durch Faktor 0,1) galten als „außerhalb der Grenzen“ – jetzt kleine Toleranz
- Ausreißer-Liste/CSV zeigen Werte mit mehr Nachkommastellen, der Grund nennt die Grenzen

### Build 10 (06.10.2026)

#### Geändert
- Archiv-Werkzeug: „Archiv prüfen“ ist jetzt die „Kontrolle nach der Übernahme“ ganz unten, erst sichtbar, wenn eine neue Variable ein Archiv hat, und bietet nur solche Variablen an

### Build 9 (06.10.2026)

#### Neu
- Archiv-Werkzeug: Ausreißer-Liste im Formular (echte Ausreißer zuerst, höchstens 1000) mit Zeit, Wert, Grund und Vergleichswert
- Komplette Ausreißer-Liste als CSV-Datei für Excel im Symcon-Ordner „user“ (im Browser unter /user/HuaweiPV_Ausreisser_<InstanzID>.csv)
- Ergebnis zeigt die Anzahl je Grund; das letzte Ergebnis steht beim Öffnen des Formulars wieder da

#### Geändert
- Tageszähler: Endstand des Vortags mit Zeitstempel kurz nach Mitternacht (bis 5 Minuten) wird als „Vortageswert nach Mitternacht“ erkannt und getrennt gezählt (weiterhin nicht übernommen)

#### Behoben
- Auswahl „Archiv prüfen von“ war leer („Diese Auswahl verfügt über keine wählbaren Optionen“)

### Build 8 (06.10.2026)

#### Behoben
- Klartext-Variablen ohne Aktion zeigten seit Build 7 die Zahl statt des Textes (z. B. bei der Akku-Betriebsart): Die Wertanzeige mit OPTIONS greift nur bei Boolean/String. Jetzt Wertanzeige mit Intervallen – je Wert ein Intervall mit festem Klartext. Kein roter Fehlertext, Klartext wie vor Build 7.

### Build 7 (06.10.2026)

#### Behoben
- Klartext-Variablen ohne Aktion (Wechselrichter-Status, Akku-Status, Akku-Betriebsart, Smartmeter-Status, Einstellungen bei gesperrter Steuerung) zeigten in der Instanz einen roten Fehlertext: Sie nutzen jetzt die Darstellung „Wertanzeige“ mit Klartext je Wert (wie BMWCarData) statt „Aufzählung“. Die Aufzählung bleibt nur für schaltbare Variablen.

### Build 6 (06.10.2026)

#### Neu
- Archiv-Werkzeug (Formular-Bereich „Archiv-Werkzeug“):
  - Übernahme alter Archivdaten in die neuen Variablen per Zuordnungsliste (alte Variable → neue Variable, Faktor, Vorzeichen umdrehen)
  - Kopiert nur (alte Daten bleiben), wandelt Integer/Float um, lässt Ausreißer aus (gleiche Regeln wie die Live-Prüfung), übernimmt nur Werte vor dem ältesten Wert der neuen Variable (kein Doppeln)
  - Schaltet die Archivierung der neuen Variable ein (Energiezähler mit Aggregation „Zähler“) und aggregiert neu
  - Vorschau ohne Änderungen mit Anzahl, Zeitraum, Ausreißern und Beispielen
  - Archiv einer Modul-Variable prüfen und gefundene Ausreißer löschen
  - Läuft im Hintergrund in Etappen (auch große Archive), übersteht Neustart, abbrechbar
- Funktionen HPV_ArchiveMigrate, HPV_ArchiveClean, HPV_ArchiveStatus, HPV_ArchiveCancel

#### Dokumentation
- README Abschnitt 11 (Archiv übernehmen) auf das Archiv-Werkzeug umgestellt

## Version 1.1

### Build 5 (06.10.2026)

#### Neu
- Vorzeichen wählbar, getrennt für Netz und Akku (Standard wie bisher = wie Huawei):
  - Netzleistung: „Einspeisung + / Bezug −“ oder „Einspeisung − / Bezug +“ (gilt auch für kW und Phasen)
  - Akku-Leistung: „Laden + / Entladen −“ oder „Laden − / Entladen +“
  - Beim Umstellen werden die aktuellen Werte sofort umgedreht und die Variablennamen angepasst (eigene Namen bleiben)
  - Hausverbrauch, Einspeisung/Bezug, Autarkie und Plausibilitätsprüfung rechnen intern unverändert mit dem Huawei-Wert
- Hinweis im Formular (Bereich „Steuerung“), wenn im Wechselrichter mehr Leistung eingestellt ist, als der Akku kann (z. B. Netzladeleistung 5500 W → wirksam 2500 W)

#### Dokumentation
- README: Vorzeichen-Optionen, Hinweis zur Archiv-Übernahme (Vorzeichen und Variablentyp der alten Variablen)

### Build 4 (05.10.2026)

#### Neu
- Themen-Ordner wie bei BMWCarData: Variablen werden in Dummy-Instanzen einsortiert – PV-Anlage, Akku, Netz & Smartmeter, Verbrauch & Bilanz, Wechselrichter, Geräteinfo, Diagnose (Option „Variablen in Themen-Ordner einsortieren“, Standard an)
- Automatische Sortierung nach Themen, innerhalb eines Themas die wichtigsten Werte oben, kW direkt unter W (Option „Variablen automatisch sortieren“, Standard an)

#### Geändert
- Schaltbare Variablen (Akku-Einstellungen, Zwangsladen, Einspeisebegrenzung & Ein/Aus) bleiben direkt unter der Instanz, weil Symcon Aktionen nur dort zulässt
- Beim Verschieben behalten Variablen ihre ID – Archiv, Verknüpfungen und Ereignisse bleiben erhalten; leere Ordner werden entfernt; eigene Namen bleiben erhalten

### Build 3 (05.10.2026)

#### Neu
- Steuerung (standardmäßig gesperrt, Freigabe per „Steuerung erlauben“):
  - Akku-Betriebsart, max. Lade-/Entladeleistung, Ladeende, Entladeende, Notstromreserve
  - Netzladen an/aus, Netzladen bis (SoC), Netzladeleistung (neu gelesen: 47088, 47242)
  - Einspeisebegrenzung: Unbegrenzt / Nulleinspeisung / Grenze in W / Grenze in % (neu gelesen: 47415–47418)
  - Zwangsladen/-entladen mit Leistung, Dauer (max. 24 h) und optionalem Ziel-Ladestand; Wächter beendet es nach Ablauf auch selbst, übersteht Neustart
  - Wechselrichter Ein/Aus (zusätzliche Freigabe nötig)
- Bedienung über die Variablen (Schieberegler/Auswahl/Schalter) und Skriptfunktionen HPV_Set…, HPV_ForceCharge/-Discharge/-Stop, HPV_SetInverter
- Jeder geschriebene Wert wird geprüft (Bereich, erlaubte Werte, Gerätegrenzen) und zurückgelesen; Fehler erscheinen als Meldung und unter „Diagnose anzeigen“
- Option „Leistungen zusätzlich in kW anzeigen“ (Standard aus): je eine kW-Variable direkt unter dem W-Wert
- Gerätedaten: Firmware/Software Wechselrichter, Software Akku, Gerätegrenzen Lade-/Entladeleistung des Akkus
- Variable „Wechselrichter eingeschaltet“ (aus dem Gerätestatus)

#### Geändert
- Variablen-Positionen neu verteilt (×10), damit kW-Werte direkt unter den W-Werten stehen
- Gerätedaten gelten als gelesen, sobald ein Teil gelesen wurde (fehlende Register werden täglich erneut versucht)

#### Dokumentation
- README: Abschnitt Steuerung, Funktionen, Variablen und Formular ergänzt

## Version 1.0

### Build 2 (05.10.2026)

#### Neu
- Option „Autarkie und Eigenverbrauch berechnen“ (Standard aus, benötigt Smartmeter): Autarkie heute und Eigenverbrauch heute in % (eine Nachkommastelle), dazu Hausverbrauch, Netzbezug und Einspeisung heute in kWh
- Tagesbilanz wird gesichert und läuft nach einem Neustart weiter

#### Geändert
- Temperatur, Prozent und Frequenz werden ohne Nachkommastelle angezeigt (gespeichert wird weiterhin der genaue Wert)

#### Dokumentation
- README: Abschnitt Autarkie/Eigenverbrauch, Variablenliste und Formular ergänzt

### Build 1 (04.10.2026)

#### Neu
- Modul „Huawei PV (SUN2000 / LUNA2000)“ als Kind-Instanz des Symcon ModBus Gateways
- Alle Abfragen streng nacheinander (eine Warteschlange), einstellbare Pause zwischen den Anfragen
- Wiederholung bei SLAVE_DEVICE_BUSY mit wachsender Wartezeit, begrenzte Wiederholung bei Timeout, Zeitbudget je Durchlauf
- Blöcke nur aus lückenlos aneinander grenzenden Registern (max. 64)
- Gestaffelte Abfrage: schnell (10 s), mittel (60 s), langsam (300 s), Gerätedaten täglich
- Plausibilitätsprüfung vor dem Schreiben: Huawei-Ungültig-Werte, feste Grenzen, Zähler (rückwärts/Sprung/Null), Tageszähler, Ladestand-Sprünge; auffällige Werte erst nach Bestätigung
- Erster Wert nach Neustart wird gegen den letzten Variablenwert geprüft
- Variablen: Leistungen, Hausverbrauch (berechnet), Netz, Akku, Energiezähler, Status im Klartext, Akku-Einstellungen (lesend)
- Optional (Standard aus): PV-Strings einzeln, Phasenwerte
- Diagnose-Variablen und Button „Diagnose anzeigen“
- Automatischer Neuaufbau der Verbindung (Client Socket 5 s schließen) nach mehreren fehlgeschlagenen Durchläufen
- Archivierung wird vom Modul nie eingeschaltet

#### Dokumentation
- README mit Einrichtung, empfohlenen Gateway-Einstellungen, Variablenliste, Plausibilitätsregeln und Anleitung zur Archiv-Übernahme von einzelnen ModBus-Adressen
