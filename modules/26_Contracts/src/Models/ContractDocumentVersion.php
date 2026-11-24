<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A version of the text of a contract (assembled from a template or edited during redlining).
 *
 * @property string $contract_kind
 * @property string $contract_id
 * @property int $version_no
 * @property string $body
 * @property string $source
 * @property string|null $template_id
 * @property array<string, mixed>|null $variables
 * @property string|null $round_id
 * @property string|null $author_id
 * @property string|null $notes
 * @property string $body_hash
 * @property int|null $open_comments_count Set by withCount() on the document version listing.
 */
final class ContractDocumentVersion extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Sources = ['template', 'edit', 'counterparty'];

    protected $table = 'contract_document_versions';

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'variables' => 'array'];
    }

    /**
     * @return HasMany<ContractComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ContractComment::class, 'document_version_id');
    }
}
