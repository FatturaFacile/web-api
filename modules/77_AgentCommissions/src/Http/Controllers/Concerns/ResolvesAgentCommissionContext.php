<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

trait ResolvesAgentCommissionContext
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    private function locale(Request $request): string
    {
        $locale = (string) ($request->input('locale') ?? app()->getLocale());

        return in_array($locale, ['it', 'en', 'de', 'fr'], true) ? $locale : 'it';
    }

    private function message(string $key): string
    {
        return __('agent_commissions.messages.'.$key);
    }
}
