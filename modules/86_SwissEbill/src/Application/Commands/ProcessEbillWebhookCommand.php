<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use Illuminate\Http\Request;

/**
 * A webhook call of the gateway for the company that owns the slug of the URL.
 */
final readonly class ProcessEbillWebhookCommand
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(public string $slug, public Request $request, public array $body) {}
}
