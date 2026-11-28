<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Fiscal;

/**
 * Country specific fiscal localization (strategy pattern): how a document total is rounded and which statutory texts a
 * document must carry. The texts themselves are locked system translations (translations.is_locked = true) seeded in the
 * four languages; the strategy only decides which keys apply.
 */
interface FiscalLocalizationStrategy
{
    /**
     * ISO 3166-1 alpha-2 code of the country the strategy implements.
     */
    public function country(): string;

    /**
     * The smallest unit the document total is rounded to (0.05 for CHF, 0.01 for EUR).
     */
    public function roundingIncrement(): string;

    /**
     * Rounds a document total to the legal increment, as a decimal string with two decimals.
     */
    public function roundDocumentTotal(string $amount): string;

    /**
     * Keys of the locked statutory texts the document must print.
     *
     * @return list<string>
     */
    public function statutoryNoticeKeys(FiscalNoticeContext $context): array;
}
