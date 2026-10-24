<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retail\StorePosTerminalRequest;
use App\Models\PosTerminal;
use Illuminate\Http\JsonResponse;

class PosTerminalController extends Controller
{
    use HandlesLookupCrud;

    protected function modelClass(): string
    {
        return PosTerminal::class;
    }

    protected function label(): string
    {
        return __('pos.terminal_label');
    }

    public function store(StorePosTerminalRequest $request): JsonResponse
    {
        return $this->createRecord($request->validated());
    }
}
