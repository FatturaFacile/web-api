<?php

declare(strict_types=1);

use App\Models\Invoice;
use Illuminate\Validation\ValidationException;
use Modules\SDI\Services\SdiPreflight;

test('an internal document is refused before anything else is checked', function (): void {
    app(SdiPreflight::class)->assertReady(new Invoice(['sdi_excluded' => true]));
})->throws(ValidationException::class, 'internal document');
