<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\Enums\TenderProcurementFramework;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\TenderAwardedAndConvertedEvent;
use Modules\SalesBoundedContext\Exceptions\SalesBoundedContextException;
use Modules\SalesBoundedContext\Models\SbcFrameworkOrder;
use Modules\SalesBoundedContext\Models\SbcPublicPrivateTender;

final readonly class ProcessPublicPrivateTenderHandler
{
    public function handle(ProcessPublicPrivateTenderCommand $command): SbcPublicPrivateTender
    {
        if ($command->convertToContract && $command->tenderId !== null) {
            return $this->convertTenderToOrder($command->tenantId, $command->tenderId);
        }

        $data = $command->payload;
        $framework = TenderProcurementFramework::from((string) ($data['procurement_framework'] ?? 'simap_ch_lapub'));
        if ($framework === TenderProcurementFramework::AnacMepaDlgs362023 && empty($data['cig_code'])) {
            throw SalesBoundedContextException::invalidStateTransition(
                'ANAC MePA (D.Lgs. 36/2023) tenders require a valid CIG code.'
            );
        }

        $techScore = round(max(0.0, min(70.0, (float) ($data['technical_envelope_score'] ?? 62.5))), 2);
        $econScore = round(max(0.0, min(30.0, (float) ($data['economic_envelope_score'] ?? 26.0))), 2);
        $totalScore = round($techScore + $econScore, 2);
        $currency = strtoupper((string) ($data['currency'] ?? ($framework === TenderProcurementFramework::SimapChLapub ? 'CHF' : 'EUR')));
        $bidAmount = DecimalMoney::of((float) ($data['bid_amount'] ?? 240000), $currency);

        return DB::transaction(function () use (
            $command,
            $data,
            $framework,
            $techScore,
            $econScore,
            $totalScore,
            $bidAmount,
            $currency,
        ): SbcPublicPrivateTender {
            return SbcPublicPrivateTender::query()->create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'tender_code' => (string) ($data['tender_code'] ?? 'TND-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 6))),
                'title' => (string) $data['title'],
                'procurement_framework' => $framework,
                'contracting_authority' => (string) ($data['contracting_authority'] ?? 'Public Contracting Authority'),
                'cig_code' => $data['cig_code'] ?? null,
                'cup_code' => $data['cup_code'] ?? null,
                'soa_category' => $data['soa_category'] ?? ($framework === TenderProcurementFramework::AnacMepaDlgs362023 ? 'OS19-III' : null),
                'edgue_status' => (string) ($data['edgue_status'] ?? 'verified'),
                'fvoe_passoe_ref' => $data['fvoe_passoe_ref'] ?? ($framework === TenderProcurementFramework::AnacMepaDlgs362023 ? 'FVOE-2026-9981' : null),
                'qualification_vault' => $data['qualification_vault'] ?? [
                    'durc_or_avs_valid' => true,
                    'iso_9001_certified' => true,
                    'bid_bond_deposited' => true,
                ],
                'admin_envelope_status' => (string) ($data['admin_envelope_status'] ?? 'verified'),
                'technical_envelope_score' => $techScore,
                'economic_envelope_score' => $econScore,
                'total_award_score' => $totalScore,
                'bid_amount' => $bidAmount->roundHalfUp(),
                'currency' => $currency,
                'pipeline_stage' => (string) ($data['pipeline_stage'] ?? 'awarded'),
            ]);
        });
    }

    private function convertTenderToOrder(string $tenantId, string $tenderId): SbcPublicPrivateTender
    {
        $tender = DB::transaction(function () use ($tenantId, $tenderId): SbcPublicPrivateTender {
            $tender = SbcPublicPrivateTender::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($tenderId);

            if ($tender->converted_order_id !== null) {
                return $tender;
            }

            $order = SbcFrameworkOrder::query()->create([
                'tenant_id' => $tenantId,
                'dossier_id' => $tender->dossier_id,
                'order_number' => 'TND-FA-'.strtoupper(substr(md5((string) $tender->id), 0, 6)),
                'order_kind' => 'framework_agreement',
                'currency' => $tender->currency,
                'ceiling_amount' => $tender->bid_amount,
                'released_amount' => '0.00',
                'remaining_ceiling' => $tender->bid_amount,
                'valid_from' => now()->toDateString(),
                'valid_to' => now()->addYear()->toDateString(),
                'total_amount' => $tender->bid_amount,
                'fulfillment_status' => 'active',
                'lines' => [[
                    'description' => $tender->title,
                    'cig_code' => $tender->cig_code,
                    'cup_code' => $tender->cup_code,
                    'procurement_framework' => $tender->procurement_framework?->value,
                ]],
            ]);

            $tender->converted_order_id = $order->id;
            $tender->pipeline_stage = 'contract_converted';
            $tender->save();

            return $tender;
        });

        TenderAwardedAndConvertedEvent::dispatch(
            $tenantId,
            (string) $tender->id,
            $tender->converted_order_id !== null ? (string) $tender->converted_order_id : null,
            $tender->cig_code,
            $tender->cup_code,
        );

        return $tender;
    }
}
