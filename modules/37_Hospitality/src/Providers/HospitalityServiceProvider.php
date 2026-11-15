<?php

declare(strict_types=1);

namespace Modules\Hospitality\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Hospitality\Models\Accommodation;
use Modules\Hospitality\Models\DiningTable;
use Modules\Hospitality\Models\FolioEntry;
use Modules\Hospitality\Models\GroupBlock;
use Modules\Hospitality\Models\GroupBlockRoom;
use Modules\Hospitality\Models\HousekeepingTask;
use Modules\Hospitality\Models\KitchenTicket;
use Modules\Hospitality\Models\Order;
use Modules\Hospitality\Models\OrderItem;
use Modules\Hospitality\Models\OrderPayment;
use Modules\Hospitality\Models\RatePlan;
use Modules\Hospitality\Models\Reservation;
use Modules\Hospitality\Models\Room;
use Modules\Hospitality\Models\RoomType;
use Modules\Hospitality\Models\Stay;
use Modules\Hospitality\Policies\HospitalityPolicy;

/**
 * Registers the hospitality module: routes and the policy of its models.
 */
final class HospitalityServiceProvider extends ServiceProvider
{
    /**
     * Loads the module routes once the application is booted and registers the policy of every hospitality model.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([Room::class, DiningTable::class, Order::class, OrderItem::class, KitchenTicket::class, OrderPayment::class, Reservation::class, RoomType::class, Accommodation::class, RatePlan::class, Stay::class, FolioEntry::class, HousekeepingTask::class, GroupBlock::class, GroupBlockRoom::class] as $model) {
            Gate::policy($model, HospitalityPolicy::class);
        }
    }
}
