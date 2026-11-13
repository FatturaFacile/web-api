<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use App\Contracts\PdfGenerator;
use App\Models\DeliveryNote;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Customs\Enums\DeclarationStatus;
use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDeclarationItem;
use Modules\Customs\Models\CustomsGatewayMessage;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Services\Edec\EdecDeclarationXmlBuilder;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The life of a declaration: draft, checks, submission through the channel of the company.
 */
final class CustomsDeclarationService
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly CustomsDeclarationValidator $validator,
        private readonly EdecDeclarationXmlBuilder $builder,
        private readonly CustomsGatewayManager $gateways,
        private readonly PdfGenerator $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $actorId): CustomsDeclaration
    {
        return DB::transaction(function () use ($data, $actorId): CustomsDeclaration {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $declaration = CustomsDeclaration::query()->create([
                ...$data,
                'trader_declaration_number' => $data['trader_declaration_number'] ?? $this->numbering->next('customs_declaration')['number'],
                'status' => DeclarationStatus::Draft,
                'created_by' => $actorId,
            ]);

            $this->replaceItems($declaration, $items);

            return $this->refreshTotals($declaration);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomsDeclaration $declaration, array $data): CustomsDeclaration
    {
        $this->assertEditable($declaration);

        return DB::transaction(function () use ($declaration, $data): CustomsDeclaration {
            $items = $data['items'] ?? null;
            unset($data['items']);

            $declaration->update([...$data, 'status' => DeclarationStatus::Draft]);

            if ($items !== null) {
                $this->replaceItems($declaration, $items);
            }

            return $this->refreshTotals($declaration);
        });
    }

    /**
     * A draft from a delivery note: the parties and the lines. Masses per line and customs codes are not on the note,
     * except the tariff code of the product, so the user completes them.
     */
    public function createFromDeliveryNote(DeliveryNote $note, DeclarationType $type, ?string $actorId): CustomsDeclaration
    {
        $note->loadMissing('items');
        $products = Product::query()->whereIn('id', $note->items->pluck('product_id')->filter())->get()->keyBy('id');

        $items = $note->items->map(static fn ($item): array => [
            'product_id' => $item->product_id,
            'description' => Str::limit((string) $item->description, 280, ''),
            'commodity_code' => (string) ($products->get($item->product_id)?->hs_code ?? ''),
        ])->all();

        return $this->create([
            'declaration_type' => $type,
            'delivery_note_id' => $note->id,
            'consignee_contact_id' => $note->contact_id,
            'trader_reference' => Str::limit((string) $note->ddt_number, 35, ''),
            'items' => $items,
        ], $actorId);
    }

    /**
     * @return array{errors: list<array{field: string, message: string}>, warnings: list<string>, status: string}
     */
    public function validate(CustomsDeclaration $declaration): array
    {
        $this->assertEditable($declaration);
        $declaration->load(['items', 'consignor', 'consignee', 'importer']);

        $result = $this->validator->check($declaration, $this->settings());
        $declaration->update(['status' => $result['errors'] === [] ? DeclarationStatus::Validated : DeclarationStatus::Draft]);

        return [...$result, 'status' => $declaration->status->value];
    }

    /**
     * The message for customs, for inspection. A transit declaration has none.
     */
    public function message(CustomsDeclaration $declaration): string
    {
        $declaration->load(['items', 'consignor', 'consignee', 'importer']);

        return $this->builder->build($declaration, $this->settings() ?? new CustomsSettings);
    }

    public function submit(CustomsDeclaration $declaration, Tenant $tenant): CustomsDeclaration
    {
        if ($declaration->status !== DeclarationStatus::Validated) {
            throw new CustomsException('Only a validated declaration can be submitted.');
        }

        $gateway = $this->gateways->for($tenant);
        $message = $declaration->declaration_type === DeclarationType::Transit && $gateway->name() === 'local'
            ? (string) json_encode(['trader_declaration_number' => $declaration->trader_declaration_number, 'type' => 'transit'])
            : $this->message($declaration);

        $this->log($declaration, $gateway->name(), 'out', 'declaration', $message, null);

        $result = $gateway->submit($tenant, $declaration, $message);

        $this->log($declaration, $gateway->name(), 'in', 'declaration_response', $result->response, $result->isAccepted ? 'accepted' : 'rejected');

        $declaration->update([
            'status' => $result->isAccepted ? DeclarationStatus::Accepted : DeclarationStatus::Rejected,
            'gateway' => $gateway->name(),
            'submitted_at' => now(),
            'accepted_at' => $result->isAccepted ? now() : null,
            'mrn' => $result->mrn,
            'mrn_version' => $result->version,
            'rejection_reason' => $result->rejectionReason,
        ]);

        return $declaration;
    }

    public function cancel(CustomsDeclaration $declaration): CustomsDeclaration
    {
        if (! $declaration->status->isEditable()) {
            throw new CustomsException('A submitted declaration is corrected or annulled at customs, not here.');
        }

        $declaration->update(['status' => DeclarationStatus::Cancelled]);

        return $declaration;
    }

    /**
     * The tariff lines used before, most recent first, one per combination of code, key, origin and description.
     *
     * @param  array{search?: string|null, commodity_code?: string|null, origin_country?: string|null, consignee_contact_id?: string|null}  $filters
     */
    public function tariffHistory(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = CustomsDeclarationItem::query()
            ->join('customs_goods_declarations as d', 'd.id', '=', 'customs_declaration_items.declaration_id')
            ->whereNull('d.deleted_at')
            ->where('d.status', '<>', DeclarationStatus::Cancelled->value)
            ->selectRaw('customs_declaration_items.commodity_code, customs_declaration_items.statistical_code, customs_declaration_items.origin_country, customs_declaration_items.description, customs_declaration_items.packaging_type, max(customs_declaration_items.id::text) as last_item_id, count(*) as times_used, max(d.created_at) as last_used_at')
            ->groupBy('customs_declaration_items.commodity_code', 'customs_declaration_items.statistical_code', 'customs_declaration_items.origin_country', 'customs_declaration_items.description', 'customs_declaration_items.packaging_type')
            ->orderByDesc('last_used_at');

        if (! empty($filters['search'])) {
            $query->whereLike('customs_declaration_items.description', '%'.$filters['search'].'%');
        }

        if (! empty($filters['commodity_code'])) {
            $query->where('customs_declaration_items.commodity_code', 'like', $filters['commodity_code'].'%');
        }

        if (! empty($filters['origin_country'])) {
            $query->where('customs_declaration_items.origin_country', $filters['origin_country']);
        }

        if (! empty($filters['consignee_contact_id'])) {
            $query->where('d.consignee_contact_id', $filters['consignee_contact_id']);
        }

        return $query->gridPaginate($perPage);
    }

    /**
     * Adds a line from an earlier one, with its customs data. The masses and values belong to the shipment.
     */
    public function reuseItem(CustomsDeclaration $declaration, CustomsDeclarationItem $source): CustomsDeclaration
    {
        $this->assertEditable($declaration);

        $declaration->items()->create([
            'line_number' => (int) $declaration->items()->max('line_number') + 1,
            ...$source->only(['product_id', 'trader_item_id', 'description', 'commodity_code', 'statistical_code', 'permit_obligation', 'non_customs_law_obligation', 'customs_clearance_type', 'commercial_good', 'vat_code', 'duty_rate', 'origin_country', 'preference', 'packaging_type']),
        ]);

        $declaration->update(['status' => DeclarationStatus::Draft]);

        return $this->refreshTotals($declaration);
    }

    public function renderPdf(CustomsDeclaration $declaration, Tenant $tenant): string
    {
        return $this->pdf->render('customs::declaration', [
            'declaration' => $declaration->load(['items', 'consignor', 'consignee', 'importer']),
            'tenant' => $tenant,
        ]);
    }

    private function settings(): ?CustomsSettings
    {
        return CustomsSettings::query()->first();
    }

    private function assertEditable(CustomsDeclaration $declaration): void
    {
        if (! $declaration->status->isEditable()) {
            throw new CustomsException("A declaration in status {$declaration->status->value} cannot be changed.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceItems(CustomsDeclaration $declaration, array $items): void
    {
        $declaration->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $declaration->items()->create([...$item, 'line_number' => $index + 1]);
        }
    }

    /**
     * The totals are always the sum of the lines.
     */
    private function refreshTotals(CustomsDeclaration $declaration): CustomsDeclaration
    {
        $items = $declaration->items()->get();
        $sum = static fn (string $field): string => $items->reduce(
            static fn (string $total, CustomsDeclarationItem $item): string => Decimal::add($total, (string) ($item->getAttribute($field) ?? '0')),
            '0',
        );

        $declaration->update([
            'total_gross_mass' => $sum('gross_mass'),
            'total_net_mass' => $sum('net_mass'),
            'total_statistical_value' => $sum('statistical_value'),
            'total_vat_value' => $sum('vat_value'),
            'total_packages' => (int) $items->sum('package_quantity'),
        ]);

        return $declaration->load('items');
    }

    private function log(CustomsDeclaration $declaration, string $gateway, string $direction, string $type, string $content, ?string $outcome): void
    {
        $id = (string) Str::uuid7();
        $path = "customs/{$declaration->tenant_id}/messages/{$declaration->id}/{$id}.xml";
        Storage::disk((string) config('customs.archive_disk'))->put($path, $content);

        CustomsGatewayMessage::query()->create([
            'id' => $id,
            'declaration_id' => $declaration->id,
            'direction' => $direction,
            'gateway' => $gateway,
            'message_type' => $type,
            'storage_path' => $path,
            'sha256' => hash('sha256', $content),
            'outcome' => $outcome,
        ]);
    }
}
