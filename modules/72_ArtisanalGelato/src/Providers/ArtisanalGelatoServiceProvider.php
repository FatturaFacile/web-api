<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\ArtisanalGelato\Application\Listeners\DeductGelatoRawMaterialsFromStock;
use Modules\ArtisanalGelato\Domain\Contracts\RawMaterialStockPort;
use Modules\ArtisanalGelato\Domain\Contracts\ScalePort;
use Modules\ArtisanalGelato\Domain\Events\GelatoLotMaterialsConsumed;
use Modules\ArtisanalGelato\Infrastructure\Adapters\InventoryRawMaterialStockAdapter;
use Modules\ArtisanalGelato\Infrastructure\Adapters\ManualScaleAdapter;

/**
 * Artisanal gelato, chocolaterie and cold pastry vertical (Mod 398): binds the scale port to the manual adapter (a hardware
 * adapter replaces the binding) and loads the routes. The balancing engine, the solver and the calculators are stateless.
 */
final class ArtisanalGelatoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ScalePort::class, ManualScaleAdapter::class);
        $this->app->bind(RawMaterialStockPort::class, InventoryRawMaterialStockAdapter::class);
    }

    public function boot(): void
    {
        Event::listen(GelatoLotMaterialsConsumed::class, DeductGelatoRawMaterialsFromStock::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
