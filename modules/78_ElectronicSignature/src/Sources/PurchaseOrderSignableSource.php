<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Sources;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Modules\ElectronicSignature\Contracts\SignableDocumentSource;
use Modules\ElectronicSignature\DTOs\SignableDocument;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Services\SignatureTextResolver;
use Modules\ElectronicSignature\Services\SimpleDocumentRenderer;
use Modules\Procurement\Models\PurchaseOrder;

/**
 * Purchase orders: signed by the supplier (counter-signature) and by the management that approves the commitment. A
 * completed signature approves an order that was waiting for approval; a declined one rejects it.
 */
final class PurchaseOrderSignableSource implements SignableDocumentSource
{
    public function __construct(private readonly SimpleDocumentRenderer $renderer, private readonly SignatureTextResolver $texts) {}

    public function type(): SignableType
    {
        return SignableType::PurchaseOrder;
    }

    public function find(string $id): ?Model
    {
        return PurchaseOrder::query()->find($id);
    }

    public function document(Model $record, string $locale): SignableDocument
    {
        if (! $record instanceof PurchaseOrder) {
            throw new SignatureException('document_not_found', 404);
        }

        $t = fn (string $key): string => $this->texts->text($key, $locale, [], $record->tenant_id);
        $number = (string) ($record->po_number ?? $record->order_number);
        $currency = (string) $record->currency;
        $money = static fn (mixed $value): string => number_format((float) $value, 2, '.', "'").' '.$currency;
        $total = (string) ($record->total_gross ?? $record->total_amount ?? '0');

        $rows = [];

        foreach ($record->lines()->orderBy('line_number')->get() as $line) {
            $rows[] = [(string) $line->description, rtrim(rtrim((string) $line->quantity_ordered, '0'), '.').' '.$line->uom, $money($line->unit_price), $money($line->line_total_amount ?? $line->line_net_amount ?? 0)];
        }

        $pdf = $this->renderer->render(
            $t('signatures.doc.purchaseOrder').' '.$number,
            [
                [$t('signatures.doc.number'), $number],
                [$t('signatures.doc.supplier'), (string) $record->supplier->company_name],
                [$t('signatures.doc.date'), $record->date?->format('d.m.Y') ?? ''],
                [$t('signatures.doc.delivery'), ($record->expected_delivery_date ?? $record->delivery_due_date)?->format('d.m.Y') ?? ''],
                [$t('signatures.doc.total'), $money($total)],
            ],
            [['headers' => [$t('signatures.doc.description'), $t('signatures.doc.quantity'), $t('signatures.doc.unitPrice'), $t('signatures.doc.amount')], 'rows' => $rows, 'widths' => [240.0, 70.0, 95.0, 95.0]]],
            [],
            $t('signatures.doc.footer'),
        );

        return new SignableDocument($pdf, $number, $total, $currency, fileName: 'purchase-order-'.$number.'.pdf');
    }

    public function onCompleted(Model $record, SignatureEnvelope $envelope): void
    {
        $this->move($record, [PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Draft], PurchaseOrderStatus::Approved);
    }

    public function onDeclined(Model $record, SignatureEnvelope $envelope): void
    {
        $this->move($record, [PurchaseOrderStatus::PendingApproval], PurchaseOrderStatus::Rejected);
    }

    public function onExpired(Model $record, SignatureEnvelope $envelope): void {}

    /**
     * @param  list<PurchaseOrderStatus>  $from
     */
    private function move(Model $record, array $from, PurchaseOrderStatus $to): void
    {
        if (! $record instanceof PurchaseOrder) {
            return;
        }

        $current = PurchaseOrderStatus::tryFrom((string) $record->status);

        if ($current !== null && in_array($current, $from, true) && in_array($to, $current->transitions(), true)) {
            $record->forceFill(['status' => $to->value])->save();
        }
    }
}
