<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\PaymentScheduleRequest;
use App\Http\Requests\Catalog\StorePaymentTermRequest;
use App\Http\Resources\CatalogResource;
use App\Models\PaymentTerm;
use App\Services\Catalog\PaymentTermScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class PaymentTermController extends Controller
{
    use HandlesLookupCrud;

    public function __construct(private readonly PaymentTermScheduler $scheduler) {}

    protected function modelClass(): string
    {
        return PaymentTerm::class;
    }

    protected function label(): string
    {
        return __('api.labels.payment_term');
    }

    public function store(StorePaymentTermRequest $request): JsonResponse
    {
        $term = $this->persist(new PaymentTerm, $request->validated());

        return $this->successResponse(new CatalogResource($term), __('catalog.messages.payment_term_created'), Response::HTTP_CREATED);
    }

    public function update(StorePaymentTermRequest $request, string $id): JsonResponse
    {
        $term = $this->persist(PaymentTerm::findOrFail($id), $request->validated());

        return $this->successResponse(new CatalogResource($term), __('catalog.messages.payment_term_updated'));
    }

    /**
     * Compute the due-date schedule (installments and early-payment discount) for an amount.
     */
    public function schedule(PaymentScheduleRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        $schedule = $this->scheduler->schedule(
            PaymentTerm::findOrFail($id),
            (float) $data['amount'],
            isset($data['date']) ? Carbon::parse($data['date']) : now(),
        );

        return $this->successResponse($schedule, __('catalog.messages.schedule_computed'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(PaymentTerm $term, array $data): PaymentTerm
    {
        return PaymentTerm::resolveConnection()->transaction(function () use ($term, $data): PaymentTerm {
            $installments = $data['installments'] ?? null;
            unset($data['installments']);

            $term->fill($data)->save();

            if ($installments !== null) {
                $term->installments()->delete();
                foreach (array_values($installments) as $index => $row) {
                    $term->installments()->create([
                        'sequence' => $index + 1,
                        'percent' => $row['percent'],
                        'days' => $row['days'],
                    ]);
                }
            }

            return $term->load('installments');
        });
    }
}
