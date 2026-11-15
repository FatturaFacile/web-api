<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\Us1099Payment;
use Modules\GeneralLedger\Models\Us1099Recipient;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Localization\Us1099Service;
use OpenApi\Attributes as OA;

/**
 * 1099 data capture: recipients (payees), the payments made to them and the yearly summary by form and box.
 */
final class Us1099Controller extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Us1099Service $summaries,
    ) {}

    #[OA\Get(path: '/api/v1/general-ledger/us-1099/recipients', operationId: 'listUs1099Recipients', description: 'The 1099 recipients of the active tenant. The tax identification number is never returned, only its last four digits.', summary: 'List 1099 recipients', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 200, description: 'Recipients', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function recipients(): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        return response()->json(['data' => Us1099Recipient::query()->where('tenant_id', $this->tenantId())->orderBy('name')->get()]);
    }

    #[OA\Post(path: '/api/v1/general-ledger/us-1099/recipients', operationId: 'storeUs1099Recipient', description: 'Adds a 1099 recipient. The tax identification number (9 digits) is stored encrypted.', summary: 'Add a 1099 recipient', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'business_name', type: 'string', nullable: true),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'tin_type', type: 'string', enum: ['ein', 'ssn', 'itin']),
        new OA\Property(property: 'tin', type: 'string', nullable: true, description: '9 digits, dashes allowed'),
        new OA\Property(property: 'address_line', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'state', type: 'string', nullable: true),
        new OA\Property(property: 'zip', type: 'string', nullable: true),
        new OA\Property(property: 'default_form', type: 'string', enum: ['1099-NEC', '1099-MISC']),
        new OA\Property(property: 'is_exempt', type: 'boolean'),
        new OA\Property(property: 'w9_received_on', type: 'string', format: 'date', nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 201, description: 'Recipient added', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeRecipient(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'business_name' => ['nullable', 'string', 'max:190'],
            'contact_id' => ['nullable', 'uuid'],
            'tin_type' => ['sometimes', Rule::in(Us1099Recipient::TinTypes)],
            'tin' => ['nullable', 'string', 'regex:/^\d{2}-?\d{7}$|^\d{3}-?\d{2}-?\d{4}$/'],
            'address_line' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'size:2', 'alpha'],
            'zip' => ['nullable', 'string', 'max:10'],
            'default_form' => ['sometimes', Rule::in(Us1099Recipient::Forms)],
            'is_exempt' => ['sometimes', 'boolean'],
            'w9_received_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $tin = isset($validated['tin']) ? preg_replace('/\D/', '', $validated['tin']) : null;
        $recipient = Us1099Recipient::create([
            ...$validated,
            'state' => isset($validated['state']) ? strtoupper($validated['state']) : null,
            'tin' => $tin,
            'tin_last4' => $tin !== null ? substr($tin, -4) : null,
            'tenant_id' => $this->tenantId(),
        ]);

        return response()->json(['data' => $recipient], 201);
    }

    #[OA\Post(path: '/api/v1/general-ledger/us-1099/payments', operationId: 'storeUs1099Payment', description: 'Records a payment to a 1099 recipient with the form and box it is reported in (1099-NEC boxes 1 and 4; 1099-MISC boxes 1, 2, 3, 6 and 10).', summary: 'Record a 1099 payment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['recipient_id', 'paid_on', 'amount', 'form_type', 'box'], properties: [
        new OA\Property(property: 'recipient_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'paid_on', type: 'string', format: 'date'),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'form_type', type: 'string', enum: ['1099-NEC', '1099-MISC']),
        new OA\Property(property: 'box', type: 'string', example: '1'),
        new OA\Property(property: 'payment_method', type: 'string', nullable: true),
        new OA\Property(property: 'reference', type: 'string', nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], responses: [
        new OA\Response(response: 201, description: 'Payment recorded', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storePayment(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $tenantId = $this->tenantId();
        $validated = $request->validate([
            'recipient_id' => ['required', 'uuid', Rule::exists('us_1099_recipients', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'form_type' => ['required', Rule::in(Us1099Recipient::Forms)],
            'box' => ['required', 'string', 'max:10'],
            'payment_method' => ['nullable', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        if (! in_array($validated['box'], Us1099Payment::Boxes[$validated['form_type']], true)) {
            throw ValidationException::withMessages(['box' => ['The box does not exist on the selected form.']]);
        }

        return response()->json(['data' => Us1099Payment::create([...$validated, 'currency' => 'USD', 'tenant_id' => $tenantId])], 201);
    }

    #[OA\Get(path: '/api/v1/general-ledger/us-1099/summary', operationId: 'getUs1099Summary', description: 'Payments of a calendar year summed by recipient, form and box, with the reporting threshold and the missing data (tax identification number, W-9).', summary: '1099 yearly summary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
    ], responses: [
        new OA\Response(response: 200, description: 'Summary', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);
        $validated = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        return response()->json(['data' => $this->summaries->summary($this->tenantId(), (int) $validated['year'])]);
    }

    private function tenantId(): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }
}
