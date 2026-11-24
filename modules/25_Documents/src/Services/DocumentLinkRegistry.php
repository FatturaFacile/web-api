<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\Attachment;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Modules\AssetManagement\Models\Asset;
use Modules\Documents\Models\DmsDocumentLink;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContract;
use Modules\Projects\Models\Project;
use Modules\ServiceManagement\Models\ServiceContract;

/**
 * Business records a document can link to, keyed by their API alias. Extends the targets of attachments, so an imported
 * attachment keeps its link. A new record type is added here.
 *
 * Resolution is tenant safe on its own, not only through the global scope of each model: the global scope also lets
 * generic records (without a tenant) through, and a link must never point at a record of another company or at a shared
 * one.
 */
final readonly class DocumentLinkRegistry
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
        'supplier' => Supplier::class,
        'purchase_requisition' => PurchaseRequisition::class,
    ];

    /**
     * Attributes tried in order to give a record a readable label.
     *
     * @var list<string>
     */
    private const array LabelAttributes = ['invoice_number', 'quote_number', 'order_number', 'po_number', 'contract_number', 'project_number', 'entry_number', 'number', 'title', 'name', 'company_name', 'original_name', 'description'];

    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return array<string, class-string<Model>>
     */
    public function targets(): array
    {
        return [...Attachment::TARGET_TYPES, ...self::ExtraTargets];
    }

    /**
     * The record of the active tenant, or null when the alias is unknown, the record does not exist, belongs to another
     * company, or is generic.
     */
    public function resolve(string $alias, string $id): ?Model
    {
        $class = $this->targets()[$alias] ?? null;

        if ($class === null) {
            return null;
        }

        $record = $class::query()->find($id);

        if ($record === null || (string) $record->getAttribute('tenant_id') !== (string) $this->tenantContext->getTenantId()) {
            return null;
        }

        return $record;
    }

    /**
     * Alias of.
     */
    public function aliasOf(string $morphClass): ?string
    {
        $alias = array_search($morphClass, $this->targets(), true);

        return $alias === false ? null : $alias;
    }

    /**
     * The link with the record it points at. A record deleted since linking shows as missing instead of failing.
     *
     * @return array{id: string, linkable_type: string, linkable_id: string, label: ?string, record_exists: bool, created_at: ?string}
     */
    public function describe(DmsDocumentLink $link): array
    {
        $record = $this->resolve((string) $link->linkable_type, (string) $link->linkable_id);
        $label = null;

        foreach (self::LabelAttributes as $attribute) {
            $value = $record?->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                $label = mb_substr($value, 0, 120);
                break;
            }
        }

        return [
            'id' => (string) $link->id,
            'linkable_type' => (string) $link->linkable_type,
            'linkable_id' => (string) $link->linkable_id,
            'label' => $label,
            'record_exists' => $record !== null,
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
