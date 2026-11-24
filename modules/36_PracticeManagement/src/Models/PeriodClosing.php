<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Database\Factories\PeriodClosingFactory;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The closing of a period for one client of a trust office or law firm: a checklist, then closed.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $client_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $status
 * @property list<array{key: string, label: string, done: bool}> $checklist
 * @property ?string $notes
 */
#[UseFactory(PeriodClosingFactory::class)]
final class PeriodClosing extends Model
{
    /** @use HasFactory<PeriodClosingFactory> */
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_period_closings';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['period_start' => 'immutable_date', 'period_end' => 'immutable_date', 'checklist' => 'array', 'closed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }
}
