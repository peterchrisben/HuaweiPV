<?php

declare(strict_types=1);

/**
 * Prüfung von Archivdaten auf Ausreißer – für die Übernahme alter Archive und das Bereinigen bestehender.
 *
 * Symcon liefert Archivwerte vom neuesten zum ältesten (AC_GetLoggedValues). Deshalb läuft die Prüfung
 * rückwärts durch die Zeit: Ein Zähler darf rückwärts gesehen nie steigen und nicht schneller fallen, als
 * die Anlage hätte erzeugen können.
 *
 * Regeln (wie bei der Live-Prüfung, siehe HPVFilter):
 *  - feste Grenzen (min/max, 'P' = Plausibilitätsgrenze Leistung) → immer Ausreißer
 *  - Zähler / Tageszähler / Ladestand: Vergleich mit dem nächstneueren gültigen Wert (Anker)
 *  - Der erste Anker muss zu den folgenden Werten passen (ein Ausreißer als Startwert wird erkannt)
 *  - Auffällige Werte, die sich über mehrere Datensätze bestätigen (z. B. Zählertausch), gelten als echt
 *  - Tageszähler: Ein Wert in den ersten Minuten nach Mitternacht, der höher ist als der folgende (neuere)
 *    Wert desselben Tages, ist der verspätet geschriebene Endstand des Vortags → eigener Grund, nicht übernommen
 *
 * Reine Logik ohne Symcon-Funktionen → mit tests/unit.php prüfbar.
 */
class HPVArchive
{
    /** Zeitfenster nach Mitternacht für verspätet geschriebene Vortageswerte (Sekunden) */
    public const LATE_WINDOW = 300;

    public const REASON_LATE = 'Vortageswert nach Mitternacht';

    /**
     * @param array $def        Registerdefinition (min, max, kind, step)
     * @param array $records    Datensätze neueste zuerst: [['TimeStamp' => int, 'Value' => float], …]
     * @param array $state      Zustand über mehrere Seiten hinweg (wird fortgeschrieben)
     * @param float $powerLimit Plausibilitätsgrenze Leistung in W
     * @param int   $confirm    Anzahl zusammenpassender auffälliger Werte, ab der sie als echt gelten
     * @return array [array $ok, array $bad] – $bad-Einträge haben zusätzlich 'Reason'
     */
    public static function Check(array $def, array $records, array &$state, float $powerLimit, int $confirm): array
    {
        $ok = [];
        $bad = [];
        $confirm = max(2, $confirm);
        $count = count($records);
        for ($i = 0; $i < $count; $i++) {
            $record = $records[$i];
            $value = (float) $record['Value'];
            $time = (int) $record['TimeStamp'];

            if (!self::InLimits($def, $value, $powerLimit)) {
                $bad[] = $record + ['Reason' => sprintf('außerhalb der Grenzen (%s … %s)',
                    self::Show(self::Limit($def['min'] ?? null, $powerLimit)), self::Show(self::Limit($def['max'] ?? null, $powerLimit)))];
                continue;
            }
            if (!self::NeedsSequence($def)) {
                $ok[] = $record;
                continue;
            }

            $anchor = $state['anchor'] ?? null;
            if ($anchor === null) {
                // Startwert nur übernehmen, wenn er zu den nächsten (älteren) Werten passt
                $votes = 0;
                $checked = 0;
                for ($j = $i + 1; $j < $count && $checked < 3; $j++) {
                    $older = (float) $records[$j]['Value'];
                    if (!self::InLimits($def, $older, $powerLimit)) {
                        continue;
                    }
                    $checked++;
                    if (self::Consistent($def, ['v' => $value, 't' => $time], $older, (int) $records[$j]['TimeStamp'], $powerLimit)) {
                        $votes++;
                    }
                }
                if ($checked === 0 || $votes * 2 >= $checked) {
                    $state['anchor'] = ['v' => $value, 't' => $time];
                    $ok[] = $record;
                } else {
                    $bad[] = $record + ['Reason' => 'passt nicht zu den Nachbarwerten'];
                }
                continue;
            }

            if (self::LateDayValue($def, $anchor, $value, $time)) {
                // Endstand des Vortags mit Zeitstempel kurz nach 0 Uhr – gehört nicht in den neuen Tag
                $bad[] = $record + ['Reason' => self::REASON_LATE] + self::Ref($anchor);
                continue;
            }

            if (self::Consistent($def, $anchor, $value, $time, $powerLimit)) {
                // Vorherige Auffälligkeiten waren Einzel-Ausreißer
                foreach ($state['pending'] ?? [] as $p) {
                    $bad[] = $p;
                }
                $state['pending'] = [];
                $state['anchor'] = ['v' => $value, 't' => $time];
                $ok[] = $record;
                continue;
            }

            // Auffällig: sammeln, bis sich der Wert bestätigt oder als Ausreißer herausstellt
            $reason = self::Reason($def, $anchor, $value, $time);
            $pending = $state['pending'] ?? [];
            if ($pending !== []) {
                $last = end($pending);
                if (!self::Consistent($def, ['v' => (float) $last['Value'], 't' => (int) $last['TimeStamp']], $value, $time, $powerLimit)) {
                    foreach ($pending as $p) {
                        $bad[] = $p;
                    }
                    $pending = [];
                }
            }
            $pending[] = $record + ['Reason' => $reason] + self::Ref($anchor);
            if (count($pending) >= $confirm) {
                // Dauerhaft anderer Verlauf (z. B. Zählertausch) → echt
                foreach ($pending as $p) {
                    unset($p['Reason'], $p['Ref'], $p['RefTime']);
                    $ok[] = $p;
                }
                $pending = [];
                $state['anchor'] = ['v' => $value, 't' => $time];
            }
            $state['pending'] = $pending;
        }
        return [$ok, $bad];
    }

