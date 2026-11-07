<?php

declare(strict_types=1);

namespace Modules\Hospitality\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Hospitality\Models\DiningTable;
use Modules\Hospitality\Models\KitchenTicket;
use Modules\Hospitality\Models\Order;
use Modules\Hospitality\Models\OrderItem;
use Modules\Hospitality\Models\OrderPayment;
use Modules\Hospitality\Models\Room;
use Modules\Hospitality\Policies\HospitalityPolicy;

final class HospitalityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([Room::class, DiningTable::class, Order::class, OrderItem::class, KitchenTicket::class, OrderPayment::class] as $model) {
            Gate::policy($model, HospitalityPolicy::class);
        }
    }
}
