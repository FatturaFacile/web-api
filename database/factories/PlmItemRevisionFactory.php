<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Plm\Models\PlmItemRevision;

/**
 * @extends Factory<PlmItemRevision>
 */
final class PlmItemRevisionFactory extends Factory
{
    protected $model = PlmItemRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'mfg_item_id' => MfgItem::factory(),
            'revision' => 'A',
            'status' => PlmItemRevision::Draft,
        ];
    }
}
