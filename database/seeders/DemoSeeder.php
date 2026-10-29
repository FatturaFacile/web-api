<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Sales\RecordPayment;
use App\Actions\Sales\SaveSalesDocument;
use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo company with customers, suppliers, products, stock, quotes, orders, invoices and payments, so a fresh
 * installation has something to look at. Documents go through the same services as the API (numbering, totals,
 * payments, stock), with dates relative to today.
 *
 * Not part of DatabaseSeeder: run it explicitly with `php artisan db:seed --class=DemoSeeder`.
 * Sign in as demo@fatturafacile.test / password.
 */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@fatturafacile.test';

    private const VAT = 'IT01234567890';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InvoiceService $invoices,
        private readonly SaveSalesDocument $salesDocuments,
        private readonly RecordPayment $payments,
        private readonly DocumentNumberingService $numbering,
        private readonly StockService $stock,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder skipped: the application is in production.');

            return;
        }

        if (Tenant::where('vat_number', self::VAT)->exists()) {
            $this->command?->info('DemoSeeder skipped: the demo company already exists.');

            return;
        }

        $tenant = Tenant::create([
            'name' => 'Officina Demo',
            'legal_name' => 'Officina Demo S.r.l.',
            'fiscal_code' => '01234567890',
            'vat_number' => self::VAT,
            'country' => 'IT',
            'default_currency' => 'EUR',
            'iban' => 'IT60X0542811101000000123456',
            'sdi_code' => 'M5UXCR1',
            'pec_email' => 'officinademo@pec.example.it',
        ]);

        $user = User::firstOrCreate(['email' => self::EMAIL], [
            'name' => 'Utente Demo',
            'password' => 'password',
            'locale' => 'it',
            'is_active' => true,
        ]);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

        $this->tenantContext->runWithTenant($tenant, function (): void {
            $terms = $this->seedPaymentTerms();
            $products = $this->seedCatalog();
            [$customers, $suppliers] = $this->seedContacts($terms);

            $this->seedQuotesAndOrders($customers, $products);
            $this->seedInvoices($customers, $products);
            $this->seedPassiveInvoices($suppliers);
        });

        $this->command?->info('Demo company created. Sign in as '.self::EMAIL.' / password');
    }

    /**
     * @return array<string, PaymentTerm>
     */
    private function seedPaymentTerms(): array
    {
        $terms = [];
        foreach ([
            ['RD', 'Rimessa diretta', 0, false, 'TP02'],
            ['BB30', 'Bonifico 30 giorni', 30, false, 'TP02'],
            ['BB60FM', 'Bonifico 60 giorni fine mese', 60, true, 'TP02'],
        ] as [$code, $name, $days, $endOfMonth, $sdi]) {
            $terms[$code] = PaymentTerm::create([
                'code' => $code,
                'name' => $name,
                'days' => $days,
                'end_of_month' => $endOfMonth,
                'sdi_terms_code' => $sdi,
            ]);
        }

        return $terms;
    }

    /**
     * @return array<string, Product>
     */
    private function seedCatalog(): array
    {
        $units = [];
        foreach (['PZ' => 'Pezzo', 'H' => 'Ora', 'KG' => 'Chilogrammo'] as $code => $name) {
            $units[$code] = UnitOfMeasure::create(['code' => $code, 'name' => $name]);
        }

        $categories = [];
        foreach (['Ricambi', 'Lubrificanti', 'Servizi'] as $name) {
            $categories[$name] = ProductCategory::create(['name' => $name]);
        }

        $brands = [];
        foreach (['Bosch', 'Castrol', 'Brembo'] as $name) {
            $brands[$name] = ProductBrand::create(['name' => $name]);
        }

        $warehouse = Warehouse::create(['code' => 'MAG1', 'name' => 'Magazzino principale', 'is_primary' => true]);

        $products = [];
        foreach ([
            ['RIC-001', 'Filtro olio', 'Ricambi', 'Bosch', 'PZ', 4.80, 12.00, 40],
            ['RIC-002', 'Filtro aria', 'Ricambi', 'Bosch', 'PZ', 7.50, 18.50, 25],
            ['RIC-003', 'Pastiglie freno anteriori', 'Ricambi', 'Brembo', 'PZ', 22.00, 54.00, 16],
            ['RIC-004', 'Disco freno anteriore', 'Ricambi', 'Brembo', 'PZ', 31.00, 72.00, 10],
            ['RIC-005', 'Candela di accensione', 'Ricambi', 'Bosch', 'PZ', 3.20, 9.90, 60],
            ['LUB-001', 'Olio motore 5W-30 (1 l)', 'Lubrificanti', 'Castrol', 'PZ', 6.40, 14.90, 80],
            ['LUB-002', 'Liquido freni DOT 4 (0,5 l)', 'Lubrificanti', 'Castrol', 'PZ', 3.90, 9.50, 30],
            ['SRV-001', 'Manodopera meccanica', 'Servizi', null, 'H', 0, 45.00, null],
            ['SRV-002', 'Tagliando completo', 'Servizi', null, 'PZ', 0, 89.00, null],
            ['SRV-003', 'Diagnosi elettronica', 'Servizi', null, 'PZ', 0, 35.00, null],
        ] as [$sku, $name, $category, $brand, $unit, $cost, $price, $initialStock]) {
            $product = Product::create([
                'sku' => $sku,
                'name' => $name,
                'unit_measure' => $unit,
                'unit_of_measure_id' => $units[$unit]->id,
                'category_id' => $categories[$category]->id,
                'brand_id' => $brand !== null ? $brands[$brand]->id : null,
                'cost_price' => $cost,
                'sale_price' => $price,
                'tax_rate' => 22,
                'track_inventory' => $initialStock !== null,
                'is_active' => true,
                'min_stock' => $initialStock !== null ? 5 : null,
            ]);

            if ($initialStock !== null) {
                $this->stock->record($product->id, 'in', $initialStock, null, $warehouse, $cost, 'Giacenza iniziale');
            }

            $products[$sku] = $product;
        }

        return $products;
    }

    /**
     * @param  array<string, PaymentTerm>  $terms
     * @return array{0: list<Contact>, 1: list<Contact>}
     */
    private function seedContacts(array $terms): array
    {
        $customers = [];
        foreach ([
            ['Autotrasporti Rossi S.r.l.', 'IT02345678901', 'Via Roma', '12', '20121', 'Milano', 'MI', 'BB30'],
            ['Bianchi Costruzioni S.p.A.', 'IT03456789012', 'Corso Vittorio Emanuele', '45', '10121', 'Torino', 'TO', 'BB60FM'],
            ['Verdi Consegne S.n.c.', 'IT04567890123', 'Via Garibaldi', '8', '40121', 'Bologna', 'BO', 'BB30'],
            ['Taxi Città Società Cooperativa', 'IT05678901234', 'Piazza Duomo', '3', '50122', 'Firenze', 'FI', 'RD'],
            ['Noleggi Lago S.r.l.', 'IT06789012345', 'Lungolago Mazzini', '21', '22100', 'Como', 'CO', 'BB30'],
        ] as [$company, $vat, $street, $number, $postalCode, $city, $province, $term]) {
            $customers[] = $this->contact('client', $terms[$term], $street, $number, $postalCode, $city, $province, [
                'company_name' => $company,
                'vat_number' => $vat,
                'sdi_code' => '0000000',
                'email' => 'amministrazione@'.$this->domain($company),
            ]);
        }

        foreach ([['Mario', 'Esposito', 'Via Toledo', '101', '80134', 'Napoli', 'NA'], ['Giulia', 'Ferrari', 'Via Dante', '7', '24121', 'Bergamo', 'BG']] as [$first, $last, $street, $number, $postalCode, $city, $province]) {
            $customers[] = $this->contact('client', $terms['RD'], $street, $number, $postalCode, $city, $province, [
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower("{$first}.{$last}@example.it"),
            ]);
        }

        $suppliers = [];
        foreach ([
            ['Ricambi Auto Nord S.p.A.', 'IT07890123456', 'Via dell\'Industria', '50', '25125', 'Brescia', 'BS'],
            ['Lubrificanti Italia S.r.l.', 'IT08901234567', 'Via Emilia', '230', '41121', 'Modena', 'MO'],
            ['Energia Facile S.p.A.', 'IT09012345678', 'Viale Monza', '15', '20125', 'Milano', 'MI'],
        ] as [$company, $vat, $street, $number, $postalCode, $city, $province]) {
            $suppliers[] = $this->contact('supplier', $terms['BB60FM'], $street, $number, $postalCode, $city, $province, [
                'company_name' => $company,
                'vat_number' => $vat,
                'email' => 'fatture@'.$this->domain($company),
            ]);
        }

        return [$customers, $suppliers];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(string $type, PaymentTerm $term, string $street, string $number, string $postalCode, string $city, string $province, array $attributes): Contact
    {
        $contact = Contact::create($attributes + [
            'type' => $type,
            'country' => 'IT',
            'payment_term_id' => $term->id,
            'payment_terms_days' => $term->days,
        ]);

        ContactAddress::create([
            'contact_id' => $contact->id,
            'type' => 'billing',
            'street' => $street,
            'house_number' => $number,
            'postal_code' => $postalCode,
            'city' => $city,
            'state_canton' => $province,
            'country' => 'IT',
        ]);

        return $contact;
    }

    private function domain(string $company): string
    {
        $words = preg_split('/[^a-z]+/', strtolower($company), -1, PREG_SPLIT_NO_EMPTY) ?: ['demo'];

        return implode('', array_slice($words, 0, 2)).'.example.it';
    }

    /**
     * @param  list<Contact>  $customers
     * @param  array<string, Product>  $products
     */
    private function seedQuotesAndOrders(array $customers, array $products): void
    {
        foreach ([
            [0, 40, 'accepted', ['SRV-002' => 6, 'LUB-001' => 30]],
            [1, 25, 'sent', ['RIC-003' => 8, 'RIC-004' => 8, 'SRV-001' => 12]],
            [4, 12, 'sent', ['SRV-002' => 3, 'SRV-003' => 3]],
            [2, 60, 'rejected', ['RIC-001' => 10, 'SRV-001' => 5]],
            [3, 3, 'draft', ['SRV-003' => 1]],
        ] as [$customer, $daysAgo, $status, $lines]) {
            $date = Carbon::today()->subDays($daysAgo);
            $this->salesDocuments->create(Quote::class, 'quote', 'quote_number', [
                'contact_id' => $customers[$customer]->id,
                'date' => $date->toDateString(),
                'valid_until' => $date->copy()->addDays(30)->toDateString(),
                'currency' => 'EUR',
                'status' => $status,
                'items' => $this->items($products, $lines),
            ]);
        }

        foreach ([
            [0, 35, 'completed', ['SRV-002' => 6, 'LUB-001' => 30]],
            [2, 6, 'confirmed', ['RIC-002' => 4, 'RIC-005' => 16, 'SRV-001' => 3]],
        ] as [$customer, $daysAgo, $status, $lines]) {
            $date = Carbon::today()->subDays($daysAgo);
            $this->salesDocuments->create(SalesOrder::class, 'sales_order', 'order_number', [
                'contact_id' => $customers[$customer]->id,
                'date' => $date->toDateString(),
                'due_date' => $date->copy()->addDays(14)->toDateString(),
                'currency' => 'EUR',
                'status' => $status,
                'items' => $this->items($products, $lines),
            ]);
        }
    }

    /**
     * Six months of invoices: most paid, some partially paid, some open and a couple overdue.
     *
     * @param  list<Contact>  $customers
     * @param  array<string, Product>  $products
     */
    private function seedInvoices(array $customers, array $products): void
    {
        foreach ([
            [0, 170, 'paid', ['SRV-002' => 4, 'LUB-001' => 20]],
            [1, 150, 'paid', ['RIC-003' => 4, 'RIC-004' => 4, 'SRV-001' => 6]],
            [2, 140, 'paid', ['RIC-001' => 6, 'RIC-002' => 6, 'SRV-001' => 3]],
            [5, 120, 'paid', ['SRV-002' => 1]],
            [3, 110, 'paid', ['SRV-003' => 4, 'RIC-005' => 16, 'SRV-001' => 4]],
            [4, 95, 'paid', ['SRV-002' => 3, 'LUB-002' => 3]],
            [0, 80, 'paid', ['SRV-001' => 10, 'RIC-001' => 8]],
            [1, 70, 'partial', ['RIC-003' => 6, 'RIC-004' => 6, 'SRV-001' => 9]],
            [6, 60, 'paid', ['SRV-002' => 1, 'RIC-005' => 4]],
            [2, 50, 'unpaid', ['SRV-001' => 6, 'LUB-001' => 10]],
            [4, 40, 'partial', ['SRV-002' => 5]],
            [0, 33, 'paid', ['SRV-002' => 6, 'LUB-001' => 30]],
            [3, 20, 'unpaid', ['SRV-003' => 2, 'SRV-001' => 2]],
            [1, 10, 'unpaid', ['RIC-002' => 8, 'SRV-001' => 4]],
            [5, 2, 'unpaid', ['SRV-003' => 1, 'RIC-005' => 4]],
        ] as [$customer, $daysAgo, $payment, $lines]) {
            $contact = $customers[$customer];
            $date = Carbon::today()->subDays($daysAgo);
            $due = $date->copy()->addDays($contact->payment_terms_days);
            $number = $this->numbering->next('invoice', $date->year);

            $invoice = $this->invoices->createInvoice([
                'contact_id' => $contact->id,
                'invoice_type' => 'sdi_electronic',
                'invoice_number' => $number['number'],
                'sequential_number' => $number['sequential_number'],
                'fiscal_year' => $number['fiscal_year'],
                'date' => $date->toDateString(),
                'due_date' => $due->toDateString(),
                'currency' => 'EUR',
                'jurisdiction' => 'IT',
                'payment_method' => 'MP05',
                'items' => $this->items($products, $lines),
            ]);

            $this->pay($invoice, $payment, $due);
        }
    }

    private function pay(Invoice $invoice, string $payment, Carbon $due): void
    {
        if ($payment === 'unpaid') {
            return;
        }

        $paidAt = $due->isFuture() ? Carbon::today() : $due->copy()->subDays(2);
        $amount = $payment === 'paid' ? (float) $invoice->total_gross : round((float) $invoice->total_gross / 2, 2);

        $this->payments->execute($invoice, [
            'amount' => $amount,
            'paid_at' => $paidAt->toDateString(),
            'payment_method' => 'MP05',
            'reference' => 'Bonifico '.$invoice->invoice_number,
        ]);
    }

    /**
     * @param  list<Contact>  $suppliers
     */
    private function seedPassiveInvoices(array $suppliers): void
    {
        foreach ([
            [0, 'RAN/2045', 90, 1830.00, 'paid'],
            [1, '118/A', 75, 742.50, 'paid'],
            [2, 'EF-778812', 45, 386.40, 'paid'],
            [0, 'RAN/2311', 20, 2415.00, 'unpaid'],
            [2, 'EF-781530', 8, 401.20, 'unpaid'],
        ] as [$supplier, $number, $daysAgo, $gross, $status]) {
            $date = Carbon::today()->subDays($daysAgo);
            $net = round($gross / 1.22, 2);

            PassiveInvoice::create([
                'supplier_id' => $suppliers[$supplier]->id,
                'invoice_number' => $number,
                'date' => $date->toDateString(),
                'due_date' => $date->copy()->addDays(60)->toDateString(),
                'document_type' => 'TD01',
                'currency' => 'EUR',
                'total_net' => $net,
                'total_tax' => round($gross - $net, 2),
                'total_amount' => $gross,
                'total_paid' => $status === 'paid' ? $gross : 0,
                'payment_status' => $status,
            ]);
        }
    }

    /**
     * @param  array<string, Product>  $products
     * @param  array<string, int>  $lines  SKU => quantity
     * @return list<array<string, mixed>>
     */
    private function items(array $products, array $lines): array
    {
        $items = [];
        foreach ($lines as $sku => $quantity) {
            $product = $products[$sku];
            $items[] = [
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_price' => (float) $product->sale_price,
                'tax_rate' => (float) $product->tax_rate,
            ];
        }

        return $items;
    }
}
