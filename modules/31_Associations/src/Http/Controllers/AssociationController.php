<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Associations\Support\AssociationCapability;

/**
 * What the staff controllers of the module share.
 */
abstract class AssociationController extends Controller
{
    protected function allow(AssociationCapability $capability): void
    {
        Gate::authorize($capability->value);
    }

    protected function perPage(Request $request, int $default = 25, int $maximum = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $maximum));
    }

    protected function actorId(Request $request): ?string
    {
        $id = $request->user()?->getKey();

        return $id === null ? null : (string) $id;
    }

    protected function pdf(string $content, string $fileName): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($fileName)).'"',
        ]);
    }
}
