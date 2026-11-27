<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\SwissEbill\Application\Commands\LinkCustomerEbillParticipantCommand;
use Modules\SwissEbill\Application\Commands\LinkCustomerEbillParticipantHandler;
use Modules\SwissEbill\Application\Commands\SyncCustomerEbillStatusCommand;
use Modules\SwissEbill\Application\Commands\SyncCustomerEbillStatusHandler;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Application\Queries\LookupEbillParticipantHandler;
use Modules\SwissEbill\Application\Queries\LookupEbillParticipantQuery;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Http\Controllers\Concerns\InteractsWithEbill;
use Modules\SwissEbill\Http\Requests\BulkVerifyCustomersRequest;
use Modules\SwissEbill\Http\Requests\LinkParticipantRequest;
use Modules\SwissEbill\Http\Requests\LookupParticipantRequest;
use Modules\SwissEbill\Http\Requests\NoInputRequest;
use Modules\SwissEbill\Infrastructure\Jobs\BulkVerifyCustomerEbillJob;
use Modules\SwissEbill\Models\EbillSetting;
use OpenApi\Attributes as OA;

/**
 * The participant directory of SIX Paynet / PostFinance: free lookups, the verification of a customer, the one-click link and the
 * background verification of the customer registry.
 */
final class ParticipantController extends Controller
{
    use ApiResponse;
    use InteractsWithEbill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Post(path: '/api/v1/swiss-ebill/participants/lookup', operationId: 'swissEbillLookupParticipant', summary: 'Look an identifier up in the eBill participant directory (UID, email, IBAN, participant id)', security: self::SECURITY, tags: ['Swiss eBill'], responses: [new OA\Response(response: 200, description: 'The matching participant, or null')])]
    public function lookup(LookupParticipantRequest $request, LookupEbillParticipantHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', EbillSetting::class);

        try {
            $criteria = ParticipantLookupCriteria::of((string) $request->input('lookup_type'), (string) $request->input('lookup_value'));
        } catch (InvalidArgumentException) {
            throw new EbillException('invalid_lookup_value', 'The identifier is not valid.', 422);
        }

        $record = $this->guarded(fn () => $handler->handle(new LookupEbillParticipantQuery($criteria, $request->boolean('force_refresh'))));

        return $this->successResponse($record?->toArray(), $this->message($record === null ? 'participant_not_found' : 'participant_found'));
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/customers/{id}/ebill/sync', operationId: 'swissEbillSyncCustomer', summary: 'Verify the eBill enrollment of a customer in the participant directory', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The customer eBill data and the matched participant')])]
    public function sync(string $id, NoInputRequest $request, SyncCustomerEbillStatusHandler $handler): JsonResponse
    {
        $customer = Contact::query()->findOrFail($id);
        Gate::authorize('create', EbillSetting::class);

        $result = $this->guarded(fn () => $handler->handle(new SyncCustomerEbillStatusCommand($id, (bool) $request->query('force_refresh', false))));

        return $this->successResponse(['customer' => $this->customerData($result['contact']), 'participant' => $result['record']?->toArray()], $this->message('customer_synced'));
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/customers/{id}/ebill/link', operationId: 'swissEbillLinkCustomer', summary: 'Link a customer to a participant of the eBill directory', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The linked customer')])]
    public function link(string $id, LinkParticipantRequest $request, LinkCustomerEbillParticipantHandler $handler): JsonResponse
    {
        $customer = Contact::query()->findOrFail($id);
        Gate::authorize('create', EbillSetting::class);

        $linked = $this->guarded(fn () => $handler->handle(new LinkCustomerEbillParticipantCommand($id, (string) $request->input('participant_id'))));

        return $this->successResponse(['customer' => $this->customerData($linked)], $this->message('customer_linked'));
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/customers/ebill/bulk-verify', operationId: 'swissEbillBulkVerifyCustomers', summary: 'Verify the eBill eligibility of the selected customers (or all) in the background', security: self::SECURITY, tags: ['Swiss eBill'], responses: [new OA\Response(response: 202, description: 'The verification was queued')])]
    public function bulkVerify(BulkVerifyCustomersRequest $request, TenantContext $tenants): JsonResponse
    {
        Gate::authorize('create', EbillSetting::class);

        $setting = EbillSetting::query()->first();

        if ($setting === null || ! $setting->ebill_enabled) {
            throw new EbillException('ebill_disabled', 'Swiss eBill is not enabled for this company.', 422);
        }

        $ids = array_values((array) $request->validated('customer_ids', []));
        BulkVerifyCustomerEbillJob::dispatch((string) $tenants->getTenantId(), $ids, $request->boolean('force_refresh'));

        return $this->successResponse(['queued_customers' => count($ids), 'all_customers' => $ids === []], $this->message('bulk_verify_queued'), 202);
    }

    /**
     * @param  callable(): mixed  $call
     */
    private function guarded(callable $call): mixed
    {
        try {
            return $call();
        } catch (EbillGatewayException $e) {
            throw new EbillException($e->isTransient() ? 'gateway_unavailable' : 'gateway_error', $e->getMessage(), 502);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function customerData(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'ebill_recipient_id' => $contact->ebill_recipient_id,
            'ebill_status' => $contact->ebill_status,
            'ebill_channel_type' => $contact->ebill_channel_type,
            'ebill_verified_at' => $contact->ebill_verified_at?->toIso8601String(),
            'ebill_opt_in_preferred' => (bool) $contact->ebill_opt_in_preferred,
        ];
    }
}
