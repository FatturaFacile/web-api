<?php

declare(strict_types=1);

use App\Actions\Auth\BootstrapSupportUser;
use App\Mail\SupportUserBootstrappedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('bootstraps support user when not present and dispatches credentials email', function (): void {
    Mail::fake();

    expect(User::where('email', BootstrapSupportUser::SUPPORT_EMAIL)->exists())->toBeFalse();

    $response = $this->getJson('/api/v1/system/bootstrap-support');

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Support user successfully created and credentials email dispatched.',
            'data' => [
                'created' => true,
                'email' => 'support@fatturafacile.eu',
                'email_dispatched' => true,
                'user' => [
                    'name' => 'Support FatturaFacile',
                    'email' => 'support@fatturafacile.eu',
                    'is_active' => true,
                ],
            ],
        ]);

    $createdUser = User::where('email', BootstrapSupportUser::SUPPORT_EMAIL)->first();
    expect($createdUser)->not->toBeNull()
        ->and($createdUser->name)->toBe('Support FatturaFacile')
        ->and($createdUser->is_active)->toBeTrue();

    Mail::assertSent(SupportUserBootstrappedMail::class, function (SupportUserBootstrappedMail $mail) use ($createdUser): bool {
        return $mail->hasTo('support@fatturafacile.eu')
            && $mail->user->id === $createdUser->id
            && ! empty($mail->rawPassword)
            && strlen($mail->rawPassword) >= 20;
    });
});

test('returns existing support user without recreating or resending emails when already present', function (): void {
    Mail::fake();

    $existing = User::create([
        'name' => 'Support FatturaFacile',
        'email' => 'support@fatturafacile.eu',
        'password' => 'already-hashed-password',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/system/bootstrap-support');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Support user is already present.',
            'data' => [
                'created' => false,
                'email' => 'support@fatturafacile.eu',
                'email_dispatched' => false,
                'user' => [
                    'id' => $existing->id,
                    'name' => 'Support FatturaFacile',
                    'email' => 'support@fatturafacile.eu',
                ],
            ],
        ]);

    expect(User::where('email', BootstrapSupportUser::SUPPORT_EMAIL)->count())->toBe(1);
    Mail::assertNothingSent();
});
