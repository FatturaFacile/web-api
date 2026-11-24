<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommercialDocumentLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CommercialDocumentLineFactory::class)]
class CommercialDocumentLine extends Model
{
    /** @use HasFactory<CommercialDocumentLineFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'commercial_document_id', 'source_line_id', 'description', 'quantity', 'unit_price', 'tax_rate',
        'vat_nature', 'net_amount', 'tax_amount', 'gross_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
        ];
    }
}
