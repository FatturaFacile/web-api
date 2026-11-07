<?php

declare(strict_types=1);

use Modules\Payroll\Calculation\Swiss\ShortTimeCompensation;

function shortTime(string $target = '176', string $worked = '136', string $absence = '0', string $overtime = '0', string $monthly = '6000', string $weekly = '42', string $waiting = '1', string $percent = '80', string $cap = '12350'): array
{
    return ShortTimeCompensation::calculate($target, $worked, $absence, $overtime, $monthly, $weekly, $waiting, $percent, $cap);
}

test('the waiting day comes off the lost hours and 80 percent of the earnings is compensated', function (): void {
    // 40 lost hours, one day of 8.4 hours waiting, 31.6 hours × 6000 / 176 = 1077.27, of which 80 % = 861.82.
    expect(shortTime())->toMatchArray([
        'lost_hours' => '40.00',
        'waiting_hours' => '8.40',
        'compensable_hours' => '31.60',
        'lost_earnings' => '1077.27',
        'compensation' => '861.82',
        'wage_reduction' => '1077.27',
    ]);
});

test('the insured salary is capped but the wage reduction follows the real salary', function (): void {
    // 31.6 hours × 12350 / 176 = 2217.39, of which 80 % = 1773.91; the wage goes down by 31.6 × 15000 / 176 = 2693.18.
    expect(shortTime(monthly: '15000'))->toMatchArray([
        'insured_monthly_salary' => '12350.00',
        'lost_earnings' => '2217.39',
        'compensation' => '1773.91',
        'wage_reduction' => '2693.18',
    ]);
});

test('absences and overtime reduce the lost hours and the waiting time never exceeds them', function (): void {
    expect(shortTime(worked: '150', absence: '8', overtime: '10'))->toMatchArray(['lost_hours' => '8.00', 'waiting_hours' => '8.00', 'compensable_hours' => '0.00', 'compensation' => '0.00']);
});

test('no target hours mean no compensation and no division by zero', function (): void {
    expect(shortTime(target: '0', worked: '0'))->toMatchArray(['lost_hours' => '0', 'compensation' => '0.00']);
});

test('the rate and waiting days are arguments, nothing is built in', function (): void {
    expect(shortTime(waiting: '2', percent: '70'))->toMatchArray(['waiting_hours' => '16.80', 'compensable_hours' => '23.20', 'compensation' => '553.64']);
});
