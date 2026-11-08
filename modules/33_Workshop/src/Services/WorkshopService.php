<?php

declare(strict_types=1);

namespace Modules\Workshop\Services;

use App\Actions\Sales\SaveSalesDocument;
use App\Actions\Subscriptions\SaveSubscription;
use App\Exceptions\BusinessRuleException;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\TireHotelRecord;
use App\Models\Vehicle;
use App\Services\Inventory\StockService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\Models\Subscription as EngineSubscription;
use Modules\Workshop\Models\RecurringService;
use Modules\Workshop\Models\VehicleSale;
use Modules\Workshop\Models\VehicleStockUnit;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;

/**
 * The workflow of a workshop: folders and their lines, the sales documents made from a folder, vehicle sales and the
 * recurring services. Every invoice goes through the invoicing service, which posts it to the ledger.
 */
final class WorkshopService
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly SaveSalesDocument $salesDocuments,
        private readonly SaveSubscription $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly StockService $stock,
        private readonly SubscriptionLifecycleServiceInterface $subscriptionLifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function openFolder(array $data): WorkshopFolder
    {
        return DB::transaction(function () use ($data): WorkshopFolder {
            $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);

            $folder = new WorkshopFolder;
            $folder->fill([
                ...$data,
                'contact_id' => $data['contact_id'] ?? $vehicle->client_id,
                'folder_number' => $this->numbering->next('workshop_folder')['number'],
                'status' => 'open',
                'received_at' => $data['received_at'] ?? now(),
            ]);
            $folder->save();

            if (isset($data['mileage_in']) && (int) $data['mileage_in'] > (int) $vehicle->mileage_km) {
                $vehicle->update(['mileage_km' => (int) $data['mileage_in']]);
            }

            return $folder;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateFolder(WorkshopFolder $folder, array $data): WorkshopFolder
    {
        return DB::transaction(function () use ($folder, $data): WorkshopFolder {
            $locked = $this->lock($folder);
            $this->assertEditable($locked);
            $locked->update($data);

            return $locked;
        });
    }

    /**
     * Adds a line to an editable folder. The folder is locked so a line cannot slip in while it is being invoiced.
     *
     * @param  array<string, mixed>  $data
     */
    public function addLine(WorkshopFolder $folder, array $data): WorkshopFolderLine
    {
        return DB::transaction(function () use ($folder, $data): WorkshopFolderLine {
            $locked = $this->lock($folder);
            $this->assertEditable($locked);

            $line = new WorkshopFolderLine;
            $line->fill([
                ...$data,
                'folder_id' => $locked->id,
                'discount_percentage' => (string) ($data['discount_percentage'] ?? '0'),
                'line_total' => $this->lineTotal((string) $data['quantity'], (string) $data['unit_price'], (string) ($data['discount_percentage'] ?? '0')),
                'sort_order' => (int) $locked->lines()->max('sort_order') + 1,
            ]);
            $line->save();

            return $line;
        });
    }

    /**
     * Removes a line of an editable folder.
     */
    public function removeLine(WorkshopFolder $folder, WorkshopFolderLine $line): void
    {
        DB::transaction(function () use ($folder, $line): void {
            $locked = $this->lock($folder);
            $this->assertEditable($locked);

            if ($line->folder_id !== $locked->id) {
                throw new BusinessRuleException('The line belongs to another folder.');
            }

            $line->delete();
        });
    }

    /**
     * Moves the folder along its workflow (see WorkshopFolder::Transitions); the mileage at hand-over is kept.
     */
    public function transition(WorkshopFolder $folder, string $target, ?int $mileageOut = null): WorkshopFolder
    {
        return DB::transaction(function () use ($folder, $target, $mileageOut): WorkshopFolder {
            $locked = $this->lock($folder);

            if (! in_array($target, WorkshopFolder::Transitions[$locked->status] ?? [], true)) {
                throw new BusinessRuleException("A folder cannot move from {$locked->status} to {$target}.");
            }

            $locked->update(array_filter(['status' => $target, 'mileage_out' => $mileageOut], static fn (mixed $value): bool => $value !== null));

            return $locked;
        });
    }

    /**
     * The quote of the sales module, made from the lines of the folder, for the customer to approve.
     */
    public function quote(WorkshopFolder $folder, ?string $validUntil = null): Quote
    {
        return DB::transaction(function () use ($folder, $validUntil): Quote {
            $locked = $this->lock($folder);
            $this->assertEditable($locked);
            $this->assertHasLines($locked);

            /** @var Quote $quote */
            $quote = $this->salesDocuments->create(Quote::class, 'quote', 'quote_number', [
                'contact_id' => $locked->contact_id,
                'date' => now()->toDateString(),
                'valid_until' => $validUntil,
                'currency' => $locked->currency,
                'status' => 'draft',
                'notes' => $this->reference($locked),
                'items' => $this->documentItems($locked),
            ]);

            $locked->update(['quote_id' => $quote->id]);

            return $quote;
        });
    }

    /**
     * The delivery note handed over with the vehicle. Tracked parts leave the stock here, as in the sales flow.
     */
    public function deliveryNote(WorkshopFolder $folder): DeliveryNote
    {
        return DB::transaction(function () use ($folder): DeliveryNote {
            $locked = $this->lock($folder);
            $this->assertHasLines($locked);

            if ($locked->delivery_note_id !== null) {
                throw new BusinessRuleException('The folder already has a delivery note.');
            }

            if (! in_array($locked->status, ['completed', 'delivered'], true)) {
                throw new BusinessRuleException('Only a completed folder gets a delivery note.');
            }

            $note = DeliveryNote::create([
                'contact_id' => $locked->contact_id,
                'ddt_number' => $this->numbering->next('delivery_note')['number'],
                'date' => now()->toDateString(),
                'status' => 'issued',
                'notes' => $this->reference($locked),
            ]);

            foreach ($this->documentItems($locked) as $item) {
                $note->items()->create([
                    'product_id' => $item['product_id'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                ]);
            }

            $this->stock->recordForLines('out', $note, $note->items()->get());
            $locked->update(['delivery_note_id' => $note->id]);

            return $note->load('items');
        });
    }

    /**
     * Invoices the intervention once, linked to the quote and the delivery note of the folder.
     */
    public function invoice(WorkshopFolder $folder, string $date): Invoice
    {
        return DB::transaction(function () use ($folder, $date): Invoice {
            $locked = $this->lock($folder);
            $this->assertHasLines($locked);

            if ($locked->invoice_id !== null || $locked->status === 'invoiced') {
                throw new BusinessRuleException('The folder is already invoiced.');
            }

            if (! in_array($locked->status, ['completed', 'delivered'], true)) {
                throw new BusinessRuleException('Only a completed or delivered folder is invoiced.');
            }

            $invoice = $this->invoices->createInvoice([
                'contact_id' => $locked->contact_id,
                'invoice_type' => 'standard',
                'date' => $date,
                'currency' => $locked->currency,
                'quote_id' => $locked->quote_id,
                'delivery_note_id' => $locked->delivery_note_id,
                'notes' => $this->reference($locked),
                'items' => $this->documentItems($locked),
            ]);

            $locked->update(['invoice_id' => $invoice->id, 'status' => 'invoiced']);

            return $invoice;
        });
    }

    /**
     * Sells a stock vehicle: the vehicle joins the registry of the buyer and the sale is invoiced.
     *
     * @param  array<string, mixed>  $data
     */
    public function sell(VehicleStockUnit $unit, array $data): VehicleSale
    {
        return DB::transaction(function () use ($unit, $data): VehicleSale {
            $locked = VehicleStockUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if ($locked->status === 'sold') {
                throw new BusinessRuleException('The vehicle is already sold.');
            }

            $plate = (string) ($data['license_plate'] ?? $locked->license_plate ?? '');

            if ($plate === '') {
                throw new BusinessRuleException('A license plate is needed to register the vehicle of the buyer.');
            }

            $vehicle = Vehicle::query()->firstOrNew(['license_plate' => $plate]);
            $vehicle->fill([
                'client_id' => $data['buyer_contact_id'],
                'vin_chassis' => $locked->vin,
                'make' => $locked->make,
                'model' => $locked->model,
                'year' => $locked->year,
                'mileage_km' => $locked->mileage_km,
            ]);
            $vehicle->save();

            $invoice = $this->invoices->createInvoice([
                'contact_id' => $data['buyer_contact_id'],
                'invoice_type' => 'standard',
                'date' => $data['sale_date'],
                'currency' => $locked->currency,
                'notes' => "Vehicle {$locked->stock_number}",
                'items' => [[
                    'description' => trim("{$locked->make} {$locked->model} ".($locked->vin ?? '')." ({$locked->condition})"),
                    'quantity' => 1,
                    'unit_price' => (string) $data['sale_price'],
                    'tax_rate' => (string) $data['tax_rate'],
                ]],
            ]);

            $sale = new VehicleSale;
            $sale->fill([
                'stock_id' => $locked->id,
                'buyer_contact_id' => $data['buyer_contact_id'],
                'vehicle_id' => $vehicle->id,
                'sale_date' => $data['sale_date'],
                'sale_price' => (string) $data['sale_price'],
                'tax_rate' => (string) $data['tax_rate'],
                'invoice_id' => $invoice->id,
            ]);
            $sale->save();

            $locked->update(['status' => 'sold', 'vehicle_id' => $vehicle->id, 'license_plate' => $plate]);

            return $sale;
        });
    }

    /**
     * A service billed at every period: a subscription of the subscription engine for the owner of the vehicle (with
     * its carrier row in `subscriptions`, the table the recurring services reference).
     *
     * @param  array<string, mixed>  $data
     */
    public function startRecurringService(array $data): RecurringService
    {
        return DB::transaction(function () use ($data): RecurringService {
            $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);

            if (isset($data['tire_hotel_record_id'])) {
                $record = TireHotelRecord::query()->findOrFail($data['tire_hotel_record_id']);

                if ($record->vehicle_id !== $vehicle->id) {
                    throw new BusinessRuleException('The stored tyre set belongs to another vehicle.');
                }
            }

            $subscription = $this->subscriptions->create([
                'contact_id' => $vehicle->client_id,
                'name' => $data['name'],
                'interval' => $data['interval'],
                'interval_count' => $data['interval_count'] ?? 1,
                'currency' => $data['currency'],
                'start_date' => $data['start_date'],
                'auto_renew' => true,
                'notes' => "Vehicle {$vehicle->license_plate}",
                'items' => [[
                    'description' => $data['description'] ?? $data['name'],
                    'quantity' => 1,
                    'unit_price' => (string) $data['unit_price'],
                    'tax_rate' => (string) $data['tax_rate'],
                ]],
            ]);

            $service = new RecurringService;
            $service->fill([
                'vehicle_id' => $vehicle->id,
                'tire_hotel_record_id' => $data['tire_hotel_record_id'] ?? null,
                'subscription_id' => $subscription->id,
                'service_type' => $data['service_type'],
            ]);
            $service->save();

            return $service->load('subscription');
        });
    }

    /**
     * Stops the billing of a recurring service from the next period on: the engine subscription is cancelled at the
     * end of the billed period and its carrier row is marked cancelled.
     */
    public function stopRecurringService(RecurringService $service): RecurringService
    {
        DB::transaction(function () use ($service): void {
            $engine = EngineSubscription::query()->whereKey($service->subscription_id)->first();

            if ($engine !== null && ! $engine->isCancelled() && ! $engine->cancel_at_period_end) {
                $this->subscriptionLifecycle->cancelSubscription(new CancelSubscriptionDTO(
                    subscriptionId: (string) $engine->id,
                    reason: 'Recurring workshop service stopped',
                    reasonCode: 'other',
                    cancellationType: 'end_of_term',
                    refund: false,
                ));
            }

            $service->subscription()->update(['status' => 'cancelled', 'next_billing_date' => null]);
        });

        return $service->load('subscription');
    }

    /**
     * Net amount of a folder line: quantity times unit price less the percentage discount, rounded to cents with
     * bcmath.
     */
    public function lineTotal(string $quantity, string $unitPrice, string $discountPercentage): string
    {
        $gross = Decimal::mul($quantity, $unitPrice);
        $discount = Decimal::div(Decimal::mul($gross, $discountPercentage), '100');

        return Decimal::round2(Decimal::sub($gross, $discount));
    }

    /**
     * @return list<array{product_id: ?string, description: string, quantity: string, unit_price: string, tax_rate: string, discount_percentage: string}>
     */
    private function documentItems(WorkshopFolder $folder): array
    {
        return $folder->lines()->get()->map(static fn (WorkshopFolderLine $line): array => [
            'product_id' => $line->product_id,
            'description' => $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'tax_rate' => (string) $line->tax_rate,
            'discount_percentage' => (string) $line->discount_percentage,
        ])->values()->all();
    }

    private function reference(WorkshopFolder $folder): string
    {
        $plate = Vehicle::query()->whereKey($folder->vehicle_id)->value('license_plate');

        return "Folder {$folder->folder_number}, vehicle {$plate}";
    }

    private function lock(WorkshopFolder $folder): WorkshopFolder
    {
        return WorkshopFolder::query()->lockForUpdate()->findOrFail($folder->id);
    }

    private function assertEditable(WorkshopFolder $folder): void
    {
        if ($folder->isLocked()) {
            throw new BusinessRuleException("A {$folder->status} folder cannot change.");
        }
    }

    private function assertHasLines(WorkshopFolder $folder): void
    {
        if (! $folder->lines()->exists()) {
            throw new BusinessRuleException('The folder has no lines.');
        }
    }
}
