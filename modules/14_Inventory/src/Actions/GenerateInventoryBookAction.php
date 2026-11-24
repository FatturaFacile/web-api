<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryBookExport;
use Modules\Inventory\Services\InventoryBookService;

final class GenerateInventoryBookAction
{
    public function __construct(
        private readonly InventoryBookService $bookService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Builds the inventory book (JSON structure of `GET book`).
     *
     * @return array{meta: array<string, mixed>, rows: list<array<string, mixed>>, totals: array{quantity: string, total_value: string}, reconciliation: array{gl_inventory_balance: string, difference: string}}
     */
    public function build(int $year, ?string $asOf = null, ?string $warehouseId = null, bool $requireClosed = false): array
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        return $this->bookService->build($tenantId, $tenantId, $year, $asOf, $warehouseId, $requireClosed);
    }

    /**
     * Persists an immutable, checksummed export of the inventory book for audit retrieval.
     */
    public function export(int $year, ?string $asOf, ?string $warehouseId, bool $requireClosed, ?string $actorId): InventoryBookExport
    {
        $book = $this->build($year, $asOf, $warehouseId, $requireClosed);
        $payload = (string) json_encode($book, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $tenantId = (string) $this->tenantContext->getTenantId();

        return DB::transaction(fn (): InventoryBookExport => InventoryBookExport::create([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'year' => $year,
            'as_of' => $book['meta']['as_of'],
            'warehouse_id' => $warehouseId,
            'status' => 'generated',
            'rows_count' => count($book['rows']),
            'total_quantity' => $book['totals']['quantity'],
            'total_value' => $book['totals']['total_value'],
            'gl_inventory_balance' => $book['reconciliation']['gl_inventory_balance'],
            'difference' => $book['reconciliation']['difference'],
            'method_breakdown' => $book['meta']['method_breakdown'],
            'payload' => $payload,
            'checksum' => hash('sha256', $payload),
            'generated_by' => $actorId,
            'generated_at' => Carbon::now(),
        ]));
    }

    public function csv(InventoryBookExport $export): string
    {
        /** @var array{rows: list<array<string, mixed>>} $book */
        $book = $export->decodedPayload();

        return $this->bookService->toCsv($book);
    }

    /**
     * @param  array{rows: list<array<string, mixed>>}  $book
     */
    public function csvFromBook(array $book): string
    {
        return $this->bookService->toCsv($book);
    }
}
