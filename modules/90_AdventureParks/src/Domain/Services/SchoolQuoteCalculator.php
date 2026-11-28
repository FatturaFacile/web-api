<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Services;

use InvalidArgumentException;

/**
 * Prices a school trip: how many accompanying teachers travel free, how many are charged, the picnic seats and the total.
 *
 * Free teachers = floor(paying students / ratio) + one support teacher per student with a disability, never more than the
 * teachers who actually come. The charged teachers pay like a student; students with a disability do not pay.
 */
final readonly class SchoolQuoteCalculator
{
    public function __construct(private int $freeRatio = 15, private int $freePerDisabled = 1)
    {
        if ($freeRatio < 1) {
            throw new InvalidArgumentException('The free teacher ratio must be at least 1.');
        }
    }

    /**
     * @param  list<string>  $pricesPerStudent  price per student of every selected workshop
     * @return array{free_teachers: int, charged_teachers: int, picnic_seats: int, total_amount: string}
     */
    public function quote(int $payingStudents, int $disabledStudents, int $accompanyingTeachers, array $pricesPerStudent): array
    {
        $free = min($accompanyingTeachers, intdiv($payingStudents, $this->freeRatio) + $disabledStudents * $this->freePerDisabled);
        $charged = $accompanyingTeachers - $free;
        $heads = $payingStudents + $charged;
        $cents = 0;

        foreach ($pricesPerStudent as $price) {
            $cents += (int) round(((float) $price) * 100) * $heads;
        }

        return [
            'free_teachers' => $free,
            'charged_teachers' => $charged,
            'picnic_seats' => $payingStudents + $disabledStudents + $accompanyingTeachers,
            'total_amount' => number_format($cents / 100, 2, '.', ''),
        ];
    }
}
