<?php

declare(strict_types=1);

/**
 * Tagesbilanz für Autarkie und Eigenverbrauchsquote.
 *
 * Huawei bietet beides nicht als Register an. Berechnung:
 *   PV-Erzeugung heute   = Integral der PV-Leistung (DC-Eingang, enthält auch die Akku-Ladung aus PV)
 *   Hausverbrauch heute  = Integral des Hausverbrauchs (Wechselrichter AC-Leistung − Netzleistung)
 *   Netzbezug heute      = Zähler Netzbezug gesamt − Stand um Mitternacht
 *   Einspeisung heute    = Zähler Einspeisung gesamt − Stand um Mitternacht
 *   Autarkie             = (Hausverbrauch − Netzbezug) / Hausverbrauch
 *   Eigenverbrauchsquote = (PV-Erzeugung − Einspeisung) / PV-Erzeugung
 *
 * Reine Logik ohne Symcon-Funktionen → mit tests/unit.php prüfbar.
 */
class HPVEnergy
{
    /** Größere Lücken zwischen zwei Leistungswerten werden nicht integriert (Ausfall). */
    public const MAX_GAP = 120;

    /** Unterhalb dieser Energie (kWh) ist eine Quote nicht aussagekräftig. */
    public const MIN_ENERGY = 0.05;

    /**
     * @param array      $s        bisheriger Zustand (leer beim ersten Aufruf)
     * @param float|null $pv       PV-Leistung in W (nur wenn in diesem Durchlauf geprüft gelesen)
     * @param float|null $house    Hausverbrauch in W (dito)
     * @param float|null $import   Zähler Netzbezug gesamt in kWh (dito)
     * @param float|null $export   Zähler Einspeisung gesamt in kWh (dito)
     */
    public static function Update(array $s, int $now, ?float $pv, ?float $house, ?float $import, ?float $export): array
    {
        $today = date('Y-m-d', $now);
        if (($s['d'] ?? '') !== $today) {
            // Neuer Tag: Integrale zurücksetzen, letzter Zählerstand des Vortags wird Tagesanfang
            $s = [
                'd'  => $today,
                'pv' => 0.0,
                'h'  => 0.0,
                't'  => $s['t'] ?? 0,
                'lp' => $s['lp'] ?? 0.0,
                'lh' => $s['lh'] ?? 0.0,
                'ib' => $s['il'] ?? null,
                'eb' => $s['el'] ?? null,
                'il' => $s['il'] ?? null,
                'el' => $s['el'] ?? null
            ];
        }

        if ($pv !== null && $house !== null) {
            $pv = max(0.0, $pv);
            $house = max(0.0, $house);
            $dt = $now - (int) ($s['t'] ?? 0);
            if (($s['t'] ?? 0) > 0 && $dt > 0 && $dt <= self::MAX_GAP) {
                $s['pv'] += ($s['lp'] + $pv) / 2 * $dt / 3600;
                $s['h'] += ($s['lh'] + $house) / 2 * $dt / 3600;
            }
            $s['t'] = $now;
            $s['lp'] = $pv;
            $s['lh'] = $house;
        }

        if ($import !== null) {
            if ($s['ib'] === null) {
                $s['ib'] = $import;
            }
            $s['il'] = $import;
        }
        if ($export !== null) {
            if ($s['eb'] === null) {
                $s['eb'] = $export;
            }
            $s['el'] = $export;
        }
        return $s;
    }

    /**
     * @return array ['pv' => kWh, 'house' => kWh, 'import' => kWh|null, 'export' => kWh|null,
     *                'autarky' => %|null, 'selfConsumption' => %|null]
     */
    public static function Results(array $s): array
    {
        $pv = ($s['pv'] ?? 0.0) / 1000;
        $house = ($s['h'] ?? 0.0) / 1000;
        $import = (isset($s['il'], $s['ib'])) ? max(0.0, $s['il'] - $s['ib']) : null;
        $export = (isset($s['el'], $s['eb'])) ? max(0.0, $s['el'] - $s['eb']) : null;

        $autarky = null;
        if ($import !== null && $house >= self::MIN_ENERGY) {
            $autarky = self::Clamp(($house - $import) / $house * 100);
        }
        $self = null;
        if ($export !== null && $pv >= self::MIN_ENERGY) {
            $self = self::Clamp(($pv - $export) / $pv * 100);
        }
        return ['pv' => $pv, 'house' => $house, 'import' => $import, 'export' => $export, 'autarky' => $autarky, 'selfConsumption' => $self];
    }

    private static function Clamp(float $v): float
    {
        return max(0.0, min(100.0, $v));
    }
}
