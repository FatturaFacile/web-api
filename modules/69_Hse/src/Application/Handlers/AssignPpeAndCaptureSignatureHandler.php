<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Hse\Application\Commands\AssignPpeAndCaptureSignatureCommand;
use Modules\Hse\Domain\Contracts\EmployeeDossierGatewayInterface;
use Modules\Hse\Domain\Contracts\PpeStockGatewayInterface;
use Modules\Hse\Domain\Enums\PpeDeliveryStatus;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Domain\Exceptions\HseNotFoundException;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;
use Modules\Hse\Infrastructure\Services\VerbaleConsegnaPdfGenerator;
use Modules\HumanResources\Models\Employee;

/**
 * Validates the stock, decrements it and records the hand-over in one transaction; the PDF is rendered before the
 * transaction (it needs the hash, not the database) and filed in the dossier right after it commits, because no file
 * write belongs inside a transaction. The signature hash covers employee, product, lot, hand-over moment and payload.
 */
final class AssignPpeAndCaptureSignatureHandler
{
    public function __construct(
        private readonly PpeStockGatewayInterface $stock,
        private readonly EmployeeDossierGatewayInterface $dossier,
        private readonly VerbaleConsegnaPdfGenerator $pdf,
    ) {}

    public function handle(AssignPpeAndCaptureSignatureCommand $command): PpeDistributionRecord
    {
        $employee = Employee::query()->where('tenant_id', $command->tenantId)->whereKey($command->employeeId)->first()
            ?? throw new HseNotFoundException('Unknown employee.');
        $tenant = Tenant::query()->findOrFail($command->tenantId);
        $productName = DB::table('products')->where('tenant_id', $command->tenantId)->where('id', $command->productId)->value('name')
            ?? throw new HseNotFoundException('Unknown PPE product.');

        if ($command->quantity <= 0) {
            throw new HseException('The quantity must be positive.');
        }

        if ($this->stock->available($command->productId, $command->warehouseId, $command->lotOrSerialId) + 1e-9 < $command->quantity) {
            throw new HseException('Not enough stock of the PPE in the warehouse.');
        }

        // The moment is normalised to the application timezone (the column keeps no offset) before it is hashed, so the
        // hash can be recomputed from the stored record.
        $handedOverAt = $command->handedOverAt->setTimezone((string) config('app.timezone'));
        $hash = hash('sha256', implode('|', [
            $command->employeeId, $command->productId, (string) $command->lotOrSerialId,
            $handedOverAt->toIso8601String(), $command->signaturePayload,
        ]));

        $id = (string) Str::uuid7();
        $record = new PpeDistributionRecord([
            'id' => $id,
            'tenant_id' => $command->tenantId,
            'employee_id' => $command->employeeId,
            'product_id' => $command->productId,
            'warehouse_id' => $command->warehouseId,
            'lot_or_serial_id' => $command->lotOrSerialId,
            'quantity' => $command->quantity,
            'ce_marking_standard' => $command->ceMarkingStandard,
            'handed_over_at' => $handedOverAt,
            'replacement_due_at' => $command->replacementDueAt?->toDateString(),
            'employee_signature_hash' => $hash,
            'signature_image_base64_or_ref' => $command->signatureImage,
            'locale' => $command->locale,
            'delivery_status' => PpeDeliveryStatus::DeliveredSigned,
        ]);
        $binary = $this->pdf->render($record, $tenant, $employee, (string) $productName);

        $record = DB::transaction(function () use ($command, $record, $id): PpeDistributionRecord {
            $movementId = $this->stock->issue($command->productId, $command->warehouseId, $command->lotOrSerialId, $command->quantity, $id, $command->userId);
            $record->stock_movement_id = $movementId;
            $record->save();

            return $record;
        });

        $documentId = $this->dossier->archive(
            $command->tenantId, $command->employeeId, 'ppe_delivery_record',
            'Verbale di consegna DPI '.$command->handedOverAt->format('Y-m-d'), $binary, 'application/pdf', false, $command->userId,
        );
        $record->update(['verbale_consegna_pdf_id' => $documentId]);

        return $record;
    }
}
