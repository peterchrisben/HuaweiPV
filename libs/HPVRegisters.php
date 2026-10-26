<?php

declare(strict_types=1);

/**
 * Registerliste Huawei SUN2000 (Hybrid) + LUNA2000 + Smartmeter (über den Wechselrichter / SDongle).
 *
 * Quelle der Adressen, Datentypen und Faktoren: Huawei "Solar Inverter Modbus Interface Definitions"
 * und die Python-Bibliothek huawei-solar (wlcrs/huawei-solar-lib, registers.py).
 *
 * Felder je Eintrag:
 *   name   Anzeigename der Variable
 *   addr   Registeradresse (Holding Register, Funktion 03)
 *   type   U16 | I16 | U32 | I32 | STR
 *   len    Anzahl Register (nur STR)
 *   gain   Rohwert / gain = Anzeigewert
 *   group  fast | medium | slow | static  (Abfragegruppe)
 *   opt    battery | meter | strings | phases | settings | ''  (abhängig von Formular-Häkchen)
 *   vt     Variablentyp (0 Bool, 1 Integer, 2 Float, 3 String)
 *   pres   Darstellung (siehe HuaweiPV::Presentation)
 *   pos    Position
 *   min/max  harte Grenzen (außerhalb = immer verwerfen); 'P' = +Plausibilitätsgrenze Leistung, '-P' = −Grenze
 *   kind   gauge | counter | daily   (counter = darf nie sinken, daily = Tageszähler, Rücksetzung bei Datumswechsel)
 *   step   max. Änderung zwischen zwei Abfragen ohne Bestätigung (nur gauge)
 *   abs    true = Betrag verwenden (Zähler, die je nach Meter negativ geliefert werden)
 */
class HPVRegisters
{
    public const GROUPS = ['fast', 'medium', 'slow', 'static'];

    /** Huawei kennzeichnet ungültige Werte mit diesen Rohwerten. */
    public const INVALID = [
        'U16' => [0xFFFF],
        'I16' => [0x7FFF, -32768],
        'U32' => [0xFFFFFFFF],
        'I32' => [0x7FFFFFFF, -2147483648]
    ];

    public const MAX_BLOCK = 64;

    /**
     * Themen für Ordner und Sortierung (Reihenfolge = Reihenfolge unter der Instanz).
     * Erst alle Themen-Ordner, danach die Variablen ohne Ordner (Einstellungen, Zwangsladen, Einspeisebegrenzung).
     * folder = true: eigener Themen-Ordner (Dummy-Instanz). folder = false: Variablen bleiben direkt
     * unter der Instanz, weil sie schaltbar sind (Aktionen funktionieren nur dort).
     * idents: Reihenfolge innerhalb des Themas (kW-Variante steht jeweils direkt darunter).
     */
    public const TOPICS = [
        'pv' => ['name' => 'PV-Anlage', 'folder' => true, 'idents' => [
            'PVPower', 'DailyYield', 'TotalYield',
            'PV1Power', 'PV1Voltage', 'PV1Current', 'PV2Power', 'PV2Voltage', 'PV2Current'
        ]],
        'battery' => ['name' => 'Akku', 'folder' => true, 'idents' => [
            'BatterySOC', 'BatteryPower', 'BatteryStatus', 'BatteryWorkingMode',
            'BatteryChargeToday', 'BatteryDischargeToday', 'BatteryChargeTotal', 'BatteryDischargeTotal', 'BatteryTemp'
        ]],
        'grid' => ['name' => 'Netz & Smartmeter', 'folder' => true, 'idents' => [
            'GridPower', 'GridFeedIn', 'GridImport', 'GridExportTotal', 'GridImportTotal',
            'GridPowerA', 'GridPowerB', 'GridPowerC', 'GridVoltageA', 'GridVoltageB', 'GridVoltageC',
            'GridCurrentA', 'GridCurrentB', 'GridCurrentC', 'MeterFrequency', 'MeterStatus'
        ]],
        'house' => ['name' => 'Verbrauch & Bilanz', 'folder' => true, 'idents' => [
            'HouseConsumption', 'SelfSufficiency', 'SelfConsumptionRate', 'HouseEnergyToday', 'GridImportToday', 'GridExportToday'
        ]],
        'inverter' => ['name' => 'Wechselrichter', 'folder' => true, 'idents' => [
            'ActivePower', 'DeviceStatus', 'FaultCode', 'InverterTemp', 'Efficiency', 'GridFrequency',
            'PhaseVoltageA', 'PhaseVoltageB', 'PhaseVoltageC'
        ]],
        'device' => ['name' => 'Geräteinfo', 'folder' => true, 'idents' => [
            'Model', 'SerialNumber', 'SoftwareVersion', 'FirmwareVersion', 'RatedPower',
            'BatterySoftwareVersion', 'BatteryCapacity', 'BatteryMaxChargeLimit', 'BatteryMaxDischargeLimit'
        ]],
        'diag' => ['name' => 'Diagnose', 'folder' => true, 'idents' => [
            'Online', 'LastSuccess', 'ErrorsToday', 'RejectedToday', 'LastError'
        ]],
        'settings' => ['name' => 'Akku-Einstellungen', 'folder' => false, 'idents' => [
            'WorkingModeSetting', 'MaxChargePower', 'MaxDischargePower', 'ChargeCutoff', 'DischargeCutoff', 'BackupSOC',
            'GridCharge', 'GridChargeCutoff', 'GridChargePower'
        ]],
        'force' => ['name' => 'Zwangsladen', 'folder' => false, 'idents' => [
            'ForceMode', 'ForcePower', 'ForceDuration', 'ForceTargetSOC', 'ForceEndTime'
        ]],
        'powerctl' => ['name' => 'Einspeisebegrenzung & Ein/Aus', 'folder' => false, 'idents' => [
            'PowerControlMode', 'MaxFeedInPower', 'MaxFeedInPercent', 'InverterOn'
        ]],
    ];

