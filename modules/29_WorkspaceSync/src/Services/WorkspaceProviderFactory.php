<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Services;

use Modules\WorkspaceSync\Contracts\WorkspaceProvider;
use Modules\WorkspaceSync\Providers\GoogleWorkspaceProvider;
use Modules\WorkspaceSync\Providers\MicrosoftGraphProvider;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Workspace provider factory (WorkspaceSync module).
 */
final class WorkspaceProviderFactory
{
    /**
     * @throws ServiceUnavailableHttpException when the installation has no application registered at the provider
     */
    public function make(string $provider): WorkspaceProvider
    {
        $clientId = (string) config("workspace_sync.providers.{$provider}.client_id");
        $clientSecret = (string) config("workspace_sync.providers.{$provider}.client_secret");

        if ($clientId === '' || $clientSecret === '') {
            throw new ServiceUnavailableHttpException(null, "The {$provider} connection is not configured on this installation.");
        }

        return $provider === 'google'
            ? new GoogleWorkspaceProvider($clientId, $clientSecret)
            : new MicrosoftGraphProvider($clientId, $clientSecret);
    }
}
