<?php

declare(strict_types=1);

namespace Modules\DataExport\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * The read-only datasets of the reporting feed and of the CSV exports: only these columns ever leave the application.
 */
final class ReportingDatasets
{
    /**
     * @return list<string>
     */
    public function columns(string $dataset): array
    {
        return match ($dataset) {
            'invoices' => ['id', 'invoice_number', 'invoice_type', 'date', 'due_date', 'contact_id', 'currency', 'total_net', 'total_tax', 'total_gross', 'total_paid', 'payment_status'],
            'purchases' => ['id', 'invoice_number', 'document_type', 'date', 'due_date', 'supplier_id', 'currency', 'total_net', 'total_tax', 'total_amount', 'total_paid', 'payment_status'],
            'contacts' => ['id', 'type', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'vat_number', 'fiscal_code', 'country'],
        };
    }

    /**
     * @param  array{from?: ?string, to?: ?string}  $period
     */
    public function query(string $dataset, array $period = []): Builder
    {
        $query = match ($dataset) {
            'invoices' => Invoice::query(),
            'purchases' => PassiveInvoice::query(),
            'contacts' => Contact::query(),
        };

        return $query
            ->when($dataset !== 'contacts' && ($period['from'] ?? null), static fn (Builder $builder) => $builder->whereDate('date', '>=', $period['from']))
            ->when($dataset !== 'contacts' && ($period['to'] ?? null), static fn (Builder $builder) => $builder->whereDate('date', '<=', $period['to']))
            ->orderBy('id')
            ->select($this->columns($dataset));
    }

    /**
     * @return array<string, mixed>
     */
    public function row(string $dataset, object $record): array
    {
        $row = [];

        foreach ($this->columns($dataset) as $column) {
            $value = $record->getAttribute($column);
            $row[$column] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $row;
    }
}
