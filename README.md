# HuaweiPV

Symcon-Modul für Huawei-PV-Anlagen: Wechselrichter **SUN2000** (z. B. SUN2000-5KTL-M1), Akku **LUNA2000** und Smartmeter **DTSU666-H** über **Modbus TCP** (Smart Dongle oder direkte Verbindung).

Aktuelle Version: 1.3 (Build 17)

Autor: Peter Chrisben · Lizenz: [CC BY-NC-SA 4.0](LICENSE)

---

## Inhalt

1. [Warum dieses Modul?](#1-warum-dieses-modul)
2. [Funktionen](#2-funktionen)
3. [Voraussetzungen](#3-voraussetzungen)
4. [Installation](#4-installation)
5. [Einrichtung](#5-einrichtung)
6. [Variablen](#6-variablen)
7. [Autarkie und Eigenverbrauch](#7-autarkie-und-eigenverbrauch)
8. [Steuerung](#8-steuerung)
9. [Plausibilitätsprüfung](#9-plausibilitätsprüfung)
10. [Kommunikation, BUSY und Timeouts](#10-kommunikation-busy-und-timeouts)
11. [Umstieg von einzelnen ModBus-Adressen (Archiv übernehmen)](#11-umstieg-von-einzelnen-modbus-adressen-archiv-übernehmen)
12. [Öffentliche Funktionen](#12-öffentliche-funktionen)
13. [Fehlersuche](#13-fehlersuche)
14. [Geplant](#14-geplant)
15. [Quellen](#15-quellen)

---

## 1. Warum dieses Modul?

Huawei-Wechselrichter sind bei Modbus empfindlich. Kommen mehrere Anfragen gleichzeitig, antwortet der Wechselrichter mit `SLAVE_DEVICE_BUSY`, er antwortet zu spät oder es kommen falsche Werte zurück. Fragt man mit einzelnen ModBus-Adress-Instanzen und mehreren Datenblöcken ab, passiert genau das. Typische Meldungen sind dann:

```
HUAWEI SUN (ModBus Gateway) (DataBlocks5): SLAVE_DEVICE_BUSY
HUAWEI SUN (ModBus Gateway) (DataBlocks3): Zeitüberschreitung beim Warten auf Antwort
FlowHandler: Kann Daten nicht zur Instanz #… weiterleiten: TransactionID stimmt nicht überein
```

Die letzte Meldung entsteht, wenn eine verspätete Antwort bei der falschen Anfrage ankommt. So landen falsche Werte im Archiv.

Dieses Modul löst das so:

- **Eine Instanz und eine Warteschlange:** Alle Anfragen laufen streng nacheinander, mit einstellbarer Pause dazwischen.
- **Wiederholung:** Bei BUSY wird mit wachsender Wartezeit erneut gefragt (0,5 s, 1 s, 2 s …).
- **Prüfung vor dem Schreiben:** Nur plausible Werte kommen in die Variablen und damit ins Archiv.
- **Selbstheilung:** Antwortet der Wechselrichter dauerhaft nicht, baut das Modul die Verbindung selbst neu auf.

## 2. Funktionen

**Anzeige:**

- PV-Leistung, Wechselrichter-Leistung und Hausverbrauch (berechnet)
- Netzleistung mit Einspeisung und Netzbezug getrennt
- Akku: Leistung, Ladestand, Status, Temperatur, Betriebsart, Lade- und Entladezähler
- Energiezähler: PV-Ertrag heute und gesamt, Einspeisung und Netzbezug gesamt
- Wechselrichter: Status im Klartext, Fehlercode, Temperatur, Wirkungsgrad, Netzfrequenz
- Einstellungen: max. Lade- und Entladeleistung, Lade- und Entladeende, Betriebsart, Netzladen, Notstromreserve, Einspeisebegrenzung
- Gerätedaten: Modell, Seriennummer, Firmware/Software von Wechselrichter und Akku

**Steuerung (standardmäßig gesperrt, siehe [8](#8-steuerung)):**

- alle Einstellungen oben, Zwangsladen und -entladen, Wechselrichter Ein/Aus

**Optional (Häkchen im Formular, standardmäßig aus):**

- PV-Strings einzeln (Spannung, Strom, Leistung je String)
- Phasenwerte: Spannung, Strom und Leistung je Phase L1–L3 vom Smartmeter (auch nachts), Netzfrequenz vom Smartmeter, Spannung L1–L3 vom Wechselrichter
- Autarkie und Eigenverbrauch heute in % (berechnet, siehe [7](#7-autarkie-und-eigenverbrauch))
- Leistungen zusätzlich in kW (je eine Variable direkt unter dem W-Wert)

**Abfrage:**

- Gestaffelt in drei Gruppen: schnell (10 s), mittel (60 s) und langsam (5 min). Gerätedaten werden täglich gelesen.
- Bereiche, die ausgeschaltet sind, werden auch nicht abgefragt.

**Diagnose:**

- Variablen für Verbindung, Fehler heute, verworfene Werte und letzten Fehler
- Button „Diagnose anzeigen“

**Archiv:**

- Das Modul schaltet die Archivierung **nie** selbst ein. Das entscheidest du.
- **Archiv-Werkzeug:** übernimmt alte Archivdaten, z. B. von einzelnen ModBus-Adressen, mit Typ- und Einheiten-Umrechnung und lässt dabei Ausreißer aus. Bestehende Archive kann es prüfen und bereinigen (siehe [11](#11-umstieg-von-einzelnen-modbus-adressen-archiv-übernehmen)).

## 3. Voraussetzungen

- **Symcon 8.0 oder neuer**
- **Modbus TCP am Wechselrichter freigeschaltet:** in der FusionSolar-App bzw. der SUN2000-App unter *Einstellungen → Kommunikationskonfiguration → Dongle-Parametereinstellungen → Modbus TCP* auf „Aktivieren (uneingeschränkt)“ stellen.
- **Verbindung:**
  - Mit Smart Dongle: IP des Dongles, Port 502, Geräte-ID 1
  - Ohne Dongle (direkt am Wechselrichter): Port 6607 bzw. 502 je nach Firmware, Geräte-ID 0 oder 1
- **Nur ein Modbus-Client am Wechselrichter.** Liest zusätzlich etwas anderes mit (Loxone, evcc, eine Wallbox …), kommt es trotz allem zu BUSY.

## 4. Installation

1. In der Symcon-Konsole **Module Control** öffnen.
2. Über **+** die URL `https://github.com/peterchrisben/HuaweiPV` hinzufügen.

## 5. Einrichtung

1. **Instanz anlegen:** *Instanz hinzufügen → Huawei PV (SUN2000 / LUNA2000)*.
2. **Gateway wählen:** Als übergeordnete Instanz das **ModBus Gateway** wählen. Ein vorhandenes Gateway kann weiterverwendet werden.
   - Modus: Modbus TCP
   - Geräte-ID: 1
   - Client Socket: IP des Dongles, Port 502
3. **Empfohlene Einstellungen im Gateway** (Expertenoptionen):

   | Einstellung | Empfehlung | Warum |
   |---|---|---|
   | Wartezeit bis zum Timeout | **10 Sekunden** | Der Smart Dongle antwortet teils langsam, besonders bei Akku-Registern. Bei 5 s kommt die Antwort manchmal erst nach dem Timeout. Das führt zu „TransactionID stimmt nicht überein“. |
   | Abfrageverzögerung | 100–200 ms | zusätzliche Pause des Gateways |
   | Datenblöcke | **keine**, sobald die alten Adress-Instanzen weg sind | Das Modul fragt selbst ab. Datenblöcke im Gateway erzeugen zusätzliche, parallele Anfragen. |

4. **Instanz einrichten:** Im Formular der Instanz einstellen, was vorhanden ist (Akku, Smartmeter) und was angezeigt werden soll. Die Standardwerte passen für SUN2000 + LUNA2000 + DTSU666-H.
5. **Testen:** Auf **„Alle Werte jetzt lesen“** klicken. Die Werte sollten erscheinen und der Status grün sein.

### Formular

| Bereich | Einstellung | Standard |
|---|---|---|
| Abfrage | Abfrage aktiv | an |
| | Leistung, Akku, Netz alle … | 10 s |
| | Energiezähler und Zustand alle … | 60 s |
| | Akku-Einstellungen alle … | 300 s |
| Anlage und Anzeige | LUNA2000-Akku vorhanden | an |
| | Smartmeter vorhanden | an |
| | Einstellungen anzeigen (Akku, Einspeisebegrenzung) | an |
| | PV-Strings einzeln anzeigen | **aus** |
| | Phasenwerte anzeigen | **aus** |
| | Autarkie und Eigenverbrauch berechnen | **aus** |
| | Leistungen zusätzlich in kW anzeigen | **aus** |
| | Vorzeichen Netzleistung | Einspeisung + / Bezug − |
| | Vorzeichen Akku-Leistung | Laden + / Entladen − |
| | Variablen in Themen-Ordner einsortieren | an |
| | Variablen automatisch nach Themen sortieren | an |
| | Diagnose-Variablen anzeigen | an |
| Steuerung | Steuerung erlauben | **aus** |
| | Wechselrichter Ein/Aus erlauben | **aus** |
| Plausibilitätsprüfung | Höchste plausible Leistung | 15000 W |
| | Auffällige Werte erst übernehmen nach … | 3 gleichen Abfragen |
| Kommunikation (Experten) | Pause zwischen zwei Anfragen | 200 ms |
| | Wiederholungen bei BUSY | 3 |
| | Verbindung automatisch neu aufbauen, wenn der Smart Dongle „hängt“ | **aus** (siehe [10](#10-kommunikation-busy-und-timeouts)) |
| | Neu aufbauen nach … | 5 fehlgeschlagenen Durchläufen |

## 6. Variablen

Leistungen werden in W angegeben, Energien in kWh. Alle Messwerte sind Float-Variablen.

**Themen-Ordner und Sortierung:** Die Variablen landen in Themen-Ordnern. Das sind Dummy-Instanzen unter der Huawei-Instanz. Die Reihenfolge ist:

1. PV-Anlage (Ordner)
2. Akku (Ordner)
3. Netz & Smartmeter (Ordner)
4. Verbrauch & Bilanz (Ordner)
5. Wechselrichter (Ordner)
6. Geräteinfo (Ordner)
7. Diagnose (Ordner)
8. Akku-Einstellungen
9. Zwangsladen
10. Einspeisebegrenzung & Ein/Aus

Die Themen 8–10 sind schaltbar. Sie stehen ohne Ordner direkt unter der Instanz, weil Symcon Aktionen (Schieberegler, Auswahl, Schalter) nur dort zulässt – deshalb ganz unten, nach allen Ordnern.

**Innerhalb eines Themas** stehen die wichtigsten Werte oben, und die kW-Variante steht direkt unter dem W-Wert.

**Was erhalten bleibt:**

- Beim Einsortieren oder Zurückschalten behalten die Variablen ihre **ID**. Archiv, Verknüpfungen und Ereignisse bleiben also erhalten.
- Leere Ordner verschwinden.
- Ordner und Variablen kannst du umbenennen, eigene Namen bleiben erhalten.
- **Eigene Positionen bleiben erhalten.** Das Modul setzt Positionen nur beim Anlegen einer Variable und einmal, wenn „Variablen automatisch nach Themen sortieren“ eingeschaltet wird (oder sich der Aufbau in einer neuen Version ändert). Wer neu sortieren lassen möchte: Häkchen aus, übernehmen, Häkchen an, übernehmen.
- **Eigene Darstellungen bleiben erhalten.** Das Modul setzt nur seine eigene Darstellung (über die Symcon-Funktion für Statusvariablen), nie die benutzerdefinierte. Eine Darstellung, die du an einer Variable selbst einstellst, überschreibt das Modul also nicht. Es arbeitet nur mit Darstellungen, nicht mit Variablenprofilen.

**Selbst sortieren:** Ohne „Themen-Ordner“ stehen alle Variablen in derselben Reihenfolge direkt unter der Instanz.

Temperatur, Prozent und Frequenz werden ohne Nachkommastelle angezeigt. Autarkie und Eigenverbrauch haben eine Nachkommastelle. Gespeichert wird immer der genaue Wert, die Anzeige rundet nur.

| Variable | Ident | Register | Einheit | Abfrage | Vorhanden wenn |
|---|---|---|---|---|---|
| PV-Leistung | `PVPower` | 32064 | W | schnell | immer |
| Wechselrichter AC-Leistung | `ActivePower` | 32080 | W | schnell | immer |
| Hausverbrauch | `HouseConsumption` | berechnet | W | – | Smartmeter |
| Netzleistung (+ Einspeisung / − Bezug) | `GridPower` | 37113 | W | schnell | Smartmeter |
| Einspeisung | `GridFeedIn` | berechnet | W | – | Smartmeter |
| Netzbezug | `GridImport` | berechnet | W | – | Smartmeter |
| Akku-Leistung (+ Laden / − Entladen) | `BatteryPower` | 37001 | W | schnell | Akku |
| Akku-Ladestand | `BatterySOC` | 37004 | % | schnell | Akku |
| Akku-Status | `BatteryStatus` | 37000 | Klartext | schnell | Akku |
| PV-Ertrag heute | `DailyYield` | 32114 | kWh | mittel | immer |
| PV-Ertrag gesamt | `TotalYield` | 32106 | kWh | mittel | immer |
| Einspeisung gesamt | `GridExportTotal` | 37119 | kWh | mittel | Smartmeter |
| Netzbezug gesamt | `GridImportTotal` | 37121 | kWh | mittel | Smartmeter |
| Akku geladen heute | `BatteryChargeToday` | 37015 | kWh | mittel | Akku |
| Akku entladen heute | `BatteryDischargeToday` | 37017 | kWh | mittel | Akku |
| Akku geladen gesamt | `BatteryChargeTotal` | 37066 | kWh | mittel | Akku |
| Akku entladen gesamt | `BatteryDischargeTotal` | 37068 | kWh | mittel | Akku |
| Autarkie heute | `SelfSufficiency` | berechnet | % | – | Autarkie + Smartmeter |
| Eigenverbrauch heute | `SelfConsumptionRate` | berechnet | % | – | Autarkie + Smartmeter |
| Hausverbrauch heute | `HouseEnergyToday` | berechnet | kWh | – | Autarkie + Smartmeter |
| Netzbezug heute | `GridImportToday` | berechnet | kWh | – | Autarkie + Smartmeter |
| Einspeisung heute | `GridExportToday` | berechnet | kWh | – | Autarkie + Smartmeter |
| Wechselrichter-Status | `DeviceStatus` | 32089 | Klartext | mittel | immer |
| Fehlercode Wechselrichter | `FaultCode` | 32090 | | mittel | immer |
| Wechselrichter-Temperatur | `InverterTemp` | 32087 | °C | mittel | immer |
| Wirkungsgrad | `Efficiency` | 32086 | % | mittel | immer |
| Netzfrequenz | `GridFrequency` | 32085 | Hz | mittel | immer |
| Akku-Betriebsart (aktuell) | `BatteryWorkingMode` | 37006 | Klartext | mittel | Akku |
| Akku-Temperatur | `BatteryTemp` | 37022 | °C | mittel | Akku |
| Smartmeter-Status | `MeterStatus` | 37100 | Klartext | mittel | Smartmeter |
| Akku max. Ladeleistung | `MaxChargePower` | 47075 | W | langsam | Akku-Einstellungen |
| Akku max. Entladeleistung | `MaxDischargePower` | 47077 | W | langsam | Akku-Einstellungen |
| Akku Ladeende bei | `ChargeCutoff` | 47081 | % | langsam | Akku-Einstellungen |
| Akku Entladeende bei | `DischargeCutoff` | 47082 | % | langsam | Akku-Einstellungen |
| Akku-Betriebsart (Einstellung) | `WorkingModeSetting` | 47086 | Klartext | langsam | Akku-Einstellungen |
| Laden aus dem Netz | `GridCharge` | 47087 | an/aus | langsam | Akku-Einstellungen |
| Akku Notstromreserve | `BackupSOC` | 47102 | % | langsam | Akku-Einstellungen |
| Netzladen bis | `GridChargeCutoff` | 47088 | % | langsam | Akku-Einstellungen |
| Netzladeleistung | `GridChargePower` | 47242 | W | langsam | Akku-Einstellungen |
| Einspeisebegrenzung | `PowerControlMode` | 47415 | Klartext | langsam | Einstellungen |
| Einspeisung max. (W) | `MaxFeedInPower` | 47416 | W | langsam | Einstellungen |
| Einspeisung max. (%) | `MaxFeedInPercent` | 47418 | % | langsam | Einstellungen |
| Wechselrichter eingeschaltet | `InverterOn` | aus 32089 | an/aus | mittel | immer |
| Zwangsladen/-entladen, Leistung, Dauer, bis Ladestand, endet spätestens | `ForceMode`, `ForcePower`, `ForceDuration`, `ForceTargetSOC`, `ForceEndTime` | – | – | – | Akku + Steuerung erlaubt |
| PV1 / PV2 Spannung | `PV1Voltage`, `PV2Voltage` | 32016, 32018 | V | mittel | PV-Strings |
| PV1 / PV2 Strom | `PV1Current`, `PV2Current` | 32017, 32019 | A | mittel | PV-Strings |
| PV1 / PV2 Leistung | `PV1Power`, `PV2Power` | berechnet | W | – | PV-Strings |
| Spannung L1–L3 (Wechselrichter) | `PhaseVoltageA/B/C` | 32069–32071 | V | mittel | Phasenwerte |
| Netzleistung L1–L3 | `GridPowerA/B/C` | 37132–37137 | W | mittel | Phasenwerte + Smartmeter |
| Spannung L1–L3 (Smartmeter) | `GridVoltageA/B/C` | 37101–37106 | V | mittel | Phasenwerte + Smartmeter |
| Strom L1–L3 (Smartmeter, Vorzeichen wie Netzleistung) | `GridCurrentA/B/C` | 37107–37112 | A | mittel | Phasenwerte + Smartmeter |
| Netzfrequenz (Smartmeter) | `MeterFrequency` | 37118 | Hz | mittel | Phasenwerte + Smartmeter |
| Modell, Seriennummer | `Model`, `SerialNumber` | 30000, 30015 | Text | täglich | immer |
| Nennleistung Wechselrichter | `RatedPower` | 30073 | W | täglich | immer |
| Akku-Kapazität | `BatteryCapacity` | 37758 | kWh | täglich | Akku |
| Akku Lade-/Entladeleistung max. (Gerät) | `BatteryMaxChargeLimit`, `BatteryMaxDischargeLimit` | 37046, 37048 | W | täglich | Akku |
| Firmware / Software Wechselrichter | `FirmwareVersion`, `SoftwareVersion` | 30035, 30050 | Text | täglich | immer |
| Software Akku | `BatterySoftwareVersion` | 37814 | Text | täglich | Akku |
| … (kW) | `<Ident>KW`, z. B. `PVPowerKW` | – | kW | – | Option kW |
| Verbindung OK, letzte erfolgreiche Abfrage, Fehler heute, verworfene Werte heute, letzter Fehler | `Online`, `LastSuccess`, `ErrorsToday`, `RejectedToday`, `LastError` | – | – | – | Diagnose |

**Vorzeichen** (im Formular unter „Anlage und Anzeige“ getrennt wählbar):

| Auswahl | Netzleistung | Akku-Leistung |
|---|---|---|
| Standard (wie Huawei) | Einspeisung **+**, Bezug **−** | Laden **+**, Entladen **−** |
| Umgekehrt | Einspeisung **−**, Bezug **+** | Laden **−**, Entladen **+** |

- Die Auswahl gilt auch für die kW-Variante und die Netzleistung je Phase.
- Beim Umstellen dreht das Modul die aktuellen Werte sofort um und passt den Namen an, z. B. „Netzleistung (+ Bezug / − Einspeisung)“. Eigene Namen bleiben erhalten.
- Bereits archivierte Werte behalten ihr altes Vorzeichen. Leg das Vorzeichen deshalb fest, **bevor** du archivierst oder alte Archivdaten überträgst.
- Hausverbrauch, Einspeisung/Bezug und Autarkie rechnen immer richtig, egal welches Vorzeichen eingestellt ist. Der **Hausverbrauch** ist die Wechselrichter-AC-Leistung minus Einspeisung plus Bezug.

## 7. Autarkie und Eigenverbrauch

Huawei liefert beides nicht als Register. Die FusionSolar-App rechnet es selbst aus. Das Modul berechnet die Werte für den laufenden Tag, wenn die Option „Autarkie und Eigenverbrauch berechnen“ eingeschaltet ist. Dafür ist ein Smartmeter nötig.

| Wert | Berechnung |
|---|---|
| Hausverbrauch heute | Hausverbrauch (W) über den Tag aufsummiert |
| PV-Erzeugung heute (intern) | PV-Leistung (W) über den Tag aufsummiert, inkl. der Energie, die in den Akku geht |
| Netzbezug heute | Zähler „Netzbezug gesamt“ minus Stand um Mitternacht |
| Einspeisung heute | Zähler „Einspeisung gesamt“ minus Stand um Mitternacht |
| **Autarkie** | (Hausverbrauch − Netzbezug) ÷ Hausverbrauch: wie viel vom Verbrauch selbst gedeckt wurde |
| **Eigenverbrauch** | (PV-Erzeugung − Einspeisung) ÷ PV-Erzeugung: wie viel vom PV-Strom selbst genutzt wurde, direkt oder über den Akku |

**Hinweise:**

- Es werden nur geprüfte Werte verwendet. Fällt die Abfrage länger als 2 Minuten aus, wird die Lücke nicht hochgerechnet. Der Tageswert fällt dann etwas zu niedrig aus.
- Am ersten Tag zählt das Modul erst ab dem Einschalten.
- Nach Mitternacht bleibt der Vortageswert stehen, bis genug Energie zusammengekommen ist (0,05 kWh). Bei der Autarkie geht das schnell, beim Eigenverbrauch erst mit der Morgensonne. So ist der letzte Wert eines Tages im Archiv immer der Tageswert.
- Die PV-Erzeugung wird auf der DC-Seite gemessen. Die Verluste im Wechselrichter (2–3 %) zählen deshalb zum Eigenverbrauch. Das ist für eine Übersicht genau genug.
- Die Tagesbilanz wird gesichert und läuft nach einem Neustart von Symcon weiter.

## 8. Steuerung

Ohne Freigabe liest das Modul nur. Mit **„Steuerung erlauben“** im Formular bekommen die Einstellungs-Variablen eine Aktion. Je nach Art ist das ein Schieberegler, eine Auswahl oder ein Schalter. Zusätzlich gibt es Skriptfunktionen (siehe [12](#12-öffentliche-funktionen)).

**Jeder Schreibbefehl wird geprüft:**

1. **Vorab:** Ist der Wert erlaubt? Bereiche wie in der Huawei-App, Leistungen höchstens bis zur Gerätegrenze des Akkus bzw. zur Nennleistung des Wechselrichters.
2. **Schreiben:** Funktion 06 für ein Register, 16 für 32-Bit-Werte. Bei BUSY wird wiederholt.
3. **Zurücklesen:** Hat der Wechselrichter den Wert nicht übernommen, gibt es eine Fehlermeldung, und die Variable zeigt den echten Wert.

| Einstellung | Variable | Bereich |
|---|---|---|
| Akku-Betriebsart | `WorkingModeSetting` | Adaptiv, Festes Laden/Entladen, Max. Eigenverbrauch, Volleinspeisung, Zeitsteuerung (LUNA2000) |
| Max. Lade-/Entladeleistung | `MaxChargePower`, `MaxDischargePower` | 0 … Gerätegrenze, 100-W-Schritte |
| Ladeende | `ChargeCutoff` | 90 … 100 % |
| Entladeende | `DischargeCutoff` | 0 … 20 % |
| Notstromreserve | `BackupSOC` | 0 … 100 % |
| Laden aus dem Netz | `GridCharge` | an/aus |
| Netzladen bis | `GridChargeCutoff` | 20 … 100 % |
| Netzladeleistung | `GridChargePower` | 0 … Gerätegrenze |
| Einspeisebegrenzung | `PowerControlMode` | Unbegrenzt, Nulleinspeisung, Begrenzt auf Watt-Wert, Begrenzt auf Prozent |
| Einspeisung max. | `MaxFeedInPower` / `MaxFeedInPercent` | 0 … Nennleistung W / 0 … 100 % |

**Einspeisebegrenzung:** Erst den Grenzwert setzen (`MaxFeedInPower` bzw. `MaxFeedInPercent`), dann die Betriebsart „Begrenzt auf …“ wählen.

**Zwangsladen und -entladen:**

1. Leistung, Dauer und optional „bis Ladestand“ einstellen. Diese Werte sind nur Vorgaben und werden noch nicht geschrieben.
2. `ForceMode` auf „Zwangsladen“ oder „Zwangsentladen“ stellen.
3. Das Modul schreibt in der Reihenfolge der Huawei-Integration: Leistung → Dauer → (Ziel-Ladestand) → Modus → Start.
4. **Ende:** Der Wechselrichter beendet den Vorgang nach Ablauf der Dauer bzw. beim Ziel-Ladestand selbst. Zur Sicherheit stoppt das Modul eine Minute nach Ablauf zusätzlich, auch nach einem Neustart von Symcon. Das ist wichtig, weil die Anlage nicht vor Ort ist.
5. **Vorzeitig beenden:** `ForceMode` auf „Aus“ stellen bzw. `HPV_ForceStop()` aufrufen.

**Wechselrichter Ein/Aus** braucht zusätzlich die Freigabe „Wechselrichter Ein/Aus erlauben“. Ausgeschaltet erzeugt die Anlage keinen Strom. Das Umschalten dauert bis zu 5 Minuten. So lange zeigt `InverterOn` die Vorgabe.

**Hinweise:**

- Die Einstellungen werden alle 5 Minuten neu gelesen. Was in der FusionSolar-App geändert wird, erscheint also auch in Symcon.
- Schreiben ist über den Smart Dongle ohne Anmeldung möglich. Bei direkter Verbindung zum Wechselrichter kann Huawei eine Installateur-Anmeldung verlangen. Dann meldet der Wechselrichter „ILLEGAL_DATA_ADDRESS“.
- Nimmt man die Freigabe wieder zurück, verschwinden die Aktionen und die Zwangsladen-Variablen.

## 9. Plausibilitätsprüfung

Bevor ein Wert in eine Variable geschrieben wird, prüft ihn das Modul:

| Prüfung | Beispiel | Ergebnis |
|---|---|---|
| Huawei-Kennung „ungültig“ (0xFFFF, 0x7FFF, 0xFFFFFFFF, 0x7FFFFFFF) | Ladestand 6553,5 % | wird immer verworfen |
| … bei Akku-Werten, während der Akku ruht (Ruhemodus, Standby, Offline) | Akku-Leistung nachts bei 5 % | kein Fehler: Akku-Leistung = 0 W, andere Akku-Werte behalten den letzten Wert |
| Feste Grenzen | Ladestand 101 %, Leistung über „höchste plausible Leistung“ | wird immer verworfen |
| Zähler läuft rückwärts oder fällt auf 0 | Gesamtertrag 12345 → 0 kWh | auffällig |
| Zähler springt schneller, als die Anlage erzeugen kann | +500 kWh in einer Minute | auffällig |
| Tageszähler nach Mitternacht zu hoch | 25 kWh um 00:01 Uhr | auffällig |
| Ladestand springt um mehr als 10 % | 79 → 0 % | auffällig |

**Auffällige Werte** werden erst übernommen, wenn sie bei den nächsten Abfragen bestätigt werden. Standard sind 3 gleiche Abfragen. Ein einzelner Ausreißer kommt so nie ins Archiv. Eine echte Änderung, z. B. ein getauschter Zähler, wird nach kurzer Zeit trotzdem übernommen.

Bei einem verworfenen Wert bleibt der alte Wert stehen. Der Grund steht im Debug-Fenster und unter „Diagnose anzeigen“.

Nach einem Neustart von Symcon vergleicht das Modul den ersten Wert mit dem zuletzt gespeicherten Variablenwert. So wird auch der erste Wert geprüft.

## 10. Kommunikation, BUSY und Timeouts

**Ablauf eines Durchlaufs:**

1. Alle fälligen Werte werden zu Blöcken zusammengefasst. Ein Block enthält nur lückenlos aneinander grenzende Register derselben Option, höchstens 64. Kennt ein Gerät ein optionales Register nicht, fällt so nur dieser Block aus.
2. Die Blöcke werden nacheinander gelesen, mit Pause dazwischen.
3. **BUSY:** Bis zu 3 Wiederholungen nach 0,5 s, 1 s und 2 s.
4. **Timeout:** Höchstens eine Wiederholung, weil jeder Timeout die Wartezeit des Gateways kostet. Antworten zwei Blöcke hintereinander nicht, wird der Durchlauf abgebrochen.
5. Ein Durchlauf startet nach 20 s keine neuen Blöcke mehr. Ein neuer Durchlauf beginnt erst, wenn der vorige fertig ist.

**Ohne Antwort:**

- Nach 3 Durchläufen ohne Antwort wechselt der Status auf „Keine Antwort vom Wechselrichter“.
- Die Variable „Verbindung OK“ steht auf Nein. **Tipp:** Lege darauf eine Benachrichtigung, dann merkst du einen Ausfall sofort und nicht erst am nächsten Tag.

**Verbindungsabbruch und „hängender“ Smart Dongle:**

- **Echter Verbindungsabbruch** (Dongle startet neu, WLAN weg): Der Client Socket von Symcon merkt das und verbindet sich selbst neu. Das Modul fragt einfach weiter ab, sobald die Verbindung wieder steht. Dafür ist nichts einzustellen.
- **Dongle „hängt“:** Beim Huawei Smart Dongle kommt es vor, dass die TCP-Verbindung für Symcon offen bleibt, aber keine Antworten mehr kommen. Symcon merkt das nicht, der Client Socket bleibt „verbunden“. Das Modul sieht es an mehreren Durchläufen ohne Antwort hintereinander.
- **Option „Verbindung automatisch neu aufbauen, wenn der Smart Dongle hängt“** (Kommunikation (Experten), Standard **aus**): Nach der eingestellten Zahl fehlgeschlagener Durchläufe (Standard 5) schließt das Modul die Schnittstelle (Client Socket) für 5 s und öffnet sie wieder – höchstens alle 10 Minuten, mit Eintrag in den Meldungen. Dabei ändert das Modul die Einstellung „Offen“ der Schnittstelle, die zur eigenen Verbindung (Gateway → Client Socket) gehört. Deshalb ist die Option nur auf ausdrücklichen Wunsch aktiv.
- Ohne die Option bleibt die Instanz bei einem hängenden Dongle auf „Keine Antwort vom Wechselrichter“, bis sich der Dongle erholt oder die Schnittstelle von Hand aus- und eingeschaltet wird.

## 11. Umstieg von einzelnen ModBus-Adressen (Archiv übernehmen)

Wer bisher einzelne „ModBus Adresse“-Instanzen hatte, kann deren Archivdaten mit dem **Archiv-Werkzeug** des Moduls in die neuen Variablen übernehmen. Das Werkzeug **kopiert**, die alten Daten bleiben unverändert. Gegenüber Symcons *Daten transferieren* hat es drei Vorteile:

- **Typen umwandeln:** Integer-Variablen (z. B. eine ModBus Adresse mit INT32 ohne Faktor) werden in Float umgewandelt. „Daten transferieren“ verlangt dagegen den gleichen Typ.
- **Umrechnung und Vorzeichen:** Eine Auswahl rechnet Einheiten um (gleiche Einheit, W → kW, Wh → kWh, kW → W, Rohwert ÷ 10/100/1000 oder eigener Faktor). Bei Bedarf wird das Vorzeichen umgedreht.
- **Ausreißer:** Falsche Werte werden ausgelassen. Es gelten dieselben Regeln wie bei der Live-Prüfung (siehe [9](#9-plausibilitätsprüfung)): Werte außerhalb der Grenzen, Zähler, die springen, rückwärts laufen oder auf 0 fallen, und Ladestand-Sprünge.
- **Vortageswerte nach Mitternacht:** Steht bei einem Tageszähler in den ersten 5 Minuten nach 0 Uhr noch der Endstand des Vortags (z. B. weil ein altes Skript ihn verspätet geschrieben hat), wird dieser Wert ausgelassen und als „Vortageswert nach Mitternacht“ eigens gezählt. Das ist unbedenklich: Der Endstand von kurz vor Mitternacht bleibt erhalten.

**Ablauf:**

1. **Testphase:** Neue Instanz anlegen und testen. Die neuen Variablen **nicht** archivieren, die alten Instanzen laufen so lange weiter.
2. **Vorzeichen festlegen:** Unter „Anlage und Anzeige“ das gewünschte Vorzeichen wählen (siehe [6](#6-variablen)).
3. **Sicherung:** Eine Symcon-Sicherung machen.
4. **Variable wählen:** Bei den Aktionen unter **„Archiv-Werkzeug“** – immer eine Variable nach der anderen:
   - **Alte Variable** (Quelle)
   - **Neue Variable** (Ziel). Dahinter stehen Einheit und „Archiv vorhanden“, wenn sie schon übernommen wurde.
   - **Umrechnung alt → neu**: „gleiche Einheit“, wenn die alte Variable schon dieselbe Einheit hat; sonst z. B. „W → kW · Wh → kWh“ oder „Rohwert ÷ 100“. Bei „eigener Faktor …“ erscheint ein Zahlenfeld.
   - **Vorzeichen umdrehen**, nur wenn die alte Variable andersherum gespeichert hat
5. **Vorschau:** Auf **„Vorschau“** klicken. Das Modul liest alle alten Werte und zeigt Anzahl, Zeitraum, gültige Werte, ein Beispiel „alt → neu“ mit Einheit und die Ausreißer nach Grund. Sind auffällig viele Werte außerhalb der Grenzen, warnt die Vorschau („Umrechnung / Faktor prüfen!“). Geändert wird dabei nichts. Die Ausreißer stehen in der Liste darunter (echte Ausreißer zuerst, höchstens 1000), die Anzahl je Grund im Ergebnis. Das Modul legt dabei keine Dateien an. Bei großen Archiven läuft das im Hintergrund in kurzen Etappen weiter (die Instanz bleibt bedienbar), Stand über **„Fortschritt / Ergebnis anzeigen“**.
6. **Übernahme starten:** Wenn die Vorschau passt, klicken. Danach:
   - Die Archivierung der neuen Variable wird eingeschaltet, bei Energiezählern mit der Aggregation „Zähler“.
   - Die gültigen Werte werden kopiert und die Variable wird neu aggregiert.
   - Übernommen werden nur Werte, die **älter** als der älteste Wert der neuen Variable sind. Ein zweiter Lauf erzeugt also keine doppelten Werte.
   - Die Felder werden zurückgesetzt – dann die nächste Variable wählen.
7. **Alte Instanzen deaktivieren** und die Daten im Archiv-Graphen kontrollieren. Die alten Variablen kannst du später löschen, wenn alles passt.
8. **Aufräumen:** Datenblöcke im Gateway löschen und die Abfrageverzögerung prüfen (siehe [5](#5-einrichtung)).

**Kontrolle nach der Übernahme:** Ganz unten im Archiv-Werkzeug. Erscheint erst, wenn eine neue Variable ein Archiv hat. Neue Variable wählen → **„Prüfen (Vorschau)“** – nach einer sauberen Übernahme sollten 0 Ausreißer gefunden werden. **„Ausreißer löschen“** entfernt gefundene Werte endgültig und aggregiert neu; vorher die Vorschau ansehen und eine Sicherung machen.

**Hinweise:**

- Der Auftrag läuft im Hintergrund weiter, auch wenn das Formular geschlossen wird oder Symcon neu startet. Mit **„Abbrechen“** lässt er sich stoppen. Das Ergebnis steht auch in den Meldungen.
- Erkannt werden nur Ausreißer, die sich verraten: Werte außerhalb der Grenzen, Sprünge und Zähler, die rückwärts laufen. Ein falscher, aber plausibler Leistungswert, z. B. 800 W statt 600 W, ist nicht von einem echten zu unterscheiden.
- Ein dauerhaft anderer Verlauf, z. B. nach einem Zählertausch, wird als echt erkannt und bleibt erhalten.

**Zuordnung der bisherigen Adressen** (Beispiel-Installation):

| bisherige Variable | Register | neue Variable |
|---|---|---|
| PV PRODUKTION AKTUELL | 32064 | `PVPower` |
| WR Gesamtleistung | 32080 | `ActivePower` |
| HUAWEI SMARTMETER NETZLEISTUNG | 37113 | `GridPower` |
| LUNA BATTERIE LADE-/ENTLADELEISTUNG | 37001 | `BatteryPower` |
| LUNA BATTERIE SOC | 37004 | `BatterySOC` |
| LUNA STATUS | 37000 | `BatteryStatus` |
| LUNA BATTERIE MODUS | 37006 | `BatteryWorkingMode` |
| PV PRODUKTION TAG | 32114 | `DailyYield` |
| HUAWEI SMARTMETER ZÄHLER GESAMTENERGIEERTRAG | 32106 | `TotalYield` (das ist der PV-Gesamtertrag des Wechselrichters, kein Smartmeter-Wert) |
| HUAWEI SMARTMETER ZÄHLER EINSPEISUNG | 37119 | `GridExportTotal` |
| HUAWEI SMARTMETER ZÄHLER NETZBEZUG | 37121 | `GridImportTotal` |
| LUNA BATTERIE Ladung Tag / Entladung Tag | 37015 / 37017 | `BatteryChargeToday` / `BatteryDischargeToday` |
| LUNA BATTERIE Gesamt Ladung / Entladung | 37066 / 37068 | `BatteryChargeTotal` / `BatteryDischargeTotal` |
| LUNA BATTERIE TEMP | 32087 | `InverterTemp` (Register 32087 ist die **Wechselrichter**-Temperatur, die Akku-Temperatur ist `BatteryTemp`) |
| HUAWEI LUNA MAX-LADELEISTUNG / MAX-ENTLADELEISTUNG | 47075 / 47077 | `MaxChargePower` / `MaxDischargePower` |
| PV1/PV2 VOLTAGE / CURRENT | 32016–32019 | `PV1Voltage` … (Option „PV-Strings“) |
| LUNA BATTERIE LADEN / ENTLADEN STEUERN | 47100 | `ForceMode` bzw. `HPV_ForceCharge()` / `HPV_ForceDischarge()` / `HPV_ForceStop()` |

## 12. Öffentliche Funktionen

```php
// Lesen / Diagnose
HPV_Poll(int $InstanzID): bool              // fällige Gruppen lesen (läuft per Timer)
HPV_UpdateAll(int $InstanzID): bool         // sofort alle Werte lesen
HPV_ShowDiagnostics(int $InstanzID): string // Diagnose als Text

// Steuerung (nur mit „Steuerung erlauben“; Rückgabe false bei Fehler, Grund unter „Diagnose anzeigen“)
HPV_SetWorkingMode(int $InstanzID, int $Mode): bool           // 0 Adaptiv, 1 Fest, 2 Max. Eigenverbrauch, 4 Volleinspeisung, 5 Zeitsteuerung
HPV_SetMaxChargePower(int $InstanzID, float $Watt): bool
HPV_SetMaxDischargePower(int $InstanzID, float $Watt): bool
HPV_SetChargeCutoff(int $InstanzID, float $Percent): bool     // 90–100
HPV_SetDischargeCutoff(int $InstanzID, float $Percent): bool  // 0–20
HPV_SetBackupSOC(int $InstanzID, float $Percent): bool
HPV_SetGridCharge(int $InstanzID, bool $Enabled): bool
HPV_SetGridChargeCutoff(int $InstanzID, float $Percent): bool // 20–100
HPV_SetGridChargePower(int $InstanzID, float $Watt): bool
HPV_SetPowerControlMode(int $InstanzID, int $Mode): bool      // 0 Unbegrenzt, 5 Null, 6 Watt, 7 Prozent
HPV_SetMaxFeedInPower(int $InstanzID, float $Watt): bool
HPV_SetMaxFeedInPercent(int $InstanzID, float $Percent): bool
HPV_ForceCharge(int $InstanzID, float $Power, int $Minutes, float $TargetSOC): bool    // TargetSOC 0 = nur Dauer
HPV_ForceDischarge(int $InstanzID, float $Power, int $Minutes, float $TargetSOC): bool
HPV_ForceStop(int $InstanzID): bool
HPV_SetInverter(int $InstanzID, bool $On): bool               // zusätzlich „Wechselrichter Ein/Aus erlauben“

// Archiv-Werkzeug (siehe Abschnitt 11)
HPV_ArchiveTransfer(int $InstanzID, int $Quelle, string $ZielIdent, string $Umrechnung, float $Faktor, bool $Vorzeichen, bool $DryRun): bool
                                                                    // Archiv übernehmen; Umrechnung: same, w_kw, kw_w, raw10, raw100, raw1000, custom (dann gilt $Faktor); true = nur Vorschau
HPV_ArchiveClean(int $InstanzID, string $Ident, bool $Delete): bool // Archiv einer Modul-Variable prüfen / Ausreißer löschen
HPV_ArchiveStatus(int $InstanzID): string                         // Fortschritt bzw. Ergebnis
HPV_ArchiveCancel(int $InstanzID): void

// intern (Timer)
HPV_ReopenConnection(int $InstanzID): void
HPV_ForceWatchdog(int $InstanzID): void
HPV_ArchiveJobStep(int $InstanzID): void
```

Beispiel: Bei günstigem Strom eine Stunde mit 2 kW aus dem Netz laden.

```php
HPV_SetGridCharge(12345, true);
HPV_ForceCharge(12345, 2000, 60, 0);
```

## 13. Fehlersuche

1. **Diagnose anzeigen** (Button) zeigt Anfragen, Fehler, BUSY, Timeouts, Wiederholungen, verworfene Werte, letzten Fehler und letzten verworfenen Wert.
2. **Debug-Fenster** der Instanz zeigt jede Anfrage mit der Rohantwort in Hex, jede Wiederholung und jeden verworfenen Wert mit Grund.
3. **Typische Ursachen:**

   | Symptom | Ursache / Lösung |
   |---|---|
   | Viele Timeouts | Timeout im Gateway auf 10 s erhöhen. Prüfen, ob noch Datenblöcke oder Adress-Instanzen parallel abfragen. WLAN-Empfang des Dongles prüfen. |
   | Viele BUSY | Ein anderer Modbus-Client fragt mit, oder die Pause ist zu kurz. Pause erhöhen, schnelles Intervall auf 15–30 s stellen. |
   | „unerwartete Antwortlänge“ | Rohantwort aus dem Debug-Fenster schicken (Issue). |
   | Status „Gateway / Client Socket nicht aktiv“ | Client Socket öffnen und IP/Port prüfen. |
   | Schreiben: „nicht übernommen“ | Der Wechselrichter lehnt den Wert ab, z. B. Notstromreserve unter dem Entladeende. Die Variable zeigt den echten Wert. |
   | Schreiben: ILLEGAL_DATA_ADDRESS | Register beim Gerät nicht vorhanden oder Anmeldung nötig (direkte Verbindung ohne Dongle). |
   | Ein Wert bleibt stehen | Unter „Diagnose anzeigen“ beim letzten verworfenen Wert nachsehen. Ggf. „höchste plausible Leistung“ anpassen. |

## 14. Geplant

- Alarme des Wechselrichters im Klartext.
- Zeitsteuerung (TOU-Zeitfenster) des Akkus einstellen.
- Firmware des Smart Dongles (eigene Geräte-ID 100, braucht ein zweites Gateway).

## 15. Quellen

- Huawei: *Solar Inverter Modbus Interface Definitions*. Die PDFs sind u. a. im [Symcon-Forum](https://community.symcon.de/t/sammelthread-huawei/137734) verlinkt.
- Registerliste und Erfahrungen zu BUSY und Timeouts: [huawei-solar-lib](https://github.com/wlcrs/huawei-solar-lib) (Apache 2.0). Daraus stammen nur Adressen, Datentypen und Faktoren, kein Code.
- Schreibreihenfolgen und Wertebereiche: [Home-Assistant-Integration huawei_solar](https://github.com/wlcrs/huawei_solar) (nur als Referenz, kein Code übernommen)
- [Symcon-Dokumentation ModBus](https://www.symcon.de/de/service/dokumentation/modulreferenz/geraete/modbus-rtu-tcp/)

---

*Huawei, SUN2000, LUNA2000 und FusionSolar sind Marken ihres jeweiligen Inhabers. HuaweiPV ist ein privates Projekt und steht in keiner Verbindung zur Huawei Technologies Co., Ltd.*
