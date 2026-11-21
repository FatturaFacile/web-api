<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of a lot consumption: item, lot code (as scanned), quantity and the over-consumption confirmation.
 */
final class ConsumeMaterialLotRequest extends MesCommandRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'mfg_item_id' => ['required', 'uuid', $this->tenantOwns('mfg_items')],
            'lot_code' => ['nullable', 'string', 'max:64'],
            'quantity' => ['required', ...self::PositiveQuantity],
            'over_consumption_confirmed' => ['nullable', 'boolean'],
        ];
    }
}
