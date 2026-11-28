<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\WithholdingRecipients\Models\RecipientProfile;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

const WR_API = '/api/v1/withholding-recipients';

/**
 * An Italian company with an accountant signed in. The clock stands on Monday 15 June 2026.
 */
function wrSetUp(object $test): void
{
    $test->travelTo('2026-06-15 09:00:00');
    $test->tenant = Tenant::create(['name' => 'Compensi Srl', 'legal_name' => 'Compensi Srl', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580']);
    $test->otherTenant = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->accountant = treasuryActor($test, 'accountant');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wrRecipientPayload(string $regime, array $overrides = []): array
{
    $base = match ($regime) {
        'agent_25bis' => ['display_name' => 'Mario Agente', 'tax_code' => 'GNTMRA70A01H501K', 'enasarco_mandate' => 'mono', 'firr_enabled' => true],
        'author_royalty' => ['display_name' => 'Giulia Autrice', 'tax_code' => 'RSSGLI96L41H501X', 'birth_date' => '1996-07-01'],
        'occasional_worker' => ['display_name' => 'Luca Occasionale', 'tax_code' => 'BNCLCU80A01H501Y'],
        'amateur_sports' => ['display_name' => 'Sara Istruttrice', 'tax_code' => 'VRDSRA90A41H501Z'],
        'swiss_foreign_artist' => ['display_name' => 'Hans Künstler', 'country_of_residence' => 'DE', 'canton' => 'ZH', 'activity_type' => 'artist', 'is_cultural_sector' => true, 'avs_number' => '756.1234.5678.97'],
        default => ['display_name' => 'Anna Aushilfe', 'avs_number' => '756.9876.5432.10'],
    };

    return array_merge(['regime' => $regime], $base, $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function wrRecipient(object $test, string $regime, array $overrides = []): RecipientProfile
{
    $id = $test->postJson(WR_API.'/recipients', wrRecipientPayload($regime, $overrides))->assertCreated()->json('data.id');

    return treasuryInTenant($test->tenant, fn (): RecipientProfile => RecipientProfile::query()->findOrFail($id));
}

/**
 * A sent INL communication that covers the given period (sent two days before it starts).
 */
function wrInlSent(object $test, RecipientProfile $profile, string $start = '2026-06-01', string $end = '2026-06-30'): string
{
    $id = $test->postJson(WR_API."/recipients/{$profile->id}/inl-notifications", [
        'work_start_date' => $start, 'work_end_date' => $end, 'activity_description' => 'Docenza occasionale', 'work_place' => 'Milano', 'estimated_compensation' => 3000,
    ])->assertCreated()->json('data.id');

    $test->postJson(WR_API."/inl-notifications/{$id}/mark-sent", ['protocol_reference' => 'INL-1', 'sent_at' => '2026-05-29 10:00:00'])->assertOk();

    return (string) $id;
}
