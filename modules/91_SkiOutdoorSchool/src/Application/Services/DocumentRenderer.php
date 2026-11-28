<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\SkiOutdoorSchool\Domain\Enums\EnrollmentStatus;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\PayoutStatement;

/**
 * Printable documents of the school, in the language of the request: the bibs / passes of a group course (one per student, with the level
 * colour, meeting point and emergency contact) and the monthly payout statement of an instructor. Every text comes from lang/*\/ski_school.php.
 */
final class DocumentRenderer
{
    public function bibsHtml(GroupCourse $course, string $locale): string
    {
        $t = static fn (string $key, array $replace = []): string => (string) __('ski_school.pdf.'.$key, $replace, $locale);
        $course->loadMissing(['level', 'instructor', 'enrollments' => static fn ($query) => $query->where('status', EnrollmentStatus::ENROLLED->value)->orderBy('bib_number')]);
        $level = $course->level;
        $levelName = $level === null ? '' : (string) __('ski_school.levels.'.$level->system->value.'.'.$level->code, [], $locale);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $level?->color) === 1 ? (string) $level?->color : '#1e88e5';
        $cards = '';

        foreach ($course->enrollments as $enrollment) {
            $cards .= '<div class="bib"><div class="badge" style="background:'.$color.'">'.e($levelName).'</div>'
                .'<div class="number">'.e((string) ($enrollment->bib_number ?? '')).'</div>'
                .'<div class="name">'.e($enrollment->student_name).'</div>'
                .'<table>'
                .$this->row($t('instructor'), $course->instructor?->fullName() ?? '—')
                .$this->row($t('period'), $course->week_start->toDateString().' – '.$course->end_date->toDateString())
                .$this->row($t('time'), $course->daily_start_time)
                .$this->row($t('meeting_point'), (string) ($course->meeting_point ?? '—'))
                .$this->row($t('emergency_contact'), trim(($enrollment->emergency_contact_name ?? '—').' '.($enrollment->emergency_contact_phone ?? '')))
                .'</table><div class="code">'.e((string) $enrollment->booking_reference).'</div></div>';
        }

        return '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}h1{font-size:15px;margin:0 0 8px}'
            .'.bib{display:inline-block;width:46%;margin:1.5%;border:1px solid #888;padding:8px;vertical-align:top;page-break-inside:avoid}'
            .'.badge{color:#fff;font-weight:bold;padding:3px 6px;display:inline-block}.number{font-size:34px;font-weight:bold;float:right}'
            .'.name{font-size:16px;font-weight:bold;margin:8px 0}td{padding:1px 4px 1px 0;vertical-align:top}.code{margin-top:6px;color:#666;font-size:9px}'
            .'</style></head><body><h1>'.e($t('bibs_title', ['code' => $course->code])).'</h1>'.$cards.'</body></html>';
    }

    public function bibsPdf(GroupCourse $course, string $locale): string
    {
        return Pdf::loadHTML($this->bibsHtml($course, $locale))->setPaper('a4')->output();
    }

    public function statementHtml(PayoutStatement $statement, string $locale): string
    {
        $t = static fn (string $key, array $replace = []): string => (string) __('ski_school.pdf.'.$key, $replace, $locale);
        $statement->loadMissing(['instructor', 'commissions']);
        $money = static fn (mixed $value): string => number_format((float) $value, 2, '.', "'");
        $rows = '';

        foreach ($statement->commissions->sortBy('work_date') as $line) {
            $rows .= '<tr><td>'.e($line->work_date->toDateString()).'</td><td>'.e($t('source.'.$line->source_type->value)).'</td><td class="r">'.e(number_format((float) $line->hours, 2)).'</td>'
                .'<td class="r">'.e($money($line->gross_fee)).'</td><td class="r">'.e($money($line->retention_amount)).'</td><td class="r">'.e($money($line->net_fee)).'</td></tr>';
        }

        $regime = $statement->regime;
        $summary = ''
            .$this->row($t('hours_private'), number_format((float) $statement->private_hours, 2))
            .$this->row($t('hours_group'), number_format((float) $statement->group_hours, 2))
            .$this->row($t('hours_total'), number_format((float) $statement->hours_total, 2))
            .$this->row($t('gross_total'), $money($statement->gross_total))
            .$this->row($t('retention_total'), '- '.$money($statement->retention_total))
            .$this->row($t('withholding', ['percent' => number_format((float) $statement->withholding_percent, 2)]), '- '.$money($statement->withholding_amount))
            .$this->row('<b>'.e($t('net_payable')).'</b>', '<b>'.e($money($statement->net_payable)).'</b>', true);

        return '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}h1{font-size:16px}table.l{width:100%;border-collapse:collapse}'
            .'table.l th,table.l td{border-bottom:1px solid #ccc;padding:3px 4px;text-align:left}.r{text-align:right!important}.note{margin-top:12px;color:#555;font-size:10px}'
            .'</style></head><body><h1>'.e($t('statement_title')).' — '.e($statement->period_month).'</h1>'
            .'<p>'.e($t('statement_for')).': <b>'.e((string) $statement->instructor?->fullName()).'</b> · '.e($statement->reference).'</p>'
            .'<table class="l"><tr><th>'.e($t('date')).'</th><th>'.e($t('source_label')).'</th><th class="r">'.e($t('hours')).'</th><th class="r">'.e($t('gross')).'</th><th class="r">'.e($t('retention')).'</th><th class="r">'.e($t('net')).'</th></tr>'
            .$rows.'</table><br><table>'.$summary.'</table>'
            .'<p class="note">'.e($t('regime_note.'.$regime->value)).'</p></body></html>';
    }

    public function statementPdf(PayoutStatement $statement, string $locale): string
    {
        return Pdf::loadHTML($this->statementHtml($statement, $locale))->setPaper('a4')->output();
    }

    private function row(string $label, string $value, bool $raw = false): string
    {
        return '<tr><td>'.($raw ? $label : e($label)).'</td><td>'.($raw ? $value : e($value)).'</td></tr>';
    }
}
