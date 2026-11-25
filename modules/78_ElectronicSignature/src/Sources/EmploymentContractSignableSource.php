<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Sources;

use Illuminate\Database\Eloquent\Model;
use Modules\ElectronicSignature\Contracts\SignableDocumentSource;
use Modules\ElectronicSignature\DTOs\SignableDocument;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Services\SignatureTextResolver;
use Modules\ElectronicSignature\Services\SimpleDocumentRenderer;
use Modules\HumanResources\Models\EmploymentContract;

/**
 * Employment contracts, addenda and NDAs: bilateral signature of the HR manager and the employee. A contract with a
 * written-form clause needs a qualified signature (ZertES / eIDAS), which the compliance rule of the module enforces.
 * The outcome is recorded in the `terms` of the contract; its status is the business of the HR workflow.
 */
final class EmploymentContractSignableSource implements SignableDocumentSource
{
    public function __construct(private readonly SimpleDocumentRenderer $renderer, private readonly SignatureTextResolver $texts) {}

    public function type(): SignableType
    {
        return SignableType::EmploymentContract;
    }

    public function find(string $id): ?Model
    {
        return EmploymentContract::query()->find($id);
    }

    public function document(Model $record, string $locale): SignableDocument
    {
        if (! $record instanceof EmploymentContract) {
            throw new SignatureException('document_not_found', 404);
        }

        $t = fn (string $key): string => $this->texts->text($key, $locale, [], $record->tenant_id);
        $employee = $record->employee;
        $employeeName = (string) ($employee->full_name ?? trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')));
        $number = (string) ($record->contract_number ?? $record->id);
        $currency = (string) ($record->currency ?? 'EUR');

        $facts = [
            [$t('signatures.doc.number'), $number],
            [$t('signatures.doc.employee'), $employeeName],
            [$t('signatures.doc.contractType'), (string) $record->contract_type],
            [$t('signatures.doc.jobTitle'), (string) ($record->job_title ?? '')],
            [$t('signatures.doc.startDate'), $record->start_date->format('d.m.Y')],
            [$t('signatures.doc.endDate'), $record->end_date?->format('d.m.Y') ?? '—'],
            [$t('signatures.doc.weeklyHours'), (string) ($record->weekly_hours ?? '')],
            [$t('signatures.doc.salary'), number_format((float) ($record->base_annual_salary ?? 0), 2, '.', "'").' '.$currency],
            [$t('signatures.doc.agreement'), (string) ($record->collective_agreement ?? '')],
        ];

        $pdf = $this->renderer->render(
            $t('signatures.doc.employmentContract').' '.$number,
            $facts,
            [],
            array_filter([(string) $record->notes]),
            $t('signatures.doc.footer'),
        );

        return new SignableDocument($pdf, $number.' — '.$employeeName, (string) ($record->base_annual_salary ?? '0'), $currency, fileName: 'employment-contract-'.$number.'.pdf');
    }

    public function onCompleted(Model $record, SignatureEnvelope $envelope): void
    {
        $this->remember($record, $envelope, 'completed');
    }

    public function onDeclined(Model $record, SignatureEnvelope $envelope): void
    {
        $this->remember($record, $envelope, 'declined');
    }

    public function onExpired(Model $record, SignatureEnvelope $envelope): void
    {
        $this->remember($record, $envelope, 'expired');
    }

    private function remember(Model $record, SignatureEnvelope $envelope, string $outcome): void
    {
        if (! $record instanceof EmploymentContract) {
            return;
        }

        $terms = $record->terms ?? [];
        $terms['e_signature'] = [
            'envelope_id' => $envelope->id,
            'outcome' => $outcome,
            'level' => $envelope->required_level->value,
            'jurisdiction' => $envelope->jurisdiction->value,
            'verification_code' => $envelope->verification_code,
            'signed_sha256' => $envelope->signed_sha256,
            'at' => now()->toIso8601String(),
        ];

        $record->forceFill(['terms' => $terms])->save();
    }
}