    /** Thema einer Variable (kW-Varianten gehören zum W-Wert). */
    public static function TopicOf(string $ident): string
    {
        $base = (substr($ident, -2) === 'KW') ? substr($ident, 0, -2) : $ident;
        foreach (self::TOPICS as $key => $group) {
            if (in_array($base, $group['idents'], true)) {
                return $key;
            }
        }
        return 'diag';
    }

    /**
     * Sortierschlüssel: [Themen-Nr. ab 1, Position im Thema].
     * Position = (Index + 1) · 10, kW-Variante + 5 → steht direkt unter dem W-Wert.
     */
    public static function SortKey(string $ident): array
    {
        $isKW = substr($ident, -2) === 'KW';
        $base = $isKW ? substr($ident, 0, -2) : $ident;
        $group = self::TopicOf($ident);
        $groupIndex = array_search($group, array_keys(self::TOPICS), true) + 1;
        $index = array_search($base, self::TOPICS[$group]['idents'], true);
        $rank = ($index === false ? 90 : $index + 1) * 10 + ($isKW ? 5 : 0);
        return [$groupIndex, $rank];
    }

    public static function Definitions(): array
    {
        return [
            // ------------------------------------------------------------ Übersicht (schnell)
            'PVPower' => ['name' => 'PV-Leistung', 'addr' => 32064, 'type' => 'I32', 'gain' => 1, 'group' => 'fast', 'opt' => '', 'vt' => 2, 'pres' => 'W', 'pos' => 10, 'min' => -100, 'max' => 'P', 'kind' => 'gauge'],
            'ActivePower' => ['name' => 'Wechselrichter AC-Leistung', 'addr' => 32080, 'type' => 'I32', 'gain' => 1, 'group' => 'fast', 'opt' => '', 'vt' => 2, 'pres' => 'W', 'pos' => 11, 'min' => '-P', 'max' => 'P', 'kind' => 'gauge'],
            'GridPower' => ['name' => 'Netzleistung (+ Einspeisung / − Bezug)', 'addr' => 37113, 'type' => 'I32', 'gain' => 1, 'group' => 'fast', 'opt' => 'meter', 'vt' => 2, 'pres' => 'W', 'pos' => 13, 'min' => '-P', 'max' => 'P', 'kind' => 'gauge'],
            'BatteryPower' => ['name' => 'Akku-Leistung (+ Laden / − Entladen)', 'addr' => 37001, 'type' => 'I32', 'gain' => 1, 'group' => 'fast', 'opt' => 'battery', 'vt' => 2, 'pres' => 'W', 'pos' => 20, 'min' => '-P', 'max' => 'P', 'kind' => 'gauge'],
            'BatterySOC' => ['name' => 'Akku-Ladestand', 'addr' => 37004, 'type' => 'U16', 'gain' => 10, 'group' => 'fast', 'opt' => 'battery', 'vt' => 2, 'pres' => 'pct', 'pos' => 21, 'min' => 0, 'max' => 100, 'kind' => 'gauge', 'step' => 10],
            'BatteryStatus' => ['name' => 'Akku-Status', 'addr' => 37000, 'type' => 'U16', 'gain' => 1, 'group' => 'fast', 'opt' => 'battery', 'vt' => 1, 'pres' => 'BatteryStatus', 'pos' => 22, 'min' => 0, 'max' => 4, 'kind' => 'gauge'],

            // ------------------------------------------------------------ Energie (mittel)
            'DailyYield' => ['name' => 'PV-Ertrag heute', 'addr' => 32114, 'type' => 'U32', 'gain' => 100, 'group' => 'medium', 'opt' => '', 'vt' => 2, 'pres' => 'kWh', 'pos' => 30, 'min' => 0, 'max' => 1000, 'kind' => 'daily'],
            'TotalYield' => ['name' => 'PV-Ertrag gesamt', 'addr' => 32106, 'type' => 'U32', 'gain' => 100, 'group' => 'medium', 'opt' => '', 'vt' => 2, 'pres' => 'kWh', 'pos' => 31, 'min' => 0, 'max' => 10000000, 'kind' => 'counter'],
            'GridExportTotal' => ['name' => 'Einspeisung gesamt', 'addr' => 37119, 'type' => 'I32', 'gain' => 100, 'group' => 'medium', 'opt' => 'meter', 'vt' => 2, 'pres' => 'kWh', 'pos' => 32, 'min' => 0, 'max' => 10000000, 'kind' => 'counter', 'abs' => true],
            'GridImportTotal' => ['name' => 'Netzbezug gesamt', 'addr' => 37121, 'type' => 'I32', 'gain' => 100, 'group' => 'medium', 'opt' => 'meter', 'vt' => 2, 'pres' => 'kWh', 'pos' => 33, 'min' => 0, 'max' => 10000000, 'kind' => 'counter', 'abs' => true],
            'BatteryChargeToday' => ['name' => 'Akku geladen heute', 'addr' => 37015, 'type' => 'U32', 'gain' => 100, 'group' => 'medium', 'opt' => 'battery', 'vt' => 2, 'pres' => 'kWh', 'pos' => 34, 'min' => 0, 'max' => 1000, 'kind' => 'daily'],
            'BatteryDischargeToday' => ['name' => 'Akku entladen heute', 'addr' => 37017, 'type' => 'U32', 'gain' => 100, 'group' => 'medium', 'opt' => 'battery', 'vt' => 2, 'pres' => 'kWh', 'pos' => 35, 'min' => 0, 'max' => 1000, 'kind' => 'daily'],
            'BatteryChargeTotal' => ['name' => 'Akku geladen gesamt', 'addr' => 37066, 'type' => 'U32', 'gain' => 100, 'group' => 'medium', 'opt' => 'battery', 'vt' => 2, 'pres' => 'kWh', 'pos' => 36, 'min' => 0, 'max' => 10000000, 'kind' => 'counter'],
            'BatteryDischargeTotal' => ['name' => 'Akku entladen gesamt', 'addr' => 37068, 'type' => 'U32', 'gain' => 100, 'group' => 'medium', 'opt' => 'battery', 'vt' => 2, 'pres' => 'kWh', 'pos' => 37, 'min' => 0, 'max' => 10000000, 'kind' => 'counter'],

            // ------------------------------------------------------------ Zustand (mittel)
            'DeviceStatus' => ['name' => 'Wechselrichter-Status', 'addr' => 32089, 'type' => 'U16', 'gain' => 1, 'group' => 'medium', 'opt' => '', 'vt' => 1, 'pres' => 'DeviceStatus', 'pos' => 40, 'min' => 0, 'max' => 0xFFFE, 'kind' => 'gauge'],
            'FaultCode' => ['name' => 'Fehlercode Wechselrichter', 'addr' => 32090, 'type' => 'U16', 'gain' => 1, 'group' => 'medium', 'opt' => '', 'vt' => 1, 'pres' => 'int', 'pos' => 41, 'min' => 0, 'max' => 0xFFFE, 'kind' => 'gauge'],
            'InverterTemp' => ['name' => 'Wechselrichter-Temperatur', 'addr' => 32087, 'type' => 'I16', 'gain' => 10, 'group' => 'medium', 'opt' => '', 'vt' => 2, 'pres' => 'degC', 'pos' => 42, 'min' => -40, 'max' => 120, 'kind' => 'gauge'],
            'Efficiency' => ['name' => 'Wirkungsgrad', 'addr' => 32086, 'type' => 'U16', 'gain' => 100, 'group' => 'medium', 'opt' => '', 'vt' => 2, 'pres' => 'pct', 'pos' => 43, 'min' => 0, 'max' => 100, 'kind' => 'gauge'],
            'GridFrequency' => ['name' => 'Netzfrequenz', 'addr' => 32085, 'type' => 'U16', 'gain' => 100, 'group' => 'medium', 'opt' => '', 'vt' => 2, 'pres' => 'Hz', 'pos' => 44, 'min' => 0, 'max' => 70, 'kind' => 'gauge'],
            'BatteryWorkingMode' => ['name' => 'Akku-Betriebsart (aktuell)', 'addr' => 37006, 'type' => 'U16', 'gain' => 1, 'group' => 'medium', 'opt' => 'battery', 'vt' => 1, 'pres' => 'WorkingModeB', 'pos' => 45, 'min' => 0, 'max' => 20, 'kind' => 'gauge'],
            'BatteryTemp' => ['name' => 'Akku-Temperatur', 'addr' => 37022, 'type' => 'I16', 'gain' => 10, 'group' => 'medium', 'opt' => 'battery', 'vt' => 2, 'pres' => 'degC', 'pos' => 46, 'min' => -40, 'max' => 100, 'kind' => 'gauge'],
            'MeterStatus' => ['name' => 'Smartmeter-Status', 'addr' => 37100, 'type' => 'U16', 'gain' => 1, 'group' => 'medium', 'opt' => 'meter', 'vt' => 1, 'pres' => 'MeterStatus', 'pos' => 47, 'min' => 0, 'max' => 1, 'kind' => 'gauge'],

            // ------------------------------------------------------------ Akku-Einstellungen (langsam, nur lesen)
            'MaxChargePower' => ['name' => 'Akku max. Ladeleistung', 'addr' => 47075, 'type' => 'U32', 'gain' => 1, 'group' => 'slow', 'opt' => 'settings', 'vt' => 2, 'pres' => 'W', 'pos' => 60, 'min' => 0, 'max' => 'P', 'kind' => 'gauge'],
            'MaxDischargePower' => ['name' => 'Akku max. Entladeleistung', 'addr' => 47077, 'type' => 'U32', 'gain' => 1, 'group' => 'slow', 'opt' => 'settings', 'vt' => 2, 'pres' => 'W', 'pos' => 61, 'min' => 0, 'max' => 'P', 'kind' => 'gauge'],
            'ChargeCutoff' => ['name' => 'Akku Ladeende bei', 'addr' => 47081, 'type' => 'U16', 'gain' => 10, 'group' => 'slow', 'opt' => 'settings', 'vt' => 2, 'pres' => 'pct', 'pos' => 62, 'min' => 0, 'max' => 100, 'kind' => 'gauge'],
            'DischargeCutoff' => ['name' => 'Akku Entladeende bei', 'addr' => 47082, 'type' => 'U16', 'gain' => 10, 'group' => 'slow', 'opt' => 'settings', 'vt' => 2, 'pres' => 'pct', 'pos' => 63, 'min' => 0, 'max' => 100, 'kind' => 'gauge'],
            'WorkingModeSetting' => ['name' => 'Akku-Betriebsart (Einstellung)', 'addr' => 47086, 'type' => 'U16', 'gain' => 1, 'group' => 'slow', 'opt' => 'settings', 'vt' => 1, 'pres' => 'WorkingModeC', 'pos' => 64, 'min' => 0, 'max' => 10, 'kind' => 'gauge'],
            'GridCharge' => ['name' => 'Laden aus dem Netz', 'addr' => 47087, 'type' => 'U16', 'gain' => 1, 'group' => 'slow', 'opt' => 'settings', 'vt' => 0, 'pres' => 'bool', 'pos' => 65, 'min' => 0, 'max' => 1, 'kind' => 'gauge'],
            'BackupSOC' => ['name' => 'Akku Notstromreserve', 'addr' => 47102, 'type' => 'U16', 'gain' => 10, 'group' => 'slow', 'opt' => 'settings', 'vt' => 2, 'pres' => 'pct', 'pos' => 66, 'min' => 0, 'max' => 100, 'kind' => 'gauge'],
            'GridChargeCutoff' => ['name' => 'Netzladen bis', 'addr' => 47088, 'type' => 'U16', 'gain' => 10, 'group' => 'slow', 'opt' => 'settings', 'vt' => 2, 'pres' => 'pct', 'pos' => 67, 'min' => 0, 'max' => 100, 'kind' => 'gauge'],
            'GridChargePower' => ['name' => 'Netzladeleistung', 'addr' => 47242, 'type' => 'U32', 'gain' => 1, 'group' => 'slow', 'opt' => 'settings', 'vt' => 2, 'pres' => 'W', 'pos' => 68, 'min' => 0, 'max' => 'P', 'kind' => 'gauge'],

            // ------------------------------------------------------------ Einspeisebegrenzung (langsam)
            'PowerControlMode' => ['name' => 'Einspeisebegrenzung', 'addr' => 47415, 'type' => 'U16', 'gain' => 1, 'group' => 'slow', 'opt' => 'powerctl', 'vt' => 1, 'pres' => 'PowerControlMode', 'pos' => 76, 'min' => 0, 'max' => 20, 'kind' => 'gauge'],
            'MaxFeedInPower' => ['name' => 'Einspeisung max. (W)', 'addr' => 47416, 'type' => 'I32', 'gain' => 1, 'group' => 'slow', 'opt' => 'powerctl', 'vt' => 2, 'pres' => 'W', 'pos' => 77, 'min' => '-P', 'max' => 'P', 'kind' => 'gauge'],
            'MaxFeedInPercent' => ['name' => 'Einspeisung max. (%)', 'addr' => 47418, 'type' => 'I16', 'gain' => 10, 'group' => 'slow', 'opt' => 'powerctl', 'vt' => 2, 'pres' => 'pct', 'pos' => 78, 'min' => -100, 'max' => 100, 'kind' => 'gauge'],

            // ------------------------------------------------------------ PV-Strings (optional)
            'PV1Voltage' => ['name' => 'PV1 Spannung', 'addr' => 32016, 'type' => 'I16', 'gain' => 10, 'group' => 'medium', 'opt' => 'strings', 'vt' => 2, 'pres' => 'V', 'pos' => 70, 'min' => 0, 'max' => 1100, 'kind' => 'gauge'],
            'PV1Current' => ['name' => 'PV1 Strom', 'addr' => 32017, 'type' => 'I16', 'gain' => 100, 'group' => 'medium', 'opt' => 'strings', 'vt' => 2, 'pres' => 'A', 'pos' => 71, 'min' => -1, 'max' => 30, 'kind' => 'gauge'],
            'PV2Voltage' => ['name' => 'PV2 Spannung', 'addr' => 32018, 'type' => 'I16', 'gain' => 10, 'group' => 'medium', 'opt' => 'strings', 'vt' => 2, 'pres' => 'V', 'pos' => 73, 'min' => 0, 'max' => 1100, 'kind' => 'gauge'],
            'PV2Current' => ['name' => 'PV2 Strom', 'addr' => 32019, 'type' => 'I16', 'gain' => 100, 'group' => 'medium', 'opt' => 'strings', 'vt' => 2, 'pres' => 'A', 'pos' => 74, 'min' => -1, 'max' => 30, 'kind' => 'gauge'],

            // ------------------------------------------------------------ Phasen (optional)
            'PhaseVoltageA' => ['name' => 'Spannung L1 (Wechselrichter)', 'addr' => 32069, 'type' => 'U16', 'gain' => 10, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'V', 'pos' => 80, 'min' => 0, 'max' => 300, 'kind' => 'gauge'],
            'PhaseVoltageB' => ['name' => 'Spannung L2 (Wechselrichter)', 'addr' => 32070, 'type' => 'U16', 'gain' => 10, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'V', 'pos' => 81, 'min' => 0, 'max' => 300, 'kind' => 'gauge'],
            'PhaseVoltageC' => ['name' => 'Spannung L3 (Wechselrichter)', 'addr' => 32071, 'type' => 'U16', 'gain' => 10, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'V', 'pos' => 82, 'min' => 0, 'max' => 300, 'kind' => 'gauge'],
            'GridPowerA' => ['name' => 'Netzleistung L1', 'addr' => 37132, 'type' => 'I32', 'gain' => 1, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'W', 'pos' => 83, 'min' => '-P', 'max' => 'P', 'kind' => 'gauge', 'needs' => 'meter'],
            'GridPowerB' => ['name' => 'Netzleistung L2', 'addr' => 37134, 'type' => 'I32', 'gain' => 1, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'W', 'pos' => 84, 'min' => '-P', 'max' => 'P', 'kind' => 'gauge', 'needs' => 'meter'],
            'GridPowerC' => ['name' => 'Netzleistung L3', 'addr' => 37136, 'type' => 'I32', 'gain' => 1, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'W', 'pos' => 85, 'min' => '-P', 'max' => 'P', 'kind' => 'gauge', 'needs' => 'meter'],
            'GridVoltageA' => ['name' => 'Spannung L1 (Smartmeter)', 'addr' => 37101, 'type' => 'I32', 'gain' => 10, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'V', 'pos' => 86, 'min' => 0, 'max' => 300, 'kind' => 'gauge', 'needs' => 'meter'],
            'GridVoltageB' => ['name' => 'Spannung L2 (Smartmeter)', 'addr' => 37103, 'type' => 'I32', 'gain' => 10, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'V', 'pos' => 87, 'min' => 0, 'max' => 300, 'kind' => 'gauge', 'needs' => 'meter'],
            'GridVoltageC' => ['name' => 'Spannung L3 (Smartmeter)', 'addr' => 37105, 'type' => 'I32', 'gain' => 10, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'V', 'pos' => 88, 'min' => 0, 'max' => 300, 'kind' => 'gauge', 'needs' => 'meter'],
            'GridCurrentA' => ['name' => 'Strom L1 (Smartmeter)', 'addr' => 37107, 'type' => 'I32', 'gain' => 100, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'A', 'pos' => 89, 'min' => -200, 'max' => 200, 'kind' => 'gauge', 'needs' => 'meter'],
            'GridCurrentB' => ['name' => 'Strom L2 (Smartmeter)', 'addr' => 37109, 'type' => 'I32', 'gain' => 100, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'A', 'pos' => 90, 'min' => -200, 'max' => 200, 'kind' => 'gauge', 'needs' => 'meter'],
            'GridCurrentC' => ['name' => 'Strom L3 (Smartmeter)', 'addr' => 37111, 'type' => 'I32', 'gain' => 100, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'A', 'pos' => 91, 'min' => -200, 'max' => 200, 'kind' => 'gauge', 'needs' => 'meter'],
            'MeterFrequency' => ['name' => 'Netzfrequenz (Smartmeter)', 'addr' => 37118, 'type' => 'I16', 'gain' => 100, 'group' => 'medium', 'opt' => 'phases', 'vt' => 2, 'pres' => 'Hz', 'pos' => 92, 'min' => 40, 'max' => 70, 'kind' => 'gauge', 'needs' => 'meter'],

            // ------------------------------------------------------------ Gerätedaten (einmalig / täglich)
            'Model' => ['name' => 'Modell', 'addr' => 30000, 'type' => 'STR', 'len' => 15, 'gain' => 1, 'group' => 'static', 'opt' => '', 'vt' => 3, 'pres' => 'text', 'pos' => 90],
            'SerialNumber' => ['name' => 'Seriennummer', 'addr' => 30015, 'type' => 'STR', 'len' => 10, 'gain' => 1, 'group' => 'static', 'opt' => '', 'vt' => 3, 'pres' => 'text', 'pos' => 91],
            'FirmwareVersion' => ['name' => 'Firmware Wechselrichter', 'addr' => 30035, 'type' => 'STR', 'len' => 15, 'gain' => 1, 'group' => 'static', 'opt' => '', 'vt' => 3, 'pres' => 'text', 'pos' => 96],
            'SoftwareVersion' => ['name' => 'Software Wechselrichter', 'addr' => 30050, 'type' => 'STR', 'len' => 15, 'gain' => 1, 'group' => 'static', 'opt' => '', 'vt' => 3, 'pres' => 'text', 'pos' => 97],
            'BatterySoftwareVersion' => ['name' => 'Software Akku', 'addr' => 37814, 'type' => 'STR', 'len' => 15, 'gain' => 1, 'group' => 'static', 'opt' => 'battery', 'vt' => 3, 'pres' => 'text', 'pos' => 98],
            'RatedPower' => ['name' => 'Nennleistung Wechselrichter', 'addr' => 30073, 'type' => 'U32', 'gain' => 1, 'group' => 'static', 'opt' => '', 'vt' => 2, 'pres' => 'W', 'pos' => 92, 'min' => 0, 'max' => 1000000, 'kind' => 'gauge'],
            'BatteryMaxChargeLimit' => ['name' => 'Akku Ladeleistung max. (Gerät)', 'addr' => 37046, 'type' => 'U32', 'gain' => 1, 'group' => 'static', 'opt' => 'battery', 'vt' => 2, 'pres' => 'W', 'pos' => 94, 'min' => 0, 'max' => 1000000, 'kind' => 'gauge'],
            'BatteryMaxDischargeLimit' => ['name' => 'Akku Entladeleistung max. (Gerät)', 'addr' => 37048, 'type' => 'U32', 'gain' => 1, 'group' => 'static', 'opt' => 'battery', 'vt' => 2, 'pres' => 'W', 'pos' => 95, 'min' => 0, 'max' => 1000000, 'kind' => 'gauge'],
            'BatteryCapacity' => ['name' => 'Akku-Kapazität', 'addr' => 37758, 'type' => 'U32', 'gain' => 1000, 'group' => 'static', 'opt' => 'battery', 'vt' => 2, 'pres' => 'kWh', 'pos' => 93, 'min' => 0, 'max' => 1000, 'kind' => 'gauge']
        ];
    }

