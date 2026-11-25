<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Contracts\SwissQrBill\SwissQrBillGeneratorInterface;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Pdf\DocumentPdfService;
use App\Services\SwissQrBill\QrBillPdfOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Models\Dossier;
use Modules\DebtCollection\Models\EnforcementOffice;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\Policy;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;
use Throwable;
use ZipArchive;

/**
 * The Swiss debt enforcement dossier (Betreibungsbegehren, LEF): one click assembles the request form with the claim
 * (principal, 5% default interest of Art. 104 CO or the agreed rate, dunning costs), the debtor and creditor, the
 * competent enforcement office found from the debtor's postal code, the full chronology (invoices, payments, credit
 * notes, dunning notices) and a ZIP bundle with the invoices and their QR-bill payment slips.
 */
final class DossierService
{
    public function __construct(
        private readonly ProposalService $proposals,
        private readonly DossierRenderer $renderer,
        private readonly HistoryRecorder $history,
        private readonly DocumentPdfService $invoicePdf,
        private readonly SwissQrBillGeneratorInterface $qrBill,
        private readonly DefaultPolicyProvisioner $defaults,
    ) {}

    /**
     * @param  list<string>  $itemIds
     */
    public function generate(string $tenantId, string $customerId, array $itemIds, ?string $officeId, ?string $locale, Carbon $asOf, ?string $actorId): Dossier
    {
        $asOf = $asOf->copy()->startOfDay();
        $contact = Contact::query()->with('addresses')->findOrFail($customerId);
        $tenant = Tenant::query()->findOrFail($tenantId);

        if ($this->proposals->jurisdictionOf($contact, (string) $tenant->country) !== 'CH') {
            throw new DebtCollectionException('The Betreibung applies to debtors in Switzerland.', 'wrong_jurisdiction');
        }

        $items = ReceivableItem::query()->whereIn('id', $itemIds)->where('customer_id', $customerId)->where('open_amount', '>', 0)->where('due_date', '<', $asOf->toDateString())->orderBy('due_date')->get();

        if ($items->isEmpty() || $items->count() !== count(array_unique($itemIds))) {
            throw new DebtCollectionException('Select overdue open items of the customer.', 'item_not_overdue');
        }

        if ($items->pluck('currency')->unique()->count() !== 1) {
            throw new DebtCollectionException('The items must share one currency.', 'mixed_currency');
        }

        $currency = (string) $items->first()->currency;
        $this->defaults->ensure();
        $policy = Policy::query()->where('is_active', true)->where('jurisdiction', 'CH')->orderByDesc('is_default')->first()
            ?? throw new DebtCollectionException('No active Swiss dunning policy.', 'no_policy');

        $claims = [];
        $principal = '0';
        $interest = '0';

        foreach ($items as $item) {
            $priced = $this->proposals->priceInterest($tenantId, $item, $policy, 'CH', $asOf, '0');
            $claims[] = [
                'receivable_item_id' => (string) $item->id,
                'invoice_id' => $item->invoice_id,
                'document_number' => (string) $item->document_number,
                'issue_date' => $item->issue_date->toDateString(),
                'due_date' => $item->due_date->toDateString(),
                'principal' => Decimal::round2((string) $item->open_amount),
                'original_amount' => Decimal::round2((string) $item->original_amount),
                'interest' => $priced['interest'],
                'interest_days' => $priced['days'],
                'rate_percent' => $priced['rate_percent'],
                'segments' => $priced['calculation']['segments'],
                'settlements' => $this->settlements($item),
            ];
            $principal = Decimal::add($principal, (string) $item->open_amount);
            $interest = Decimal::add($interest, $priced['interest']);
        }

        $notices = Notice::query()->with('lines')->where('customer_id', $customerId)->where('status', '!=', 'cancelled')->orderBy('issued_on')->get()
            ->filter(static fn (Notice $notice): bool => $notice->lines->pluck('receivable_item_id')->intersect($itemIds)->isNotEmpty())
            ->values();
        $fee = $notices->whereIn('status', ['sent', 'delivered'])->reduce(static fn (string $carry, Notice $notice): string => Decimal::add($carry, (string) $notice->fee), '0');

        $office = $this->office($officeId, $contact);
        $locale = in_array($locale, TemplateService::Locales, true) ? (string) $locale : 'de';

        $snapshot = [
            'as_of' => $asOf->toDateString(),
            'creditor' => $this->creditor($tenant),
            'debtor' => $this->debtor($contact),
            'claims' => $claims,
            'notices' => $notices->map(static fn (Notice $notice): array => [
                'number' => (string) $notice->number,
                'level' => (int) $notice->level,
                'issued_on' => $notice->issued_on->toDateString(),
                'channel' => (string) $notice->channel,
                'status' => (string) $notice->status,
                'total' => (string) $notice->total,
                'fee' => (string) $notice->fee,
            ])->all(),
            'interest_basis' => (string) $policy->interest_basis,
        ];

        return DB::transaction(function () use ($tenantId, $contact, $currency, $principal, $interest, $fee, $office, $itemIds, $snapshot, $locale, $asOf, $actorId, $items): Dossier {
            $dossier = Dossier::create([
                'number' => $this->nextNumber($asOf),
                'customer_id' => $contact->id,
                'currency' => $currency,
                'principal' => Decimal::round2($principal),
                'interest' => Decimal::round2($interest),
                'fee' => Decimal::round2($fee),
                'total' => Decimal::round2(Decimal::add(Decimal::add($principal, $interest), $fee)),
                'status' => 'generated',
                'office_id' => $office?->id,
                'office_snapshot' => $office === null ? null : $office->only(['name', 'address', 'postal_code', 'city', 'canton', 'email']),
                'receivable_item_ids' => $itemIds,
                'snapshot' => $snapshot,
                'locale' => $locale,
                'generated_at' => now(),
                'created_by' => $actorId,
            ]);

            $base = "debt-collection/{$tenantId}/dossiers/{$dossier->number}";
            $pdf = $this->renderer->pdf($dossier);
            Storage::disk('local')->put($base.'.pdf', $pdf);
            Storage::disk('local')->put($base.'.zip', $this->bundle($dossier, $pdf, $items->all()));
            $dossier->update(['pdf_path' => $base.'.pdf', 'bundle_path' => $base.'.zip']);

            foreach ($items as $item) {
                DB::table('dc_cases')->where('receivable_item_id', $item->id)->update(['status' => 'enforcement', 'current_level' => 3]);
            }

            $this->history->record((string) $contact->id, 'dossier_generated', 'dossier', (string) $dossier->id, 3, (string) $dossier->total, $currency, ['number' => $dossier->number, 'items' => count($itemIds)], $actorId);

            return $dossier->refresh();
        });
    }

