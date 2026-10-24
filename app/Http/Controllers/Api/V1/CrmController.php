<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CrmController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FatturaPaBuilderService $fatturaPaBuilder,
        private readonly QrBillGeneratorService $qrBillGenerator
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Contact::with('addresses')->latest();

        if ($request->filled('type')) {
            $type = (string) $request->query('type');
            if ($type === 'customer' || $type === 'client') {
                $query->whereIn('type', ['client', 'customer']);
            } else {
                $query->where('type', $type);
            }
        }

        if ($request->filled('country')) {
            $query->where('country', strtoupper((string) $request->query('country')));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('vat_number', 'like', "%{$search}%")
                    ->orWhere('fiscal_code', 'like', "%{$search}%");
            });
        }

        return $this->successResponse(
            data: $query->paginate(25),
            message: 'Contacts retrieved successfully'
        );
    }

    public function clients(Request $request): JsonResponse
    {
        $clients = Contact::with('addresses')
            ->whereIn('type', ['client', 'customer', 'both'])
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $clients, message: 'Clients retrieved');
    }

    public function suppliers(Request $request): JsonResponse
    {
        $suppliers = Contact::with('addresses')
            ->whereIn('type', ['supplier', 'both'])
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $suppliers, message: 'Suppliers retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:client,customer,supplier,both,lead'],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'], // ISO 3166-1 alpha-2 code
            'company_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'fiscal_code' => ['nullable', 'string', 'max:16'],
            'uid_number' => ['nullable', 'string', 'max:20'],
            'sdi_code' => ['nullable', 'string', 'size:7'],
            'pec' => ['nullable', 'email'],
            'iban' => ['nullable', 'string', 'max:34'],
            'qr_iban' => ['nullable', 'string', 'max:34'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'address' => ['nullable', 'array'],
            'address.street' => ['required_with:address', 'string'],
            'address.house_number' => ['nullable', 'string'],
            'address.postal_code' => ['required_with:address', 'string'],
            'address.city' => ['required_with:address', 'string'],
            'address.state_canton' => ['nullable', 'string'],
            'address.country' => ['required_with:address', 'string', 'size:2'],
        ]);

        $addressData = $validated['address'] ?? null;
        unset($validated['address']);

        $contact = new Contact;
        $contact->tenant_id = $this->tenantContext->getTenantId();
        $contact->fill($validated);
        $contact->save();

        if ($addressData !== null) {
            $contact->addresses()->create([
                'type' => 'billing',
                ...$addressData,
            ]);
        }

        return $this->successResponse(
            data: $contact->load('addresses'),
            message: 'Contact created successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    public function show(string $id): JsonResponse
    {
        $contact = Contact::with('addresses')->findOrFail($id);

        return $this->successResponse(data: $contact, message: 'Contact details retrieved');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $contact->update($request->all());

        return $this->successResponse(data: $contact->load('addresses'), message: 'Contact updated successfully');
    }

    public function destroy(string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();

        return $this->successResponse(message: 'Contact deleted successfully');
    }
}