    /** Berechnete Werte (nur aus geprüften Messwerten). */
    public static function Derived(): array
    {
        return [
            'HouseConsumption' => ['name' => 'Hausverbrauch', 'vt' => 2, 'pres' => 'W', 'pos' => 12, 'opt' => 'meter'],
            'GridFeedIn' => ['name' => 'Einspeisung', 'vt' => 2, 'pres' => 'W', 'pos' => 14, 'opt' => 'meter'],
            'GridImport' => ['name' => 'Netzbezug', 'vt' => 2, 'pres' => 'W', 'pos' => 15, 'opt' => 'meter'],
            'SelfSufficiency' => ['name' => 'Autarkie heute', 'vt' => 2, 'pres' => 'pct1', 'pos' => 50, 'opt' => 'autarky'],
            'SelfConsumptionRate' => ['name' => 'Eigenverbrauch heute', 'vt' => 2, 'pres' => 'pct1', 'pos' => 51, 'opt' => 'autarky'],
            'HouseEnergyToday' => ['name' => 'Hausverbrauch heute', 'vt' => 2, 'pres' => 'kWh', 'pos' => 52, 'opt' => 'autarky'],
            'GridImportToday' => ['name' => 'Netzbezug heute', 'vt' => 2, 'pres' => 'kWh', 'pos' => 53, 'opt' => 'autarky'],
            'GridExportToday' => ['name' => 'Einspeisung heute', 'vt' => 2, 'pres' => 'kWh', 'pos' => 54, 'opt' => 'autarky'],
            'InverterOn' => ['name' => 'Wechselrichter eingeschaltet', 'vt' => 0, 'pres' => 'bool', 'pos' => 39, 'opt' => ''],
            'PV1Power' => ['name' => 'PV1 Leistung', 'vt' => 2, 'pres' => 'W', 'pos' => 72, 'opt' => 'strings'],
            'PV2Power' => ['name' => 'PV2 Leistung', 'vt' => 2, 'pres' => 'W', 'pos' => 75, 'opt' => 'strings']
        ];
    }

