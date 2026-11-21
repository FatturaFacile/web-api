<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Executors;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\Invoicing\Actions\CreateSalesQuoteAction;
use Modules\Procurement\Actions\CreatePurchaseOrderAction;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\ServiceManagement\Actions\CreateTicketAction;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;

/**
 * Creates the ERP draft of an approved extraction through the same actions the rest of the ERP uses: a sales quote, a
 * purchase order, a support ticket or a contact. The payload is validated like a user request; whatever fails goes back to
 * the reviewer as a readable message, so a half-understood email never becomes a broken record.
 */
final readonly class ErpDraftExecutor implements SuggestionExecutorInterface
{
    public function __construct(
        private CreateSalesQuoteAction $quotes,
        private CreatePurchaseOrderAction $orders,
        private CreateTicketAction $tickets,
    ) {}

    public function supports(SuggestionKind $kind): bool
    {
        return $kind->isDraftEntity();
    }

    public function execute(string $tenantId, ?string $userId, SuggestionKind $kind, array $payload): array
    {
        return DB::transaction(fn (): array => match ($kind) {
            SuggestionKind::SalesQuote => $this->quote($tenantId, $userId, $payload),
            SuggestionKind::PurchaseOrder => $this->order($tenantId, $userId, $payload),
            SuggestionKind::SupportTicket => $this->ticket($tenantId, $userId, $payload),
            SuggestionKind::Contact => $this->contact($tenantId, $payload),
            default => throw new AiCognitiveException('This suggestion creates no record.'),
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string|null, id: string|null}
     */
    private function quote(string $tenantId, ?string $userId, array $payload): array
    {
        $data = $this->validate($payload, [
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'currency' => ['nullable', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.uom' => ['nullable', 'string', 'max:20'],
        ]);

        $items = array_values((array) $data['items']);
        unset($data['items']);

        $quote = $this->quotes->execute(array_filter($data, static fn (mixed $value): bool => $value !== null) + ['tenant_id' => $tenantId, 'created_by' => $userId], $items);

        return ['type' => 'quotes', 'id' => (string) $quote->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string|null, id: string|null}
     */
    private function order(string $tenantId, ?string $userId, array $payload): array
    {
        $data = $this->validate($payload, [
            'supplier_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'currency' => ['nullable', 'string', 'size:3'],
            'order_date' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity_ordered' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.uom' => ['nullable', 'string', 'max:20'],
        ]);

        $lines = array_map(static fn (array $line): array => array_merge($line, ['uom' => $line['uom'] ?? 'PCS']), array_values((array) $data['lines']));

        $order = $this->orders->execute(new CreatePurchaseOrderDTO(
            supplierId: (string) $data['supplier_id'],
            orderDate: (string) ($data['order_date'] ?? now()->toDateString()),
            expectedDeliveryDate: (string) ($data['expected_delivery_date'] ?? now()->addDays(14)->toDateString()),
            lines: $lines,
            currency: (string) ($data['currency'] ?? 'EUR'),
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            tenantId: $tenantId,
            companyId: $tenantId,
            creatorUserId: $userId,
        ));

        return ['type' => 'purchase_orders', 'id' => (string) $order->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string|null, id: string|null}
     */
    private function ticket(string $tenantId, ?string $userId, array $payload): array
    {
        $data = $this->validate($payload, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'customer_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $ticket = $this->tickets->execute(new CreateTicketDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            title: (string) $data['title'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            customerId: isset($data['customer_id']) ? (string) $data['customer_id'] : null,
            contactEmail: isset($data['contact_email']) ? (string) $data['contact_email'] : null,
            contactPhone: isset($data['contact_phone']) ? (string) $data['contact_phone'] : null,
            priority: (string) ($data['priority'] ?? 'medium'),
            channel: 'email',
            requesterUserId: $userId,
        ), $userId);

        return ['type' => 'tickets', 'id' => (string) $ticket->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string|null, id: string|null}
     */
    private function contact(string $tenantId, array $payload): array
    {
        $data = $this->validate($payload, [
            'type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'company_name' => ['required_without:last_name', 'nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required_without:company_name', 'nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vat_number' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        $contact = Contact::query()->create(array_filter($data, static fn (mixed $value): bool => $value !== null) + ['tenant_id' => $tenantId]);

        return ['type' => 'contacts', 'id' => (string) $contact->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $payload, array $rules): array
    {
        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            throw new AiCognitiveException('The proposal is incomplete: '.implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }
}
