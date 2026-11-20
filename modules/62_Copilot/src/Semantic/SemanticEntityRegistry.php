<?php

declare(strict_types=1);

namespace Modules\Copilot\Semantic;

use App\Models\Contact;
use App\Models\Invoice;
use Modules\HumanResources\Models\Employee;
use Modules\Invoicing\Models\SalesQuote;

/**
 * The closed list of entities and fields the Copilot can query. A new entity or field is a reviewed change here; nothing a
 * person or a model types ever becomes part of a query except values bound as parameters.
 */
final class SemanticEntityRegistry
{
    /**
     * @var array<string, SemanticEntity>|null
     */
    private ?array $entities = null;

    /**
     * @return array<string, SemanticEntity>
     */
    public function all(): array
    {
        return $this->entities ??= [
            'invoices' => new SemanticEntity('invoices', Invoice::class, 'invoicing', 'Customer invoices', 'invoice_number', 'Invoice', '/invoices/{id}', [
                'invoice_number' => new SemanticField(SemanticField::String),
                'invoice_type' => new SemanticField(SemanticField::Enum),
                'date' => new SemanticField(SemanticField::Date),
                'due_date' => new SemanticField(SemanticField::Date),
                'currency' => new SemanticField(SemanticField::Enum),
                'total_net' => new SemanticField(SemanticField::Number),
                'total_tax' => new SemanticField(SemanticField::Number),
                'total_gross' => new SemanticField(SemanticField::Number),
                'total_paid' => new SemanticField(SemanticField::Number),
                'payment_status' => new SemanticField(SemanticField::Enum),
                'contact_id' => new SemanticField(SemanticField::Uuid),
            ]),
            'quotes' => new SemanticEntity('quotes', SalesQuote::class, 'invoicing', 'Sales quotes', 'quote_number', 'Quote', '/quotes/{id}', [
                'quote_number' => new SemanticField(SemanticField::String),
                'date' => new SemanticField(SemanticField::Date),
                'valid_until' => new SemanticField(SemanticField::Date),
                'currency' => new SemanticField(SemanticField::Enum),
                'total_net' => new SemanticField(SemanticField::Number),
                'total_tax' => new SemanticField(SemanticField::Number),
                'total_gross' => new SemanticField(SemanticField::Number),
                'status' => new SemanticField(SemanticField::Enum),
                'contact_id' => new SemanticField(SemanticField::Uuid),
            ]),
            'contacts' => new SemanticEntity('contacts', Contact::class, 'crm', 'Customers and suppliers', 'company_name', 'Contact', '/contacts/{id}', [
                'company_name' => new SemanticField(SemanticField::String),
                'type' => new SemanticField(SemanticField::Enum),
                'country' => new SemanticField(SemanticField::Enum),
            ]),
            'employees' => new SemanticEntity('employees', Employee::class, 'hr', 'Employees', 'employee_number', 'Employee', '/hr/employees/{id}', [
                'employee_number' => new SemanticField(SemanticField::String),
                'full_name' => new SemanticField(SemanticField::String),
                'employment_status' => new SemanticField(SemanticField::Enum),
                'employment_type' => new SemanticField(SemanticField::Enum),
                'hire_date' => new SemanticField(SemanticField::Date),
                'department_id' => new SemanticField(SemanticField::Uuid),
                'base_annual_salary' => new SemanticField(SemanticField::Number, sensitiveModule: 'payroll'),
                'hourly_cost_rate' => new SemanticField(SemanticField::Number, sensitiveModule: 'payroll'),
            ]),
        ];
    }

    public function find(string $key): ?SemanticEntity
    {
        return $this->all()[$key] ?? null;
    }
}