    /**
     * Schreibbare Einstellungen (Adresse/Typ/Faktor aus Definitions()).
     *   values  erlaubte Werte (Auswahl)
     *   min/max Bereich; max als Ident = Wert dieser Variable (Gerätegrenze), sonst default_max
     *   step    Schrittweite des Schiebereglers
     */
    public static function Controls(): array
    {
        return [
            // LUNA2000: Zeitsteuerung (LG) = 3 ist nicht zulässig
            'WorkingModeSetting' => ['values' => [0, 1, 2, 4, 5]],
            'MaxChargePower'     => ['min' => 0, 'max' => 'BatteryMaxChargeLimit', 'default_max' => 5000, 'step' => 100],
            'MaxDischargePower'  => ['min' => 0, 'max' => 'BatteryMaxDischargeLimit', 'default_max' => 5000, 'step' => 100],
            'ChargeCutoff'       => ['min' => 90, 'max' => 100, 'step' => 1],
            'DischargeCutoff'    => ['min' => 0, 'max' => 20, 'step' => 1],
            'BackupSOC'          => ['min' => 0, 'max' => 100, 'step' => 1],
            'GridCharge'         => ['values' => [0, 1]],
            'GridChargeCutoff'   => ['min' => 20, 'max' => 100, 'step' => 1],
            'GridChargePower'    => ['min' => 0, 'max' => 'BatteryMaxChargeLimit', 'default_max' => 5000, 'step' => 100],
            'PowerControlMode'   => ['values' => [0, 5, 6, 7]],
            'MaxFeedInPower'     => ['min' => 0, 'max' => 'RatedPower', 'default_max' => 10000, 'step' => 100],
            'MaxFeedInPercent'   => ['min' => 0, 'max' => 100, 'step' => 1]
        ];
    }

