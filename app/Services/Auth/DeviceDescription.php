<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Readable browser and system for the security emails. The raw user agent is sent by the client, so it never reaches
 * a template: only these fixed names do.
 */
final class DeviceDescription
{
    /**
     * Checked in order: Edge and Opera also declare Chrome, and Chrome also declares Safari.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'EdgA/' => 'Edge',
        'EdgiOS/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'CriOS/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /**
     * Checked in order: Android declares Linux, and iOS declares "like Mac OS X".
     *
     * @var array<string, string>
     */
    private const SYSTEMS = [
        'Android' => 'Android',
        'iPhone' => 'iOS (iPhone)',
        'iPad' => 'iOS (iPad)',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    public static function from(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $names = array_filter([self::firstMatch(self::BROWSERS, $userAgent), self::firstMatch(self::SYSTEMS, $userAgent)]);

        return $names === [] ? null : implode(' · ', $names);
    }

    /**
     * @param  array<string, string>  $names
     */
    private static function firstMatch(array $names, string $userAgent): ?string
    {
        foreach ($names as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
