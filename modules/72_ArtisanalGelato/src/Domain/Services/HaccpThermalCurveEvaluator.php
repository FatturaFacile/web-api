<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Services;

use Carbon\CarbonImmutable;

/**
 * Pass/fail of a pasteurization or churning lot from its HACCP thermal readings (timestamp, degrees Celsius, optional phase
 * heat / cool / age / harden). A pasteurized base must reach the pasteurization temperature (default +85 C), reach +4 C within
 * the cooling window after the last reading at pasteurization temperature (default 120 minutes) and, when a hardening phase is
 * recorded, finish at -18 C or colder.
 */
final class HaccpThermalCurveEvaluator
{
    public const float PASTEURIZATION_CELSIUS = 85.0;

    public const float COLD_CHAIN_CELSIUS = 4.0;

    public const float HARDENING_CELSIUS = -18.0;

    public const int COOLING_WINDOW_MINUTES = 120;

    /**
     * @param  list<array{at: string, temperature_celsius: float|int|string, phase?: string|null}>  $readings
     * @return array{passed: bool, findings: list<string>, peak_celsius: float|null, cooling_minutes: int|null}
     */
    public function evaluate(array $readings, bool $requiresPasteurization = true): array
    {
        $points = [];

        foreach ($readings as $reading) {
            $points[] = ['at' => CarbonImmutable::parse($reading['at']), 'temp' => (float) $reading['temperature_celsius'], 'phase' => $reading['phase'] ?? null];
        }

        usort($points, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $findings = [];
        $peak = $points === [] ? null : max(array_column($points, 'temp'));
        $coolingMinutes = null;

        if ($points === []) {
            return ['passed' => false, 'findings' => ['HACCP_NO_READINGS'], 'peak_celsius' => null, 'cooling_minutes' => null];
        }

        if ($requiresPasteurization) {
            $hot = array_values(array_filter($points, static fn (array $p): bool => $p['temp'] >= self::PASTEURIZATION_CELSIUS));

            if ($hot === []) {
                $findings[] = 'HACCP_PASTEURIZATION_NOT_REACHED';
            } else {
                $lastHot = end($hot)['at'];
                $cold = array_values(array_filter($points, static fn (array $p): bool => $p['at'] >= $lastHot && $p['temp'] <= self::COLD_CHAIN_CELSIUS));

                if ($cold === []) {
                    $findings[] = 'HACCP_COLD_CHAIN_NOT_REACHED';
                } else {
                    $coolingMinutes = (int) round(($cold[0]['at']->getTimestamp() - $lastHot->getTimestamp()) / 60);

                    if ($coolingMinutes > self::COOLING_WINDOW_MINUTES) {
                        $findings[] = 'HACCP_COOLING_TOO_SLOW';
                    }
                }
            }
        }

        $hardening = array_values(array_filter($points, static fn (array $p): bool => $p['phase'] === 'harden'));

        if ($hardening !== [] && end($hardening)['temp'] > self::HARDENING_CELSIUS) {
            $findings[] = 'HACCP_HARDENING_NOT_REACHED';
        }

        return ['passed' => $findings === [], 'findings' => $findings, 'peak_celsius' => $peak, 'cooling_minutes' => $coolingMinutes];
    }
}