    /** Nur-Schreib-Register (Zwangsladen, Start/Stopp). */
    public const FORCE_POWER_CHARGE = 47247;    // U32 W
    public const FORCE_POWER_DISCHARGE = 47249; // U32 W
    public const FORCE_PERIOD = 47083;          // U16 min
    public const FORCE_SETTING_MODE = 47246;    // U16 0 = Dauer, 1 = Ziel-SoC
    public const FORCE_TARGET_SOC = 47101;      // U16 %·10
    public const FORCE_COMMAND = 47100;         // U16 0 Stopp, 1 Laden, 2 Entladen
    public const INVERTER_START = 40200;        // U16, Wert 0
    public const INVERTER_STOP = 40201;         // U16, Wert 0

    /** Anzeigewert → Registerwerte (16 Bit). */
    public static function Encode(array $def, float $value): array
    {
        $raw = (int) round($value * $def['gain']);
        switch ($def['type']) {
            case 'U16':
            case 'I16':
                return [$raw & 0xFFFF];
            case 'U32':
            case 'I32':
                $raw &= 0xFFFFFFFF;
                return [($raw >> 16) & 0xFFFF, $raw & 0xFFFF];
            default:
                throw new Exception('Typ ' . $def['type'] . ' nicht schreibbar');
        }
    }

