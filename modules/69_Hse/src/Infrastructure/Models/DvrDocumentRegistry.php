<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A revision of the Italian DVR (Documento di Valutazione dei Rischi) with the signatures of the employer, the RSPP,
 * the RLS and the occupational physician.
 *
 * @property int $revision_number
 * @property Carbon $issued_at
 * @property Carbon|null $review_due_at
 * @property array<string, mixed>|null $signatures_json
 * @property string|null $document_id
 * @property string $status
 */
final class DvrDocumentRegistry extends BaseEnterpriseModel
{
    /** The roles whose signature completes a revision. */
    public const array RequiredSignatories = ['employer', 'rspp', 'rls', 'medico_competente'];

    protected $table = 'hse_dvr_documents';

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'review_due_at' => 'date', 'signatures_json' => 'array'];
    }
}
