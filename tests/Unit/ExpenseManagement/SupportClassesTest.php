<?php

declare(strict_types=1);

use Modules\ExpenseManagement\Domain\Enums\ReportStatus;
use Modules\ExpenseManagement\Services\DeterministicReceiptParser;
use Modules\ExpenseManagement\Services\DuplicateDetector;
use Modules\ExpenseManagement\Support\Decimal;

it('rounds half up and keeps exact decimals', function (): void {
    expect(Decimal::round('1.005'))->toBe('1.01')
        ->and(Decimal::round('1.0049'))->toBe('1.00')
        ->and(Decimal::round('-1.005'))->toBe('-1.01')
        ->and(Decimal::mul('142', '0.5481'))->toBe('77.83')
        ->and(Decimal::div('46.48', '3'))->toBe('15.49')
        ->and(Decimal::normalize('12,5'))->toBe('0.00')
        ->and(Decimal::normalize(12.5))->toBe('12.50')
        ->and(Decimal::add('0.10', '0.20'))->toBe('0.30')
        ->and(Decimal::min('3.00', '2.00'))->toBe('2.00');
});

it('moves a report only along the allowed transitions', function (): void {
    expect(ReportStatus::Draft->canMoveTo(ReportStatus::Submitted))->toBeTrue()
        ->and(ReportStatus::Draft->canMoveTo(ReportStatus::Approved))->toBeFalse()
        ->and(ReportStatus::Submitted->canMoveTo(ReportStatus::Approved))->toBeTrue()
        ->and(ReportStatus::Rejected->canMoveTo(ReportStatus::Submitted))->toBeTrue()
        ->and(ReportStatus::Approved->canMoveTo(ReportStatus::Posted))->toBeTrue()
        ->and(ReportStatus::Posted->canMoveTo(ReportStatus::Cancelled))->toBeFalse()
        ->and(ReportStatus::Reimbursed->next())->toBe([])
        ->and(ReportStatus::Draft->isEditable())->toBeTrue()
        ->and(ReportStatus::Submitted->isEditable())->toBeFalse();
});

it('normalises merchant names so spelling differences still match', function (): void {
    expect(DuplicateDetector::normalizeMerchant('  Trattoria  "Da Mario"! '))->toBe('trattoria da mario')
        ->and(DuplicateDetector::normalizeMerchant('Café Zürich AG'))->toBe(DuplicateDetector::normalizeMerchant('cafe zurich ag'));
});

it('reads merchant, date, currency, total, VAT and lines from a receipt text', function (): void {
    $result = (new DeterministicReceiptParser)->extract("Trattoria da Mario\n12/03/2028\nPizza margherita 12,50\nCoperto 2,00\nTOTALE EUR 38,50\nIVA 10% 3,50\n", 'text/plain', 'scontrino.txt');

    expect($result->provider)->toBe('deterministic')
        ->and($result->merchantName)->toBe('Trattoria da Mario')
        ->and($result->expenseDate)->toBe('2028-03-12')
        ->and($result->currency)->toBe('EUR')
        ->and($result->totalAmount)->toBe('38.50')
        ->and($result->vatAmount)->toBe('3.50')
        ->and($result->vatRate)->toBe('10.0000')
        ->and($result->lines)->toHaveCount(2)
        ->and($result->confidence)->toBeGreaterThan(0.6);
});

it('reads Swiss amounts with apostrophes and the text sent by the mobile client', function (): void {
    $result = (new DeterministicReceiptParser)->extract('', 'image/jpeg', 'foto.jpg', "Hotel Bellevue\n2028-04-02\nTotal CHF 1'234.50\nMWST 8.1% 92.50\n");

    expect($result->currency)->toBe('CHF')->and($result->totalAmount)->toBe('1234.50')->and($result->vatAmount)->toBe('92.50')->and($result->vatRate)->toBe('8.1000');
});

it('returns an empty result with zero confidence for an image without text, never an invented one', function (): void {
    $result = (new DeterministicReceiptParser)->extract("\xFF\xD8\xFF binary", 'image/jpeg', 'foto.jpg');

    expect($result->confidence)->toBe(0.0)->and($result->totalAmount)->toBeNull()->and($result->merchantName)->toBeNull();
});
