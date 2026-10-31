<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\Appointments\ConflictCheckRequest;
use App\Models\Payment;
use App\Models\User;
use App\Policies\PaymentPolicy;
use Illuminate\Support\Str;

test('conflict check request authorization and validation rules', function (): void {
    $request = new ConflictCheckRequest;

    expect($request->authorize())->toBeTrue();

    $rules = $request->rules();
    expect($rules)->toHaveKeys(['starts_at', 'ends_at', 'user_ids', 'user_ids.*', 'ignore_appointment_id'])
        ->and($rules['starts_at'])->toContain('required', 'date')
        ->and($rules['ends_at'])->toContain('required', 'date', 'after:starts_at')
        ->and($rules['user_ids'])->toContain('required', 'array', 'min:1')
        ->and($rules['user_ids.*'])->toContain('required', 'uuid')
        ->and($rules['ignore_appointment_id'])->toContain('nullable', 'uuid');
});

test('payment policy authorization per tenant and roles', function (): void {
    $policy = new PaymentPolicy;
    $tenant1Id = (string) Str::uuid();
    $tenant2Id = (string) Str::uuid();

    $user = new User(['id' => (string) Str::uuid()]);
    $user->tenant_id = $tenant1Id;

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue();

    $sameTenantPayment = new Payment(['tenant_id' => $tenant1Id]);
    expect($policy->view($user, $sameTenantPayment))->toBeTrue()
        ->and($policy->delete($user, $sameTenantPayment))->toBeTrue();

    $otherTenantPayment = new Payment(['tenant_id' => $tenant2Id]);
    $userWithoutTenants = new User(['id' => (string) Str::uuid()]);
    $userWithoutTenants->setRelation('tenants', collect());

    expect($policy->view($userWithoutTenants, $otherTenantPayment))->toBeFalse()
        ->and($policy->delete($userWithoutTenants, $otherTenantPayment))->toBeFalse();
});
