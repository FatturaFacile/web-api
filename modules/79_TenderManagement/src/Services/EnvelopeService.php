<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\TenderManagement\Domain\Enums\EnvelopeType;
use Modules\TenderManagement\Domain\ValueObjects\ComplianceIssue;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

/**
 * The three sealed envelopes of a tender (administrative, technical, economic): their checklists, the document uploads with the
 * signature inspection (.p7m / PAdES), and the readiness gate that decides whether the dossier can be lodged. The envelopes
 * stay strictly separate: a document belongs to exactly one of them.
 */
final class EnvelopeService
{
    public function __construct(
        private readonly TenderStrategyRegistry $strategies,
        private readonly SignatureInspector $signatures,
        private readonly VaultService $vault,
    ) {}

    /**
     * Loads the default checklist of the jurisdiction, keeping what was already uploaded under the same key.
     */
    public function seed(TenderParticipation $participation, string $envelope): TenderParticipation
    {
        $type = $this->envelope($envelope);
        $template = $this->strategies->for($participation->notice->jurisdiction)->envelopeTemplate()[$type->value] ?? [];

        return $this->define($participation, $envelope, $template);
    }

    /**
     * Replaces the checklist of an envelope.
     *
     * @param  list<array{key: string, label?: string|null, required?: bool, signature_required?: bool}>  $items
     */
    public function define(TenderParticipation $participation, string $envelope, array $items): TenderParticipation
    {
        $type = $this->envelope($envelope);
        $existing = collect($participation->envelopeDocs($type->value))->keyBy('key');
        $next = [];

        foreach ($items as $item) {
            $key = (string) $item['key'];
            $current = $existing->get($key, []);
            $next[$key] = array_merge($this->blank($key), $current, [
                'key' => $key,
                'label' => $item['label'] ?? ($current['label'] ?? null),
                'required' => (bool) ($item['required'] ?? true),
                'signature_required' => (bool) ($item['signature_required'] ?? false),
            ]);
            $next[$key] = $this->withStatus($next[$key]);
        }

        foreach ($existing as $key => $current) {
            if (! isset($next[$key]) && ($current['path'] ?? null) !== null) {
                $this->deleteFile((string) $current['path']);
            }
        }

        $participation->forceFill([$type->value.'_envelope_docs' => array_values($next)])->save();

        return $participation;
    }

    /**
     * Stores a document in its envelope and inspects its signature.
     *
     * @return array<string, mixed> the updated item
     */
    public function upload(TenderParticipation $participation, string $envelope, string $docKey, UploadedFile $file): array
    {
        $type = $this->envelope($envelope);
        $docs = $participation->envelopeDocs($type->value);
        $index = $this->indexOf($docs, $docKey);

        if ($file->getSize() > (int) config('tender_management.uploads.max_kilobytes', 20480) * 1024) {
            throw new TenderException('file_too_large', 422);
        }

        $item = $docs[$index];

        if ($item['signature_required'] && ! $this->signatures->isSignatureFormat((string) $file->getClientOriginalName())) {
            throw new TenderException('invalid_signature_format');
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $signature = $this->signatures->inspect($contents, (string) $file->getClientOriginalName(), (bool) $item['signature_required']);

        if (($item['path'] ?? null) !== null) {
            $this->deleteFile((string) $item['path']);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension()) ?: 'bin';
        $path = "tender-management/{$participation->tenant_id}/{$participation->id}/{$type->value}/".bin2hex(random_bytes(8)).'.'.$extension;
        Storage::disk($this->disk())->put($path, $contents);

        $docs[$index] = $this->withStatus(array_merge($item, [
            'path' => $path,
            'file_name' => (string) $file->getClientOriginalName(),
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'uploaded_at' => CarbonImmutable::now()->toIso8601String(),
            'signature' => $signature,
        ]));

        $participation->forceFill([$type->value.'_envelope_docs' => $docs])->save();

        return $docs[$index];
    }

    public function remove(TenderParticipation $participation, string $envelope, string $docKey): void
    {
        $type = $this->envelope($envelope);
        $docs = $participation->envelopeDocs($type->value);
        $index = $this->indexOf($docs, $docKey);

        if (($docs[$index]['path'] ?? null) !== null) {
            $this->deleteFile((string) $docs[$index]['path']);
        }

        $docs[$index] = $this->withStatus(array_merge($docs[$index], $this->blank($docKey), ['key' => $docKey, 'label' => $docs[$index]['label'] ?? null, 'required' => $docs[$index]['required'], 'signature_required' => $docs[$index]['signature_required']]));
        $participation->forceFill([$type->value.'_envelope_docs' => $docs])->save();
    }

    /**
     * Per-envelope completeness and every compliance finding that stands between the dossier and its submission.
     *
     * @return array{ready: bool, envelopes: array<string, array{complete: bool, items: list<array<string, mixed>>}>, issues: list<array<string, mixed>>}
     */
    public function readiness(TenderParticipation $participation, CarbonImmutable $today): array
    {
        $notice = $participation->notice;
        $strategy = $this->strategies->for($notice->jurisdiction);
        $issues = $strategy->validateSubmission($notice, $participation, $this->vault->activeDocuments(), $today);
        $envelopes = [];

        foreach (EnvelopeType::cases() as $type) {
            $items = array_map(fn (array $item): array => $this->withStatus($item), $participation->envelopeDocs($type->value));
            $complete = $items !== [];

            foreach ($items as $item) {
                if ($item['required'] && $item['status'] !== 'ok') {
                    $complete = false;
                    $issues[] = $item['status'] === 'signature_invalid'
                        ? ComplianceIssue::blocking('signature_invalid', ['type' => $item['key']], $type->value)
                        : ComplianceIssue::blocking('document_missing', ['type' => $item['key']], $type->value);
                }
            }

            $envelopes[$type->value] = ['complete' => $complete, 'items' => array_map($this->publicItem(...), $items)];
        }

        $blocking = array_filter($issues, static fn (ComplianceIssue $issue): bool => $issue->isBlocking());

        return [
            'ready' => $blocking === [] && ! in_array(false, array_column($envelopes, 'complete'), true),
            'envelopes' => $envelopes,
            'issues' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $issues),
        ];
    }

    /**
     * The item without its storage path.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function publicItem(array $item): array
    {
        unset($item['path']);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withStatus(array $item): array
    {
        $signature = $item['signature'] ?? null;

        if (($item['file_name'] ?? null) === null) {
            $item['status'] = 'missing';
        } elseif ($item['signature_required'] && ($signature === null || $signature['status'] !== 'valid')) {
            $item['status'] = 'signature_invalid';
        } else {
            $item['status'] = 'ok';
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(string $key): array
    {
        return ['key' => $key, 'label' => null, 'required' => true, 'signature_required' => false, 'path' => null, 'file_name' => null, 'size' => null, 'sha256' => null, 'uploaded_at' => null, 'status' => 'missing', 'signature' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $docs
     */
    private function indexOf(array $docs, string $docKey): int
    {
        foreach ($docs as $index => $doc) {
            if ($doc['key'] === $docKey) {
                return $index;
            }
        }

        throw new TenderException('unknown_envelope_document', 404);
    }

    private function envelope(string $envelope): EnvelopeType
    {
        return EnvelopeType::tryFrom($envelope) ?? throw new TenderException('unknown_envelope', 404);
    }

    private function disk(): string
    {
        return (string) config('tender_management.uploads.disk', 'local');
    }

    private function deleteFile(string $path): void
    {
        Storage::disk($this->disk())->delete($path);
    }
}
