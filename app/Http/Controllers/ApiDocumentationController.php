<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Interactive documentation of the API (Scalar) and the OpenAPI specification it shows.
 */
class ApiDocumentationController extends Controller
{
    public function ui(): View
    {
        $this->ensureEnabled();

        return view('docs.scalar', ['title' => __('docs.ui.title'), 'specUrl' => route('documentation.spec', [], false)]);
    }

    public function spec(): BinaryFileResponse
    {
        $this->ensureEnabled();

        abort_unless(is_file((string) config('docs.spec_path')), 404, __('docs.errors.spec_missing'));

        return response()->file((string) config('docs.spec_path'), ['Content-Type' => 'application/json']);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('docs.enabled'), 404, __('docs.errors.disabled'));
    }
}
