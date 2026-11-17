<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use App\Contracts\PdfGenerator;
use App\Models\Contact;
use Illuminate\Support\Facades\Mail;
use Modules\ServiceManagement\Exceptions\HourBankException;
use Modules\ServiceManagement\Mail\ResidualHoursReportMail;
use Modules\ServiceManagement\Models\ServiceContract;

/**
 * Builds the residual-hours statement of a maintenance contract (PDF + localized e-mail summary) and sends it.
 */
final class ResidualHoursReportService
{
    public function __construct(private readonly PdfGenerator $pdf) {}

    public function send(ServiceContract $contract): void
    {
        $customer = Contact::query()->find($contract->customer_id);
        $recipient = $contract->report_email ?: $customer?->email;
        if ($recipient === null || $recipient === '') {
            throw HourBankException::noRecipient();
        }

        $locale = $this->localeOf($customer);
        $data = $this->viewData($contract, $customer, $locale);

        $pdf = $this->pdf->render('documents.residual-hours-statement', $data);

        Mail::to($recipient)->send(new ResidualHoursReportMail(
            subjectLine: __('esm_residual_report.subject', ['contract' => $contract->contract_number], $locale),
            reportData: $data,
            pdfContent: $pdf,
            filename: 'residual-hours-'.$contract->contract_number.'.pdf',
        ));

        $contract->forceFill(['last_residual_report_at' => now()])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(ServiceContract $contract, ?Contact $customer, string $locale): array
    {
        return [
            'contract' => $contract,
            'customerName' => $customer !== null ? ContactNameResolver::nameOf($customer) : '',
            'remaining' => $contract->remainingHours(),
            'lowBalance' => $contract->isLowBalance(),
            'transactions' => $contract->hourTransactions()->with('asset')->get(),
            'locale' => $locale,
        ];
    }

    private function localeOf(?Contact $customer): string
    {
        $locale = (string) ($customer?->getAttribute('locale') ?? '');

        return in_array($locale, ['it', 'en', 'de', 'fr'], true) && is_dir(lang_path($locale)) ? $locale : (string) config('app.locale', 'en');
    }
}
