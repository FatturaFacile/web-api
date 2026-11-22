<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ArtisanalGelato\Domain\Contracts\ScalePort;
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
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
