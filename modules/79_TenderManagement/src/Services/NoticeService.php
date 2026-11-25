<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Modules\TenderManagement\Domain\Enums\AwardCriterion;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\Enums\ProcedureType;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\TenderNotice;
use Throwable;

/**
 * Tender notices: manual entry and the ingestion of a simap.ch publication (project number, localised title, procurement
 * office, procedure type, CPV codes and the offer and question deadlines).
 */
final class NoticeService
{
    /** @var list<string> */
    private const array Fillable = [
        'title', 'description', 'jurisdiction', 'procedure_type', 'contracting_authority_name', 'contracting_authority_contact_id',
        'simap_project_number', 'cig_code_it', 'cup_code_it', 'rup_name', 'dedicated_bank_account_it', 'cpv_category_codes',
        'award_criterion', 'formula_type', 'currency', 'base_auction_amount', 'non_subject_safety_costs_amount',
        'estimated_labor_cost_amount', 'technical_max_score', 'economic_max_score', 'required_soa_categories',
        'required_min_turnover', 'turnover_reference_years', 'required_similar_projects_count', 'required_similar_projects_amount',
        'required_certifications', 'publication_date', 'clarification_deadline_at', 'submission_peremptory_deadline_at', 'status',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): TenderNotice
    {
        $attributes = array_intersect_key($data, array_flip(self::Fillable));

        if (($attributes['award_criterion'] ?? null) === AwardCriterion::Oepv->value && blank($attributes['formula_type'] ?? null)) {
            throw new TenderException('formula_required');
        }

        return TenderNotice::create(array_merge($attributes, ['created_by' => $userId]))->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TenderNotice $notice, array $data, ?string $userId): TenderNotice
    {
        $notice->fill(array_merge(array_intersect_key($data, array_flip(self::Fillable)), ['updated_by' => $userId]));

        if ($notice->award_criterion === AwardCriterion::Oepv && $notice->formula_type === null) {
            throw new TenderException('formula_required');
        }

        $notice->save();

        return $notice->refresh();
    }

    /**
     * Creates a notice from a simap.ch publication payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingestSimap(array $payload, ?string $userId, string $locale = 'it'): TenderNotice
    {
        $number = $this->first($payload, ['projectNumber', 'project-number', 'project_number', 'projectId', 'id']);
        $title = $this->localised($this->first($payload, ['title', 'projectTitle', 'project-title']), $locale);

        if ($number === null && $title === null) {
            throw new TenderException('simap_payload_invalid');
        }

        $existing = $number !== null ? TenderNotice::query()->where('simap_project_number', (string) $number)->first() : null;
        $authority = $this->localised($this->first($payload, ['procOfficeName', 'procurementOffice', 'contractingAuthority', 'orderAddress']), $locale);

        $attributes = [
            'title' => $title ?? 'simap '.$number,
            'description' => $this->localised($this->first($payload, ['description', 'projectDescription', 'orderDescription']), $locale),
            'jurisdiction' => Jurisdiction::SwissSimap->value,
            'procedure_type' => $this->procedure($this->first($payload, ['procedureType', 'procedure-type', 'procedure'])),
            'contracting_authority_name' => $authority ?? 'simap.ch',
            'simap_project_number' => $number === null ? null : (string) $number,
            'cpv_category_codes' => $this->cpvCodes($this->first($payload, ['cpvCodes', 'cpvCode', 'cpv'])),
            'award_criterion' => AwardCriterion::LowestPrice->value,
            'currency' => 'CHF',
            'publication_date' => $this->date($this->first($payload, ['publicationDate', 'publication-date', 'publishedAt'])),
            'clarification_deadline_at' => $this->date($this->first($payload, ['questionDeadline', 'question-deadline', 'clarificationDeadline'])),
            'submission_peremptory_deadline_at' => $this->date($this->first($payload, ['offerDeadline', 'offer-deadline', 'submissionDeadline'])),
        ];

        $estimated = $this->first($payload, ['estimatedValue', 'estimated-value', 'baseAmount']);
        if (is_numeric($estimated)) {
            $attributes['base_auction_amount'] = number_format((float) $estimated, 2, '.', '');
        }

        if ($existing !== null) {
            $existing->fill(array_merge($attributes, ['updated_by' => $userId]))->save();

            return $existing->refresh();
        }

        return TenderNotice::create(array_merge($attributes, ['status' => 'open', 'created_by' => $userId]))->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function first(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== []) {
                return $payload[$key];
            }
        }

        return null;
    }

    private function localised(mixed $value, string $locale): ?string
    {
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ([$locale, 'it', 'de', 'fr', 'en'] as $language) {
            if (isset($value[$language]) && is_string($value[$language]) && trim($value[$language]) !== '') {
                return trim($value[$language]);
            }
        }

        $first = reset($value);

        return is_string($first) && trim($first) !== '' ? trim($first) : null;
    }

    private function procedure(mixed $value): string
    {
        $text = strtolower(str_replace(['-', ' '], '_', (string) $value));

        return match (true) {
            str_contains($text, 'select') => ProcedureType::Selective->value,
            str_contains($text, 'invit') => ProcedureType::Invitation->value,
            str_contains($text, 'direct') || str_contains($text, 'freih') || str_contains($text, 'gre_de_gre') => ProcedureType::DirectAward->value,
            default => ProcedureType::Open->value,
        };
    }

    /**
     * @return list<string>
     */
    private function cpvCodes(mixed $value): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? preg_split('/[\s,;]+/', $value) : []);
        $codes = [];

        foreach ((array) $items as $item) {
            $code = is_array($item) ? (string) ($item['code'] ?? '') : (string) $item;

            if (preg_match('/^(\d{8})(?:-\d)?$/', trim($code), $match) === 1) {
                $codes[] = $match[1];
            }
        }

        return array_values(array_unique($codes));
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
