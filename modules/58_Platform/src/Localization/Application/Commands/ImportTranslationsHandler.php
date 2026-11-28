<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use Modules\Platform\Localization\Application\Data\ImportResult;
use Modules\Platform\Localization\Domain\Contracts\TranslationCatalogRepository;
use Modules\Platform\Localization\Domain\Entities\TranslationCatalog;
use Modules\Platform\Localization\Domain\Exceptions\LockedTranslationException;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Domain\ValueObjects\TranslationKey;
use Modules\Platform\Localization\Infrastructure\Persistence\DictionaryBuilder;
use Modules\Platform\Localization\Infrastructure\Spreadsheet\TranslationsSpreadsheetImporter;

final class ImportTranslationsHandler
{
    public const int MAX_VALUE_LENGTH = 5000;

    public function __construct(
        private readonly TranslationsSpreadsheetImporter $importer,
        private readonly TranslationCatalogRepository $catalogs,
        private readonly DictionaryBuilder $dictionaries,
    ) {}

    /**
     * @throws LockedTranslationException when a committed sheet touches a locked key (nothing is written)
     */
    public function __invoke(ImportTranslationsCommand $command): ImportResult
    {
        $parsed = $this->importer->read($command->path, $command->extension);

        if ($parsed['header_errors'] !== []) {
            return new ImportResult($command->dryRun, [], $parsed['header_errors']);
        }

        $result = new ImportResult($command->dryRun, $this->analyse($command->tenantId, $parsed['cells']));

        if ($command->dryRun || $result->hasErrors()) {
            return $result;
        }

        if ($result->blockedKeys() !== []) {
            throw new LockedTranslationException($result->blockedKeys()[0]);
        }

        $this->apply($command->tenantId, $result);

        return $result;
    }

    /**
     * @param  list<array{row: int, key: string, locale: Locale, value: string}>  $cells
     * @return list<array{row: int, key: string, locale: string, action: string, old_value: string|null, new_value: string, errors: list<string>}>
     */
    private function analyse(string $tenantId, array $cells): array
    {
        $byLocale = [];

        foreach ($cells as $cell) {
            $byLocale[$cell['locale']->value()][] = $cell;
        }

        $rows = [];

        foreach ($byLocale as $localeCells) {
            $locale = $localeCells[0]['locale'];
            $effective = $this->dictionaries->build($tenantId, $locale);
            $valid = array_values(array_filter(array_map(static fn (array $cell): ?TranslationKey => TranslationKey::isValid($cell['key']) ? TranslationKey::from($cell['key']) : null, $localeCells)));
            $catalog = $this->catalogs->load($tenantId, $locale, $valid);

            foreach ($localeCells as $cell) {
                $rows[] = $this->classify($catalog, $effective, $cell);
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['row'], $a['locale']] <=> [$b['row'], $b['locale']]);

        return $rows;
    }

    /**
     * @param  array<string, string>  $effective
     * @param  array{row: int, key: string, locale: Locale, value: string}  $cell
     * @return array{row: int, key: string, locale: string, action: string, old_value: string|null, new_value: string, errors: list<string>}
     */
    private function classify(TranslationCatalog $catalog, array $effective, array $cell): array
    {
        $row = ['row' => $cell['row'], 'key' => $cell['key'], 'locale' => $cell['locale']->value(), 'action' => ImportResult::UNCHANGED, 'old_value' => $effective[$cell['key']] ?? null, 'new_value' => $cell['value'], 'errors' => []];

        if (! TranslationKey::isValid($cell['key'])) {
            return ['action' => ImportResult::REJECTED, 'errors' => ['invalid_key']] + $row;
        }

        if (! array_key_exists($cell['key'], $effective)) {
            return ['action' => ImportResult::REJECTED, 'errors' => ['unknown_key']] + $row;
        }

        if (mb_strlen($cell['value']) > self::MAX_VALUE_LENGTH) {
            return ['action' => ImportResult::REJECTED, 'errors' => ['value_too_long']] + $row;
        }

        $key = TranslationKey::from($cell['key']);
        $stored = $catalog->entry($key);
        $current = $stored !== null ? $stored->value() : $effective[$cell['key']];

        // A text that does not change is no violation even on a locked key: an exported sheet imports back cleanly
        if ($current === $cell['value']) {
            return ['action' => ImportResult::UNCHANGED] + $row;
        }

        if ($catalog->isLocked($key)) {
            return ['action' => ImportResult::BLOCKED, 'errors' => ['locked_key']] + $row;
        }

        return ['action' => $stored !== null ? ImportResult::UPDATE : ImportResult::CREATE] + $row;
    }

    private function apply(string $tenantId, ImportResult $result): void
    {
        $byLocale = [];

        foreach ($result->rows as $row) {
            if (in_array($row['action'], [ImportResult::CREATE, ImportResult::UPDATE], true)) {
                $byLocale[$row['locale']][] = $row;
            }
        }

        foreach ($byLocale as $code => $rows) {
            $locale = Locale::from($code);
            $keys = array_map(static fn (array $row): TranslationKey => TranslationKey::from($row['key']), $rows);
            $catalog = $this->catalogs->load($tenantId, $locale, $keys);

            foreach ($rows as $row) {
                $catalog->upsert(TranslationKey::from($row['key']), $row['new_value']);
            }

            $this->catalogs->save($catalog);
        }
    }
}
