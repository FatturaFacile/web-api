<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\ProcessingActivity;

/**
 * @extends Factory<ProcessingActivity>
 */
class ProcessingActivityFactory extends Factory
{
    protected $model = ProcessingActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => (string) Str::uuid7(),
            'version' => 1,
            'status' => ProcessingActivity::Draft,
            'name' => 'Membership management',
            'data_subjects' => ['members'],
            'data_categories' => ['identity data', 'contact data'],
            'purposes' => ['keeping the members book', 'sending notices of meetings'],
            'legal_bases' => ['contract', 'legal_obligation'],
            'retention' => 'For the duration of the membership and afterwards as required by the law.',
            'recipients' => ['accountant'],
        ];
    }

    public function consentBased(): static
    {
        return $this->state(['name' => 'Newsletter', 'purposes' => ['sending the newsletter'], 'legal_bases' => ['consent']]);
    }
}
