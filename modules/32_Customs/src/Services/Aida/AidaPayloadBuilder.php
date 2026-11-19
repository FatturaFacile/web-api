<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Aida;

use App\Models\Contact;
use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDeclarationItem;
use Modules\Customs\Models\CustomsSettings;

/**
 * Builds a JSON payload for the Italian AIDA channel, keyed on the boxes of the Documento Amministrativo Unico (DAU).
 * It carries what the application holds and is NOT validated against an ADM schema: the real transmission goes through
 * the ADM Servizio Telematico Doganale in its own message format, and no such transport exists in this application.
 */
final class AidaPayloadBuilder
{
    public const string FORMAT = 'fattura-facile/aida-sandbox/1';

    public function build(CustomsDeclaration $declaration, CustomsSettings $settings): string
    {
        $items = $declaration->items->map(fn (CustomsDeclarationItem $item): array => [
            'box_32_item_number' => $item->line_number,
            'box_31_description' => $item->description,
            'box_33_commodity_code' => $item->commodity_code,
            'box_34_origin_country' => $item->origin_country,
            'box_35_gross_mass' => (string) $item->gross_mass,
            'box_38_net_mass' => (string) $item->net_mass,
            'box_36_preference' => (bool) $item->preference,
            'box_46_statistical_value' => (string) $item->statistical_value,
            'box_31_packages' => ['type' => $item->packaging_type, 'quantity' => $item->package_quantity],
        ])->values()->all();

        $flow = match ($declaration->declaration_type) {
            DeclarationType::Export => 'EX',
            DeclarationType::Import => 'IM',
            DeclarationType::Transit => 'T',
        };

        return (string) json_encode([
            'format' => self::FORMAT,
            'box_1_declaration' => [
                'flow' => $flow,
                'transit_document' => $declaration->transit_document_type->value,
            ],
            'box_2_consignor' => $this->party($declaration->consignor),
            'box_8_consignee' => $this->party($declaration->consignee),
            'box_14_declarant' => ['name' => $settings->declarant_name, 'identification' => $settings->trader_identification_number],
            'box_15_dispatch_country' => $declaration->dispatch_country,
            'box_17_destination_country' => $declaration->destination_country,
            'box_18_transport' => ['mode' => $declaration->transport_mode, 'identity' => $declaration->transportation_number],
            'box_20_incoterms' => $declaration->incoterms,
            'box_29_office' => $declaration->customs_office_number,
            'box_5_item_count' => count($items),
            'box_6_package_count' => $declaration->total_packages,
            'box_35_total_gross_mass' => (string) $declaration->total_gross_mass,
            'box_38_total_net_mass' => (string) $declaration->total_net_mass,
            'box_22_total_value' => (string) $declaration->total_statistical_value,
            'local_reference' => $declaration->trader_reference ?? $declaration->trader_declaration_number,
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{name: string|null, vat_number: string|null, country: string|null}
     */
    private function party(?Contact $contact): array
    {
        return [
            'name' => $contact?->company_name,
            'vat_number' => $contact?->vat_number,
            'country' => $contact?->country,
        ];
    }
}
