<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Services;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianCausalCode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianStatus;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\Services\SianComplianceService;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\OilDensityConverter;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\SianCuaaIdentifier;
use Modules\OliveMillAndGrainMill\Models\SianMovement;

/**
 * Writes the operations of the SIAN telematic oil register: progressive number, operation date with its six day transmission deadline and the
 * counterparty CUAA. Rows are created as drafts; the transmission moves them on.
 */
final class SianRegister
{
    public function __construct(private readonly SianComplianceService $compliance) {}

    /**
     * @param  array{
     *     from_tank_id?: string|null, to_tank_id?: string|null, counterparty?: Contact|null, quantity_kg: float, category?: OilCategory|null,
     *     origin?: OriginDesignation|null, lot_code?: string|null, bottle_format?: string|null, bottle_count?: int|null, package_lot?: string|null,
     *     best_before?: string|null, source_type?: string|null, source_id?: string|null, notes?: string|null, user_id?: string|null
     * }  $data
     *
     * @throws OliveMillException counterparty_required, invalid_cuaa
     */
    public function record(SianCausalCode $code, Carbon $operationDate, array $data): SianMovement
    {
        $contact = $data['counterparty'] ?? null;
        $cuaa = $contact === null ? null : $this->cuaaOf($contact);
        $this->compliance->assertCounterparty($code, $cuaa);

        $kg = round($data['quantity_kg'], 3);

        return SianMovement::query()->create([
            'progressive' => $this->nextProgressive(),
            'operation_date' => $operationDate->toDateString(),
            'transmission_deadline' => $this->compliance->deadline($operationDate)->toDateString(),
            'causal' => $code,
            'from_tank_id' => $data['from_tank_id'] ?? null,
            'to_tank_id' => $data['to_tank_id'] ?? null,
            'counterparty_contact_id' => $contact?->id,
            'counterparty_name' => $contact === null ? null : $this->nameOf($contact),
            'counterparty_cuaa' => $cuaa === null ? null : SianCuaaIdentifier::fromString($cuaa)->value,
            'quantity_kg' => $kg,
            'quantity_l' => OilDensityConverter::fromConfig()->toLiters($kg),
            'category' => $data['category'] ?? null,
            'origin_designation' => $data['origin'] ?? null,
            'lot_code' => $data['lot_code'] ?? null,
            'bottle_format' => $data['bottle_format'] ?? null,
            'bottle_count' => $data['bottle_count'] ?? null,
            'package_lot' => $data['package_lot'] ?? null,
            'best_before' => $data['best_before'] ?? null,
            'status' => SianStatus::Draft,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['user_id'] ?? null,
        ])->refresh();
    }

    /**
     * The CUAA of a farmer or buyer: the Codice Fiscale, otherwise the Partita IVA of the contact.
     */
    public function cuaaOf(Contact $contact): ?string
    {
        foreach ([$contact->fiscal_code, $contact->vat_number] as $candidate) {
            if (is_string($candidate) && SianCuaaIdentifier::isValid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function nameOf(Contact $contact): string
    {
        $company = trim((string) $contact->company_name);

        return $company !== '' ? $company : trim($contact->first_name.' '.$contact->last_name);
    }

    private function nextProgressive(): int
    {
        return (int) SianMovement::query()->withTrashed()->max('progressive') + 1;
    }
}
