<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\AuthorizationStatus;
use Modules\Associations\Models\Authorization;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<Authorization>
 */
final class AuthorizationFactory extends Factory
{
    protected $model = Authorization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = 'Il sottoscritto autorizza il minore a partecipare alla trasferta del torneo regionale e al trasporto con i mezzi della società.';

        return [
            'tenant_id' => Tenant::factory(),
            'minor_member_id' => static fn (array $attributes): string => Member::factory()->minor()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'guardianship_id' => static fn (array $attributes): string => Guardianship::create([
                'tenant_id' => $attributes['tenant_id'],
                'minor_member_id' => $attributes['minor_member_id'],
                'guardian_contact_id' => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
                'relationship' => 'parent',
                'is_primary' => true,
                'valid_from' => '2026-09-01',
            ])->id,
            'title' => 'Autorizzazione trasferta torneo regionale',
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'status' => AuthorizationStatus::Pending,
            'valid_until' => '2027-06-30',
        ];
    }
}