    /** Am Ende der Daten: nicht bestätigte auffällige Werte sind Ausreißer. */
    public static function Flush(array &$state): array
    {
        $bad = $state['pending'] ?? [];
        $state['pending'] = [];
        return $bad;
    }

    /** Vergleichswert (nächstneuerer gültiger Wert) für die Ausreißer-Liste */
    private static function Ref(array $anchor): array
    {
        return ['Ref' => (float) $anchor['v'], 'RefTime' => (int) $anchor['t']];
    }

    /** Tageszähler: höherer Wert in den ersten Minuten des Tages als der neuere Wert desselben Tages */
    private static function LateDayValue(array $def, array $newer, float $value, int $time): bool
    {
        if (($def['kind'] ?? '') !== 'daily' || date('Y-m-d', $time) !== date('Y-m-d', (int) $newer['t'])) {
            return false;
        }
        $sinceMidnight = $time - (int) strtotime(date('Y-m-d', $time) . ' 00:00:00');
        return $sinceMidnight <= self::LATE_WINDOW && $value > (float) $newer['v'] + 0.005;
    }

    private static function NeedsSequence(array $def): bool
    {
        return in_array($def['kind'] ?? 'gauge', ['counter', 'daily'], true) || isset($def['step']);
    }

    public static function InLimits(array $def, float $value, float $powerLimit): bool
    {
        $min = self::Limit($def['min'] ?? null, $powerLimit);
        $max = self::Limit($def['max'] ?? null, $powerLimit);
        // Kleine Toleranz: Altwerte enthalten oft Rundungsreste (z. B. 100,0000015 % aus Faktor 0,1 in einfacher Genauigkeit)
        $tol = fn(float $limit): float => 0.0001 + abs($limit) * 0.000001;
        return !(($min !== null && $value < $min - $tol($min)) || ($max !== null && $value > $max + $tol($max)));
    }

    /** Passt der ältere Wert ($value bei $time) zum neueren Anker? */
    private static function Consistent(array $def, array $newer, float $value, int $time, float $powerLimit): bool
    {
        $dt = max(1, (int) $newer['t'] - $time);
        $kind = $def['kind'] ?? 'gauge';
        if ($kind === 'counter' || $kind === 'daily') {
            if ($kind === 'daily' && date('Y-m-d', $time) !== date('Y-m-d', (int) $newer['t'])) {
                // Rückwärts über Mitternacht: älterer Wert muss zur Uhrzeit seines Tages passen
                $sinceMidnight = $time - (int) strtotime(date('Y-m-d', $time) . ' 00:00:00');
                return $value <= HPVFilter::MaxEnergyDelta(max(1, $sinceMidnight), $powerLimit) + 0.5;
            }
            return $value <= (float) $newer['v'] + 0.005
                && (float) $newer['v'] - $value <= HPVFilter::MaxEnergyDelta($dt, $powerLimit);
        }
        if (isset($def['step'])) {
            return $dt > HPVFilter::STEP_MAX_AGE || abs($value - (float) $newer['v']) <= (float) $def['step'];
        }
        return true;
    }

    private static function Reason(array $def, array $newer, float $value, int $time): string
    {
        $kind = $def['kind'] ?? 'gauge';
        if ($kind === 'counter' || $kind === 'daily') {
            return $value > (float) $newer['v'] ? 'Zähler läuft rückwärts' : 'Zählersprung';
        }
        return 'Sprung';
    }

    private static function Show(?float $limit): string
    {
        return $limit === null ? '–' : str_replace('.', ',', (string) round($limit, 3));
    }

    private static function Limit($limit, float $powerLimit): ?float
    {
        if ($limit === null) {
            return null;
        }
        if ($limit === 'P') {
            return $powerLimit;
        }
        if ($limit === '-P') {
            return -$powerLimit;
        }
        return (float) $limit;
    }
}