    public static function Length(array $def): int
    {
        switch ($def['type']) {
            case 'U32':
            case 'I32':
                return 2;
            case 'STR':
                return (int) $def['len'];
            default:
                return 1;
        }
    }

    /**
     * Fasst Register zu Blöcken zusammen – nur lückenlos aneinander grenzende Register,
     * damit nie undefinierte Adressen abgefragt werden.
     *
     * @param array $defs   Ident => Definition
     * @param int   $maxGap Nur für den Schnappschuss: Lücke aus bekannten, dokumentierten Registern, die mitgelesen
     *                      werden darf (z. B. 32066–32079 zwischen PV- und Wirkleistung). Standard 0 = lückenlos.
     * @return array Liste von ['addr' => int, 'qty' => int, 'idents' => string[]]
     *               Die Lage eines Werts im Block ist immer addr(Wert) − addr(Block).
     */
    /**
     * Schnappschuss: Werte, die zeitgleich gebraucht werden (Bilanz PV/Netz/Akku/Haus). Sie werden in jedem
     * Durchlauf zuerst und direkt nacheinander gelesen – ohne andere Blöcke dazwischen.
     * Zwischen PV-Leistung (32064) und Wirkleistung (32080) bzw. Akkustatus (37000) und Ladestand (37004)
     * liegen nur dokumentierte Register (Spannungen/Ströme 32066–32079, Bus-Spannung 37003) → je ein Block.
     */
    public const SNAPSHOT = ['PVPower', 'ActivePower', 'BatteryStatus', 'BatteryPower', 'BatterySOC', 'GridPower'];
    public const SNAPSHOT_GAP = 16;

