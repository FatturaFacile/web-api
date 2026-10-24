<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StoreAlertRequest;
use App\Http\Resources\CatalogResource;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AlertController extends Controller
{
    use HandlesLookupCrud;

    protected function modelClass(): string
    {
        return Alert::class;
    }

    protected function label(): string
    {
        return __('api.labels.alert');
    }

    public function store(StoreAlertRequest $request): JsonResponse
    {
        return $this->createRecord(array_merge($this->payload($request->validated()), ['created_by' => Auth::id()]));
    }

    public function update(StoreAlertRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $this->payload($request->validated()));
    }

    /**
     * Alerts currently in force: global ones plus those attached to the given contact/document.
     */
    public function active(Request $request): JsonResponse
    {
        $type = $request->query('alertable_type');
        $targetClass = is_string($type) ? (Alert::TARGET_TYPES[$type] ?? null) : null;

        $alerts = Alert::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(function ($q) use ($targetClass, $request): void {
                $q->whereNull('alertable_id');

                if ($targetClass !== null && $request->filled('alertable_id')) {
                    $q->orWhere(fn ($t) => $t->where('alertable_type', $targetClass)->where('alertable_id', $request->query('alertable_id')));
                }
            })
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->get();

        return $this->successResponse(CatalogResource::collection($alerts), __('alerts.messages.active'));
    }

    /**
     * Translate the API alias into the model class and verify the target belongs to the tenant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        if (isset($data['alertable_type'], $data['alertable_id'])) {
            $class = Alert::TARGET_TYPES[$data['alertable_type']];
            $class::findOrFail($data['alertable_id']);
            $data['alertable_type'] = $class;
        }

        return $data;
    }
}
