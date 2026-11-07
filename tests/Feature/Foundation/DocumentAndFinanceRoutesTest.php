<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Assistant\Models\AssistantQuestionLog;
use Modules\Contracts\Models\ContractAlert;
use Modules\Contracts\Models\ContractRenewal;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentLink;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\Documents\Models\DmsExtraction;
use Modules\Documents\Models\DmsFolder;
use Modules\Documents\Models\DmsRetentionPolicy;
use Modules\Documents\Models\DmsTag;
use Modules\Documents\Models\TenantAiSetting;
use Modules\EDocumentNetworks\Models\EDocumentTransmission;
use Modules\GeneralLedger\Models\LedgerBudget;
use Modules\GeneralLedger\Models\LedgerBudgetLine;
use Modules\GeneralLedger\Models\StatementScheme;
use Modules\GeneralLedger\Models\StatementSchemeRow;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Models\OpenBankingPayment;
use Modules\WorkspaceSync\Models\WorkspaceConnection;
use Modules\WorkspaceSync\Models\WorkspaceSyncedItem;
use OpenApi\Attributes\Delete;
use OpenApi\Attributes\Get;
use OpenApi\Attributes\Post;
use OpenApi\Attributes\Put;

uses(RefreshDatabase::class);

test('every new route declares its OpenAPI operation with the same path', function (): void {
    $prefixes = [
        'api/v1/dms',
        'api/v1/contract-register',
        'api/v1/assistant',
        'api/v1/edocument-networks',
        'api/v1/workspace',
        'api/v1/invoice-approvals',
        'api/v1/treasury/open-banking',
        'api/v1/treasury/iban',
        'api/v1/general-ledger/budgets',
        'api/v1/general-ledger/statement-schemes',
        'api/v1/general-ledger/financial-statements/comparative',
        'api/v1/general-ledger/financial-statements/reclassified',
        'api/v1/general-ledger/financial-statements/cash-flow-statement',
        'api/v1/general-ledger/financial-statements/ratios',
        'api/v1/procurement/supplier-invoices',
    ];

    $checked = 0;
    $missing = [];

    foreach (app('router')->getRoutes() as $route) {
        $uri = $route->uri();

        if (! collect($prefixes)->contains(static fn (string $prefix): bool => str_starts_with($uri, $prefix)) && ! str_ends_with($uri, '/carry-forward')) {
            continue;
        }

        $checked++;
        $method = $route->methods()[0];
        $attribute = ['GET' => Get::class, 'POST' => Post::class, 'PUT' => Put::class, 'DELETE' => Delete::class][$method];
        [$controller, $action] = explode('@', $route->getActionName());
        $attributes = (new ReflectionMethod($controller, $action))->getAttributes($attribute);
        $expected = '/'.rtrim($uri, '/');

        if ($attributes === [] || rtrim((string) ($attributes[0]->getArguments()['path'] ?? ''), '/') !== $expected) {
            $missing[] = "{$method} {$uri}";
        }
    }

    expect($checked)->toBeGreaterThan(50)->and($missing)->toBe([]);
});

test('the scheduled jobs of the new modules are registered per tenant', function (): void {
    $names = collect(app(Schedule::class)->events())->map(static fn ($event): ?string => $event->description)->all();

    expect($names)->toContain('dms:retention', 'contracts:deadlines', 'workspace:sync');
});

test('every new model has a working factory', function (string $model): void {
    $factory = $model::factory();

    expect($factory)->toBeInstanceOf(Factory::class)
        ->and($factory->create()->exists)->toBeTrue();
})->with([
    DmsFolder::class,
    DmsDocument::class,
    DmsDocumentVersion::class,
    DmsTag::class,
    DmsDocumentLink::class,
    DmsRetentionPolicy::class,
    DmsExtraction::class,
    TenantAiSetting::class,
    ContractAlert::class,
    ContractRenewal::class,
    AssistantQuestionLog::class,
    StatementScheme::class,
    StatementSchemeRow::class,
    LedgerBudget::class,
    LedgerBudgetLine::class,
    EDocumentTransmission::class,
    OpenBankingConsent::class,
    OpenBankingPayment::class,
    WorkspaceConnection::class,
    WorkspaceSyncedItem::class,
]);
