<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DocumentTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
final class DocumentTemplateFactory extends Factory
{
    protected $model = DocumentTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'document_type' => DocumentTemplate::DefaultType,
            'logo_data_uri' => null,
            'primary_color' => '#1F4E79',
            'secondary_color' => '#C9D6E3',
            'footer_text' => fake()->company().' S.r.l. - P.IVA IT'.fake()->numerify('###########').' - Via Roma 12, 20121 Milano',
        ];
    }
}
