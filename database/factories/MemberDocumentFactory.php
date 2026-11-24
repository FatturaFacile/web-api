<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberDocument;

/**
 * @extends Factory<MemberDocument>
 */
final class MemberDocumentFactory extends Factory
{
    protected $model = MemberDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => 'Documento di identità',
            'category' => 'identity',
            'disk' => 'local',
            'path' => static fn (): string => 'associations/members/'.Str::uuid7().'.pdf',
            'original_name' => 'carta_identita.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(50_000, 2_000_000),
            'expires_on' => '2031-06-30',
            'visible_to_member' => false,
        ];
    }
}
