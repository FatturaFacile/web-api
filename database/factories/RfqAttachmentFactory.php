<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RfqAttachment>
 */
final class RfqAttachmentFactory extends Factory
{
    protected $model = RfqAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = 'RFQ specification content';

        return [
            'id' => (string) Str::uuid7(),
            'rfq_id' => RequestForQuotation::factory(),
            'file_name' => 'spec-'.fake()->lexify('????').'.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($content),
            'storage_disk' => 'local',
            'storage_path' => 'rfq-attachments/'.Str::uuid7().'.pdf',
            'sha256_hash' => hash('sha256', $content),
            'is_visible_to_suppliers' => true,
        ];
    }
}
