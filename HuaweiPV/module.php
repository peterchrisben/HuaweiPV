<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/HPVRegisters.php';
require_once __DIR__ . '/../libs/HPVFilter.php';
require_once __DIR__ . '/../libs/HPVEnergy.php';
require_once __DIR__ . '/../libs/HPVArchive.php';

/**
 * Huawei PV (SUN2000 / LUNA2000 / Smartmeter) über das Symcon ModBus Gateway.
 *
 * Eine Instanz fragt Wechselrichter, Akku und Smartmeter streng nacheinander ab
 * (Warteschlange statt paralleler Datenblöcke), wiederholt bei SLAVE_DEVICE_BUSY/Timeout
 * mit wachsender Wartezeit und schreibt nur plausible Werte in die Variablen.
 *
 * Autor: Peter Chrisben – Lizenz: CC BY-NC-SA 4.0
 */
class HuaweiPV extends IPSModule
{
    private const GATEWAY_TX = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
    private const GATEWAY_MODULE = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';

    /** Ab dieser Laufzeit (s) werden in einem Durchlauf keine weiteren Blöcke mehr gestartet. */
    private const CYCLE_BUDGET = 20;

    private const DEVICE_STATUS = [
        0x0000 => 'Standby: Initialisierung',
        0x0001 => 'Standby: Isolationsprüfung',
        0x0002 => 'Standby: Einstrahlungsprüfung',
        0x0003 => 'Standby: Netzprüfung',
        0x0100 => 'Startet',
        0x0200 => 'Netzbetrieb',
        0x0201 => 'Netzbetrieb: Leistung begrenzt',
        0x0202 => 'Netzbetrieb: Selbst-Derating',
        0x0203 => 'Inselbetrieb',
        0x0300 => 'Abgeschaltet: Fehler',
        0x0301 => 'Abgeschaltet: Befehl',
        0x0302 => 'Abgeschaltet: OVGR',
        0x0303 => 'Abgeschaltet: Kommunikation getrennt',
        0x0304 => 'Abgeschaltet: Leistung begrenzt',
        0x0305 => 'Abgeschaltet: manueller Start nötig',
        0x0306 => 'Abgeschaltet: DC-Schalter aus',
        0x0307 => 'Abgeschaltet: Schnellabschaltung',
        0x0308 => 'Abgeschaltet: zu wenig Eingangsleistung',
        0x030C => 'Abgeschaltet: Akku-Entladeende',
        0x0401 => 'Netzvorgabe: cosφ-P-Kurve',
        0x0402 => 'Netzvorgabe: Q-U-Kurve',
        0x0403 => 'Netzvorgabe: PF-U-Kurve',
        0x0404 => 'Netzvorgabe: Trockenkontakt',
        0x0405 => 'Netzvorgabe: Q-P-Kurve',
        0x0500 => 'Stichprobe bereit',
        0x0501 => 'Stichprobe läuft',
        0x0600 => 'Prüfung läuft',
        0x0700 => 'AFCI-Selbsttest',
        0x0800 => 'I-U-Kennlinien-Scan',
        0x0900 => 'DC-Eingangsprüfung',
        0x0A00 => 'Inselbetrieb: Laden',
        0xA000 => 'Standby: keine Einstrahlung'
    ];

    private const BATTERY_STATUS = [0 => 'Offline', 1 => 'Standby', 2 => 'In Betrieb', 3 => 'Fehler', 4 => 'Ruhemodus'];

    private const WORKING_MODE_B = [
        0  => 'Keine',
        1  => 'Zwangsladen/-entladen',
        2  => 'Zeitsteuerung (LG)',
        3  => 'Festes Laden/Entladen',
        4  => 'Max. Eigenverbrauch',
        5  => 'Volleinspeisung',
        6  => 'Zeitsteuerung (LUNA2000)',
        7  => 'Fernsteuerung: max. Eigenverbrauch',
        8  => 'Fernsteuerung: Volleinspeisung',
        9  => 'Fernsteuerung: Zeitsteuerung',
        10 => 'KI-Energiemanagement',
        11 => 'Fernsteuerung: KI'
    ];

    private const WORKING_MODE_C = [
        0 => 'Adaptiv',
        1 => 'Festes Laden/Entladen',
        2 => 'Max. Eigenverbrauch',
        3 => 'Zeitsteuerung (LG)',
        4 => 'Volleinspeisung',
        5 => 'Zeitsteuerung (LUNA2000)'
    ];

    private const METER_STATUS = [0 => 'Offline', 1 => 'Normal'];

    private const POWER_CONTROL_MODE = [
        0 => 'Unbegrenzt',
        1 => 'DI-Steuerung (Rundsteuerempfänger)',
        5 => 'Nulleinspeisung',
        6 => 'Begrenzt auf Watt-Wert',
        7 => 'Begrenzt auf Prozent'
    ];

    private const FORCE_MODE = [0 => 'Aus', 1 => 'Zwangsladen', 2 => 'Zwangsentladen'];

    /** Nachlauf, bevor der Wächter eine Zwangsladung selbst beendet (s). */
    private const FORCE_GRACE = 60;

    public function Create()
    {
        parent::Create();

        $this->ConnectParent(self::GATEWAY_MODULE);

        // Abfrage
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyInteger('PollFast', 10);
        $this->RegisterPropertyInteger('PollMedium', 60);
        $this->RegisterPropertyInteger('PollSlow', 300);

        // Anlage / Umfang
        $this->RegisterPropertyBoolean('HasBattery', true);
        $this->RegisterPropertyBoolean('HasMeter', true);
        $this->RegisterPropertyBoolean('ShowSettings', true);
        $this->RegisterPropertyBoolean('ShowStrings', false);
        $this->RegisterPropertyBoolean('ShowPhases', false);
        $this->RegisterPropertyBoolean('ShowAutarky', false);
        $this->RegisterPropertyBoolean('ShowKW', false);
        $this->RegisterPropertyBoolean('ShowDiagnostics', true);
        $this->RegisterPropertyBoolean('GroupFolders', true);
        // Vorzeichen: 0 = wie Huawei (Einspeisung + / Bezug −, Laden + / Entladen −), 1 = umgekehrt
        $this->RegisterPropertyInteger('GridSign', 0);
        $this->RegisterPropertyInteger('BatterySign', 0);

        // Archiv-Werkzeug: Zuordnung alte Variable → neue Variable
        $this->RegisterPropertyBoolean('SortVariables', true);

        // Plausibilität
        $this->RegisterPropertyInteger('PowerLimit', 15000);
        $this->RegisterPropertyInteger('ConfirmReads', 3);

        // Kommunikation
        $this->RegisterPropertyInteger('RequestPause', 200);
        $this->RegisterPropertyInteger('MaxRetries', 3);
        // Standard aus: schaltet die Schnittstelle (fremde Instanz) – nur auf ausdrücklichen Wunsch (Store-Regel)
        $this->RegisterPropertyBoolean('AutoReconnect', false);
        $this->RegisterPropertyInteger('ReconnectAfter', 5);

        // Steuerung (standardmäßig gesperrt)
        $this->RegisterPropertyBoolean('AllowControl', false);
        $this->RegisterPropertyBoolean('AllowInverterSwitch', false);

        // Tagesbilanz (Autarkie/Eigenverbrauch) übersteht einen Neustart
        $this->RegisterAttributeString('DayStats', '');
        // Laufende Zwangsladung (Richtung, Start, Ende) übersteht einen Neustart
        $this->RegisterAttributeString('ForceState', '');
        // Zuletzt angewendete Vorzeichen (für sofortiges Umdrehen der aktuellen Werte bei Änderung)
        $this->RegisterAttributeString('AppliedSigns', '');
        $this->RegisterAttributeString('PresentationSigs', '{}');
        $this->RegisterAttributeString('SortSig', '');
        $this->RegisterAttributeString('SnapshotMode', '');
        $this->RegisterAttributeBoolean('LegacyCustomCleared', false);
        // Archiv-Werkzeug: laufender Auftrag und letztes Ergebnis
        $this->RegisterAttributeString('ArchiveJob', '');
        $this->RegisterAttributeString('ArchiveReport', '');
        $this->RegisterAttributeString('ArchiveFlags', '');
        $this->RegisterAttributeString('ArchiveOutliers', '');

        $this->RegisterTimer('Poll', 0, 'HPV_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Reopen', 0, 'HPV_ReopenConnection($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ForceEnd', 0, 'HPV_ForceWatchdog($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ArchiveJob', 0, 'HPV_ArchiveJobStep($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->MaintainVariables();
        $this->ApplySignChange();
        $this->SetupControls();
        $this->RestoreForceTimer();
        // Unterbrochenen Archiv-Auftrag (z. B. nach Neustart) fortsetzen
        $this->SetTimerInterval('ArchiveJob', $this->ReadAttributeString('ArchiveJob') !== '' ? 2000 : 0);

        // Filterzustand nach Konfigurationsänderung neu aufbauen (wird aus den Variablen vorbelegt)
        $this->SetBuffer('FilterState', '');
        $this->SetBuffer('LastGroupRun', '');

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatusIfChanged(104);
            return;
        }

        $this->SetTimerInterval('Poll', max(5, $this->ReadPropertyInteger('PollFast')) * 1000);
        $this->SetStatusIfChanged($this->HasActiveParent() ? 102 : 202);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function ReceiveData($JSONString)
    {
        // Antworten kommen als Rückgabewert von SendDataToParent.
        // Vom Gateway weitergereichte Datenblöcke anderer Instanzen werden ignoriert.
        return '';
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'UpdateAll':
                $this->UpdateAll();
                break;
            case 'Diagnostics':
                $this->ShowDiagnostics();
                break;
            case 'ForceMode':
                $mode = (int) $Value;
                if ($mode === 0) {
                    $ok = $this->ForceStop();
                } else {
                    $ok = $this->StartForced($mode, (float) $this->GetVal('ForcePower'),
                        (int) $this->GetVal('ForceDuration'), (float) $this->GetVal('ForceTargetSOC'));
                }
                if (!$ok) {
                    throw new Exception($this->LastWriteError());
                }
                break;
            case 'ForcePower':
            case 'ForceDuration':
            case 'ForceTargetSOC':
                // nur Vorgabe für die nächste Zwangsladung, wird nicht sofort geschrieben
                $limits = $this->ForceLimits();
                [$min, $max] = $limits[$Ident];
                if ($Value < $min || $Value > $max) {
                    throw new Exception(sprintf('Wert außerhalb %s … %s', $min, $max));
                }
                $this->SetVal($Ident, $Value);
                break;
            case 'MigConvert':
                // Formular: Feld „Eigener Faktor“ nur bei „eigener Faktor …“ zeigen
                $this->UpdateFormField('MigFactor', 'visible', (string) $Value === 'custom');
                break;
            case 'InverterOn':
                if (!$this->SetInverter((bool) $Value)) {
                    throw new Exception($this->LastWriteError());
                }
                break;
            default:
                if (array_key_exists($Ident, HPVRegisters::Controls())) {
                    $value = is_bool($Value) ? ($Value ? 1 : 0) : $Value;
                    if (!$this->WriteControl($Ident, (float) $value)) {
                        throw new Exception($this->LastWriteError());
                    }
                    break;
                }
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'ExpansionPanel' && ($element['caption'] ?? '') === 'Steuerung') {
                $text = $this->EffectiveLimitsText();
                if ($text !== '') {
                    $element['items'][] = ['type' => 'Label', 'caption' => $text];
                }
            }
        }
        unset($element);

