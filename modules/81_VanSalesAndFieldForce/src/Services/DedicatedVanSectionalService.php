<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Services;

use Illuminate\Support\Facades\DB;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

/**
 * Allocates collision-free definitive document numbers using each van's dedicated
 * fiscal sectionals (`sezionali`) for Immediate Invoices, Delivery Notes (DDT),
 * Commercial Receipts (RT), and offline handheld reservation batches.
 */
final class DedicatedVanSectionalService
{
    /**
     * Atomically allocate the next definitive document number on the van's sectional.
     *
     * @return array{sectional_code: string, sequential_number: int, document_number: string}
     */
    public function allocateNext(VanWarehouse $van, VanFiscalDocumentType $documentType, ?string $explicitOfflineNumber = null): array
    {
        return DB::transaction(function () use ($van, $documentType, $explicitOfflineNumber): array {
            /** @var VanWarehouse $locked */
            $locked = VanWarehouse::query()->whereKey($van->id)->lockForUpdate()->firstOrFail();

            [$codeField, $seqField] = match ($documentType) {
                VanFiscalDocumentType::ImmediateInvoice => ['invoice_sectional_code', 'invoice_next_number'],
                VanFiscalDocumentType::DeliveryNoteDdt => ['ddt_sectional_code', 'ddt_next_number'],
                VanFiscalDocumentType::CommercialReceiptRt => ['receipt_sectional_code', 'receipt_next_number'],
                VanFiscalDocumentType::SalesOrder => ['ddt_sectional_code', 'ddt_next_number'],
            };

            $sectionalCode = (string) $locked->{$codeField};
            $seq = (int) $locked->{$seqField};
            $year = (int) $locked->fiscal_year;

            if ($explicitOfflineNumber !== null && $explicitOfflineNumber !== '') {
                $locked->{$seqField} = $seq + 1;
                $locked->save();

                return [
                    'sectional_code' => $sectionalCode,
                    'sequential_number' => $seq,
                    'document_number' => $explicitOfflineNumber,
                ];
            }

            $prefix = $documentType === VanFiscalDocumentType::SalesOrder ? 'ORD-'.$sectionalCode : $sectionalCode;
            $docNumber = sprintf('%s/%d/%05d', $prefix, $year, $seq);

            $locked->{$seqField} = $seq + 1;
            $locked->save();

            return [
                'sectional_code' => $sectionalCode,
                'sequential_number' => $seq,
                'document_number' => $docNumber,
            ];
        });
    }

    /**
     * Reserve a contiguous batch of definitive fiscal numbers for 100% offline handheld operation.
     *
     * @return array{sectional_code: string, fiscal_year: int, range_start: int, range_end: int, reserved_numbers: list<string>}
     */
    public function reserveOfflineBatch(VanWarehouse $van, VanFiscalDocumentType $documentType, int $count = 25): array
    {
        $batchSize = max(1, min(200, $count));

        return DB::transaction(function () use ($van, $documentType, $batchSize): array {
            /** @var VanWarehouse $locked */
            $locked = VanWarehouse::query()->whereKey($van->id)->lockForUpdate()->firstOrFail();

            [$codeField, $seqField] = match ($documentType) {
                VanFiscalDocumentType::ImmediateInvoice => ['invoice_sectional_code', 'invoice_next_number'],
                VanFiscalDocumentType::DeliveryNoteDdt => ['ddt_sectional_code', 'ddt_next_number'],
                VanFiscalDocumentType::CommercialReceiptRt => ['receipt_sectional_code', 'receipt_next_number'],
                VanFiscalDocumentType::SalesOrder => ['ddt_sectional_code', 'ddt_next_number'],
            };

            $sectionalCode = (string) $locked->{$codeField};
            $start = (int) $locked->{$seqField};
            $end = $start + $batchSize - 1;
            $year = (int) $locked->fiscal_year;

            $numbers = [];
            for ($n = $start; $n <= $end; $n++) {
                $numbers[] = sprintf('%s/%d/%05d', $sectionalCode, $year, $n);
            }

            $locked->{$seqField} = $end + 1;
            $locked->sync_version = (int) $locked->sync_version + 1;
            $locked->save();

            return [
                'sectional_code' => $sectionalCode,
                'fiscal_year' => $year,
                'range_start' => $start,
                'range_end' => $end,
                'reserved_numbers' => $numbers,
            ];
        });
    }
}
