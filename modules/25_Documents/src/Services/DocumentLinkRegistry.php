<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Modules\AssetManagement\Models\Asset;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Procurement\Models\SupplierContract;
use Modules\Projects\Models\Project;
use Modules\ServiceManagement\Models\ServiceContract;

/**
 * Business records a document can link to, keyed by their API alias. Extends the targets of attachments, so an imported
 * attachment keeps its link. A new record type is added here.
 */
final class DocumentLinkRegistry
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const array ExtraTargets = [
        'journal_entry' => JournalEntry::class,
        'project' => Project::class,
        'asset' => Asset::class,
        'service_contract' => ServiceContract::class,
        'supplier_contract' => SupplierContract::class,
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    public function targets(): array
    {
        return [...Attachment::TARGET_TYPES, ...self::ExtraTargets];
    }

    /**
     * The record of the active tenant, or null when the alias or the record is unknown.
     */
    public function resolve(string $alias, string $id): ?Model
    {
        $class = $this->targets()[$alias] ?? null;

        return $class === null ? null : $class::query()->find($id);
    }

    public function aliasOf(string $morphClass): ?string
    {
        $alias = array_search($morphClass, $this->targets(), true);

        return $alias === false ? null : $alias;
    }
}
