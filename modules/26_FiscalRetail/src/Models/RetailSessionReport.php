<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use Database\Factories\RetailSessionReportFactory;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * X (running) or Z (closing) report of a POS session, stored with the totals as they were when it was produced.
 */
#[UseFactory(RetailSessionReportFactory::class)]
final class RetailSessionReport extends Model
{
    /** @use HasFactory<RetailSessionReportFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'retail_session_reports';

    protected $fillable = ['tenant_id', 'pos_session_id', 'kind', 'number', 'business_date', 'totals', 'generated_by'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'totals' => 'array'];
    }
}
