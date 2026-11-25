<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\FrameworkAgreementCallOffExecutedEvent;
use Modules\SalesBoundedContext\Exceptions\SalesBoundedContextException;
use Modules\SalesBoundedContext\Models\SbcFrameworkOrder;

final readonly class ProcessSalesOrderAndCallOffHandler
{
    public function handle(ProcessSalesOrderAndCallOffCommand $command): SbcFrameworkOrder
    {
        $data = $command->payload;
        $orderKind = (string) ($data['order_kind'] ?? 'sales_order');
        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));

        if ($orderKind === 'framework_agreement') {
            $ceiling = DecimalMoney::of((float) ($data['ceiling_amount'] ?? 0), $currency);

            return SbcFrameworkOrder::create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'order_number' => (string) ($data['order_number'] ?? 'FA-'.strtoupper(Str::random(6))),
                'order_kind' => 'framework_agreement',
                'currency' => $currency,
                'ceiling_amount' => $ceiling->roundHalfUp(),
                'released_amount' => '0.00',
                'remaining_ceiling' => $ceiling->roundHalfUp(),
                'valid_from' => $data['valid_from'] ?? now()->toDateString(),
                'valid_to' => $data['valid_to'] ?? now()->addYear()->toDateString(),
                'fulfillment_status' => 'active',
                'lines' => $data['lines'] ?? [],
            ]);
        }

        $requestedQty = (float) ($data['requested_qty'] ?? 10);
        $atpQty = (float) ($data['atp_qty'] ?? 8);
        $ctpQty = (float) ($data['ctp_qty'] ?? 4);
        $shippedQty = (float) ($data['shipped_qty'] ?? min($requestedQty, $atpQty));
        $reservedQty = min($requestedQty, $atpQty + $ctpQty);
        $backorderQty = max(0.0, $requestedQty - $shippedQty);
        $totalAmount = DecimalMoney::of((float) ($data['total_amount'] ?? 0), $currency);

        $fulfillmentStatus = match (true) {
            $backorderQty > 0 && $shippedQty > 0 => 'partial_shipped',
            $backorderQty > 0 => 'backordered',
            default => 'fulfilled',
        };

        $order = DB::transaction(function () use ($command, $data, $currency, $requestedQty, $atpQty, $ctpQty, $reservedQty, $shippedQty, $backorderQty, $totalAmount, $fulfillmentStatus): SbcFrameworkOrder {
            $frameworkId = $data['framework_order_id'] ?? null;
            if ($frameworkId !== null) {
                $framework = SbcFrameworkOrder::query()
                    ->where('tenant_id', $command->tenantId)
                    ->where('order_kind', 'framework_agreement')
                    ->lockForUpdate()
                    ->findOrFail($frameworkId);

                $today = now()->toDateString();
                if (($framework->valid_from !== null && $today < $framework->valid_from->toDateString())
                    || ($framework->valid_to !== null && $today > $framework->valid_to->toDateString())) {
                    throw SalesBoundedContextException::frameworkExpired($framework->order_number);
                }

                $remaining = (float) $framework->remaining_ceiling;
                if ($totalAmount->toFloat() > $remaining) {
                    throw SalesBoundedContextException::frameworkCeilingExceeded($framework->order_number, $remaining, $totalAmount->toFloat());
                }

                $newReleased = DecimalMoney::of((float) $framework->released_amount, $framework->currency)->add($totalAmount);
                $newRemaining = DecimalMoney::of($remaining, $framework->currency)->subtract($totalAmount);
                $framework->released_amount = $newReleased->roundHalfUp();
                $framework->remaining_ceiling = $newRemaining->roundHalfUp();
                $framework->save();
            }

            return SbcFrameworkOrder::create([
                'tenant_id' => $command->tenantId,
                'framework_order_id' => $frameworkId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'quote_id' => $data['quote_id'] ?? null,
                'order_number' => (string) ($data['order_number'] ?? 'SO-'.strtoupper(Str::random(6))),
                'order_kind' => 'sales_order',
                'currency' => $currency,
                'atp_qty' => number_format($atpQty, 3, '.', ''),
                'ctp_qty' => number_format($ctpQty, 3, '.', ''),
                'requested_qty' => number_format($requestedQty, 3, '.', ''),
                'reserved_stock_qty' => number_format($reservedQty, 3, '.', ''),
                'shipped_qty' => number_format($shippedQty, 3, '.', ''),
                'backorder_qty' => number_format($backorderQty, 3, '.', ''),
                'total_amount' => $totalAmount->roundHalfUp(),
                'fulfillment_status' => $fulfillmentStatus,
                'lines' => $data['lines'] ?? [],
            ]);
        });

        if ($order->framework_order_id !== null) {
            $fw = SbcFrameworkOrder::query()->find($order->framework_order_id);
            if ($fw !== null) {
                FrameworkAgreementCallOffExecutedEvent::dispatch(
                    $command->tenantId,
                    (string) $fw->id,
                    (string) $order->id,
                    $totalAmount->roundHalfUp(),
                    (string) $fw->remaining_ceiling,
                );
            }
        }

        return $order;
    }
}