    public static function BuildBlocks(array $defs, int $maxGap = 0): array
    {
        uasort($defs, function ($a, $b) {
            return $a['addr'] <=> $b['addr'];
        });
        $blocks = [];
        $cur = null;
        foreach ($defs as $ident => $def) {
            $len = self::Length($def);
            // Nur lückenlose Register derselben Option zusammenfassen: Kennt ein Gerät ein optionales Register
            // nicht (ILLEGAL_DATA_ADDRESS), fällt nur dieser Block aus – nicht z. B. die Netzleistung.
            $end = $cur === null ? 0 : $cur['addr'] + $cur['qty'];
            if ($cur !== null && $def['addr'] >= $end && $def['addr'] <= $end + $maxGap
                && $def['addr'] + $len - $cur['addr'] <= self::MAX_BLOCK
                && ($def['opt'] ?? '') === $cur['opt']) {
                $cur['qty'] = $def['addr'] + $len - $cur['addr'];
                $cur['idents'][] = $ident;
                continue;
            }
            if ($cur !== null) {
                $blocks[] = $cur;
            }
            $cur = ['addr' => $def['addr'], 'qty' => $len, 'idents' => [$ident], 'opt' => $def['opt'] ?? ''];
        }
        if ($cur !== null) {
            $blocks[] = $cur;
        }
        return $blocks;
    }

    /**
     * Wandelt Rohregister (Liste von 16-Bit-Werten) in den Anzeigewert um.
     * Rückgabe null = von Huawei als ungültig gekennzeichnet.
     */
    public static function Decode(array $def, array $regs)
    {
        switch ($def['type']) {
            case 'U16':
                $raw = $regs[0];
                break;
            case 'I16':
                $raw = $regs[0] >= 0x8000 ? $regs[0] - 0x10000 : $regs[0];
                break;
            case 'U32':
                $raw = ($regs[0] << 16) | $regs[1];
                break;
            case 'I32':
                $raw = ($regs[0] << 16) | $regs[1];
                if ($raw >= 0x80000000) {
                    $raw -= 0x100000000;
                }
                break;
            case 'STR':
                $s = '';
                foreach ($regs as $r) {
                    $s .= chr(($r >> 8) & 0xFF) . chr($r & 0xFF);
                }
                return trim(str_replace("\0", '', $s));
            default:
                return null;
        }
        if (in_array($raw, self::INVALID[$def['type']], true)) {
            return null;
        }
        $value = $raw / $def['gain'];
        if (!empty($def['abs'])) {
            $value = abs($value);
        }
        return $value;
    }
}