    public function submit(Dossier $dossier, ?string $actorId): Dossier
    {
        if ($dossier->status !== 'generated') {
            throw new DebtCollectionException('The dossier was already submitted.', 'dossier_not_submittable');
        }

        $dossier->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->history->record((string) $dossier->customer_id, 'dossier_submitted', 'dossier', (string) $dossier->id, 3, (string) $dossier->total, (string) $dossier->currency, ['number' => $dossier->number], $actorId);

        return $dossier->refresh();
    }

    /**
     * ZIP with the request form, a manifest, the notices sent and, per invoice, the invoice PDF and its QR-bill slip.
     *
     * @param  list<ReceivableItem>  $items
     */
    private function bundle(Dossier $dossier, string $requestPdf, array $items): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dcz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('01-betreibungsbegehren-'.$dossier->number.'.pdf', $requestPdf);

        $manifest = ['number' => $dossier->number, 'attachments' => []];
        $language = in_array($dossier->locale, QrBillPdfOptions::LANGUAGES, true) ? (string) $dossier->locale : 'de';

        foreach ($items as $item) {
            $invoice = $item->invoice_id === null ? null : Invoice::query()->find($item->invoice_id);

            if ($invoice === null) {
                $manifest['attachments'][] = ['document' => $item->document_number, 'invoice' => 'missing', 'qr_bill' => 'missing'];

                continue;
            }

            $entry = ['document' => $item->document_number, 'invoice' => 'included', 'qr_bill' => 'included'];
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $invoice->invoice_number);

