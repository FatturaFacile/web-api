<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\AuditStatus;

/**
 * An inspection of a store by an area manager: scored answers, photos, notes and corrective actions.
 *
 * @property string $store_id
 * @property string $template_id
 * @property string|null $auditor_id
 * @property AuditStatus $status
 * @property Carbon $audited_on
 * @property string $score
 * @property string $max_score
 * @property string $score_percent
 * @property bool|null $passed
 * @property string|null $notes
 * @property list<array<string, mixed>>|null $answers
 * @property list<array{id: string, path: string, original_name: string, mime: string|null, size: int, criterion_code: string|null, caption: string|null, taken_at: string}>|null $photos
 * @property Carbon|null $submitted_at
 */
final class StoreAudit extends BaseEnterpriseModel
{
    protected $table = 'frn_audits';

    protected function casts(): array
    {
        return [
            'status' => AuditStatus::class,
            'audited_on' => 'date',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'score_percent' => 'decimal:2',
            'passed' => 'boolean',
            'answers' => 'array',
            'photos' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FranchiseStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(FranchiseStore::class, 'store_id');
    }

    /**
     * @return BelongsTo<AuditTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AuditTemplate::class, 'template_id');
    }

    /**
     * @return HasMany<AuditAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(AuditAction::class, 'audit_id');
    }
}
