<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Database\Eloquent\Model;
use Modules\IndustrialLaundry\Models\BillingRun;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Models\WashBatch;

final class GetEntityDetailHandler
{
    public function handle(GetEntityDetailQuery $query): Model
    {
        return match ($query->entity) {
            GetEntityDetailQuery::Garment => Garment::query()->with(['customer:id,company_name,first_name,last_name', 'events' => fn ($events) => $events->orderByDesc('occurred_at')->limit(100)])->findOrFail($query->id),
            GetEntityDetailQuery::ParContract => ParContract::query()->with(['customer:id,company_name,first_name,last_name,country', 'lines'])->findOrFail($query->id),
            GetEntityDetailQuery::ScanSession => ScanSession::query()->with(['customer:id,company_name,first_name,last_name', 'rollCage'])->findOrFail($query->id),
            GetEntityDetailQuery::WashBatch => WashBatch::query()->with(['sessions', 'garments'])->findOrFail($query->id),
            GetEntityDetailQuery::BillingRun => BillingRun::query()->with(['customer:id,company_name,first_name,last_name', 'contract:id,code', 'lines'])->findOrFail($query->id),
            default => RollCage::query()->with('holder:id,company_name,first_name,last_name')->findOrFail($query->id),
        };
    }
}
