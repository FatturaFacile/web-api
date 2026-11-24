<?php

declare(strict_types=1);

namespace Modules\Communication\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Communication\Contracts\ConsentLookup;
use Modules\Communication\Models\CommunicationCampaign;
use Modules\Communication\Models\CommunicationConversation;
use Modules\Communication\Models\ContactChannelPreference;
use Modules\Communication\Policies\CommunicationPolicy;
use Modules\Communication\Services\CrmConsentLookup;

/**
 * Registers the routes, policies and bindings of the Communication module.
 */
final class CommunicationServiceProvider extends ServiceProvider
{
    /**
     * Register the bindings: consent is read from the CRM.
     */
    public function register(): void
    {
        $this->app->bind(ConsentLookup::class, CrmConsentLookup::class);
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([CommunicationConversation::class, CommunicationCampaign::class, ContactChannelPreference::class] as $model) {
            Gate::policy($model, CommunicationPolicy::class);
        }
    }
}
