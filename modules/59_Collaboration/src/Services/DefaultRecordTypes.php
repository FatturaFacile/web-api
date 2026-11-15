<?php

declare(strict_types=1);

namespace Modules\Collaboration\Services;

use App\Models\Contact;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Models\Employee;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Procurement\Models\Supplier;
use Modules\Projects\Models\Project;

/**
 * The key entities that are searchable, commentable and taggable out of the box.
 */
final class DefaultRecordTypes
{
    public static function registerInto(CollaborationRegistry $registry): void
    {
        $registry->register(new RecordType(
            key: 'contact',
            modelClass: Contact::class,
            module: 'crm',
            labelKey: 'collaboration.types.contact',
            searchColumns: ['company_name', 'first_name', 'last_name', 'email', 'vat_number', 'fiscal_code'],
            title: static fn (Model $record): string => self::joined([$record->getAttribute('company_name'), $record->getAttribute('first_name'), $record->getAttribute('last_name')]),
            subtitle: static fn (Model $record): ?string => self::joined([$record->getAttribute('email'), $record->getAttribute('vat_number')], ' - '),
            path: '/crm/clients',
        ));
        $registry->register(new RecordType(
            key: 'invoice',
            modelClass: SalesInvoice::class,
            module: 'invoicing',
            labelKey: 'collaboration.types.invoice',
            searchColumns: ['invoice_number'],
            title: static fn (Model $record): string => (string) $record->getAttribute('invoice_number'),
            subtitle: static fn (Model $record): ?string => self::joined([self::day($record->getAttribute('date')), $record->getAttribute('currency'), $record->getAttribute('total_gross')], ' '),
            path: '/sales/invoices',
        ));
        $registry->register(new RecordType(
            key: 'quote',
            modelClass: SalesQuote::class,
            module: 'invoicing',
            labelKey: 'collaboration.types.quote',
            searchColumns: ['quote_number'],
            title: static fn (Model $record): string => (string) $record->getAttribute('quote_number'),
            subtitle: static fn (Model $record): ?string => self::joined([self::day($record->getAttribute('date')), $record->getAttribute('status')], ' - '),
            path: '/sales/quotes',
        ));
        $registry->register(new RecordType(
            key: 'product',
            modelClass: Product::class,
            module: 'inventory',
            labelKey: 'collaboration.types.product',
            searchColumns: ['sku', 'name', 'barcode'],
            title: static fn (Model $record): string => (string) $record->getAttribute('name'),
            subtitle: static fn (Model $record): ?string => (string) $record->getAttribute('sku'),
            path: '/inventory/items',
        ));
        $registry->register(new RecordType(
            key: 'project',
            modelClass: Project::class,
            module: 'projects',
            labelKey: 'collaboration.types.project',
            searchColumns: ['project_number', 'name'],
            title: static fn (Model $record): string => (string) $record->getAttribute('name'),
            subtitle: static fn (Model $record): ?string => (string) $record->getAttribute('project_number'),
            path: '/projects',
        ));
        $registry->register(new RecordType(
            key: 'supplier',
            modelClass: Supplier::class,
            module: 'procurement',
            labelKey: 'collaboration.types.supplier',
            searchColumns: ['company_name', 'supplier_code', 'vat_number'],
            title: static fn (Model $record): string => (string) $record->getAttribute('company_name'),
            subtitle: static fn (Model $record): ?string => (string) $record->getAttribute('supplier_code'),
            path: '/procurement/suppliers',
        ));
        $registry->register(new RecordType(
            key: 'employee',
            modelClass: Employee::class,
            module: 'hr',
            labelKey: 'collaboration.types.employee',
            searchColumns: ['full_name', 'employee_number', 'work_email'],
            title: static fn (Model $record): string => (string) $record->getAttribute('full_name'),
            subtitle: static fn (Model $record): ?string => (string) $record->getAttribute('employee_number'),
            path: '/hr/employees/{id}',
        ));
    }

    private static function day(mixed $value): ?string
    {
        return $value instanceof Carbon ? $value->toDateString() : null;
    }

    /**
     * @param  list<mixed>  $parts
     */
    private static function joined(array $parts, string $separator = ' '): string
    {
        $texts = array_map(static fn (mixed $part): string => trim((string) $part), $parts);

        return implode($separator, array_filter($texts, static fn (string $part): bool => $part !== ''));
    }
}
