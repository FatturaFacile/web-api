<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

use Database\Seeders\ChartOfAccountTemplateSeeder;
use Modules\GeneralLedger\Models\ChartAccountTemplate;

final readonly class ListChartTemplatesHandler
{
    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     ui_copy: array<string, string>,
     * }
     */
    public function handle(ListChartTemplatesQuery $query): array
    {
        $locale = in_array($query->locale, ['it', 'en', 'de', 'fr'], true) ? $query->locale : 'it';
        $dbTemplates = ChartAccountTemplate::query()
            ->where('is_active', true)
            ->orderBy('key')
            ->get()
            ->keyBy('key');

        $definitions = ChartOfAccountTemplateSeeder::definitions();
        $result = [];

        foreach ($definitions as $key => $definition) {
            $record = $dbTemplates->get($key);
            /** @var array<string, mixed> $texts */
            $texts = $record !== null ? $record->texts : $definition['texts'];
            /** @var array<string, mixed> $localized */
            $localized = $texts[$locale] ?? $texts['it'] ?? $texts['en'] ?? [];
            /** @var list<array<string, mixed>> $nodes */
            $nodes = $record !== null ? $record->nodes : $definition['nodes'];
            /** @var list<array<string, mixed>> $defaultMappings */
            $defaultMappings = $record !== null ? ($record->default_mappings ?? []) : ($definition['default_mappings'] ?? []);

            $result[] = [
                'key' => $key,
                'standard' => $record !== null ? $record->standard : $definition['standard'],
                'name' => (string) ($localized['name'] ?? $key),
                'subtitle' => (string) ($localized['subtitle'] ?? ''),
                'statutory_reference' => (string) ($localized['statutory_reference'] ?? ''),
                'country' => $record !== null ? $record->country : $definition['country'],
                'currency' => $record !== null ? $record->currency : $definition['currency'],
                'account_count' => count($nodes),
                'default_mappings_count' => count($defaultMappings),
                'classes' => $localized['classes'] ?? [],
            ];
        }

        return [
            'data' => $result,
            'ui_copy' => ChartOfAccountTemplateSeeder::localizedUiCopy($locale),
        ];
    }
}
