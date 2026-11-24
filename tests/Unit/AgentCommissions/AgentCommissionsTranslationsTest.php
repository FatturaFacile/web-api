<?php

declare(strict_types=1);

use Database\Seeders\Translations\AgentCommissionsFrontendTranslations;
use Modules\AgentCommissions\Domain\Enums\AccrualBasis;
use Modules\AgentCommissions\Domain\Enums\AgentType;
use Modules\AgentCommissions\Domain\Enums\EnasarcoMandate;
use Modules\AgentCommissions\Domain\Enums\EntryRole;
use Modules\AgentCommissions\Domain\Enums\EntryStatus;
use Modules\AgentCommissions\Domain\Enums\FiscalRegime;
use Modules\AgentCommissions\Domain\Enums\PeriodType;
use Modules\AgentCommissions\Domain\Enums\RateType;
use Modules\AgentCommissions\Domain\Enums\SettlementChannel;
use Modules\AgentCommissions\Domain\Enums\SplitMode;
use Modules\AgentCommissions\Domain\Enums\StatementStatus;

it('has the four languages for every frontend key and no empty text', function (): void {
    foreach (AgentCommissionsFrontendTranslations::definitions() as $key => $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('');
        }
    }

    $keys = array_keys(AgentCommissionsFrontendTranslations::definitions());

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(AgentCommissionsFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('has a label for every value of every enumeration the API reports', function (): void {
    $keys = array_keys(AgentCommissionsFrontendTranslations::definitions());
    $groups = [
        'agentType' => AgentType::values(),
        'fiscalRegime' => FiscalRegime::values(),
        'enasarcoMandate' => EnasarcoMandate::values(),
        'accrualBasis' => AccrualBasis::values(),
        'rateType' => RateType::values(),
        'splitMode' => SplitMode::values(),
        'settlementChannel' => SettlementChannel::values(),
        'periodType' => PeriodType::values(),
        'statementStatus' => StatementStatus::values(),
        'entryStatus' => EntryStatus::values(),
        'role' => EntryRole::values(),
    ];

    foreach ($groups as $group => $values) {
        foreach ($values as $value) {
            expect($keys)->toContain("agentCommissions.{$group}.{$value}");
        }
    }
});

it('has a frontend text for every error code of the module and the same codes in all backend dictionaries', function (): void {

    $root = dirname(__DIR__, 3);
    $codes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/modules/77_AgentCommissions/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            preg_match_all("/new AgentCommissionException\\([^;]*?'([a-z_]+)'(?:, \\d+)?\\)/s", (string) file_get_contents($file->getPathname()), $found);
            $codes = array_merge($codes, $found[1]);
        }
    }

    $codes = array_values(array_unique($codes));
    expect($codes)->not->toBeEmpty();

    $keys = array_keys(AgentCommissionsFrontendTranslations::definitions());
    $reference = array_keys((require $root.'/lang/it/agent_commissions.php')['errors']);

    foreach ($codes as $code) {
        expect($keys)->toContain("agentCommissions.error.{$code}")->and($reference)->toContain($code);
    }

    foreach (['en', 'de', 'fr'] as $locale) {
        $dictionary = require $root."/lang/{$locale}/agent_commissions.php";
        $italian = require $root.'/lang/it/agent_commissions.php';

        foreach ($italian as $section => $entries) {
            expect(array_keys($dictionary[$section]))->toBe(array_keys($entries));

            foreach ($dictionary[$section] as $text) {
                expect(is_array($text) || trim((string) $text) !== '')->toBeTrue();
            }
        }
    }
});
