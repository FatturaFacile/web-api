<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('csrf-cookie|127.0.0.1');
    RateLimiter::clear('web|127.0.0.1');
});

test('the sanctum csrf cookie route allows 60 requests per minute and answers 429 to the 61st', function (): void {
    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
    }

    $this->get('/sanctum/csrf-cookie')->assertStatus(429);
});

test('the csrf cookie limit does not consume the budget of the other web routes', function (): void {
    for ($attempt = 1; $attempt <= 61; $attempt++) {
        $this->get('/sanctum/csrf-cookie');
    }

    $this->get('/')->assertStatus(301);
});

test('the csrf cookie response keeps its contract below the limit', function (): void {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertHeader('X-RateLimit-Limit', '60')
        ->assertCookie('XSRF-TOKEN');
});
