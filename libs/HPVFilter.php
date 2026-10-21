<?php

declare(strict_types=1);

/**
 * Plausibilitätsprüfung für Messwerte, bevor sie in eine Variable (und damit ins Archiv) geschrieben werden.
 *
 * Regeln:
 *  1. Harte Grenzen (min/max): außerhalb → immer verwerfen.
 *  2. Zähler (counter): dürfen nicht sinken, nicht auf 0 fallen und nicht schneller steigen,
 *     als es die Plausibilitätsgrenze Leistung in der vergangenen Zeit erlaubt.
 *  3. Tageszähler (daily): wie Zähler, aber bei Datumswechsel ist ein Neustart ab 0 erlaubt.
 *  4. Messwerte mit "step" (z. B. Ladestand): Sprung größer als step → verdächtig.
 *  Verdächtige Werte werden erst übernommen, wenn sie durch weitere Abfragen bestätigt werden
 *  (Anzahl einstellbar). So bleiben echte Änderungen erhalten, Einzel-Ausreißer fliegen raus.
 *
 * Reine Logik ohne Symcon-Funktionen → mit tests/unit.php prüfbar.
 */
class HPVFilter
{
    /** Ab diesem Alter des letzten Werts wird die Sprungprüfung bei Messwerten ausgesetzt. */
    public const STEP_MAX_AGE = 1800;

    /**
     * @param array      $def        Registerdefinition (min, max, kind, step)
     * @param float      $value      neuer, dekodierter Wert
     * @param array|null $state      bisheriger Zustand ['v','t','d','p','pc','pt'] oder null
     * @param int        $now        Unix-Zeit
     * @param int        $confirm    Anzahl Abfragen, die einen verdächtigen Wert bestätigen müssen (1 = sofort übernehmen)
     * @param float      $powerLimit Plausibilitätsgrenze Leistung in W
     * @param string     $reason     Grund bei Ablehnung
     * @return array [bool $accept, array $newState]
     */
    public static function Check(array $def, float $value, ?array $state, int $now, int $confirm, float $powerLimit, ?string &$reason = null): array
    {
        $reason = '';
        $min = self::Limit($def['min'] ?? null, $powerLimit);
        $max = self::Limit($def['max'] ?? null, $powerLimit);
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            $reason = sprintf('außerhalb %s … %s', self::Fmt($min), self::Fmt($max));
            return [false, $state ?? []];
        }

        $kind = $def['kind'] ?? 'gauge';
        $today = date('Y-m-d', $now);

        // Erster Wert: ohne Vergleich übernehmen (harte Grenzen sind geprüft)
        if ($state === null || !isset($state['v'])) {
            return [true, self::Accepted($value, $now, $today)];
        }

        $last = (float) $state['v'];
        $age = max(1, $now - (int) $state['t']);
        $suspect = '';
        $tolerance = 0.0;

        switch ($kind) {
            case 'counter':
            case 'daily':
                $maxDelta = self::MaxEnergyDelta($age, $powerLimit);
                $tolerance = $maxDelta;
                if ($kind === 'daily' && ($state['d'] ?? '') !== $today) {
                    // Neuer Tag: Wert muss zum seit Mitternacht Möglichen passen
                    $sinceMidnight = $now - (int) strtotime($today . ' 00:00:00');
                    if ($value > self::MaxEnergyDelta(max(1, $sinceMidnight), $powerLimit) + 0.5) {
                        $suspect = 'Tageszähler nach Datumswechsel zu hoch';
                    }
                    break;
                }
                if ($value < $last - 0.005) {
                    $suspect = sprintf('Zähler rückwärts (%s → %s)', self::Fmt($last), self::Fmt($value));
                } elseif ($value - $last > $maxDelta) {
                    $suspect = sprintf('Zählersprung +%s kWh in %d s', self::Fmt($value - $last), $age);
                }
                break;

            default:
                if (isset($def['step']) && $age <= self::STEP_MAX_AGE && abs($value - $last) > (float) $def['step']) {
                    $suspect = sprintf('Sprung %s → %s', self::Fmt($last), self::Fmt($value));
                    $tolerance = (float) $def['step'];
                }
                break;
        }

        if ($suspect === '') {
            return [true, self::Accepted($value, $now, $today)];
        }

        // Verdächtig: Bestätigung abwarten
        if ($confirm <= 1) {
            return [true, self::Accepted($value, $now, $today)];
        }
        $pending = $state['p'] ?? null;
        $count = (int) ($state['pc'] ?? 0);
        if ($pending !== null && abs($value - (float) $pending) <= max($tolerance, 0.01) + 0.0001) {
            $count++;
        } else {
            $count = 1;
        }
        if ($count >= $confirm) {
            return [true, self::Accepted($value, $now, $today)];
        }
        $state['p'] = $value;
        $state['pc'] = $count;
        $state['pt'] = $now;
        $reason = $suspect . sprintf(' – wartet auf Bestätigung (%d/%d)', $count, $confirm);
        return [false, $state];
    }

    /** Größtmöglicher Energiezuwachs in kWh für einen Zeitraum (mit Reserve). */
    public static function MaxEnergyDelta(int $seconds, float $powerLimit): float
    {
        return max(0.2, $powerLimit / 1000 * $seconds / 3600 * 1.2);
    }

    private static function Accepted(float $value, int $now, string $today): array
    {
        return ['v' => $value, 't' => $now, 'd' => $today];
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

    private static function Fmt(?float $v): string
    {
        if ($v === null) {
            return '–';
        }
        return rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',');
    }
}