        $report = $this->ReadAttributeString('ArchiveReport');
        $this->FillFormItems($form['actions'], $this->CleanFields() + $this->TransferFields() + [
            'ArchiveResult'    => ['caption' => $report],
            'ArchiveOutliers'  => ['values' => $this->ArchiveOutlierRows()],
            'ArchiveListInfo'  => ['caption' => $this->ArchiveListInfo()]
        ] + $this->FlagFields(json_decode($this->ReadAttributeString('ArchiveFlags'), true) ?: []));
        return json_encode($form);
    }

    /** Formular-Elemente per Name füllen – auch verschachtelt (RowLayout, ExpansionPanel) */
    private function FillFormItems(array &$items, array $fill): void
    {
        foreach ($items as &$item) {
            if (isset($item['name'], $fill[$item['name']])) {
                $item = array_merge($item, $fill[$item['name']]);
            }
            if (isset($item['items']) && is_array($item['items'])) {
                $this->FillFormItems($item['items'], $fill);
            }
        }
        unset($item);
    }

    /** Hinweis, wenn im Wechselrichter mehr eingestellt ist, als der Akku kann */
    private function EffectiveLimitsText(): string
    {
        if (!$this->ReadPropertyBoolean('HasBattery') || !$this->HasIdent('BatteryMaxChargeLimit')) {
            return '';
        }
        $limits = [
            'MaxChargePower'    => ['Max. Ladeleistung', 'BatteryMaxChargeLimit'],
            'MaxDischargePower' => ['Max. Entladeleistung', 'BatteryMaxDischargeLimit'],
            'GridChargePower'   => ['Netzladeleistung', 'BatteryMaxChargeLimit']
        ];
        $notes = [];
        $deviceMax = 0.0;
        foreach ($limits as $ident => [$caption, $limitIdent]) {
            $limit = $this->HasIdent($limitIdent) ? (float) $this->GetVal($limitIdent) : 0.0;
            $deviceMax = max($deviceMax, $limit);
            if ($limit > 0 && $this->HasIdent($ident) && (float) $this->GetVal($ident) > $limit) {
                $notes[] = sprintf('%s: im Wechselrichter %d W eingestellt – wirksam höchstens %d W (Grenze des Akkus).',
                    $caption, (float) $this->GetVal($ident), $limit);
            }
        }
        if ($deviceMax <= 0) {
            return '';
        }
        if ($notes === []) {
            return sprintf('Leistungsgrenze des Akkus: %d W. Alle eingestellten Leistungen liegen darunter.', $deviceMax);
        }
        return implode(' ', $notes);
    }

    // ================================================================== öffentliche Funktionen

    /** Timer: fällige Abfragegruppen lesen. */
    public function Poll(): bool
    {
        return $this->RunCycle(false);
    }

    /** Alle Werte sofort lesen (Button „Alle Werte jetzt lesen“). */
    public function UpdateAll(): bool
    {
        $ok = $this->RunCycle(true);
        $this->UpdateFormField('Result', 'caption', $ok
            ? 'Abfrage erfolgreich (' . date('H:i:s') . ').'
            : 'Abfrage fehlgeschlagen – Details unter „Diagnose anzeigen“ bzw. im Debug-Fenster.');
        return $ok;
    }

    /** Diagnose als Text (wird auch im Formular angezeigt). */
    /**
     * Letzter Schnappschuss als JSON – zeitgleiche Werte für Energiemanager (z. B. SYMEMS).
     * Immer im Huawei-Vorzeichen, unabhängig von GridSign/BatterySign: grid + = Einspeisung, battery + = Laden.
     * {"t": Unix-Zeit, "pv": W (DC-Eingang 32064), "pvAc": W (Wirkleistung 32080), "grid": W, "battery": W|null,
     *  "soc": %|null, "house": W, "ok": Bilanz plausibel, "dur": ms für die Schnappschuss-Abfragen}
     */
    public function GetSnapshot(): string
    {
        $snap = $this->GetBuffer('Snapshot');
        if ($snap === '') {
            return json_encode(['t' => 0, 'pv' => null, 'pvAc' => null, 'grid' => null, 'battery' => null,
                'soc' => null, 'house' => null, 'ok' => false, 'dur' => 0]);
        }
        return $snap;
    }

    public function ShowDiagnostics(): string
    {
        $d = $this->Diag();
        $lines = [
            'Stand: ' . date('d.m.Y H:i:s'),
            'Instanzstatus: ' . $this->GetStatus(),
            'Gateway: #' . $this->GatewayID() . ' / Schnittstelle: #' . $this->IOID(),
            'Letzte erfolgreiche Abfrage: ' . ($d['lastSuccess'] > 0 ? date('d.m.Y H:i:s', $d['lastSuccess']) : '–'),
            sprintf('Heute (%s): %d Anfragen, %d Fehler (davon %d BUSY, %d Timeout), %d Wiederholungen, %d verworfene Werte',
                $d['date'], $d['requests'], $d['errors'], $d['busy'], $d['timeouts'], $d['retries'], $d['rejected']),
            'Fehlgeschlagene Durchläufe in Folge: ' . $d['failedCycles'],
            'Letzter Fehler: ' . ($d['lastError'] !== '' ? $d['lastError'] : '–'),
            'Letzter verworfener Wert: ' . ($d['lastRejected'] !== '' ? $d['lastRejected'] : '–'),
            'Letzter Neuaufbau der Verbindung: ' . ($d['lastReconnect'] > 0 ? date('d.m.Y H:i:s', $d['lastReconnect']) : '–'),
            sprintf('Schnappschuss (PV/Netz/Akku zeitgleich): heute %d, Dauer im Schnitt %s ms, Bilanz unplausibel: %d',
                $d['snapshots'], $d['snapshots'] > 0 ? (string) round($d['snapshotMs'] / $d['snapshots']) : '–', $d['balanceBad']),
            'Letzte unplausible Bilanz: ' . ($d['lastBalance'] !== '' ? $d['lastBalance'] : '–'),
            'Steuerung: ' . ($this->ReadPropertyBoolean('AllowControl') ? 'erlaubt' : 'gesperrt')
                . sprintf(' – heute %d Schreibbefehle, %d übersprungen (Wert stand schon im Wechselrichter)', $d['writes'], $d['writesSkipped']),
            'Letzter Schreibbefehl: ' . ($d['lastWrite'] !== '' ? $d['lastWrite'] : '–'),
            'Letzter Schreibfehler: ' . ($d['lastWriteError'] !== '' ? $d['lastWriteError'] : '–')
        ];
        if (!empty($d['balanceLog'])) {
            $lines[] = 'Letzte ' . count($d['balanceLog']) . ' unplausible Bilanzen (neueste zuletzt, Abweichung = PV − Netz − Akku − Haus):';
            foreach ($d['balanceLog'] as $entry) {
                $lines[] = '  · ' . $entry;
            }
        }
        $text = implode("\n", $lines);
        $this->SendDebug('Diagnose', $text, 0);
        $this->UpdateFormField('Result', 'caption', $text);
        return $text;
    }

    /** Timer: Schnittstelle nach dem Neuaufbau wieder öffnen. */
    public function ReopenConnection(): void
    {
        $this->SetTimerInterval('Reopen', 0);
        $io = $this->IOID();
        if ($io > 0 && $this->HasOpenProperty($io)) {
            IPS_SetProperty($io, 'Open', true);
            IPS_ApplyChanges($io);
            $this->SendDebug('Verbindung', 'Schnittstelle #' . $io . ' wieder geöffnet', 0);
        }
    }

    // ================================================================== öffentliche Funktionen: Steuerung
    // Alle Funktionen schreiben nur, wenn „Steuerung erlauben“ aktiv ist, und lesen zur Kontrolle zurück.

    /** Akku-Betriebsart: 0 Adaptiv, 1 Festes Laden/Entladen, 2 Max. Eigenverbrauch, 4 Volleinspeisung, 5 Zeitsteuerung (LUNA2000) */
    public function SetWorkingMode(int $Mode): bool
    {
        return $this->WriteControl('WorkingModeSetting', (float) $Mode);
    }

    public function SetMaxChargePower(float $Watt): bool
    {
        return $this->WriteControl('MaxChargePower', $Watt);
    }

    public function SetMaxDischargePower(float $Watt): bool
    {
        return $this->WriteControl('MaxDischargePower', $Watt);
    }

    /** Ladeende 90–100 % */
    public function SetChargeCutoff(float $Percent): bool
    {
        return $this->WriteControl('ChargeCutoff', $Percent);
    }

    /** Entladeende 0–20 % */
    public function SetDischargeCutoff(float $Percent): bool
    {
        return $this->WriteControl('DischargeCutoff', $Percent);
    }

    public function SetBackupSOC(float $Percent): bool
    {
        return $this->WriteControl('BackupSOC', $Percent);
    }

    public function SetGridCharge(bool $Enabled): bool
    {
        return $this->WriteControl('GridCharge', $Enabled ? 1.0 : 0.0);
    }

    /** Netzladen bis 20–100 % */
    public function SetGridChargeCutoff(float $Percent): bool
    {
        return $this->WriteControl('GridChargeCutoff', $Percent);
    }

    public function SetGridChargePower(float $Watt): bool
    {
        return $this->WriteControl('GridChargePower', $Watt);
    }

    /** Einspeisebegrenzung: 0 Unbegrenzt, 5 Nulleinspeisung, 6 Begrenzt (W), 7 Begrenzt (%) */
    public function SetPowerControlMode(int $Mode): bool
    {
        return $this->WriteControl('PowerControlMode', (float) $Mode);
    }

    public function SetMaxFeedInPower(float $Watt): bool
    {
        return $this->WriteControl('MaxFeedInPower', $Watt);
    }

    public function SetMaxFeedInPercent(float $Percent): bool
    {
        return $this->WriteControl('MaxFeedInPercent', $Percent);
    }

    /** Zwangsladen mit Leistung (W) für Minuten (1–1440); TargetSOC > 0 = bis Ladestand (Dauer bleibt Obergrenze) */
    public function ForceCharge(float $Power, int $Minutes, float $TargetSOC): bool
    {
        return $this->StartForced(1, $Power, $Minutes, $TargetSOC);
    }

    /** Zwangsentladen mit Leistung (W) für Minuten (1–1440); TargetSOC > 0 = bis Ladestand (Dauer bleibt Obergrenze) */
    public function ForceDischarge(float $Power, int $Minutes, float $TargetSOC): bool
    {
        return $this->StartForced(2, $Power, $Minutes, $TargetSOC);
    }

    public function ForceStop(): bool
    {
        if (!$this->ControlAllowed('battery')) {
            return false;
        }
        $ok = $this->WithLock(function (): bool {
            $ok = $this->WriteRaw(HPVRegisters::FORCE_COMMAND, [0], 'Zwangsladen stoppen');
            // wie Huawei-App: Dauer und Modus zurücksetzen (Fehler hier sind unkritisch)
            $this->WriteRaw(HPVRegisters::FORCE_PERIOD, [0], 'Dauer zurücksetzen');
            $this->WriteRaw(HPVRegisters::FORCE_SETTING_MODE, [0], 'Modus zurücksetzen');
            return $ok;
        });
        if ($ok) {
            $this->ClearForceState();
            $this->SendDebug('Steuerung', 'Zwangsladen/-entladen beendet', 0);
        }
        return $ok;
    }

    /** Timer: beendet eine Zwangsladung spätestens nach Ablauf der Dauer (Sicherheitsnetz). */
    public function ForceWatchdog(): void
    {
        $this->SetTimerInterval('ForceEnd', 0);
        $state = json_decode($this->ReadAttributeString('ForceState'), true);
        if (!is_array($state) || ($state['mode'] ?? 0) === 0) {
            return;
        }
        $this->LogMessage('Zwangsladen/-entladen: Dauer abgelaufen – wird vom Modul beendet.', KL_NOTIFY);
        if (!$this->ForceStop()) {
            // später erneut versuchen
            $this->SetTimerInterval('ForceEnd', 60000);
        }
    }

    /** Wechselrichter starten (true) oder herunterfahren (false). Benötigt zusätzlich „Ein/Aus erlauben“. */
    public function SetInverter(bool $On): bool
    {
        if (!$this->ControlAllowed('inverter')) {
            return false;
        }
        $reg = $On ? HPVRegisters::INVERTER_START : HPVRegisters::INVERTER_STOP;
        $ok = $this->WithLock(function () use ($reg, $On): bool {
            return $this->WriteRaw($reg, [0], $On ? 'Wechselrichter starten' : 'Wechselrichter herunterfahren');
        });
        if ($ok) {
            // Statuswechsel dauert bis zu 5 Minuten – so lange die Vorgabe anzeigen
            $this->SetBuffer('InverterPending', json_encode(['on' => $On, 'until' => time() + 300]));
            $this->SetValueIfChanged('InverterOn', $On);
            $this->LogMessage('Wechselrichter wird ' . ($On ? 'gestartet' : 'heruntergefahren') . '.', KL_NOTIFY);
        }
        return $ok;
    }

    // ================================================================== öffentliche Funktionen: Archiv-Werkzeug

    /**
     * Archivdaten einer alten Variable in eine neue Modul-Variable übernehmen.
     * Es wird nur kopiert (die alten Daten bleiben unverändert), Ausreißer werden ausgelassen,
     * Integer/Float wird umgewandelt, mit Umrechnung (siehe CONVERSIONS) und optional umgedrehtem Vorzeichen.
     * Übernommen werden nur Werte, die älter sind als der älteste Wert der neuen Variable (kein Doppeln).
     *
     * @param string $Conversion Schlüssel aus CONVERSIONS ('same', 'w_kw', …) oder 'custom' (dann gilt $Factor)
     */
    public function ArchiveTransfer(int $Source, string $Target, string $Conversion, float $Factor, bool $Invert, bool $DryRun): bool
    {
        $archive = $this->ArchiveID();
        if ($archive === 0) {
            return $this->ArchiveMessage('Kein Archiv gefunden.');
        }
        $targetID = $Target === '' ? 0 : $this->VarID($Target);
        if ($Source <= 0 || !IPS_VariableExists($Source)) {
            return $this->ArchiveMessage('Bitte die alte Variable auswählen.');
        }
        if ($targetID === 0) {
            return $this->ArchiveMessage('Bitte die neue Variable auswählen.');
        }
        if ($Source === $targetID) {
            return $this->ArchiveMessage('Alte und neue Variable sind identisch.');
        }
        if (IPS_GetVariable($Source)['VariableType'] === VARIABLETYPE_STRING) {
            return $this->ArchiveMessage('Textvariablen können nicht übernommen werden.');
        }
        if (!AC_GetLoggingStatus($archive, $Source)) {
            return $this->ArchiveMessage('Die alte Variable „' . IPS_GetName($Source) . '“ wird nicht archiviert – es gibt nichts zu übernehmen.');
        }
        if ($Conversion === 'custom') {
            if ($Factor == 0.0) {
                return $this->ArchiveMessage('Eigener Faktor darf nicht 0 sein.');
            }
            $factor = $Factor;
        } elseif (isset(self::CONVERSIONS[$Conversion])) {
            $factor = self::CONVERSIONS[$Conversion][1];
        } else {
            return $this->ArchiveMessage('Bitte eine Umrechnung auswählen.');
        }
        $row = $this->ArchiveRow($archive, $Target, $targetID, $Source, $factor, $Invert, IPS_GetName($Source));
        return $this->ArchiveStart('migrate', $DryRun, [$row], []);
    }

    /** Umrechnungen für die Übernahme: Schlüssel => [Beschriftung, Faktor] */
    private const CONVERSIONS = [
        'same'    => ['gleiche Einheit (× 1)', 1.0],
        'w_kw'    => ['W → kW  ·  Wh → kWh (× 0,001)', 0.001],
        'kw_w'    => ['kW → W  ·  kWh → Wh  ·  MWh → kWh (× 1000)', 1000.0],
        'raw10'   => ['Rohwert ÷ 10, z. B. 0,1 % (× 0,1)', 0.1],
        'raw100'  => ['Rohwert ÷ 100, z. B. 0,01 kWh (× 0,01)', 0.01],
        'raw1000' => ['Rohwert ÷ 1000 (× 0,001)', 0.001]
    ];

    /** Archiv einer Modul-Variable auf Ausreißer prüfen ($Delete = false) oder diese löschen ($Delete = true). */
    public function ArchiveClean(string $Ident, bool $Delete): bool
    {
        $archive = $this->ArchiveID();
        if ($archive === 0) {
            return $this->ArchiveMessage('Kein Archiv gefunden.');
        }
        $id = $this->VarID($Ident);
        if ($id === 0) {
            return $this->ArchiveMessage('Variable „' . $Ident . '“ gibt es nicht.');
        }
        if (!AC_GetLoggingStatus($archive, $id)) {
            return $this->ArchiveMessage('„' . IPS_GetName($id) . '“ wird nicht archiviert – nichts zu prüfen.');
        }
        $row = $this->ArchiveRow($archive, $Ident, $id, $id, 1.0, false, IPS_GetName($id));
        $row['cutoff'] = time() + 1;
        $row['cursor'] = time();
        return $this->ArchiveStart('clean', !$Delete, [$row], []);
    }

    /** Fortschritt bzw. Ergebnis des letzten Auftrags anzeigen. */
    public function ArchiveStatus(): string
    {
        $job = json_decode($this->ReadAttributeString('ArchiveJob'), true);
        if (is_array($job)) {
            $text = $this->ArchiveProgressText($job);
        } else {
            $text = $this->ReadAttributeString('ArchiveReport');
            if ($text === '') {
                $text = 'Noch kein Auftrag ausgeführt.';
            }
        }
        $this->UpdateFormField('ArchiveResult', 'caption', $text);
        return $text;
    }

    public function ArchiveCancel(): void
    {
        $job = json_decode($this->ReadAttributeString('ArchiveJob'), true);
        $this->SetTimerInterval('ArchiveJob', 0);
        $this->WriteAttributeString('ArchiveJob', '');
        if (is_array($job)) {
            $text = "Abgebrochen.\n" . $this->ArchiveProgressText($job);
            $this->WriteAttributeString('ArchiveOutliers', json_encode($job['outliers'] ?? []));
            $this->WriteAttributeString('ArchiveReport', $text);
            $this->UpdateFormField('ArchiveResult', 'caption', $text);
            $this->SetFlags(['warn' => 'Auftrag abgebrochen – es wurde nur ein Teil verarbeitet.']);
            $this->LogMessage('Archiv-Werkzeug: Auftrag abgebrochen.', KL_NOTIFY);
        }
    }

    /** Timer: verarbeitet den laufenden Auftrag in Etappen. */
    public function ArchiveJobStep(): void
    {
        $this->SetTimerInterval('ArchiveJob', 0);
        $job = json_decode($this->ReadAttributeString('ArchiveJob'), true);
        if (!is_array($job)) {
            return;
        }
        $archive = $this->ArchiveID();
        $start = microtime(true);
        $limit = 10000;
        $powerLimit = (float) $this->ReadPropertyInteger('PowerLimit');
        $confirm = max(2, $this->ReadPropertyInteger('ConfirmReads'));

        while ($job['row'] < count($job['rows']) && microtime(true) - $start < 2) {
            $r = &$job['rows'][$job['row']];
            $def = $this->ArchiveDef($r['target']);
            $page = AC_GetLoggedValues($archive, $r['source'], 0, $r['cursor'], $limit);
            if (!isset($r['sample']) && $page !== []) {
                $r['sample'] = (float) $page[0]['Value'];
            }
            $records = [];
            foreach ($page as $entry) {
                $records[] = ['TimeStamp' => (int) $entry['TimeStamp'], 'Value' => (float) $entry['Value'] * $r['factor'] * ($r['invert'] ? -1 : 1)];
            }
            [$ok, $bad] = HPVArchive::Check($def, $records, $r['state'], $powerLimit, $confirm);
            $finished = count($page) < $limit;
            if ($finished) {
                $bad = array_merge($bad, HPVArchive::Flush($r['state']));
            }
            $r['read'] += count($records);
            $r['ok'] += count($ok);
            $r['bad'] += count($bad);
            foreach ($bad as $b) {
                $r['reasons'][$b['Reason']] = ($r['reasons'][$b['Reason']] ?? 0) + 1;
                if ($b['Reason'] !== HPVArchive::REASON_LATE && count($r['examples']) < 5) {
                    $r['examples'][] = date('d.m.Y H:i:s', $b['TimeStamp']) . ': ' . round($b['Value'], 3) . ' (' . $b['Reason'] . ')';
                }
                // Liste fürs Formular: echte Ausreißer und Vortageswerte getrennt, je höchstens 1000
                $kind = $b['Reason'] === HPVArchive::REASON_LATE ? 'late' : 'real';
                if (count($job['outliers'][$kind] ?? []) < self::OUTLIER_LIST_MAX) {
                    $job['outliers'][$kind][] = [
                        'Variable' => IPS_GetName($r['targetID']),
                        'Time'     => date('d.m.Y H:i:s', $b['TimeStamp']),
                        'Value'    => $this->Num($b['Value']),
                        'Reason'   => $b['Reason'],
                        'Ref'      => isset($b['Ref']) ? $this->Num($b['Ref']) . ' (' . date('d.m.Y H:i:s', $b['RefTime']) . ')' : ''
                    ];
                }
            }
            if ($records !== []) {
                $r['oldest'] = $records[count($records) - 1]['TimeStamp'];
                $r['newest'] = max($r['newest'], $records[0]['TimeStamp']);
            }

            if (!$job['dry']) {
                if ($job['mode'] === 'migrate' && $ok !== []) {
                    $r['written'] += $this->ArchiveWrite($archive, $r, $ok);
                }
                if ($job['mode'] === 'clean') {
                    foreach ($bad as $b) {
                        $r['written'] += AC_DeleteVariableData($archive, $r['targetID'], $b['TimeStamp'], $b['TimeStamp']);
                    }
                }
            }

            if ($records !== []) {
                $r['cursor'] = $records[count($records) - 1]['TimeStamp'] - 1;
            }
            if ($finished || $r['cursor'] <= 0) {
                if (!$job['dry'] && $r['written'] > 0) {
                    AC_ReAggregateVariable($archive, $r['targetID']);
                }
                $job['row']++;
            }
            unset($r);
        }

        if ($job['row'] >= count($job['rows'])) {
            $text = $this->ArchiveProgressText($job);
            $this->WriteAttributeString('ArchiveJob', '');
            $this->WriteAttributeString('ArchiveReport', $text);
            $this->UpdateFormField('ArchiveResult', 'caption', $text);
            $this->UpdateFormField('ArchiveOutliers', 'values', json_encode($this->ArchiveOutlierRows()));
            $this->UpdateFormField('ArchiveListInfo', 'caption', $this->ArchiveListInfo());
            $this->WriteAttributeString('ArchiveOutliers', json_encode($job['outliers'] ?? []));
            $this->SetFlags($this->JobFlags($job));
            $fields = $this->CleanFields();
            if ($job['mode'] === 'migrate' && !$job['dry']) {
                $fields += $this->TransferFields();
            }
            foreach ($fields as $field => $params) {
                foreach ($params as $param => $value) {
                    $this->UpdateFormField($field, $param, is_array($value) ? json_encode($value) : $value);
                }
            }
            $this->SendDebug('Archiv', $text, 0);
            $this->LogMessage('Archiv-Werkzeug fertig: ' . str_replace("\n", ' | ', $text), KL_NOTIFY);
            return;
        }
        $this->WriteAttributeString('ArchiveJob', json_encode($job));
        $this->UpdateFormField('ArchiveResult', 'caption', $this->ArchiveProgressText($job));
        $this->SetTimerInterval('ArchiveJob', 500);
    }

    // ================================================================== Archiv-Werkzeug (intern)

    private function ArchiveID(): int
    {
        $list = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return $list ? (int) $list[0] : 0;
    }

    private function ArchiveRow(int $archive, string $target, int $targetID, int $source, float $factor, bool $invert, string $label): array
    {
        // Nur Werte übernehmen, die älter sind als der älteste vorhandene Wert der neuen Variable
        $cutoff = time();
        if (AC_GetLoggingStatus($archive, $targetID)) {
            $oldest = $this->OldestLogged($archive, $targetID);
            if ($oldest > 0) {
                $cutoff = $oldest;
            }
        }
        return [
            'label' => $label, 'target' => $target, 'targetID' => $targetID, 'source' => $source,
            'factor' => $factor, 'invert' => $invert, 'cutoff' => $cutoff, 'cursor' => $cutoff - 1,
            'state' => [], 'read' => 0, 'ok' => 0, 'bad' => 0, 'written' => 0,
            'examples' => [], 'reasons' => [], 'oldest' => 0, 'newest' => 0, 'loggingEnabled' => false
        ];
    }

    private function OldestLogged(int $archive, int $id): int
    {
        $end = 0;
        $oldest = 0;
        for ($i = 0; $i < 1000; $i++) {
            $page = AC_GetLoggedValues($archive, $id, 0, $end, 10000);
            if ($page === []) {
                break;
            }
            $oldest = (int) $page[count($page) - 1]['TimeStamp'];
            if (count($page) < 10000) {
                break;
            }
            $end = $oldest - 1;
        }
        return $oldest;
    }

    private function ArchiveStart(string $mode, bool $dry, array $rows, array $problems): bool
    {
        if ($this->ReadAttributeString('ArchiveJob') !== '') {
            return $this->ArchiveMessage('Es läuft bereits ein Auftrag. Erst abwarten oder „Abbrechen“.');
        }
        $job = ['mode' => $mode, 'dry' => $dry, 'rows' => $rows, 'row' => 0, 'started' => time(), 'problems' => $problems,
            'outliers' => ['real' => [], 'late' => []]];
        $this->WriteAttributeString('ArchiveJob', json_encode($job));
        $this->UpdateFormField('ArchiveResult', 'caption', $this->ArchiveProgressText($job));
        $this->SetFlags([]);
        $this->SetTimerInterval('ArchiveJob', 200);
        return true;
    }

    /** Werte in die neue Variable schreiben; Archivierung dafür einschalten (Zähler mit Aggregation „Zähler“). */
    private function ArchiveWrite(int $archive, array &$row, array $records): int
    {
        if (!AC_GetLoggingStatus($archive, $row['targetID'])) {
            AC_SetLoggingStatus($archive, $row['targetID'], true);
            if (($this->ArchiveDef($row['target'])['kind'] ?? '') === 'counter') {
                AC_SetAggregationType($archive, $row['targetID'], 1);
            }
            IPS_ApplyChanges($archive);
            $row['loggingEnabled'] = true;
        }
        $type = IPS_GetVariable($row['targetID'])['VariableType'];
        $values = [];
        foreach ($records as $rec) {
            $v = $rec['Value'];
            switch ($type) {
                case VARIABLETYPE_BOOLEAN:
                    $v = $v >= 1;
                    break;
                case VARIABLETYPE_INTEGER:
                    $v = (int) round($v);
                    break;
                default:
                    $v = (float) $v;
            }
            $values[] = ['TimeStamp' => (int) $rec['TimeStamp'], 'Value' => $v];
        }
        if (!AC_AddLoggedValues($archive, $row['targetID'], $values)) {
            $row['errors'] = ($row['errors'] ?? 0) + 1;
            return 0;
        }
        return count($values);
    }

    /** Prüfregeln für eine Modul-Variable (auch für berechnete Werte). */
    private function ArchiveDef(string $ident): array
    {
        $defs = HPVRegisters::Definitions();
        if (isset($defs[$ident])) {
            return $defs[$ident];
        }
        $derived = HPVRegisters::Derived()[$ident] ?? ['pres' => ''];
        switch ($derived['pres']) {
            case 'W':
                return ['min' => '-P', 'max' => 'P', 'kind' => 'gauge'];
            case 'kWh':
                return ['min' => 0, 'max' => 1000, 'kind' => 'daily'];
            case 'pct':
            case 'pct1':
                return ['min' => 0, 'max' => 100, 'kind' => 'gauge'];
            default:
                return ['kind' => 'gauge'];
        }
    }

    /** Formularfelder der Übernahme (Grundzustand): Auswahl der neuen Variable mit Einheit und Archiv-Hinweis */
    private function TransferFields(): array
    {
        $archive = $this->ArchiveID();
        $options = [['caption' => '– bitte wählen –', 'value' => '']];
        foreach (array_keys(HPVRegisters::Definitions() + HPVRegisters::Derived()) as $ident) {
            $id = $this->VarID($ident);
            if ($id === 0 || IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_STRING) {
                continue;
            }
            $info = [];
            $unit = trim($this->UnitSuffix($ident));
            if ($unit !== '') {
                $info[] = $unit;
            }
            if ($archive !== 0 && AC_GetLoggingStatus($archive, $id)) {
                $info[] = 'Archiv vorhanden';
            }
            $options[] = ['caption' => IPS_GetName($id) . ($info ? ' (' . implode(', ', $info) . ')' : ''), 'value' => $ident];
        }
        $convert = [];
        foreach (self::CONVERSIONS as $key => [$caption, $factor]) {
            $convert[] = ['caption' => $caption, 'value' => $key];
        }
        $convert[] = ['caption' => 'eigener Faktor …', 'value' => 'custom'];
        return [
            'MigSource'  => ['value' => 0],
            'MigTarget'  => ['options' => $options, 'value' => ''],
            'MigConvert' => ['options' => $convert, 'value' => 'same'],
            'MigFactor'  => ['value' => 1, 'visible' => false],
            'MigInvert'  => ['value' => false]
        ];
    }

    /** Einheit einer Modul-Variable für Anzeigen (z. B. „ W“), leer wenn keine */
    private function UnitSuffix(string $ident): string
    {
        $pres = (HPVRegisters::Definitions() + HPVRegisters::Derived())[$ident]['pres'] ?? '';
        $p = $pres === '' ? null : $this->Presentation($pres);
        return is_array($p) && isset($p['SUFFIX']) ? $p['SUFFIX'] : '';
    }

    /**
     * Kontrolle nach der Übernahme (unten im Archiv-Werkzeug): nur Modul-Variablen, die schon ein Archiv haben.
     * Ohne solche Variablen bleibt die Zeile ausgeblendet und ein Hinweis steht da.
     */
    private function CleanFields(): array
    {
        $archive = $this->ArchiveID();
        $options = [];
        if ($archive !== 0) {
            foreach (array_keys(HPVRegisters::Definitions() + HPVRegisters::Derived()) as $ident) {
                $id = $this->VarID($ident);
                if ($id === 0 || IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_STRING || !AC_GetLoggingStatus($archive, $id)) {
                    continue;
                }
                $options[] = ['caption' => IPS_GetName($id), 'value' => $ident];
            }
        }
        $visible = $options !== [];
        $hint = $visible
            ? 'Archiv einer neuen Variable auf Ausreißer prüfen – nach einer sauberen Übernahme sollten es 0 sein.'
            : 'Erst verfügbar, wenn eine neue Variable ein Archiv hat (nach „Übernahme starten“).';
        return [
            'CleanHint'   => ['caption' => $hint],
            'CleanTarget' => ['options' => $visible ? $options : [['caption' => '–', 'value' => '']], 'value' => $options[0]['value'] ?? '', 'visible' => $visible],
            'CleanCheck'  => ['visible' => $visible],
            'CleanDelete' => ['visible' => $visible]
        ];
    }

    private function ArchiveProgressText(array $job): string
    {
        $what = $job['mode'] === 'migrate'
            ? ($job['dry'] ? 'Übernahme – VORSCHAU (es wird nichts geändert)' : 'Übernahme')
            : ($job['dry'] ? 'Archiv prüfen – VORSCHAU (es wird nichts gelöscht)' : 'Ausreißer löschen');
        $lines = [$what . ', gestartet ' . date('d.m.Y H:i', $job['started'])];
        foreach ($job['rows'] as $i => $r) {
            $state = $i < $job['row'] ? 'fertig' : ($i === $job['row'] ? 'läuft' : 'wartet');
            $range = $r['oldest'] > 0 ? sprintf(' (%s – %s)', date('d.m.Y', $r['oldest']), date('d.m.Y', $r['newest'])) : '';
            $source = $job['mode'] === 'migrate' ? '„' . IPS_GetName($r['source']) . '“ → ' : '';
            $line = sprintf('• %s%s: %d Werte gelesen%s, %d gültig, %d Ausreißer', $source, IPS_GetName($r['targetID']),
                $r['read'], $range, $r['ok'], $r['bad']);
            if (!$job['dry']) {
                $line .= $job['mode'] === 'migrate' ? sprintf(', %d übernommen', $r['written']) : sprintf(', %d gelöscht', $r['written']);
            }
            $line .= ' – ' . $state;
            if (!empty($r['loggingEnabled'])) {
                $line .= ' (Archivierung der neuen Variable eingeschaltet)';
            }
            if (!empty($r['errors'])) {
                $line .= ' – ACHTUNG: ' . $r['errors'] . '× Schreibfehler';
            }
            $lines[] = $line;
            if ($job['mode'] === 'migrate' && isset($r['sample'])) {
                $new = $r['sample'] * $r['factor'] * ($r['invert'] ? -1 : 1);
                $lines[] = sprintf('    Beispiel (neuester Wert): alt %s → neu %s%s', $this->Num($r['sample']), $this->Num($new), $this->UnitSuffix($r['target']));
            }
            $reasons = [];
            foreach ($r['reasons'] ?? [] as $reason => $n) {
                $reasons[] = $n . '× ' . $reason . ($reason === HPVArchive::REASON_LATE ? ' (Endstand des Vortags mit Zeitstempel kurz nach 0 Uhr – unbedenklich)' : '');
            }
            if ($reasons !== []) {
                $lines[] = '    Gründe: ' . implode(', ', $reasons);
            }
            if (!empty($r['examples'])) {
                $lines[] = '    Beispiele echter Ausreißer (alle: Liste unten):';
            }
            foreach ($r['examples'] as $example) {
                $lines[] = '    – ' . $example;
            }
        }
        foreach ($job['problems'] ?? [] as $problem) {
            $lines[] = 'Übersprungen: ' . $problem;
        }
        return implode("\n", $lines);
    }

    private function Num(float $value): string
    {
        // genug Stellen, damit Rundungsreste sichtbar sind (z. B. 100,0000015)
        return str_replace('.', ',', (string) round($value, 7));
    }

    private const OUTLIER_LIST_MAX = 1000;

    /** Zeilen für die Liste im Formular (letzter Auftrag): echte Ausreißer zuerst, höchstens 1000 */
    private function ArchiveOutlierRows(): array
    {
        $list = json_decode($this->ReadAttributeString('ArchiveOutliers'), true);
        if (!is_array($list)) {
            return [];
        }
        return array_slice(array_merge($list['real'] ?? [], $list['late'] ?? []), 0, self::OUTLIER_LIST_MAX);
    }

    private function ArchiveListInfo(): string
    {
        return 'Echte Ausreißer zuerst, höchstens ' . self::OUTLIER_LIST_MAX . '. Die Gründe stehen gezählt im Ergebnis.';
    }

    /** Hervorhebung unter dem Ergebnis: rot = Warnung, grün = alles gut */
    private function JobFlags(array $job): array
    {
        $warn = [];
        $read = 0;
        $bad = 0;
        $written = 0;
        foreach ($job['rows'] as $r) {
            $read += $r['read'];
            $bad += $r['bad'];
            $written += $r['written'];
            $outside = 0;
            foreach ($r['reasons'] ?? [] as $reason => $n) {
                if (strpos($reason, 'außerhalb der Grenzen') === 0) {
                    $outside += $n;
                }
            }
            if ($r['read'] > 0 && $outside > max(10, $r['read'] * 0.05)) {
                $warn[] = 'ACHTUNG: sehr viele Werte außerhalb der Grenzen – Umrechnung / Faktor prüfen!';
            }
            if (!empty($r['errors'])) {
                $warn[] = 'ACHTUNG: ' . $r['errors'] . '× Schreibfehler im Archiv';
            }
        }
        if ($warn !== []) {
            return ['warn' => implode("\n", array_unique($warn))];
        }
        if ($read === 0) {
            return ['warn' => 'Keine Werte im Archiv gefunden.'];
        }
        if ($job['mode'] === 'clean') {
            return $bad === 0 ? ['ok' => '✓ Keine Ausreißer – das Archiv ist sauber.'] : [];
        }
        if (!$job['dry']) {
            return ['ok' => sprintf('✓ Übernahme fertig: %d Werte übernommen.', $written)];
        }
        return ['ok' => '✓ Vorschau fertig – wenn alles passt: „Übernahme starten“.'];
    }

    private function SetFlags(array $flags): void
    {
        $this->WriteAttributeString('ArchiveFlags', json_encode($flags));
        foreach ($this->FlagFields($flags) as $field => $params) {
            foreach ($params as $param => $value) {
                $this->UpdateFormField($field, $param, $value);
            }
        }
    }

    private function FlagFields(array $flags): array
    {
        return [
            'ArchiveWarn' => ['caption' => $flags['warn'] ?? '', 'visible' => ($flags['warn'] ?? '') !== ''],
            'ArchiveOk'   => ['caption' => $flags['ok'] ?? '', 'visible' => ($flags['ok'] ?? '') !== '']
        ];
    }

    private function ArchiveMessage(string $text): bool
    {
        $this->UpdateFormField('ArchiveResult', 'caption', '');
        $this->SetFlags(['warn' => $text]);
        $this->SendDebug('Archiv', $text, 0);
        return false;
    }

    // ================================================================== Abfrage

    private function RunCycle(bool $all): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }
        if (!$this->HasActiveParent()) {
            $this->SetStatusIfChanged(202);
            $this->SendDebug('Abfrage', 'Gateway/Schnittstelle nicht aktiv – übersprungen', 0);
            return false;
        }

        $key = 'HPV_Poll_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, $all ? 30000 : 0)) {
            $this->SendDebug('Abfrage', 'Vorheriger Durchlauf läuft noch – übersprungen', 0);
            return false;
        }
        try {
            return $this->DoCycle($all);
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    private function DoCycle(bool $all): bool
    {
        $now = time();
        $groups = $all ? HPVRegisters::GROUPS : $this->DueGroups($now);
        $defs = array_filter($this->ActiveDefinitions(), function ($d) use ($groups) {
            return in_array($d['group'], $groups, true);
        });
        if (count($defs) === 0) {
            return true;
        }
        // Schnappschuss zuerst und direkt nacheinander, danach alles andere
        $snapDefs = array_intersect_key($defs, array_flip(HPVRegisters::SNAPSHOT));
        $restDefs = array_diff_key($defs, $snapDefs);
        $merge = $this->ReadAttributeString('SnapshotMode') !== 'split';
        $snapBlocks = HPVRegisters::BuildBlocks($snapDefs, $merge ? HPVRegisters::SNAPSHOT_GAP : 0);
        foreach ($snapBlocks as &$b) {
            $b['snapshot'] = true;
        }
        unset($b);
        $blocks = array_merge($snapBlocks, HPVRegisters::BuildBlocks($restDefs));
        $this->SendDebug('Abfrage', 'Gruppen: ' . implode(', ', $groups) . ' – ' . count($blocks) . ' Anfragen (davon '
            . count($snapBlocks) . ' Schnappschuss)', 0);
        $snapStart = 0.0;
        $snapEnd = 0.0;
        $snapRaw = [];

        $diag = $this->Diag();
        $state = $this->FilterState();
        $updated = [];
        $okBlocks = 0;
        $failedInRow = 0;
        $start = microtime(true);
        $pause = max(0, $this->ReadPropertyInteger('RequestPause'));
        $first = true;

        while (($block = array_shift($blocks)) !== null) {
            if (microtime(true) - $start > self::CYCLE_BUDGET) {
                $this->SendDebug('Abfrage', 'Zeitbudget erschöpft – restliche Anfragen im nächsten Durchlauf', 0);
                break;
            }
            if (!$first && $pause > 0) {
                IPS_Sleep($pause);
            }
            $first = false;

            $isSnap = !empty($block['snapshot']);
            if ($isSnap && $snapStart === 0.0) {
                $snapStart = microtime(true);
            }
            $res = $this->ReadWithRetry($block['addr'], $block['qty'], $diag);
            if ($isSnap) {
                $snapEnd = microtime(true);
            }
            if (!$res['ok'] && $isSnap && $res['kind'] === 'error' && $this->HasGap($block, $defs)) {
                // Gerät kennt ein Register in der Lücke nicht → ab jetzt einzeln lesen (gleich in diesem Durchlauf)
                if ($this->ReadAttributeString('SnapshotMode') !== 'split') {
                    $this->WriteAttributeString('SnapshotMode', 'split');
                }
                $this->SendDebug('Schnappschuss', 'Zusammengefasster Block ' . $block['addr'] . ' ×' . $block['qty']
                    . ' abgelehnt – lese ab jetzt einzeln', 0);
                $split = HPVRegisters::BuildBlocks(array_intersect_key($defs, array_flip($block['idents'])));
                foreach (array_reverse($split) as $sb) {
                    $sb['snapshot'] = true;
                    array_unshift($blocks, $sb);
                }
                continue;
            }
            if (!$res['ok']) {
                $failedInRow++;
                if ($failedInRow >= 2 && $res['kind'] === 'timeout') {
                    $this->SendDebug('Abfrage', 'Zwei Blöcke in Folge ohne Antwort – Durchlauf abgebrochen', 0);
                    break;
                }
                continue;
            }
            $failedInRow = 0;
            $okBlocks++;

            foreach ($block['idents'] as $ident) {
                $def = $defs[$ident];
                $len = HPVRegisters::Length($def);
                $value = HPVRegisters::Decode($def, array_slice($res['regs'], $def['addr'] - $block['addr'], $len));
                if ($this->Accept($ident, $def, $value, $state, $diag, $now)) {
                    $updated[$ident] = true;
                    if ($isSnap) {
                        $snapRaw[$ident] = (float) $this->RawVal($ident);
                    }
                }
            }
        }

        $this->ApplyDerived($updated);
        if ($snapStart > 0.0) {
            $this->StoreSnapshot($snapRaw, $snapStart, $snapEnd, $snapDefs, $diag);
        }
        $this->SyncForceState($updated, $now);
        $this->UpdateEnergyBalance($updated, $now, in_array('medium', $groups, true));
        if (isset($updated['BatteryMaxChargeLimit']) || isset($updated['BatteryMaxDischargeLimit']) || isset($updated['RatedPower'])) {
            // Gerätegrenzen bestimmen die Schieberegler
            $this->RefreshControlPresentation();
        }
        $this->WriteFilterState($state);

        $success = $okBlocks > 0;
        if ($success) {
            $diag['failedCycles'] = 0;
            $diag['lastSuccess'] = time();
            $this->MarkGroupsRun($groups, $now);
            $this->SetStatusIfChanged(102);
        } else {
            $diag['failedCycles']++;
            if ($diag['failedCycles'] >= 3) {
                $this->SetStatusIfChanged(201);
            }
        }
        $this->WriteDiag($diag);
        $this->UpdateDiagnosticVariables($diag, $success);

        if (!$success) {
            $this->CheckReconnect($diag);
        }
        return $success;
    }

    /**
     * Schnappschuss speichern: PV, Wirkleistung, Netz, Akku aus einem Durchlauf, immer im Huawei-Vorzeichen
     * (Netz + = Einspeisung, Akku + = Laden). Bilanz PV − Netz − Akku − Haus prüfen und Abweichungen in der Diagnose zählen.
     */
    private function StoreSnapshot(array $raw, float $start, float $end, array $snapDefs, array &$diag): void
    {
        $hasBattery = isset($snapDefs['BatteryPower']);
        $pv = $raw['PVPower'] ?? null;
        $ac = $raw['ActivePower'] ?? null;
        $grid = $raw['GridPower'] ?? null;
        $battery = $hasBattery ? ($raw['BatteryPower'] ?? ($this->BatteryIdle() ? 0.0 : null)) : 0.0;
        $soc = $hasBattery && isset($raw['BatterySOC']) ? $raw['BatterySOC'] : null;
        $house = ($ac !== null && $grid !== null) ? round(max(0.0, $ac - $grid), 1) : null;
        $complete = $pv !== null && $ac !== null && $grid !== null && $battery !== null;
        $deviation = $complete ? $pv - $grid - $battery - $house : null;
        $ok = $complete && abs($deviation) <= self::BALANCE_TOLERANCE_W + 0.05 * max(0.0, $pv);
        $dur = (int) round(($end - $start) * 1000);
        $sinceWrite = $diag['lastWriteTime'] > 0 ? max(0, (int) floor($start) - (int) $diag['lastWriteTime']) : null;
        $snapshot = [
            't' => (int) floor($start), 'pv' => $pv, 'pvAc' => $ac, 'grid' => $grid, 'battery' => $hasBattery ? $battery : null,
            'soc' => $soc, 'house' => $house, 'ok' => $ok, 'dur' => $dur, 'sinceWrite' => $sinceWrite
        ];
        $this->SetBuffer('Snapshot', json_encode($snapshot));
        $this->SendDebug('Schnappschuss', sprintf('%d ms – PV %s W, AC %s W, Netz %s W, Akku %s W, Haus %s W, Abweichung %s W%s',
            $dur, $this->Show($pv), $this->Show($ac), $this->Show($grid), $this->Show($battery), $this->Show($house),
            $this->Show($deviation), $ok ? '' : ' – NICHT plausibel'), 0);
        if ($complete && !$ok) {
            $diag['balanceBad']++;
            $diag['lastBalance'] = sprintf('%s – %d W (PV %d, AC %d, Netz %d, Akku %d, Haus %d)', date('d.m. H:i:s', (int) $start),
                (int) round($deviation), (int) round($pv), (int) round($ac), (int) round($grid), (int) round($battery), (int) round($house));
            // Die letzten 20 für die Diagnose – mit Abstand zum letzten Schreibbefehl, um Zusammenhänge zu erkennen
            $diag['balanceLog'][] = $diag['lastBalance'] . ($sinceWrite === null ? '' : sprintf(' · %s nach Schreibbefehl', $this->Ago($sinceWrite)));
            $diag['balanceLog'] = array_slice($diag['balanceLog'], -self::BALANCE_LOG_MAX);
        }
        if ($complete) {
            $diag['snapshots']++;
            $diag['snapshotMs'] += $dur;
        }
    }

    /** Enthält der Block mitgelesene Register, die zu keinem Wert gehören (Lücke im Schnappschuss)? */
    private function HasGap(array $block, array $defs): bool
    {
        $len = 0;
        foreach ($block['idents'] as $ident) {
            $len += HPVRegisters::Length($defs[$ident]);
        }
        return $block['qty'] > $len;
    }

    private const BALANCE_LOG_MAX = 20;

    private function Ago(int $seconds): string
    {
        if ($seconds < 120) {
            return $seconds . ' s';
        }
        if ($seconds < 7200) {
            return round($seconds / 60) . ' min';
        }
        return round($seconds / 3600) . ' h';
    }

    /** Erlaubte Abweichung der Bilanz PV − Netz − Akku − Haus: 500 W + 5 % der PV-Leistung (Wechselrichterverluste). */
    private const BALANCE_TOLERANCE_W = 500;

    private function Show(?float $v): string
    {
        return $v === null ? '–' : (string) round($v);
    }

    /**
     * Liest einen Block, wiederholt bei BUSY/Timeout mit wachsender Wartezeit.
     * @return array ['ok' => bool, 'regs' => int[], 'kind' => string]
     */
    private function ReadWithRetry(int $addr, int $qty, array &$diag): array
    {
        $maxRetries = max(0, $this->ReadPropertyInteger('MaxRetries'));
        $timeouts = 0;
        $attempt = 0;
        while (true) {
            $diag['requests']++;
            $res = $this->ReadRegisters($addr, $qty);
            if ($res['ok']) {
                return $res;
            }
            $diag['errors']++;
            if ($res['kind'] === 'busy') {
                $diag['busy']++;
            } elseif ($res['kind'] === 'timeout') {
                $diag['timeouts']++;
                $timeouts++;
            }
            $diag['lastError'] = date('d.m. H:i:s') . ' – Register ' . $addr . ' ×' . $qty . ': ' . $res['message'];
            $this->SendDebug('Fehler', $diag['lastError'], 0);

            // Timeouts kosten jeweils die Wartezeit des Gateways → höchstens einmal wiederholen
            if ($attempt >= $maxRetries || ($res['kind'] === 'timeout' && $timeouts > 1) || $res['kind'] === 'error') {
                return $res;
            }
            $attempt++;
            $diag['retries']++;
            $wait = min(4000, 500 * (2 ** ($attempt - 1)));
            $this->SendDebug('Wiederholung', 'Versuch ' . ($attempt + 1) . ' in ' . $wait . ' ms', 0);
            IPS_Sleep($wait);
        }
    }

    /**
     * Eine Anfrage (Funktion 03) über das ModBus Gateway.
     * @return array ['ok' => bool, 'regs' => int[], 'kind' => 'ok'|'busy'|'timeout'|'error', 'message' => string]
     */
    private function ReadRegisters(int $addr, int $qty): array
    {
        $request = json_encode([
            'DataID'   => self::GATEWAY_TX,
            'Function' => 3,
            'Address'  => $addr,
            'Quantity' => $qty,
            'Data'     => ''
        ]);

        $lock = $this->BusLock();
        if (!IPS_SemaphoreEnter($lock, 15000)) {
            return ['ok' => false, 'regs' => [], 'kind' => 'busy', 'message' => 'Gateway belegt (Semaphore)'];
        }
        $error = '';
        set_error_handler(function ($no, $str) use (&$error) {
            $error = $str;
            return true;
        });
        try {
            $response = $this->SendDataToParent($request);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $response = false;
        } finally {
            restore_error_handler();
            IPS_SemaphoreLeave($lock);
        }

        if (!is_string($response) || $response === '') {
            $msg = $error !== '' ? $error : 'keine Antwort';
            return ['ok' => false, 'regs' => [], 'kind' => $this->ClassifyError($msg), 'message' => $msg];
        }

        $data = $this->ExtractPayload($response, $qty);
        if ($data === null) {
            $this->SendDebug('Antwort ' . $addr, $response, 1);
            $msg = 'unerwartete Antwortlänge ' . strlen($response) . ' Byte (erwartet ' . ($qty * 2) . ')';
            return ['ok' => false, 'regs' => [], 'kind' => 'error', 'message' => $msg];
        }
        $this->SendDebug('Antwort ' . $addr . ' ×' . $qty, bin2hex($data), 0);
        return ['ok' => true, 'regs' => array_values(unpack('n*', $data)), 'kind' => 'ok', 'message' => ''];
    }

    /** Liefert genau qty*2 Datenbytes oder null. Je nach Kernel enthält die Antwort Funktionscode und Byteanzahl. */
    private function ExtractPayload(string $response, int $qty): ?string
    {
        $expected = $qty * 2;
        foreach ([$response, mb_convert_encoding($response, 'ISO-8859-1', 'UTF-8')] as $candidate) {
            $len = strlen($candidate);
            if ($len === $expected + 2) {
                return substr($candidate, 2);
            }
            if ($len === $expected + 1) {
                return substr($candidate, 1);
            }
            if ($len === $expected) {
                return $candidate;
            }
        }
        return null;
    }

    private function ClassifyError(string $msg): string
    {
        if (stripos($msg, 'BUSY') !== false) {
            return 'busy';
        }
        if (stripos($msg, 'Zeitüberschreitung') !== false || stripos($msg, 'timeout') !== false
            || stripos($msg, 'TransactionID') !== false) {
            return 'timeout';
        }
        if (stripos($msg, 'keine Antwort') !== false) {
            return 'timeout';
        }
        return 'error';
    }

    // ================================================================== Werte prüfen und schreiben

    private function Accept(string $ident, array $def, $value, array &$state, array &$diag, int $now): bool
    {
        if ($value === null) {
            if (($def['opt'] ?? '') === 'battery' && $this->BatteryIdle()) {
                // Akku im Ruhemodus/Standby/Offline: Huawei meldet „kein Wert“ – das ist kein Fehler.
                if ($ident !== 'BatteryPower') {
                    $this->SendDebug('Akku ruht', $ident . ': kein Wert (letzter Wert bleibt)', 0);
                    return false;
                }
                $value = 0.0;   // es fließt nichts
            } elseif ($ident === 'Efficiency') {
                // Ohne ausreichende PV-Leistung (morgens/abends) liefert Huawei keinen Wirkungsgrad – kein Fehler
                $this->SendDebug('Kein Wirkungsgrad', 'Wechselrichter meldet keinen Wirkungsgrad (letzter Wert bleibt)', 0);
                return false;
            } else {
                $this->Reject($ident, $def, 'ungültiger Rohwert (Huawei-Kennung)', $diag);
                return false;
            }
        }
        if ($def['type'] === 'STR') {
            if ($value === '') {
                return false;
            }
            $this->SetValueIfChanged($ident, $value);
            return true;
        }

        if (!isset($state[$ident])) {
            $state[$ident] = $this->SeedState($ident, $def);
        }
        $reason = '';
        [$ok, $newState] = HPVFilter::Check($def, (float) $value, $state[$ident], $now,
            max(1, $this->ReadPropertyInteger('ConfirmReads')), (float) $this->ReadPropertyInteger('PowerLimit'), $reason);
        $state[$ident] = $newState;
        if (!$ok) {
            $this->Reject($ident, $def, $reason . ' (Wert ' . $value . ')', $diag);
            return false;
        }

        switch ($def['vt']) {
            case VARIABLETYPE_BOOLEAN:
                $this->SetValueIfChanged($ident, $value >= 1);
                break;
            case VARIABLETYPE_INTEGER:
                $this->SetValueIfChanged($ident, (int) round($value));
                break;
            default:
                // Filter und Berechnungen arbeiten mit dem Huawei-Rohwert, erst hier wird das Vorzeichen angewendet
                $signed = round((float) $value * $this->SignFactor($ident), 3);
                $this->SetValueIfChanged($ident, $signed == 0.0 ? 0.0 : $signed);   // keine „−0 W“
                break;
        }
        return true;
    }

    /** Akku meldet Offline (0), Standby (1) oder Ruhemodus (4) */
    private function BatteryIdle(): bool
    {
        return $this->HasIdent('BatteryStatus') && in_array((int) $this->GetVal('BatteryStatus'), [0, 1, 4], true);
    }

    /** Filterzustand aus der bestehenden Variable vorbelegen (z. B. nach Neustart), damit auch der erste Wert geprüft wird. */
    private function SeedState(string $ident, array $def): ?array
    {
        if (!in_array($def['kind'] ?? 'gauge', ['counter', 'daily'], true) && !isset($def['step'])) {
            return null;
        }
        $id = $this->VarID($ident);
        if (!$id) {
            return null;
        }
        $var = IPS_GetVariable($id);
        $updated = (int) ($var['VariableUpdated'] ?? 0);
        if ($updated <= 0) {
            return null;
        }
        return ['v' => (float) GetValue($id), 't' => $updated, 'd' => date('Y-m-d', $updated)];
    }

    private function Reject(string $ident, array $def, string $reason, array &$diag): void
    {
        $diag['rejected']++;
        $diag['lastRejected'] = date('d.m. H:i:s') . ' – ' . $def['name'] . ': ' . $reason;
        $this->SendDebug('Verworfen', $ident . ': ' . $reason, 0);
    }

    private function ApplyDerived(array &$updated): void
    {
        if (isset($updated['ActivePower']) && isset($updated['GridPower']) && $this->HasIdent('HouseConsumption')) {
            $house = $this->GetVal('ActivePower') - $this->RawVal('GridPower');
            $this->SetValueIfChanged('HouseConsumption', round(max(0.0, $house), 1));
            $updated['HouseConsumption'] = true;
        }
        if (isset($updated['GridPower']) && $this->HasIdent('GridFeedIn')) {
            $grid = $this->RawVal('GridPower');
            $this->SetValueIfChanged('GridFeedIn', max(0.0, $grid));
            $this->SetValueIfChanged('GridImport', max(0.0, -$grid));
        }
        if (isset($updated['DeviceStatus']) && $this->HasIdent('InverterOn')) {
            $status = (int) $this->GetVal('DeviceStatus');
            $on = !($status >= 0x0300 && $status <= 0x03FF);
            $pending = json_decode($this->GetBuffer('InverterPending'), true);
            if (is_array($pending) && time() < $pending['until'] && $on !== $pending['on']) {
                // Befehl läuft noch, Vorgabe stehen lassen
            } else {
                $this->SetBuffer('InverterPending', '');
                $this->SetValueIfChanged('InverterOn', $on);
            }
        }
        foreach (['PV1', 'PV2'] as $s) {
            if ((isset($updated[$s . 'Voltage']) || isset($updated[$s . 'Current'])) && $this->HasIdent($s . 'Power')) {
                $p = (float) $this->GetVal($s . 'Voltage') * (float) $this->GetVal($s . 'Current');
                $this->SetValueIfChanged($s . 'Power', round(max(0.0, $p), 1));
            }
        }
    }

    /** Tagesbilanz fortschreiben und Autarkie/Eigenverbrauch setzen (Option „Autarkie und Eigenverbrauch“). */
    private function UpdateEnergyBalance(array $updated, int $now, bool $persist): void
    {
        if (!$this->OptionEnabled('autarky')) {
            return;
        }
        $stats = json_decode($this->GetBuffer('DayStats'), true);
        if (!is_array($stats)) {
            // nach Neustart aus dem Attribut fortsetzen
            $stats = json_decode($this->ReadAttributeString('DayStats'), true);
            if (!is_array($stats)) {
                $stats = [];
            }
        }
        $pick = function (string $ident) use ($updated): ?float {
            return isset($updated[$ident]) ? (float) $this->GetVal($ident) : null;
        };
        $stats = HPVEnergy::Update($stats, $now, $pick('PVPower'), $pick('HouseConsumption'),
            $pick('GridImportTotal'), $pick('GridExportTotal'));
        $this->SetBuffer('DayStats', json_encode($stats));
        if ($persist) {
            $this->WriteAttributeString('DayStats', json_encode($stats));
        }

        $r = HPVEnergy::Results($stats);
        $this->SetValueIfChanged('HouseEnergyToday', round($r['house'], 2));
        if ($r['import'] !== null) {
            $this->SetValueIfChanged('GridImportToday', round($r['import'], 2));
        }
        if ($r['export'] !== null) {
            $this->SetValueIfChanged('GridExportToday', round($r['export'], 2));
        }
        if ($r['autarky'] !== null) {
            $this->SetValueIfChanged('SelfSufficiency', round($r['autarky'], 1));
        }
        if ($r['selfConsumption'] !== null) {
            $this->SetValueIfChanged('SelfConsumptionRate', round($r['selfConsumption'], 1));
        }
    }

    // ================================================================== Steuerung (intern)

    /** Prüft die Freigabe; $what: 'settings' | 'battery' | 'inverter'. */
    private function ControlAllowed(string $what): bool
    {
        $error = '';
        if (!$this->ReadPropertyBoolean('AllowControl')) {
            $error = 'Steuerung ist gesperrt (Formular: „Steuerung erlauben“).';
        } elseif ($what === 'inverter' && !$this->ReadPropertyBoolean('AllowInverterSwitch')) {
            $error = 'Ein-/Ausschalten des Wechselrichters ist gesperrt (Formular: „Wechselrichter Ein/Aus erlauben“).';
        } elseif ($what === 'battery' && !$this->ReadPropertyBoolean('HasBattery')) {
            $error = 'Kein Akku eingerichtet.';
        } elseif (!$this->HasActiveParent()) {
            $error = 'ModBus Gateway bzw. Client Socket nicht aktiv.';
        }
        if ($error !== '') {
            $this->NoteWriteError($error);
            return false;
        }
        return true;
    }

    /** Erlaubter Bereich einer Einstellung [min, max]; Obergrenze ggf. aus den Gerätedaten. */
    private function ControlRange(string $ident): array
    {
        $c = HPVRegisters::Controls()[$ident];
        $max = $c['max'];
        if (is_string($max)) {
            $device = $this->HasIdent($max) ? (float) $this->GetVal($max) : 0.0;
            $max = $device > 0 ? $device : (float) $c['default_max'];
        }
        return [(float) $c['min'], (float) $max];
    }

    /** Bereiche der Vorgaben für Zwangsladen. */
    private function ForceLimits(): array
    {
        $charge = $this->HasIdent('BatteryMaxChargeLimit') ? (float) $this->GetVal('BatteryMaxChargeLimit') : 0.0;
        $discharge = $this->HasIdent('BatteryMaxDischargeLimit') ? (float) $this->GetVal('BatteryMaxDischargeLimit') : 0.0;
        $max = max($charge, $discharge);
        return [
            'ForcePower'     => [100.0, $max > 0 ? $max : 5000.0],
            'ForceDuration'  => [1, 1440],
            'ForceTargetSOC' => [0.0, 100.0]
        ];
    }

    /** Einstellung schreiben, zurücklesen, Variable aktualisieren. */
    private function WriteControl(string $ident, float $value): bool
    {
        $controls = HPVRegisters::Controls();
        $defs = HPVRegisters::Definitions();
        if (!isset($controls[$ident], $defs[$ident])) {
            $this->NoteWriteError('Unbekannte Einstellung ' . $ident);
            return false;
        }
        $def = $defs[$ident];
        $c = $controls[$ident];
        if (!$this->ControlAllowed($def['opt'] === 'powerctl' ? 'settings' : 'battery')) {
            return false;
        }
        if (isset($c['values'])) {
            if (!in_array((int) round($value), $c['values'], true)) {
                $this->NoteWriteError($def['name'] . ': Wert ' . $value . ' nicht erlaubt (' . implode(', ', $c['values']) . ')');
                return false;
            }
        } else {
            [$min, $max] = $this->ControlRange($ident);
            if ($value < $min || $value > $max) {
                $this->NoteWriteError(sprintf('%s: %s außerhalb %s … %s', $def['name'], $value, $min, $max));
                return false;
            }
        }

        $regs = HPVRegisters::Encode($def, $value);
        return $this->WithLock(function () use ($ident, $def, $regs, $value): bool {
            // Steht der Wert schon im Register, nichts senden (schont den Speicher des Wechselrichters)
            $diag = $this->Diag();
            $before = $this->ReadWithRetry($def['addr'], HPVRegisters::Length($def), $diag);
            if ($before['ok'] && $before['regs'] === $regs) {
                $diag['writesSkipped']++;
                $this->WriteDiag($diag);
                $this->SendDebug('Schreiben', $def['name'] . ' = ' . $value . ' steht bereits im Wechselrichter – nicht gesendet', 0);
                $current = HPVRegisters::Decode($def, $before['regs']);
                if ($current !== null) {
                    $this->StoreValue($ident, $def, $current);
                }
                return true;
            }
            $this->WriteDiag($diag);
            if (!$this->WriteRaw($def['addr'], $regs, $def['name'] . ' = ' . $value)) {
                return false;
            }
            // Zurücklesen (Huawei übernimmt manche Werte erst nach kurzer Zeit)
            $read = null;
            foreach ([700, 2000] as $wait) {
                IPS_Sleep($wait);
                $diag = $this->Diag();
                $res = $this->ReadWithRetry($def['addr'], HPVRegisters::Length($def), $diag);
                $this->WriteDiag($diag);
                if (!$res['ok']) {
                    continue;
                }
                $read = HPVRegisters::Decode($def, $res['regs']);
                if ($read !== null && abs($read - $value) <= 0.5 / $def['gain'] + 0.0001) {
                    break;
                }
            }
            if ($read === null || abs($read - $value) > 0.5 / $def['gain'] + 0.0001) {
                $this->NoteWriteError($def['name'] . ': geschrieben ' . $value . ', zurückgelesen ' . ($read === null ? '–' : $read)
                    . '. Der Wechselrichter hat den Wert nicht übernommen.');
                if ($read !== null) {
                    $this->StoreValue($ident, $def, $read);
                }
                return false;
            }
            $this->StoreValue($ident, $def, $read);
            return true;
        });
    }

    /** Wert nach erfolgreichem Schreiben in Variable und Filterzustand übernehmen. */
    private function StoreValue(string $ident, array $def, float $value): void
    {
        $state = $this->FilterState();
        $state[$ident] = ['v' => $value, 't' => time(), 'd' => date('Y-m-d')];
        $this->WriteFilterState($state);
        switch ($def['vt']) {
            case VARIABLETYPE_BOOLEAN:
                $this->SetValueIfChanged($ident, $value >= 1);
                break;
            case VARIABLETYPE_INTEGER:
                $this->SetValueIfChanged($ident, (int) round($value));
                break;
            default:
                $this->SetValueIfChanged($ident, round($value, 3));
        }
    }

    private function StartForced(int $mode, float $power, int $minutes, float $targetSoc): bool
    {
        if (!$this->ControlAllowed('battery')) {
            return false;
        }
        $limits = $this->ForceLimits();
        $checks = ['ForcePower' => $power, 'ForceDuration' => $minutes, 'ForceTargetSOC' => $targetSoc];
        foreach ($checks as $key => $v) {
            [$min, $max] = $limits[$key];
            if ($v < $min || $v > $max) {
                $this->NoteWriteError(sprintf('Zwangsladen: %s = %s außerhalb %s … %s', $key, $v, $min, $max));
                return false;
            }
        }
        if (!in_array($mode, [1, 2], true)) {
            $this->NoteWriteError('Zwangsladen: Modus muss 1 (Laden) oder 2 (Entladen) sein');
            return false;
        }

        $powerReg = $mode === 1 ? HPVRegisters::FORCE_POWER_CHARGE : HPVRegisters::FORCE_POWER_DISCHARGE;
        $u32 = ['type' => 'U32', 'gain' => 1];
        $ok = $this->WithLock(function () use ($powerReg, $u32, $power, $minutes, $targetSoc, $mode): bool {
            // Reihenfolge wie in der Huawei-Integration: Leistung, Dauer bzw. Ziel-SoC, Modus, Befehl
            if (!$this->WriteRaw($powerReg, HPVRegisters::Encode($u32, $power), 'Zwangsladen: Leistung ' . $power . ' W')) {
                return false;
            }
            if (!$this->WriteRaw(HPVRegisters::FORCE_PERIOD, [$minutes], 'Zwangsladen: Dauer ' . $minutes . ' min')) {
                return false;
            }
            if ($targetSoc > 0) {
                if (!$this->WriteRaw(HPVRegisters::FORCE_TARGET_SOC, [(int) round($targetSoc * 10)], 'Zwangsladen: bis ' . $targetSoc . ' %')) {
                    return false;
                }
            }
            if (!$this->WriteRaw(HPVRegisters::FORCE_SETTING_MODE, [$targetSoc > 0 ? 1 : 0], 'Zwangsladen: Modus ' . ($targetSoc > 0 ? 'Ladestand' : 'Dauer'))) {
                return false;
            }
            return $this->WriteRaw(HPVRegisters::FORCE_COMMAND, [$mode], $mode === 1 ? 'Zwangsladen starten' : 'Zwangsentladen starten');
        });
        if (!$ok) {
            return false;
        }

        $now = time();
        $end = $now + $minutes * 60;
        $this->WriteAttributeString('ForceState', json_encode(['mode' => $mode, 'start' => $now, 'end' => $end]));
        $this->SetValueIfChanged('ForceMode', $mode);
        $this->SetValueIfChanged('ForceEndTime', date('d.m.Y H:i', $end));
        $this->SetTimerInterval('ForceEnd', ($minutes * 60 + self::FORCE_GRACE) * 1000);
        $this->LogMessage(sprintf('%s gestartet: %s W, bis %s Uhr%s.', self::FORCE_MODE[$mode], $power, date('H:i', $end),
            $targetSoc > 0 ? ' bzw. bis ' . $targetSoc . ' %' : ''), KL_NOTIFY);
        return true;
    }

    private function ClearForceState(): void
    {
        $this->WriteAttributeString('ForceState', '');
        $this->SetTimerInterval('ForceEnd', 0);
        $this->SetValueIfChanged('ForceMode', 0);
        $this->SetValueIfChanged('ForceEndTime', '–');
    }

    /** Nach Neustart/ApplyChanges: Wächter für eine laufende Zwangsladung wieder stellen. */
    private function RestoreForceTimer(): void
    {
        $state = json_decode($this->ReadAttributeString('ForceState'), true);
        if (!is_array($state) || ($state['mode'] ?? 0) === 0) {
            $this->SetTimerInterval('ForceEnd', 0);
            return;
        }
        $remaining = (int) $state['end'] + self::FORCE_GRACE - time();
        $this->SetTimerInterval('ForceEnd', max(5, $remaining) * 1000);
    }

    /** Hat der Akku die Zwangsladung selbst beendet (Dauer/Ziel erreicht, App), Anzeige zurücksetzen. */
    private function SyncForceState(array $updated, int $now): void
    {
        if (!isset($updated['BatteryWorkingMode'])) {
            return;
        }
        $state = json_decode($this->ReadAttributeString('ForceState'), true);
        if (!is_array($state) || ($state['mode'] ?? 0) === 0) {
            return;
        }
        if ($now - (int) $state['start'] > 120 && (int) $this->GetVal('BatteryWorkingMode') !== 1) {
            $this->SendDebug('Steuerung', 'Akku meldet keine Zwangsladung mehr – Anzeige zurückgesetzt', 0);
            $this->ClearForceState();
        }
    }

    /** Führt eine Schreibfolge exklusiv aus (keine Abfrage dazwischen). */
    private function WithLock(callable $fn): bool
    {
        $key = 'HPV_Poll_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 30000)) {
            $this->NoteWriteError('Abfrage läuft noch – bitte erneut versuchen.');
            return false;
        }
        try {
            return (bool) $fn();
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    /** Register schreiben (Funktion 06 für ein Register, 16 für mehrere), mit Wiederholung bei BUSY. */
    private function WriteRaw(int $addr, array $regs, string $what): bool
    {
        $diag = $this->Diag();
        $maxRetries = max(0, $this->ReadPropertyInteger('MaxRetries'));
        $attempt = 0;
        while (true) {
            $diag['writes']++;
            $res = $this->WriteRegisters($addr, $regs);
            if ($res['ok']) {
                $diag['lastWrite'] = date('d.m. H:i:s') . ' – ' . $what . ' (Register ' . $addr . ')';
                $diag['lastWriteTime'] = time();
                $this->WriteDiag($diag);
                $this->SendDebug('Schreiben', $diag['lastWrite'], 0);
                return true;
            }
            $diag['errors']++;
            if ($attempt >= $maxRetries || !in_array($res['kind'], ['busy', 'timeout'], true) || ($res['kind'] === 'timeout' && $attempt >= 1)) {
                $this->WriteDiag($diag);
                $this->NoteWriteError($what . ' (Register ' . $addr . '): ' . $res['message']);
                return false;
            }
            $attempt++;
            $diag['retries']++;
            IPS_Sleep(min(4000, 500 * (2 ** ($attempt - 1))));
        }
    }

    private function WriteRegisters(int $addr, array $regs): array
    {
        $single = count($regs) === 1;
        $binary = '';
        foreach ($regs as $r) {
            $binary .= pack('n', $r);
        }
        $request = json_encode([
            'DataID'   => self::GATEWAY_TX,
            'Function' => $single ? 6 : 16,
            'Address'  => $addr,
            'Quantity' => count($regs),
            // Binärdaten im Datenfluss: UTF-8-kodiert (Symcon-Konvention für IPSModule)
            'Data'     => mb_convert_encoding($binary, 'UTF-8', 'ISO-8859-1')
        ]);
        $this->SendDebug('Schreibe ' . $addr, bin2hex($binary), 0);

        $lock = $this->BusLock();
        if (!IPS_SemaphoreEnter($lock, 15000)) {
            return ['ok' => false, 'kind' => 'busy', 'message' => 'Gateway belegt (Semaphore)'];
        }
        $error = '';
        set_error_handler(function ($no, $str) use (&$error) {
            $error = $str;
            return true;
        });
        try {
            $response = $this->SendDataToParent($request);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $response = false;
        } finally {
            restore_error_handler();
            IPS_SemaphoreLeave($lock);
        }
        if ($error !== '' || $response === false) {
            $msg = $error !== '' ? $error : 'keine Antwort';
            return ['ok' => false, 'kind' => $this->ClassifyError($msg), 'message' => $msg];
        }
        return ['ok' => true, 'kind' => 'ok', 'message' => ''];
    }

    private function NoteWriteError(string $msg): void
    {
        $diag = $this->Diag();
        $diag['lastWriteError'] = date('d.m. H:i:s') . ' – ' . $msg;
        $this->WriteDiag($diag);
        $this->SendDebug('Steuerung', $msg, 0);
        $this->LogMessage('Steuerung: ' . $msg, KL_WARNING);
        $this->SetBuffer('LastWriteError', $msg);
    }

    private function LastWriteError(): string
    {
        $msg = $this->GetBuffer('LastWriteError');
        return $msg !== '' ? $msg : 'Schreiben fehlgeschlagen';
    }

    // ================================================================== Gruppen / Zeitsteuerung

    private function DueGroups(int $now): array
    {
        $last = json_decode($this->GetBuffer('LastGroupRun'), true);
        if (!is_array($last)) {
            $last = [];
        }
        $due = ['fast'];
        $fast = max(5, $this->ReadPropertyInteger('PollFast'));
        $intervals = [
            'medium' => max($fast, $this->ReadPropertyInteger('PollMedium')),
            'slow'   => max($fast, $this->ReadPropertyInteger('PollSlow')),
            'static' => 86400
        ];
        foreach ($intervals as $group => $interval) {
            // kleine Toleranz, damit z. B. 60 s bei 10-s-Takt nicht auf 70 s rutscht
            if ($now - (int) ($last[$group] ?? 0) >= $interval - 2) {
                $due[] = $group;
            }
        }
        return $due;
    }

    private function MarkGroupsRun(array $groups, int $now): void
    {
        $last = json_decode($this->GetBuffer('LastGroupRun'), true);
        if (!is_array($last)) {
            $last = [];
        }
        foreach ($groups as $g) {
            // Fehlgeschlagene Blöcke werden im nächsten Intervall erneut versucht (Gerätedaten: am nächsten Tag)
            $last[$g] = $now;
        }
        $this->SetBuffer('LastGroupRun', json_encode($last));
    }

    // ================================================================== Verbindung neu aufbauen

    private function CheckReconnect(array &$diag): void
    {
        if (!$this->ReadPropertyBoolean('AutoReconnect')) {
            return;
        }
        if ($diag['failedCycles'] < max(2, $this->ReadPropertyInteger('ReconnectAfter'))) {
            return;
        }
        if (time() - $diag['lastReconnect'] < 600) {
            return;
        }
        $io = $this->IOID();
        if ($io <= 0 || !$this->HasOpenProperty($io)) {
            return;
        }
        $diag['lastReconnect'] = time();
        $diag['failedCycles'] = 0;
        $this->WriteDiag($diag);
        $this->LogMessage('Keine Antwort vom Wechselrichter – Verbindung (Schnittstelle #' . $io . ') wird neu aufgebaut.', KL_WARNING);
        IPS_SetProperty($io, 'Open', false);
        IPS_ApplyChanges($io);
        $this->SetTimerInterval('Reopen', 5000);
    }

    private function GatewayID(): int
    {
        return (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    /**
     * Sperre für den Datenverkehr: je Schnittstelle (Client Socket), nicht je Gateway. Bei kaskadierten Anlagen
     * hängen mehrere Gateways (Geräte-IDs) mit je einer Instanz am selben Smart Dongle – so fragt immer nur
     * eine Instanz gleichzeitig. Bei einer Anlage mit einem Wechselrichter ändert sich nichts.
     */
    private function BusLock(): string
    {
        $io = $this->IOID();
        return $io > 0 ? 'HPV_IO_' . $io : 'HPV_Gateway_' . $this->GatewayID();
    }

    private function IOID(): int
    {
        $gw = $this->GatewayID();
        if ($gw <= 0 || !IPS_InstanceExists($gw)) {
            return 0;
        }
        return (int) (IPS_GetInstance($gw)['ConnectionID'] ?? 0);
    }

    private function HasOpenProperty(int $io): bool
    {
        if (!IPS_InstanceExists($io)) {
            return false;
        }
        $config = json_decode(IPS_GetConfiguration($io), true);
        return is_array($config) && array_key_exists('Open', $config);
    }

    // ================================================================== Variablen

    private function ActiveDefinitions(): array
    {
        $result = [];
        foreach (HPVRegisters::Definitions() as $ident => $def) {
            if ($this->OptionEnabled($def['opt']) && $this->OptionEnabled($def['needs'] ?? '')) {
                $result[$ident] = $def;
            }
        }
        return $result;
    }

    private function OptionEnabled(string $opt): bool
    {
        switch ($opt) {
            case 'battery':
                return $this->ReadPropertyBoolean('HasBattery');
            case 'meter':
                return $this->ReadPropertyBoolean('HasMeter');
            case 'settings':
                return $this->ReadPropertyBoolean('HasBattery') && $this->ReadPropertyBoolean('ShowSettings');
            case 'strings':
                return $this->ReadPropertyBoolean('ShowStrings');
            case 'phases':
                return $this->ReadPropertyBoolean('ShowPhases');
            case 'autarky':
                return $this->ReadPropertyBoolean('ShowAutarky') && $this->ReadPropertyBoolean('HasMeter');
            case 'powerctl':
                return $this->ReadPropertyBoolean('ShowSettings');
            case 'force':
                return $this->ReadPropertyBoolean('HasBattery') && $this->ReadPropertyBoolean('AllowControl');
            default:
                return true;
        }
    }

    /** Variablen anlegen/entfernen, in Themen-Ordner einsortieren, Reihenfolge setzen. */
    private function MaintainVariables(): void
    {
        $this->idCache = null;
        // Positionen nur neu setzen, wenn Sortierung eingeschaltet wurde oder sich der Aufbau geändert hat –
        // sonst bleiben Positionen, die der Benutzer selbst gesetzt hat, unangetastet (Store-Regel).
        $sortSig = json_encode([$this->ReadPropertyBoolean('SortVariables'), $this->ReadPropertyBoolean('GroupFolders'), self::LAYOUT_VERSION]);
        $this->resort = $this->ReadPropertyBoolean('SortVariables') && $this->ReadAttributeString('SortSig') !== $sortSig;
        $this->presentationSigs = json_decode($this->ReadAttributeString('PresentationSigs'), true) ?: [];
        $this->ClearLegacyCustomPresentations();
        $kw = $this->ReadPropertyBoolean('ShowKW');
        $kwIdents = $this->KWIdents();
        $all = HPVRegisters::Definitions() + HPVRegisters::Derived();
        foreach ($all as $ident => $def) {
            $keep = $this->OptionEnabled($def['opt']) && $this->OptionEnabled($def['needs'] ?? '');
            [$name, $autoNames] = $this->VariableNames($ident, $def['name']);
            $this->MaintainVar($ident, $name, $def['vt'], $this->VariablePresentation($ident, $def['pres']), $keep, $autoNames);
            if (in_array($ident, $kwIdents, true)) {
                $this->MaintainVar($ident . 'KW', $name . ' (kW)', VARIABLETYPE_FLOAT, $this->Presentation('kW'), $keep && $kw,
                    array_map(fn($n) => $n . ' (kW)', $autoNames));
                if ($keep && $kw) {
                    $this->SetValueIfChanged($ident . 'KW', round((float) $this->GetVal($ident) / 1000, 3));
                }
            }
        }

        // Zwangsladen/-entladen (nur mit Akku und freigegebener Steuerung)
        $force = $this->OptionEnabled('force');
        $this->MaintainVar('ForceMode', 'Zwangsladen/-entladen', VARIABLETYPE_INTEGER, $this->Presentation('ForceMode'), $force);
        $this->MaintainVar('ForcePower', 'Zwangsladen: Leistung', VARIABLETYPE_FLOAT, $this->Slider(' W', 0, 100, $this->ForceLimits()['ForcePower']), $force);
        $this->MaintainVar('ForceDuration', 'Zwangsladen: Dauer', VARIABLETYPE_INTEGER, $this->Slider(' min', 0, 5, $this->ForceLimits()['ForceDuration']), $force);
        $this->MaintainVar('ForceTargetSOC', 'Zwangsladen: bis Ladestand (0 = nur Dauer)', VARIABLETYPE_FLOAT, $this->Slider(' %', 0, 5, $this->ForceLimits()['ForceTargetSOC']), $force);
        $this->MaintainVar('ForceEndTime', 'Zwangsladen endet spätestens', VARIABLETYPE_STRING, $this->Presentation('text'), $force);

        $diag = $this->ReadPropertyBoolean('ShowDiagnostics');
        $this->MaintainVar('Online', 'Verbindung OK', VARIABLETYPE_BOOLEAN, $this->BoolOptions('Nein', 'Ja'), $diag);
        $this->MaintainVar('LastSuccess', 'Letzte erfolgreiche Abfrage', VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'DATE' => 1, 'MONTH_TEXT' => false, 'DAY_OF_THE_WEEK' => false, 'TIME' => 2
        ], $diag);
        $this->MaintainVar('ErrorsToday', 'Kommunikationsfehler heute', VARIABLETYPE_INTEGER, $this->Presentation('int'), $diag);
        $this->MaintainVar('RejectedToday', 'Verworfene Werte heute', VARIABLETYPE_INTEGER, $this->Presentation('int'), $diag);
        $this->MaintainVar('LastError', 'Letzter Fehler', VARIABLETYPE_STRING, $this->Presentation('text'), $diag);

        $this->TidyFolders();
        $this->WriteAttributeString('SortSig', $sortSig);
        $this->WriteAttributeString('PresentationSigs', json_encode($this->presentationSigs));
        $this->resort = false;
    }

    /**
     * Einmalige Bereinigung für Installationen vor Build 17: Ältere Versionen haben die Darstellung von Variablen
     * in den Themen-Ordnern als benutzerdefinierte Darstellung bzw. System-Profil gesetzt. Diese würde die Darstellung
     * des Moduls dauerhaft überdecken. Betrifft nur Variablen des Moduls in dessen eigenen Themen-Ordnern und läuft
     * genau einmal (Attribut LegacyCustomCleared); danach setzt das Modul nie wieder eine benutzerdefinierte Darstellung.
     */
    private function ClearLegacyCustomPresentations(): void
    {
        if ($this->ReadAttributeBoolean('LegacyCustomCleared')) {
            return;
        }
        $cleared = 0;
        foreach ($this->FolderIDs() as $folderID) {
            foreach (IPS_GetChildrenIDs($folderID) as $childID) {
                if (!IPS_VariableExists($childID)) {
                    continue;
                }
                $var = IPS_GetVariable($childID);
                if (!empty($var['VariableCustomPresentation'])) {
                    IPS_SetVariableCustomPresentation($childID, []);
                    $cleared++;
                }
                if (($var['VariableCustomProfile'] ?? '') !== '') {
                    IPS_SetVariableCustomProfile($childID, '');
                    $cleared++;
                }
            }
        }
        if ($cleared > 0) {
            $this->SendDebug('Darstellung', 'Alte Darstellungen aus Versionen vor Build 17 zurückgesetzt: ' . $cleared, 0);
        }
        $this->WriteAttributeBoolean('LegacyCustomCleared', true);
    }

    /** Erhöhen, wenn sich Reihenfolge/Themen ändern – dann wird einmal neu sortiert (falls Sortierung an). */
    private const LAYOUT_VERSION = 2;

    private $resort = false;
    private $presentationSigs = [];

    // ================================================================== Vorzeichen

    /** Variablen, deren Vorzeichen wählbar ist: Ident => Property */
    private const SIGNED = [
        'GridPower'    => 'GridSign',
        'GridPowerA'   => 'GridSign',
        'GridPowerB'   => 'GridSign',
        'GridPowerC'   => 'GridSign',
        'GridCurrentA' => 'GridSign',
        'GridCurrentB' => 'GridSign',
        'GridCurrentC' => 'GridSign',
        'BatteryPower' => 'BatterySign'
    ];

    /** Namen der Variablen mit wählbarem Vorzeichen: [Standard (Huawei), umgekehrt] */
    private const SIGNED_NAMES = [
        'GridPower'    => ['Netzleistung (+ Einspeisung / − Bezug)', 'Netzleistung (+ Bezug / − Einspeisung)'],
        'BatteryPower' => ['Akku-Leistung (+ Laden / − Entladen)', 'Akku-Leistung (+ Entladen / − Laden)']
    ];

    /** 1 = Huawei-Vorzeichen, −1 = umgekehrt */
    private function SignFactor(string $ident): float
    {
        if (!isset(self::SIGNED[$ident])) {
            return 1.0;
        }
        return $this->ReadPropertyInteger(self::SIGNED[$ident]) === 1 ? -1.0 : 1.0;
    }

    /** Wert einer Variable mit Huawei-Vorzeichen (für Berechnungen) */
    private function RawVal(string $ident): float
    {
        return (float) $this->GetVal($ident) * $this->SignFactor($ident);
    }

    /** [aktueller Name, alle automatisch vergebenen Namen] */
    private function VariableNames(string $ident, string $default): array
    {
        if (!isset(self::SIGNED_NAMES[$ident])) {
            return [$default, [$default]];
        }
        $names = self::SIGNED_NAMES[$ident];
        return [$names[$this->SignFactor($ident) < 0 ? 1 : 0], $names];
    }

    /** Vorzeichen geändert → aktuelle Werte sofort umdrehen (neue Werte kommen ohnehin richtig) */
    private function ApplySignChange(): void
    {
        $now = ['grid' => $this->ReadPropertyInteger('GridSign'), 'battery' => $this->ReadPropertyInteger('BatterySign')];
        $before = json_decode($this->ReadAttributeString('AppliedSigns'), true);
        $this->WriteAttributeString('AppliedSigns', json_encode($now));
        if (!is_array($before)) {
            return; // erster Start: Werte sind bereits mit dem aktuellen Vorzeichen geschrieben
        }
        foreach (self::SIGNED as $ident => $property) {
            $key = $property === 'GridSign' ? 'grid' : 'battery';
            if (($before[$key] ?? 0) === $now[$key]) {
                continue;
            }
            foreach ([$ident, $ident . 'KW'] as $varIdent) {
                if ($this->HasIdent($varIdent)) {
                    $this->SetVal($varIdent, -1 * (float) $this->GetVal($varIdent));
                }
            }
            $this->SendDebug('Vorzeichen', $ident . ' umgedreht', 0);
        }
    }

    /** Leistungs-Messwerte, die es optional zusätzlich in kW gibt. */
    private function KWIdents(): array
    {
        $result = [];
        foreach (HPVRegisters::Definitions() as $ident => $def) {
            if ($def['pres'] === 'W' && $def['group'] !== 'static' && !in_array($def['opt'], ['settings', 'powerctl'], true)) {
                $result[] = $ident;
            }
        }
        foreach (HPVRegisters::Derived() as $ident => $def) {
            if ($def['pres'] === 'W') {
                $result[] = $ident;
            }
        }
        return $result;
    }

    /** Einstellungen bekommen bei freigegebener Steuerung einen Schieberegler, sonst reine Anzeige. */
    private function VariablePresentation(string $ident, string $pres)
    {
        $controls = HPVRegisters::Controls();
        if ($this->ReadPropertyBoolean('AllowControl') && isset($controls[$ident]['min'])) {
            $suffix = $pres === 'W' ? ' W' : ' %';
            return $this->Slider($suffix, 0, (float) $controls[$ident]['step'], $this->ControlRange($ident));
        }
        // Schalter nur mit Aktion – sonst Wertanzeige mit Klartext (Darstellung „Schalter“ ohne Aktion ist nicht zulässig)
        if ($pres === 'bool') {
            $switchable = $this->ReadPropertyBoolean('AllowControl')
                && ($ident === 'InverterOn' ? $this->ReadPropertyBoolean('AllowInverterSwitch') : isset($controls[$ident]));
            return $switchable ? $this->Presentation('bool') : $this->BoolOptions('Aus', 'An');
        }
        // Auswahl (Aufzählung) nur bei schaltbaren Variablen – reine Anzeigen bekommen die Wertanzeige mit Intervallen
        $actionable = $this->ReadPropertyBoolean('AllowControl') && isset($controls[$ident]['values']);
        if (!$actionable && isset(self::TEXT_MAPS[$pres])) {
            return $this->ValueIntervals(constant('self::' . self::TEXT_MAPS[$pres]));
        }
        return $this->Presentation($pres);
    }

    /** Klartext-Zuordnungen der Integer-Variablen: Darstellungs-Schlüssel => Konstante */
    private const TEXT_MAPS = [
        'DeviceStatus'     => 'DEVICE_STATUS',
        'BatteryStatus'    => 'BATTERY_STATUS',
        'WorkingModeB'     => 'WORKING_MODE_B',
        'WorkingModeC'     => 'WORKING_MODE_C',
        'MeterStatus'      => 'METER_STATUS',
        'PowerControlMode' => 'POWER_CONTROL_MODE'
    ];

    /**
     * Wertanzeige mit Intervallen: je Wert ein Intervall (Min = Max) mit festem Klartext.
     * Für Integer-Variablen ohne Aktion der vorgesehene Weg (Aufzählung verlangt eine Aktion,
     * OPTIONS greift nur bei Boolean/String) – siehe Symcon-Forum, Thema 144574.
     */
    private function ValueIntervals(array $map): array
    {
        $intervals = [];
        foreach ($map as $value => $text) {
            $intervals[] = [
                'IntervalMinValue' => $value, 'IntervalMaxValue' => $value,
                'ConstantActive'   => true, 'ConstantValue' => $text,
                'ConversionFactor' => 1,
                'PrefixActive'     => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive'     => false, 'DigitsValue' => 0,
                'IconActive'       => false, 'IconValue' => '', 'ColorActive' => false, 'Color' => -1
            ];
        }
        return [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => json_encode($intervals)
        ];
    }

    /** Wertanzeige mit Klartext für Boolean-Variablen ohne Aktion (OPTIONS funktioniert bei Boolean) */
    private function BoolOptions(string $off, string $on): array
    {
        $options = [];
        foreach ([false => $off, true => $on] as $value => $caption) {
            $options[] = ['Value' => (bool) $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode($options)
        ];
    }

    private function Slider(string $suffix, int $digits, float $step, array $range): array
    {
        // Parameternamen der Darstellung „Schieberegler“ (prüfen: MIN, MAX, STEP_SIZE)
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => $range[0],
            'MAX'          => $range[1],
            'STEP_SIZE'    => $step,
            'SUFFIX'       => $suffix,
            'DIGITS'       => $digits
        ];
    }

    /** Aktionen setzen bzw. entfernen, Vorgabewerte für Zwangsladen. */
    private function SetupControls(): void
    {
        $allow = $this->ReadPropertyBoolean('AllowControl');
        foreach (array_keys(HPVRegisters::Controls()) as $ident) {
            if ($this->HasIdent($ident)) {
                $this->MaintainAction($ident, $allow);
            }
        }
        if ($this->HasIdent('InverterOn')) {
            $this->MaintainAction('InverterOn', $allow && $this->ReadPropertyBoolean('AllowInverterSwitch'));
        }
        if ($this->HasIdent('ForceMode')) {
            foreach (['ForceMode', 'ForcePower', 'ForceDuration', 'ForceTargetSOC'] as $ident) {
                $this->EnableAction($ident);
            }
            if ((float) $this->GetVal('ForcePower') <= 0) {
                $this->SetVal('ForcePower', min(2500.0, $this->ForceLimits()['ForcePower'][1]));
            }
            if ((int) $this->GetVal('ForceDuration') <= 0) {
                $this->SetVal('ForceDuration', 60);
            }
        }
    }

    private function RefreshControlPresentation(): void
    {
        $key = json_encode([$this->ControlRange('MaxChargePower'), $this->ControlRange('MaxFeedInPower'), $this->ControlRange('MaxDischargePower')]);
        if ($this->GetBuffer('SliderLimits') === $key) {
            return;
        }
        $this->SetBuffer('SliderLimits', $key);
        $this->MaintainVariables();
        $this->SetupControls();
    }

    private function Presentation(string $key)
    {
        $value = function (string $suffix, int $digits) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => $suffix,
                'DIGITS'       => $digits
            ];
        };
        switch ($key) {
            case 'W':
                return $value(' W', 0);
            case 'kWh':
                return $value(' kWh', 2);
            case 'pct':
                return $value(' %', 0);
            case 'pct1':
                return $value(' %', 1);
            case 'degC':
                return $value(' °C', 0);
            case 'Hz':
                return $value(' Hz', 0);
            case 'V':
                return $value(' V', 1);
            case 'A':
                return $value(' A', 2);
            case 'kW':
                return $value(' kW', 2);
            case 'int':
                return $value('', 0);
            case 'text':
                return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
            case 'bool':
                return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
            case 'DeviceStatus':
                return $this->Enumeration(self::DEVICE_STATUS);
            case 'BatteryStatus':
                return $this->Enumeration(self::BATTERY_STATUS);
            case 'WorkingModeB':
                return $this->Enumeration(self::WORKING_MODE_B);
            case 'WorkingModeC':
                return $this->Enumeration(self::WORKING_MODE_C);
            case 'MeterStatus':
                return $this->Enumeration(self::METER_STATUS);
            case 'PowerControlMode':
                return $this->Enumeration(self::POWER_CONTROL_MODE);
            case 'ForceMode':
                return $this->Enumeration(self::FORCE_MODE);
            default:
                return '';
        }
    }

    private function Enumeration(array $map): array
    {
        $options = [];
        foreach ($map as $value => $caption) {
            $options[] = ['Value' => $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options)
        ];
    }

    private function UpdateDiagnosticVariables(array $diag, bool $success): void
    {
        if (!$this->HasIdent('Online')) {
            return;
        }
        $this->SetValueIfChanged('Online', $success);
        if ($success) {
            $this->SetVal('LastSuccess', $diag['lastSuccess']);
        }
        $this->SetValueIfChanged('ErrorsToday', (int) $diag['errors']);
        $this->SetValueIfChanged('RejectedToday', (int) $diag['rejected']);
        $this->SetValueIfChanged('LastError', (string) $diag['lastError']);
    }

    // ================================================================== Puffer

    private function Diag(): array
    {
        $d = json_decode($this->GetBuffer('Diag'), true);
        $default = [
            'date' => date('Y-m-d'), 'requests' => 0, 'errors' => 0, 'busy' => 0, 'timeouts' => 0, 'retries' => 0,
            'rejected' => 0, 'lastError' => '', 'lastRejected' => '', 'lastSuccess' => 0, 'failedCycles' => 0, 'lastReconnect' => 0,
            'writes' => 0, 'lastWrite' => '', 'lastWriteError' => '',
            'writesSkipped' => 0, 'snapshots' => 0, 'snapshotMs' => 0, 'balanceBad' => 0, 'lastBalance' => '',
            'lastWriteTime' => 0, 'balanceLog' => []
        ];
        $d = is_array($d) ? array_merge($default, $d) : $default;
        if ($d['date'] !== date('Y-m-d')) {
            // Tageszähler zurücksetzen, Rest behalten
            foreach (['requests', 'errors', 'busy', 'timeouts', 'retries', 'rejected', 'writes', 'writesSkipped', 'snapshots', 'snapshotMs', 'balanceBad'] as $k) {
                $d[$k] = 0;
            }
            $d['balanceLog'] = [];
            $d['date'] = date('Y-m-d');
        }
        return $d;
    }

    private function WriteDiag(array $diag): void
    {
        $this->SetBuffer('Diag', json_encode($diag));
    }

    private function FilterState(): array
    {
        $s = json_decode($this->GetBuffer('FilterState'), true);
        return is_array($s) ? $s : [];
    }

    private function WriteFilterState(array $state): void
    {
        $this->SetBuffer('FilterState', json_encode($state));
    }

    // ================================================================== Hilfsfunktionen

    private function HasIdent(string $ident): bool
    {
        return $this->VarID($ident) > 0;
    }

    // ================================================================== Themen-Ordner (Dummy-Instanzen), wie BMWCarData

    /** Zwischenspeicher Ident → ObjektID für die laufende Ausführung */
    private $idCache = null;

    /** ID einer Variable – direkt unter der Instanz oder in einem Themen-Ordner. 0 = nicht vorhanden */
    private function VarID(string $ident): int
    {
        if (is_array($this->idCache) && isset($this->idCache[$ident]) && IPS_VariableExists($this->idCache[$ident])) {
            return $this->idCache[$ident];
        }
        $found = 0;
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id && IPS_VariableExists((int) $id)) {
            $found = (int) $id;
        } else {
            foreach ($this->FolderIDs() as $folderID) {
                $id = @IPS_GetObjectIDByIdent($ident, $folderID);
                if ($id) {
                    $found = (int) $id;
                    break;
                }
            }
        }
        if (!is_array($this->idCache)) {
            $this->idCache = [];
        }
        if ($found) {
            $this->idCache[$ident] = $found;
        }
        return $found;
    }

    private function GetVal(string $ident)
    {
        $id = $this->VarID($ident);
        return $id ? GetValue($id) : null;
    }

    private function SetVal(string $ident, $value): void
    {
        $id = $this->VarID($ident);
        if (!$id) {
            return;
        }
        switch (IPS_GetVariable($id)['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $value = (bool) $value;
                break;
            case VARIABLETYPE_INTEGER:
                $value = (int) $value;
                break;
            case VARIABLETYPE_FLOAT:
                $value = (float) $value;
                break;
            default:
                $value = (string) $value;
        }
        SetValue($id, $value);
    }

    /** Vorhandene Themen-Ordner: [Thema => ObjektID] */
    private function FolderIDs(): array
    {
        $folders = [];
        foreach (array_keys(HPVRegisters::TOPICS) as $topic) {
            $id = @IPS_GetObjectIDByIdent('G_' . $topic, $this->InstanceID);
            if ($id) {
                $folders[$topic] = (int) $id;
            }
        }
        return $folders;
    }

    /** Themen-Ordner holen bzw. anlegen */
    private function FolderFor(string $topic): int
    {
        $id = @IPS_GetObjectIDByIdent('G_' . $topic, $this->InstanceID);
        if ($id) {
            return (int) $id;
        }
        $id = IPS_CreateInstance($this->DummyModuleID());
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetIdent($id, 'G_' . $topic);
        IPS_SetName($id, HPVRegisters::TOPICS[$topic]['name']);
        IPS_SetPosition($id, $this->TopicPosition($topic));
        $this->SendDebug('Ordner', 'angelegt: ' . HPVRegisters::TOPICS[$topic]['name'], 0);
        return (int) $id;
    }

    private function TopicPosition(string $topic): int
    {
        return 1000 * (array_search($topic, array_keys(HPVRegisters::TOPICS), true) + 1);
    }

    private function DummyModuleID(): string
    {
        foreach (IPS_GetModuleList() as $guid) {
            if ((IPS_GetModule($guid)['ModuleName'] ?? '') === 'Dummy Module') {
                return $guid;
            }
        }
        return '{485D0419-BE97-4548-AA9C-C083EB82E61E}';
    }

    /**
     * Variable pflegen: anlegen, in den richtigen Ordner verschieben (IPS_SetParent – ID, Archiv und
     * Verknüpfungen bleiben erhalten), Darstellung setzen, Position setzen oder löschen.
     *
     * Store-Regeln:
     *  - Die Darstellung wird nur über MaintainVariable() gesetzt (Darstellung des Moduls). Die benutzerdefinierte
     *    Darstellung bleibt dem Benutzer. MaintainVariable() sieht nur direkte Kinder der Instanz – Variablen im
     *    Themen-Ordner werden dafür kurz zurückgeholt, aber nur, wenn sich ihre Darstellung geändert hat.
     *  - Positionen werden nur beim Anlegen bzw. beim (Wieder-)Einschalten der Sortierung gesetzt.
     * Schaltbare Variablen (Themen ohne Ordner) bleiben direkt unter der Instanz, weil nur dort Aktionen möglich sind.
     */
    private function MaintainVar(string $ident, string $name, int $type, $presentation, bool $keep, array $autoNames = []): void
    {
        $id = $this->VarID($ident);
        if (!$keep) {
            if ($id) {
                $this->DeleteVar($ident, $id);
            }
            unset($this->presentationSigs[$ident]);
            return;
        }

        $topic = HPVRegisters::TopicOf($ident);
        [$topicIndex, $rank] = HPVRegisters::SortKey($ident);
        $inFolder = $this->ReadPropertyBoolean('GroupFolders') && HPVRegisters::TOPICS[$topic]['folder'];
        $position = $inFolder ? $rank : $topicIndex * 1000 + $rank;
        $parent = $inFolder ? $this->FolderFor($topic) : $this->InstanceID;
        $sig = md5(json_encode([$type, $presentation]));

        if ($id && IPS_GetVariable($id)['VariableType'] !== $type) {
            $this->DeleteVar($ident, $id);
            $id = 0;
        }

        $isNew = $id === 0;
        $current = ($this->presentationSigs[$ident] ?? '') === $sig;
        if ($isNew || IPS_GetParent($id) === $this->InstanceID || !$current) {
            // An der Wurzel anlegen bzw. Darstellung des Moduls setzen
            if (!$isNew && IPS_GetParent($id) !== $this->InstanceID) {
                IPS_SetParent($id, $this->InstanceID);
            }
            $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);
            $id = (int) @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            $this->presentationSigs[$ident] = $sig;
        }
        if ($id && IPS_GetParent($id) !== $parent) {
            IPS_SetParent($id, $parent);
        }
        if (!is_array($this->idCache)) {
            $this->idCache = [];
        }
        $this->idCache[$ident] = $id;
        // Automatisch vergebene Namen nachziehen (z. B. Vorzeichen geändert); eigene Namen bleiben
        if ($id && $autoNames !== [] && IPS_GetName($id) !== $name && in_array(IPS_GetName($id), $autoNames, true)) {
            IPS_SetName($id, $name);
        }
        if ($id && ($isNew || $this->resort)) {
            $this->SetPositionIfChanged($id, $position);
        }
    }

    private function DeleteVar(string $ident, int $id): void
    {
        if (IPS_GetParent($id) !== $this->InstanceID) {
            IPS_SetParent($id, $this->InstanceID);
        }
        $this->UnregisterVariable($ident);
        unset($this->idCache[$ident]);
    }

    private function SetPositionIfChanged(int $objectID, int $position): void
    {
        if ((IPS_GetObject($objectID)['ObjectPosition'] ?? -1) !== $position) {
            IPS_SetPosition($objectID, $position);
        }
    }

    /** Leere Themen-Ordner entfernen, Ordner sortieren */
    private function TidyFolders(): void
    {
        foreach ($this->FolderIDs() as $topic => $folderID) {
            if (count(IPS_GetChildrenIDs($folderID)) === 0) {
                IPS_DeleteInstance($folderID);
                continue;
            }
            if ($this->resort) {
                $this->SetPositionIfChanged($folderID, $this->TopicPosition($topic));
            }
        }
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        if (!$this->HasIdent($ident)) {
            return;
        }
        if ($this->GetVal($ident) !== $value) {
            $this->SetVal($ident, $value);
        }
        if (is_float($value) && $this->HasIdent($ident . 'KW')) {
            $kw = round($value / 1000, 3);
            if ($this->GetVal($ident . 'KW') !== $kw) {
                $this->SetVal($ident . 'KW', $kw);
            }
        }
    }

    private function SetStatusIfChanged(int $status): void
    {
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
    }
}
