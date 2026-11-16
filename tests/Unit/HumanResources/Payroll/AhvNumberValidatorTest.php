<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Modules\HumanResources\Rules\ValidAhvNumber;
use Modules\HumanResources\Services\Payroll\AhvNumberValidator;

test('valid AHV numbers pass the EAN-13 checksum and are normalized to the dotted format', function (string $input): void {
    expect(AhvNumberValidator::isValid($input))->toBeTrue()
        ->and(AhvNumberValidator::normalize($input))->toBe('756.9217.0769.85');
})->with([
    'dotted' => ['756.9217.0769.85'],
    'digits only' => ['7569217076985'],
    'with spaces' => [' 756 9217 0769 85 '],
]);

test('invalid AHV numbers are rejected', function (string $input): void {
    expect(AhvNumberValidator::isValid($input))->toBeFalse()
        ->and(AhvNumberValidator::normalize($input))->toBeNull();
})->with([
    'wrong check digit' => ['756.9217.0769.84'],
    'wrong country prefix' => ['757.9217.0769.85'],
    'too short' => ['756.9217.0769.8'],
    'letters' => ['756.ABCD.0769.85'],
    'empty' => [''],
]);

test('the check digit follows the EAN-13 weights', function (): void {
    expect(AhvNumberValidator::checkDigit('756921707698'))->toBe(5);
});

test('the validation rule reports invalid AHV numbers', function (): void {
    $valid = Validator::make(['ahv_number' => '756.9217.0769.85'], ['ahv_number' => [new ValidAhvNumber]]);
    $invalid = Validator::make(['ahv_number' => '756.9217.0769.80'], ['ahv_number' => [new ValidAhvNumber]]);

    expect($valid->passes())->toBeTrue()
        ->and($invalid->fails())->toBeTrue();
});
