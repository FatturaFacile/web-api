<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SupplierPayment;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        new OA\Property(property: 'supplier_payment_id', description: 'Links the 1099 payment to a USD supplier payment (type payment) not linked yet; paid_on, amount and reference default to its values', type: 'string', format: 'uuid', nullable: true),
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
            'supplier_payment_id' => ['nullable', 'uuid', Rule::exists('supplier_payments', 'id')->where('tenant_id', $tenantId), Rule::unique('us_1099_payments', 'supplier_payment_id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'paid_on' => ['required_without:supplier_payment_id', 'nullable', 'date_format:Y-m-d'],
            'amount' => ['required_without:supplier_payment_id', 'nullable', 'numeric', 'gt:0'],
            'form_type' => ['required', Rule::in(Us1099Recipient::Forms)],
            'box' => ['required', 'string', 'max:10'],
            'payment_method' => ['nullable', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        if (! in_array($validated['box'], Us1099Payment::Boxes[$validated['form_type']], true)) {
            throw ValidationException::withMessages(['box' => ['The box does not exist on the selected form.']]);
        }

        if (isset($validated['supplier_payment_id'])) {
            $supplierPayment = SupplierPayment::query()->where('tenant_id', $tenantId)->findOrFail($validated['supplier_payment_id']);

            if ($supplierPayment->type !== 'payment' || strtoupper((string) $supplierPayment->currency) !== 'USD') {
                throw ValidationException::withMessages(['supplier_payment_id' => [__('validation.us_1099_supplier_payment_invalid')]]);
            }

            $validated['paid_on'] ??= $supplierPayment->paid_at->toDateString();
            $validated['amount'] ??= (string) $supplierPayment->amount;
            $validated['reference'] ??= $supplierPayment->reference;
        }

        return response()->json(['data' => Us1099Payment::create([...$validated, 'currency' => 'USD', 'tenant_id' => $tenantId])], 201);
    }

    #[OA\Put(path: '/api/v1/general-ledger/us-1099/recipients/{id}', operationId: 'updateUs1099Recipient', description: 'Updates a 1099 recipient. The tax identification number is replaced only when `tin` is sent, and is never returned.', summary: 'Update a 1099 recipient', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 190),
        new OA\Property(property: 'business_name', type: 'string', nullable: true),
        new OA\Property(property: 'tin_type', type: 'string', enum: ['ein', 'ssn', 'itin']),
        new OA\Property(property: 'tin', type: 'string', nullable: true, description: 'Nine digits, with or without dashes; null clears it'),
        new OA\Property(property: 'address_line', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'state', type: 'string', nullable: true),
        new OA\Property(property: 'zip', type: 'string', nullable: true),
        new OA\Property(property: 'default_form', type: 'string', enum: ['1099-NEC', '1099-MISC']),
        new OA\Property(property: 'is_exempt', type: 'boolean'),
        new OA\Property(property: 'w9_received_on', type: 'string', format: 'date', nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateRecipient(Request $request, string $id): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $recipient = Us1099Recipient::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'business_name' => ['nullable', 'string', 'max:190'],
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

        if (array_key_exists('state', $validated) && $validated['state'] !== null) {
            $validated['state'] = strtoupper($validated['state']);
        }

        if (array_key_exists('tin', $validated)) {
            $tin = $validated['tin'] !== null ? preg_replace('/\D/', '', $validated['tin']) : null;
            $validated['tin'] = $tin;
            $validated['tin_last4'] = $tin !== null ? substr($tin, -4) : null;
        }

        $recipient->update($validated);

        return response()->json(['data' => $recipient->fresh()]);
    }

    #[OA\Delete(path: '/api/v1/general-ledger/us-1099/recipients/{id}', operationId: 'deleteUs1099Recipient', description: 'Deletes a 1099 recipient and the payments captured for it (soft delete).', summary: 'Delete a 1099 recipient', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyRecipient(string $id): Response
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $tenantId = $this->tenantId();
        $recipient = Us1099Recipient::query()->where('tenant_id', $tenantId)->findOrFail($id);

        $recipient->getConnection()->transaction(function () use ($recipient, $tenantId): void {
            Us1099Payment::query()->where('tenant_id', $tenantId)->where('recipient_id', $recipient->id)->get()->each->delete();
            $recipient->delete();
        });

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/general-ledger/us-1099/payments', operationId: 'listUs1099Payments', description: 'Payments captured for 1099 reporting. Filters: year, recipient_id.', summary: 'List 1099 payments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'recipient_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Payments', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function payments(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);
        $validated = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'recipient_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => Us1099Payment::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($validated['year']), static fn ($query) => $query->whereBetween('paid_on', ["{$validated['year']}-01-01", "{$validated['year']}-12-31"]))
            ->when(isset($validated['recipient_id']), static fn ($query) => $query->where('recipient_id', $validated['recipient_id']))
            ->orderByDesc('paid_on')
            ->get()]);
    }

    #[OA\Put(path: '/api/v1/general-ledger/us-1099/payments/{id}', operationId: 'updateUs1099Payment', description: 'Updates a captured 1099 payment: date, amount, form, box, method and reference.', summary: 'Update a 1099 payment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'paid_on', type: 'string', format: 'date'),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'form_type', type: 'string', enum: ['1099-NEC', '1099-MISC']),
        new OA\Property(property: 'box', type: 'string', example: '1'),
        new OA\Property(property: 'payment_method', type: 'string', nullable: true),
        new OA\Property(property: 'reference', type: 'string', nullable: true),
    ])), tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updatePayment(Request $request, string $id): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $payment = Us1099Payment::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $validated = $request->validate([
            'paid_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'form_type' => ['sometimes', 'required', Rule::in(Us1099Recipient::Forms)],
            'box' => ['sometimes', 'required', 'string', 'max:10'],
            'payment_method' => ['nullable', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $form = $validated['form_type'] ?? $payment->form_type;
        $box = $validated['box'] ?? $payment->box;

        if (! in_array($box, Us1099Payment::Boxes[$form], true)) {
            throw ValidationException::withMessages(['box' => ['The box does not exist on the selected form.']]);
        }

        $payment->update($validated);

        return response()->json(['data' => $payment->fresh()]);
    }

    #[OA\Delete(path: '/api/v1/general-ledger/us-1099/payments/{id}', operationId: 'deleteUs1099Payment', description: 'Deletes a captured 1099 payment (soft delete). The supplier payment it was linked to becomes available again.', summary: 'Delete a 1099 payment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroyPayment(string $id): Response
    {
        $this->authorize('manageSettings', VatSettlement::class);
        Us1099Payment::query()->where('tenant_id', $this->tenantId())->findOrFail($id)->delete();

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/general-ledger/us-1099/supplier-payments', operationId: 'listUs1099SupplierPayments', description: 'Supplier payments of the active tenant that are not yet linked to a 1099 payment, newest first. Filter: year (payment date). Use `supplier_payment_id` on the 1099 payment to link one.', summary: 'List supplier payments available for 1099 reporting', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'Unlinked supplier payments', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'passive_invoice_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'invoice_number', type: 'string', nullable: true),
            new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
            new OA\Property(property: 'amount', type: 'string'),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'reference', type: 'string', nullable: true),
        ]))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function supplierPayments(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);
        $validated = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $tenantId = $this->tenantId();
        $linked = Us1099Payment::query()->where('tenant_id', $tenantId)->whereNotNull('supplier_payment_id')->pluck('supplier_payment_id');

        return response()->json(['data' => SupplierPayment::query()
            ->with('passiveInvoice:id,invoice_number')
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->whereNotIn('id', $linked)
            ->when(isset($validated['year']), static fn ($query) => $query->whereBetween('paid_at', ["{$validated['year']}-01-01", "{$validated['year']}-12-31"]))
            ->orderByDesc('paid_at')
            ->limit(500)
            ->get()
            ->map(static fn (SupplierPayment $payment): array => [
                'id' => $payment->id,
                'passive_invoice_id' => $payment->passive_invoice_id,
                'invoice_number' => $payment->passiveInvoice?->invoice_number,
                'paid_at' => $payment->paid_at->toDateString(),
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
            ])
            ->values()]);
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
