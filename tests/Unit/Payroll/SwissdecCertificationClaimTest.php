<?php

declare(strict_types=1);

use Modules\Payroll\Swissdec\SalaryDeclarationBuilder;
use Modules\Payroll\Swissdec\SwissdecCertificationStatus;
use Modules\Payroll\Swissdec\SwissdecTransmissionUnavailable;
use Modules\Payroll\Swissdec\UncertifiedTransmitterStub;

test('the code never claims certification', function (): void {
    $status = SwissdecCertificationStatus::toArray();

    expect($status['is_certified'])->toBeFalse()
        ->and($status['schema_validated'])->toBeFalse()
        ->and($status['missing_for_certification'])->not->toBeEmpty()
        ->and((new UncertifiedTransmitterStub)->isCertified())->toBeFalse();
});

test('the stub sends nothing and says so', function (): void {
    expect(fn () => (new UncertifiedTransmitterStub)->transmit(['SalaryDeclaration' => []]))
        ->toThrow(SwissdecTransmissionUnavailable::class, 'non è certificata');
});

test('the check digit of an AHV number is verified', function (?string $number, bool $valid): void {
    expect(SalaryDeclarationBuilder::isValidSocialSecurityNumber($number))->toBe($valid);
})->with([
    'a valid number with dots' => ['756.9217.0769.85', true],
    'a valid number without dots' => ['7569217076985', true],
    'a wrong check digit' => ['756.9217.0769.86', false],
    'a wrong country prefix' => ['755.9217.0769.85', false],
    'too short' => ['756.9217.0769', false],
    'missing' => [null, false],
]);
