<?php

declare(strict_types=1);

namespace App\Actions\Ecommerce;

use App\Actions\Sales\SaveSalesDocument;
use App\Models\Contact;
use App\Models\EcommerceChannel;
use App\Models\EcommerceMapping;
use App\Models\SalesOrder;
use App\Services\Ecommerce\EcommerceProductResolver;
use App\Services\Inventory\StockService;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Imports one normalised e-commerce order as an ERP sales order, exactly once.
 *
 * The customer is matched by email (or created), every line is resolved to the exact product or variant bought and
 * stock is unloaded on that row. The order is recorded in `ecommerce_mappings`, so importing it again only synchronises
 * a changed status: a cancellation returns the stock, a reactivation takes it out again.
 */
class ImportEcommerceOrder
{
    public const IMPORTED = 'imported';

    public const UPDATED = 'updated';

    public const DUPLICATE = 'duplicate';

    private const LOCAL_STATUSES = ['draft', 'confirmed', 'in_production', 'shipped', 'completed', 'cancelled'];

    public function __construct(
        private readonly SaveSalesDocument $saveDocument,
        private readonly EcommerceProductResolver $resolver,
        private readonly StockService $stock,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $remote  a normalised order
     * @return array{status: string, order_id: ?string}
     */
    public function execute(EcommerceChannel $channel, array $remote): array
    {
        return DB::transaction(function () use ($channel, $remote): array {
            $mapping = EcommerceMapping::query()
                ->where('tenant_id', $channel->tenant_id)
                ->where('channel_id', $channel->id)
                ->where('entity_type', 'order')
                ->where('remote_id', $remote['remote_id'])
                ->lockForUpdate()
                ->first();

            return $mapping !== null ? $this->synchronise($channel, $mapping, $remote) : $this->create($channel, $remote);
        });
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{status: string, order_id: ?string}
     */
    private function create(EcommerceChannel $channel, array $remote): array
    {
        $contact = $this->contact($channel, $remote['customer']);
        $unresolved = 0;
        $items = [];

        foreach ($remote['lines'] as $line) {
            $product = $this->resolver->resolve($channel, $line);
            $unresolved += $product === null && $line['remote_product_id'] !== null ? 1 : 0;

            $items[] = [
                'product_id' => $product?->id,
                'description' => $line['name'] !== '' ? $line['name'] : ($product?->name ?? __('ecommerce.order.line_fallback', ['id' => $line['remote_variant_id'] ?? $line['remote_product_id'] ?? $line['sku'] ?? '-'])),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
            ];
        }

        /** @var SalesOrder $order */
        $order = $this->saveDocument->create(SalesOrder::class, 'sales_order', 'order_number', [
            'contact_id' => $contact->id,
            'date' => $remote['date'],
            'currency' => $remote['currency'],
            'status' => $this->localStatus($channel, $remote),
            'notes' => __('ecommerce.order.note', ['number' => $remote['number'], 'channel' => $channel->name]),
            'items' => $items,
        ]);

        // The goods leave through the shop, so the lines are delivered and stock is taken out of the exact row bought.
        if ($remote['canonical_status'] !== 'cancelled') {
            $this->stock->recordForLines('out', $order, $order->items);
        }

        $order->items()->update(['quantity_delivered' => DB::raw('quantity')]);

        EcommerceMapping::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'entity_type' => 'order',
            'local_id' => $order->id,
            'remote_id' => $remote['remote_id'],
            'extra_data' => ['number' => $remote['number'], 'status' => $remote['status'], 'canonical_status' => $remote['canonical_status'], 'unresolved_lines' => $unresolved],
        ]);

        $this->events->record($order, 'imported_from_ecommerce', ['channel_id' => $channel->id, 'remote_id' => $remote['remote_id']]);

        return ['status' => self::IMPORTED, 'order_id' => $order->id];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{status: string, order_id: ?string}
     */
    private function synchronise(EcommerceChannel $channel, EcommerceMapping $mapping, array $remote): array
    {
        $order = SalesOrder::query()->where('tenant_id', $channel->tenant_id)->with('items')->find($mapping->local_id);
        $previous = $mapping->extra_data['canonical_status'] ?? null;

        if ($order === null || ($previous === $remote['canonical_status'] && ($mapping->extra_data['status'] ?? null) === $remote['status'])) {
            return ['status' => self::DUPLICATE, 'order_id' => $order?->id];
        }

        if ($remote['canonical_status'] === 'cancelled' && $previous !== 'cancelled') {
            $this->stock->recordForLines('in', $order, $order->items);
        } elseif ($remote['canonical_status'] !== 'cancelled' && $previous === 'cancelled') {
            $this->stock->recordForLines('out', $order, $order->items);
        }

        $order->update(['status' => $this->localStatus($channel, $remote)]);
        $mapping->update(['extra_data' => array_merge($mapping->extra_data ?? [], ['status' => $remote['status'], 'canonical_status' => $remote['canonical_status']])]);
        $this->events->record($order, 'ecommerce_status_changed', ['from' => $previous, 'to' => $remote['canonical_status']]);

        return ['status' => self::UPDATED, 'order_id' => $order->id];
    }

    /**
     * The channel's own status map (remote status => ERP status) wins over the default of the canonical status.
     *
     * @param  array<string, mixed>  $remote
     */
    private function localStatus(EcommerceChannel $channel, array $remote): string
    {
        $custom = $channel->settings['order_status_map'][$remote['status']] ?? null;

        if (is_string($custom) && in_array($custom, self::LOCAL_STATUSES, true)) {
            return $custom;
        }

        return match ($remote['canonical_status']) {
            'processing' => 'confirmed',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'draft',
        };
    }

    /**
     * The customer by email, or a new client contact with the billing address of the order.
     *
     * @param  array<string, mixed>  $customer
     */
    private function contact(EcommerceChannel $channel, array $customer): Contact
    {
        $email = strtolower(trim((string) $customer['email']));
        $contact = $email !== ''
            ? Contact::query()->where('tenant_id', $channel->tenant_id)->whereRaw('lower(email) = ?', [$email])->first()
            : null;

        if ($contact === null) {
            $address = $customer['address'];
            $country = preg_match('/^[A-Z]{2}$/', (string) $address['country']) ? $address['country'] : ($channel->tenant?->country ?: 'IT');

            $contact = Contact::create([
                'tenant_id' => $channel->tenant_id,
                'type' => 'client',
                'country' => $country,
                'company_name' => $customer['company'] !== '' ? $customer['company'] : (($customer['first_name'].$customer['last_name']) === '' ? ($email !== '' ? $email : null) : null),
                'first_name' => $customer['first_name'] ?: null,
                'last_name' => $customer['last_name'] ?: null,
                'email' => $email !== '' ? $email : null,
                'phone' => $customer['phone'] ?: null,
            ]);

            if ($address['street'] !== '' && $address['postal_code'] !== '' && $address['city'] !== '') {
                $contact->addresses()->create([
                    'type' => 'billing',
                    'street' => $address['street'],
                    'postal_code' => $address['postal_code'],
                    'city' => $address['city'],
                    'state_canton' => $address['state'] ?: null,
                    'country' => $country,
                ]);
            }
        }

        if ($customer['remote_id'] !== null) {
            EcommerceMapping::query()->updateOrCreate(
                ['tenant_id' => $channel->tenant_id, 'channel_id' => $channel->id, 'entity_type' => 'contact', 'remote_id' => $customer['remote_id']],
                ['local_id' => $contact->id]
            );
        }

        return $contact;
    }
}
