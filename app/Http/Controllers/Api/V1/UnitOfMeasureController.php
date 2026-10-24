<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreUnitOfMeasureRequest;
use App\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;

class UnitOfMeasureController extends Controller
{
    use HandlesLookupCrud;

    protected function modelClass(): string
    {
        return UnitOfMeasure::class;
    }

    protected function label(): string
    {
        return __('api.labels.unit_of_measure');
    }

    public function store(StoreUnitOfMeasureRequest $request): JsonResponse
    {
        return $this->createRecord($request->validated());
    }

    public function update(StoreUnitOfMeasureRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $request->validated());
    }
}