            try {
                $zip->addFromString("invoices/{$name}.pdf", $this->invoicePdf->render($invoice)->content);
            } catch (Throwable $exception) {
                $entry['invoice'] = 'error: '.mb_substr($exception->getMessage(), 0, 120);
            }

            try {
                $zip->addFromString("qr-bills/{$name}-qr-bill.pdf", $this->qrBill->generatePdf($invoice, new QrBillPdfOptions(QrBillPdfOptions::MODE_STANDALONE, QrBillPdfOptions::PERFORATION_NONE, $language)));
            } catch (Throwable $exception) {
                $entry['qr_bill'] = 'error: '.mb_substr($exception->getMessage(), 0, 120);
            }

            $manifest['attachments'][] = $entry;
        }

        foreach (Notice::query()->whereIn('number', array_column((array) ($dossier->snapshot['notices'] ?? []), 'number'))->get() as $notice) {
            $zip->addFromString('notices/'.$notice->number.'.pdf', app(NoticeRenderer::class)->pdf($notice));
        }

        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * @return list<array{type: string, reference: string|null, amount: string, value_date: string}>
     */
    private function settlements(ReceivableItem $item): array
    {
        return $item->allocations()->whereNull('reversed_at')->orderBy('value_date')->get()->map(static fn ($allocation): array => [
            'type' => (string) $allocation->source_type,
            'reference' => $allocation->source_reference,
            'amount' => Decimal::round2((string) $allocation->amount),
            'value_date' => $allocation->value_date->toDateString(),
        ])->all();
    }

    private function office(?string $officeId, Contact $contact): ?EnforcementOffice
    {
        if ($officeId !== null) {
            return EnforcementOffice::query()->findOrFail($officeId);
        }

        $zip = (string) ($contact->addresses->sortByDesc('is_default')->first()->postal_code ?? '');

        if ($zip === '') {
            return null;
        }

        return EnforcementOffice::query()
            ->whereNotNull('postal_code_from')
            ->where('postal_code_from', '<=', $zip)
            ->where(fn ($query) => $query->where('postal_code_to', '>=', $zip)->orWhereNull('postal_code_to'))
            ->orderByDesc('postal_code_from')
            ->first();
    }

    /**
     * @return array<string, string|null>
     */
    private function creditor(Tenant $tenant): array
    {
        $company = (array) ($tenant->settings['preferences']['company'] ?? []);

        return [
            'name' => (string) ($tenant->legal_name ?: $tenant->name),
            'uid' => $tenant->uid_number,
            'street' => trim((string) ($company['street'] ?? $company['address_line_1'] ?? '').' '.(string) ($company['house_number'] ?? '')),
            'postal_code' => (string) ($company['postal_code'] ?? ''),
            'city' => (string) ($company['city'] ?? ($company['address_line_2'] ?? '')),
            'iban' => $tenant->qr_iban ?: $tenant->iban,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function debtor(Contact $contact): array
    {
        $address = $contact->addresses->sortByDesc('is_default')->first();

        return [
            'name' => $this->proposals->nameOf($contact),
            'uid' => $contact->uid_number,
            'street' => trim((string) ($address->street ?? '').' '.(string) ($address->house_number ?? '')),
            'postal_code' => (string) ($address->postal_code ?? ''),
            'city' => (string) ($address->city ?? ''),
            'canton' => $address->state_canton ?? null,
            'country' => (string) $contact->country,
        ];
    }

    private function nextNumber(Carbon $asOf): string
    {
        $prefix = 'BB-'.$asOf->format('Y').'-';
        $last = Dossier::withTrashed()->where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ($last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1), 5, '0', STR_PAD_LEFT);
    }
}
