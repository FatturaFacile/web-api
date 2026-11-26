<?php

declare(strict_types=1);

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\FiduciaryPortal\Models\FiduciaryAccountTranscoding;
use Modules\FiduciaryPortal\Services\Exporters\AbacusAsciiExporter;
use Modules\FiduciaryPortal\Services\Exporters\AbacusXmlExporter;
use Modules\FiduciaryPortal\Services\Exporters\DatevKoinosExporter;
use Modules\FiduciaryPortal\Services\Exporters\ProfisExporter;
use Modules\FiduciaryPortal\Services\Exporters\Sage50ExtraExporter;
use Modules\FiduciaryPortal\Services\Exporters\StudioExportRegistry;
use Modules\FiduciaryPortal\Services\Exporters\SwissFiduciaryUniversalExporter;
use Modules\FiduciaryPortal\Services\Exporters\TeamSystemExporter;
use Modules\FiduciaryPortal\Services\Exporters\TopalExporter;
use Modules\FiduciaryPortal\Services\Exporters\UniversalCsvExporter;
use Modules\FiduciaryPortal\Services\Exporters\ZucchettiExporter;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

test('all 10 studio export strategies generate valid payloads with transcoding applied', function (): void {
    $registry = new StudioExportRegistry(
        new AbacusXmlExporter,
        new AbacusAsciiExporter,
        new TopalExporter,
        new Sage50ExtraExporter,
        new SwissFiduciaryUniversalExporter,
        new TeamSystemExporter,
        new ZucchettiExporter,
        new ProfisExporter,
        new DatevKoinosExporter,
        new UniversalCsvExporter,
    );

    $tenant = new Tenant([
        'name' => 'Helvetia Advisory SA',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'uid_number' => 'CHE-114.892.451',
        'vat_number' => 'CHE-114.892.451 MWST',
    ]);

    $bankAccount = new ChartOfAccount(['account_code' => '1020', 'name' => 'Bank CHF']);
    $bankAccount->id = '00000000-0000-0000-0000-000000000101';
    $revAccount = new ChartOfAccount(['account_code' => '3400', 'name' => 'Consulting Revenue']);
    $revAccount->id = '00000000-0000-0000-0000-000000000102';

    $line1 = new JournalLine([
        'line_number' => 1,
        'account_id' => $bankAccount->id,
        'debit_amount' => '1000.0000',
        'credit_amount' => '0.0000',
        'functional_debit' => '1000.0000',
        'functional_credit' => '0.0000',
        'currency' => 'CHF',
        'exchange_rate' => '1.000000',
        'description' => 'Client invoice payment',
    ]);
    $line1->setRelation('account', $bankAccount);

    $line2 = new JournalLine([
        'line_number' => 2,
        'account_id' => $revAccount->id,
        'debit_amount' => '0.0000',
        'credit_amount' => '1000.0000',
        'functional_debit' => '0.0000',
        'functional_credit' => '1000.0000',
        'currency' => 'CHF',
        'exchange_rate' => '1.000000',
        'description' => 'Consulting fee',
    ]);
    $line2->setRelation('account', $revAccount);

    $entry = new JournalEntry([
        'entry_number' => 'JE-2026-001',
        'posting_date' => '2026-03-15',
        'document_date' => '2026-03-15',
        'journal_type' => 'standard',
        'description' => 'Advisory retainer',
        'total_amount' => '1000.0000',
        'fiduciary_verified' => true,
    ]);
    $entry->setRelation('lines', new Collection([$line1, $line2]));

    $transcodingMap = [
        $bankAccount->id => new FiduciaryAccountTranscoding([
            'source_account_id' => $bankAccount->id,
            'studio_account_code' => '1021',
            'studio_account_name' => 'Studio Bank UBS',
            'studio_vat_code' => 'N0',
        ]),
        $revAccount->id => new FiduciaryAccountTranscoding([
            'source_account_id' => $revAccount->id,
            'studio_account_code' => '3401',
            'studio_account_name' => 'Studio Advisory Revenue',
            'studio_vat_code' => 'U81',
        ]),
    ];

    $from = CarbonImmutable::parse('2026-03-01');
    $to = CarbonImmutable::parse('2026-03-31');

    foreach (StudioExportFormat::cases() as $format) {
        $strategy = $registry->resolve($format);
        $result = $strategy->generate($tenant, new Collection([$entry]), $transcodingMap, $from, $to, 'CHF', ['apply_transcoding' => true]);

        expect($result['content'])->not->toBeEmpty()
            ->and($result['rows_count'])->toBeGreaterThanOrEqual(1)
            ->and($result['file_name'])->toEndWith('.'.$format->extension())
            ->and($result['content'])->toContain('1021');
    }
});
