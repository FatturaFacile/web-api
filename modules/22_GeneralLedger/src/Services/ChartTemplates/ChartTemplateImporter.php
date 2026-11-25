<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\ChartTemplates;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\ProvisionChartTemplateCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\ProvisionChartTemplateHandler;

/**
 * Loads a standard chart of accounts into a tenant. The import is idempotent: an account whose code already exists
 * (also a deleted one, the code is unique per tenant) is left untouched, so a tenant can load a template over a chart
 * it has already started and run the import again without duplicates.
 */
final class ChartTemplateImporter
{
    /**
     * @return array<string, array{standard: string, name: string, country: string, currency: string, accounts: int}>
     */
    public function templates(): array
    {
        return array_map(
            static fn (array $template): array => [
                'standard' => $template['standard'],
                'name' => $template['name'],
                'country' => $template['country'],
                'currency' => $template['currency'],
                'accounts' => count($template['accounts']),
            ],
            ChartTemplateCatalog::all(),
        );
    }

    /**
     * @return array{template: string, created: int, skipped: int}
     *
     * @throws InvalidArgumentException when the template does not exist
     */
    public function import(string $tenantId, string $templateKey, ?string $companyId = null, bool $includeDefaultMappings = true, string $locale = 'it'): array
    {
        try {
            $result = (new ProvisionChartTemplateHandler)->handle(
                new ProvisionChartTemplateCommand(
                    tenantId: $tenantId,
                    companyId: $companyId,
                    templateKey: $templateKey,
                    includeDefaultMappings: $includeDefaultMappings,
                    locale: $locale,
                ),
            );
        } catch (ValidationException $e) {
            throw new InvalidArgumentException("Unknown chart template [{$templateKey}]", 0, $e);
        }

        return [
            'template' => $result['template'],
            'created' => $result['created_count'],
            'skipped' => $result['skipped_count'],
        ];
    }
}
