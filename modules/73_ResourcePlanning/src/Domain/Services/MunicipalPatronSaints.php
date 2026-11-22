<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Services;

/**
 * The patron saint day (Santo Patrono) of the larger Italian municipalities, keyed by the lower-case name of the municipality.
 * Municipalities that are not listed (Luino, for example) take their patron saint from the local holidays a tenant records, so
 * nothing is guessed.
 */
final class MunicipalPatronSaints
{
    /** @var array<string, array{month: int, day: int, name: string}> */
    private const array Saints = [
        'milano' => ['month' => 12, 'day' => 7, 'name' => "Sant'Ambrogio"],
        'roma' => ['month' => 6, 'day' => 29, 'name' => 'Santi Pietro e Paolo'],
        'torino' => ['month' => 6, 'day' => 24, 'name' => 'San Giovanni Battista'],
        'firenze' => ['month' => 6, 'day' => 24, 'name' => 'San Giovanni Battista'],
        'genova' => ['month' => 6, 'day' => 24, 'name' => 'San Giovanni Battista'],
        'monza' => ['month' => 6, 'day' => 24, 'name' => 'San Giovanni Battista'],
        'napoli' => ['month' => 9, 'day' => 19, 'name' => 'San Gennaro'],
        'bologna' => ['month' => 10, 'day' => 4, 'name' => 'San Petronio'],
        'palermo' => ['month' => 7, 'day' => 15, 'name' => 'Santa Rosalia'],
        'bari' => ['month' => 12, 'day' => 6, 'name' => 'San Nicola'],
        'trieste' => ['month' => 11, 'day' => 3, 'name' => 'San Giusto'],
        'verona' => ['month' => 5, 'day' => 21, 'name' => 'San Zeno'],
        'venezia' => ['month' => 4, 'day' => 25, 'name' => 'San Marco'],
        'padova' => ['month' => 6, 'day' => 13, 'name' => "Sant'Antonio"],
        'varese' => ['month' => 5, 'day' => 8, 'name' => 'San Vittore'],
        'como' => ['month' => 8, 'day' => 28, 'name' => "Sant'Abbondio"],
        'bergamo' => ['month' => 8, 'day' => 26, 'name' => "Sant'Alessandro"],
        'brescia' => ['month' => 2, 'day' => 15, 'name' => 'Santi Faustino e Giovita'],
        'cremona' => ['month' => 11, 'day' => 13, 'name' => "Sant'Omobono"],
        'pavia' => ['month' => 12, 'day' => 9, 'name' => 'San Siro'],
        'parma' => ['month' => 1, 'day' => 13, 'name' => "Sant'Ilario"],
        'modena' => ['month' => 1, 'day' => 31, 'name' => 'San Geminiano'],
        'catania' => ['month' => 2, 'day' => 5, 'name' => "Sant'Agata"],
        'cagliari' => ['month' => 10, 'day' => 30, 'name' => 'San Saturnino'],
    ];

    /**
     * @return array{month: int, day: int, name: string}|null
     */
    public static function for(?string $municipality): ?array
    {
        if ($municipality === null || trim($municipality) === '') {
            return null;
        }

        return self::Saints[mb_strtolower(trim($municipality))] ?? null;
    }
}
