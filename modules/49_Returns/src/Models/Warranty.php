<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Contact;
use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The warranty of a sold unit (serial number) or batch (lot number): product, customer, period and coverage.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $contact_id
 * @property string|null $invoice_id
 * @property string|null $serial_number
 * @property string|null $lot_number
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $coverage_type
 * @property string|null $coverage_terms
 * @property string $status
 * @property string|null $void_reason
 */
final class Warranty extends BaseEnterpriseModel
{
    public const string Active = 'active';

    public const string Void = 'void';

    /** @var list<string> */
    public const array Statuses = [self::Active, self::Void];

    /** @var list<string> */
    public const array CoverageTypes = ['full', 'parts', 'labor', 'limited'];

    protected $table = 'returns_warranties';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<WarrantyClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'warranty_id');
    }
}
