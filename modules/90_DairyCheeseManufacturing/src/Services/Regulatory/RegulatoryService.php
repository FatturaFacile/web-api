<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services\Regulatory;

use App\Services\Tenancy\TenantContext;
use Modules\DairyCheeseManufacturing\Domain\Enums\Authority;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\RegulatorySubmission;

/**
 * DBMilch.ch / TSM and SIAN declarations: validation and preview of the movements of a month, file generation (the generated
 * payload and its checksum are kept as the audit log) and the record of the submission.
 */
final class RegulatoryService
{
    public function __construct(private readonly MovementCollector $collector, private readonly DeclarationWriter $writer, private readonly TenantContext $tenantContext) {}

    /**
     * @return array{authority: string, period: string, valid: bool, errors: list<string>, warnings: list<string>, movements: list<array<string, mixed>>, totals: array<string, string|int>}
     */
    public function preview(Authority $authority, string $period): array
    {
        $result = $this->collector->collect($authority, $period);

        return [
            'authority' => $authority->value,
            'period' => $period,
            'valid' => $result['errors'] === [],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'movements' => array_map(static fn (array $m): array => [
                'date' => $m['date'], 'type' => $m['type'], 'description' => $m['description'], 'quantity_kg' => $m['quantity_kg'], 'reference' => $m['reference'],
            ], $result['movements']),
            'totals' => $result['totals'],
        ];
    }

    public function generate(Authority $authority, string $period, ?string $format, ?string $userId = null): RegulatorySubmission
    {
        $format ??= $authority === Authority::Dbmilch ? 'xml' : 'csv';
        $result = $this->collector->collect($authority, $period);
        $base = ['authority' => $authority->value, 'period' => $period, 'format' => $format, 'generated_at' => now(), 'created_by' => $userId];

        if ($result['errors'] !== []) {
            return RegulatorySubmission::query()->create($base + ['status' => 'failed', 'record_count' => 0, 'validation_errors' => $result['errors']]);
        }

        $tenant = (string) $this->tenantContext->getTenant()?->name;
        $payload = $format === 'xml'
            ? $this->writer->xml($authority, $period, $tenant, $result['movements'], $result['breeders'], $result['totals'])
            : $this->writer->csv($authority, $result['movements'], $result['breeders']);
        $name = ($authority === Authority::Dbmilch ? 'dbmilch_tsm_' : 'sian_registro_latte_').$period.'.'.$format;

        return RegulatorySubmission::query()->create($base + [
            'status' => 'generated', 'record_count' => count($result['movements']), 'checksum' => hash('sha256', $payload), 'file_name' => $name,
            'validation_errors' => $result['warnings'], 'payload' => $payload,
        ]);
    }

    public function markSubmitted(RegulatorySubmission $submission): RegulatorySubmission
    {
        if ($submission->status !== 'generated') {
            throw new DairyCheeseException('Only a generated declaration can be marked as submitted.', 'submission_not_generated');
        }

        $submission->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        return $submission;
    }

    /**
     * @return array{content: string, file_name: string, mime: string}
     */
    public function download(RegulatorySubmission $submission): array
    {
        $payload = RegulatorySubmission::query()->whereKey($submission->id)->value('payload');

        if ($submission->status === 'failed' || ! is_string($payload) || $payload === '') {
            throw new DairyCheeseException('The declaration has no file.', 'submission_without_file');
        }

        return [
            'content' => $payload,
            'file_name' => (string) $submission->file_name,
            'mime' => $submission->format === 'xml' ? 'application/xml' : 'text/csv',
        ];
    }
}
