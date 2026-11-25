<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\FranchiseNetwork\Domain\Enums\ActionStatus;
use Modules\FranchiseNetwork\Domain\Enums\AuditStatus;
use Modules\FranchiseNetwork\Domain\Support\AuditScorer;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\AuditAction;
use Modules\FranchiseNetwork\Models\AuditTemplate;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\StoreAudit;

/**
 * Store inspections by the area manager: signage, cleanliness, visual merchandising, layout and stock / pricing compliance.
 * A draft keeps the scores, notes and photo metadata while the manager walks the store (also from a tablet); submitting scores it,
 * opens a corrective action for every weak criterion and locks it. Photos live on the private disk, only their metadata is stored.
 */
final class AuditService
{
    private const int MAX_PHOTOS = 40;

    public function start(FranchiseStore $store, AuditTemplate $template, ?string $auditorId, ?string $auditedOn): StoreAudit
    {
        if (! $template->is_active) {
            throw new FranchiseNetworkException('audit_template_inactive', 409);
        }

        return StoreAudit::query()->create([
            'store_id' => $store->id,
            'template_id' => $template->id,
            'auditor_id' => $auditorId,
            'status' => AuditStatus::Draft->value,
            'audited_on' => $auditedOn ?? CarbonImmutable::today()->toDateString(),
            'answers' => [],
            'photos' => [],
            'created_by' => $auditorId,
        ])->refresh();
    }

    /**
     * @param  list<array{criterion_code: string, score: int|float|string, notes?: string|null}>  $answers
     */
    public function saveAnswers(StoreAudit $audit, array $answers, ?string $notes, ?string $userId): StoreAudit
    {
        $this->assertDraft($audit);
        $criteria = collect($audit->template->criteria)->keyBy('code');
        $current = collect($audit->answers ?? [])->keyBy('criterion_code');

        foreach ($answers as $answer) {
            $criterion = $criteria->get($answer['criterion_code']);

            if ($criterion === null) {
                throw new FranchiseNetworkException('audit_criterion_unknown', 422, ['criterion' => $answer['criterion_code']]);
            }

            if ((float) $answer['score'] < 0 || (float) $answer['score'] > (float) ($criterion['max_score'] ?? 5)) {
                throw new FranchiseNetworkException('audit_score_out_of_range', 422, ['criterion' => $answer['criterion_code']]);
            }

            $current->put($answer['criterion_code'], ['criterion_code' => $answer['criterion_code'], 'score' => $answer['score'], 'notes' => $answer['notes'] ?? null]);
        }

        $audit->update(['answers' => $current->values()->all(), 'notes' => $notes ?? $audit->notes, 'updated_by' => $userId]);

        return $audit->refresh();
    }

    /**
     * @return array<string, mixed> metadata of the stored photo
     */
    public function addPhoto(StoreAudit $audit, UploadedFile $file, ?string $criterionCode, ?string $caption, ?string $userId): array
    {
        $this->assertDraft($audit);
        $photos = $audit->photos ?? [];

        if (count($photos) >= self::MAX_PHOTOS) {
            throw new FranchiseNetworkException('audit_too_many_photos', 422);
        }

        $id = (string) Str::uuid7();
        $path = $file->storeAs('franchise-network/'.$audit->tenant_id.'/audits/'.$audit->id, $id.'.'.($file->guessExtension() ?: 'jpg'), $this->disk());
        $photo = [
            'id' => $id,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'criterion_code' => $criterionCode,
            'caption' => $caption,
            'taken_at' => now()->toIso8601String(),
        ];

        $audit->update(['photos' => [...$photos, $photo], 'updated_by' => $userId]);

        return $photo;
    }

    public function removePhoto(StoreAudit $audit, string $photoId, ?string $userId): StoreAudit
    {
        $this->assertDraft($audit);
        $photos = collect($audit->photos ?? []);
        $photo = $photos->firstWhere('id', $photoId);

        if ($photo === null) {
            throw new FranchiseNetworkException('audit_photo_not_found', 404);
        }

        Storage::disk($this->disk())->delete($photo['path']);
        $audit->update(['photos' => $photos->reject(static fn (array $row): bool => $row['id'] === $photoId)->values()->all(), 'updated_by' => $userId]);

        return $audit->refresh();
    }

    /**
     * @return array{path: string, mime: string|null, name: string}
     */
    public function photo(StoreAudit $audit, string $photoId): array
    {
        $photo = collect($audit->photos ?? [])->firstWhere('id', $photoId);

        if ($photo === null || ! Storage::disk($this->disk())->exists($photo['path'])) {
            throw new FranchiseNetworkException('audit_photo_not_found', 404);
        }

        return ['path' => Storage::disk($this->disk())->path($photo['path']), 'mime' => $photo['mime'] ?? null, 'name' => $photo['original_name']];
    }

    public function submit(StoreAudit $audit, ?string $userId): StoreAudit
    {
        $this->assertDraft($audit);
        $template = $audit->template;
        $answers = collect($audit->answers ?? [])->keyBy('criterion_code');
        $missing = collect($template->criteria)->pluck('code')->diff($answers->keys());

        if ($missing->isNotEmpty()) {
            throw new FranchiseNetworkException('audit_incomplete', 422, ['missing' => $missing->count()]);
        }

        $result = AuditScorer::score($template->criteria, $answers->map(static fn (array $row) => $row['score'])->all(), (string) $template->pass_threshold_percent);
        $criteria = collect($template->criteria)->keyBy('code');

        return DB::transaction(function () use ($audit, $result, $answers, $userId): StoreAudit {
            $audit->update([
                'status' => AuditStatus::Submitted->value,
                'score' => $result['score'],
                'max_score' => $result['max_score'],
                'score_percent' => $result['score_percent'],
                'passed' => $result['passed'],
                'submitted_at' => now(),
                'updated_by' => $userId,
            ]);

            foreach ($result['weak'] as $code) {
                $critical = in_array($code, $result['failed_critical'], true);
                AuditAction::query()->create([
                    'audit_id' => $audit->id,
                    'store_id' => $audit->store_id,
                    'criterion_code' => $code,
                    'description' => (string) ($answers->get($code)['notes'] ?? '') !== '' ? (string) $answers->get($code)['notes'] : ('criterion:'.$code),
                    'due_date' => CarbonImmutable::today()->addDays((int) config($critical ? 'franchise_network.audit.critical_action_days' : 'franchise_network.audit.action_days'))->toDateString(),
                    'status' => ActionStatus::Open->value,
                    'created_by' => $userId,
                ]);
            }

            return $audit->refresh();
        });
    }

    public function completeAction(AuditAction $action, ?string $note, ?string $userId): AuditAction
    {
        if ($action->status === ActionStatus::Done) {
            throw new FranchiseNetworkException('audit_action_already_done', 409);
        }

        $action->update(['status' => ActionStatus::Done->value, 'completed_at' => now(), 'completion_note' => $note, 'updated_by' => $userId]);

        return $action->refresh();
    }

    private function disk(): string
    {
        return (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
    }

    private function assertDraft(StoreAudit $audit): void
    {
        if ($audit->status !== AuditStatus::Draft) {
            throw new FranchiseNetworkException('audit_locked', 409);
        }
    }
}
