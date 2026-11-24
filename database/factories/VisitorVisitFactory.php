<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Visitors\Models\Visitor;
use Modules\Visitors\Models\VisitorVisit;

/**
 * @extends Factory<VisitorVisit>
 */
final class VisitorVisitFactory extends Factory
{
    protected $model = VisitorVisit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'visitor_id' => static fn (array $attributes): string => (string) Visitor::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'host_user_id' => static function (array $attributes): string {
                $host = User::factory()->create();
                $host->tenants()->attach($attributes['tenant_id'], ['id' => (string) Str::uuid(), 'role' => 'member']);

                return (string) $host->id;
            },
            'expected_at' => now()->addHours(2)->startOfHour(),
            'expected_until' => now()->addHours(4)->startOfHour(),
            'purpose' => fake()->randomElement(['Riunione commerciale', 'Colloquio', 'Manutenzione impianti', 'Consegna documenti']),
            'status' => VisitorVisit::Expected,
        ];
    }

    public function checkedIn(string $badgeNumber = 'V-001'): self
    {
        return $this->state(fn (): array => ['status' => VisitorVisit::CheckedIn, 'badge_number' => $badgeNumber, 'expected_at' => now()->subHour(), 'checked_in_at' => now()->subMinutes(30)]);
    }

    public function checkedOut(): self
    {
        return $this->state(fn (): array => ['status' => VisitorVisit::CheckedOut, 'badge_number' => 'V-'.fake()->numerify('###'), 'expected_at' => now()->subHours(3), 'checked_in_at' => now()->subHours(3), 'checked_out_at' => now()->subHour()]);
    }
}
