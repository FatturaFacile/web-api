<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Workflow\Contracts\ApprovableDocument;

/**
 * The list of document classes that may be approved, keyed by subject type. Consumer modules are listed in
 * `config/workflow.php` (`approvables`) and may add themselves at boot with {@see register()}; the designer, the matrix
 * editor and the start endpoint read the same list, so a subject type exists in exactly one place.
 */
final class ApprovableRegistry
{
    /**
     * Subject types the designer offers even when no consumer registered yet (the module may be switched off).
     *
     * @var list<string>
     */
    public const array KnownSubjectTypes = [
        'sales_quote', 'purchase_request', 'purchase_order', 'supplier_invoice', 'expense_report',
        'leave_request', 'payment_batch', 'supplier_master', 'dms_document',
    ];

    /**
     * Legacy configuration aliases mapped to the canonical subject type.
     *
     * @var array<string, string>
     */
    private const array Aliases = [
        'purchase_requisition' => 'purchase_request',
        'passive_invoice' => 'supplier_invoice',
    ];

    /**
     * @var array<string, array{class: class-string<Model>, module: ?string}>
     */
    private array $subjects = [];

    /**
     * Loads the configured approvables.
     */
    public function __construct()
    {
        foreach ((array) config('workflow.approvables', []) as $type => $definition) {
            if (is_array($definition) && isset($definition['class'])) {
                $this->register((string) $type, $definition['class'], $definition['module'] ?? null);
            }
        }
    }

    /**
     * Registers (or replaces) a subject type.
     *
     * @param  class-string<Model>  $class
     */
    public function register(string $subjectType, string $class, ?string $module = null): void
    {
        $this->subjects[self::canonical($subjectType)] = ['class' => $class, 'module' => $module];
    }

    /**
     * The canonical code of a subject type or legacy alias.
     */
    public static function canonical(string $subjectType): string
    {
        return self::Aliases[$subjectType] ?? $subjectType;
    }

    /**
     * Every subject type with its class and module (null when only known by name).
     *
     * @return list<array{code: string, class: ?string, module: ?string, registered: bool}>
     */
    public function subjectTypes(): array
    {
        $codes = array_values(array_unique([...self::KnownSubjectTypes, ...array_keys($this->subjects)]));

        return array_map(fn (string $code): array => [
            'code' => $code,
            'class' => $this->subjects[$code]['class'] ?? null,
            'module' => $this->subjects[$code]['module'] ?? null,
            'registered' => isset($this->subjects[$code]),
        ], $codes);
    }

    /**
     * Whether the code or the class is a known subject.
     */
    public function has(string $subjectTypeOrClass): bool
    {
        return $this->resolve($subjectTypeOrClass) !== null;
    }

    /**
     * The subject type of an alias, a code or a model class; null when unknown.
     */
    public function resolve(string $subjectTypeOrClass): ?string
    {
        $canonical = self::canonical($subjectTypeOrClass);

        if (isset($this->subjects[$canonical])) {
            return $canonical;
        }

        foreach ($this->subjects as $code => $definition) {
            if ($definition['class'] === $subjectTypeOrClass) {
                return $code;
            }
        }

        return in_array($canonical, self::KnownSubjectTypes, true) ? $canonical : null;
    }

    /**
     * The model class of a subject type.
     *
     * @return class-string<Model>
     *
     * @throws InvalidArgumentException when the type is not registered
     */
    public function classFor(string $subjectType): string
    {
        $code = $this->resolve($subjectType);

        if ($code === null || ! isset($this->subjects[$code])) {
            throw new InvalidArgumentException("Subject type [{$subjectType}] is not registered as approvable.");
        }

        return $this->subjects[$code]['class'];
    }

    /**
     * The module key (permission area) of a subject type.
     */
    public function moduleOf(string $subjectTypeOrClass): ?string
    {
        $code = $this->resolve($subjectTypeOrClass);

        return $code === null ? null : ($this->subjects[$code]['module'] ?? null);
    }

    /**
     * The attributes the engine evaluates for a document: the contract of {@see ApprovableDocument}, or the usual amount
     * columns for documents that have not adopted the contract yet.
     *
     * @return array<string, scalar|null>
     */
    public function attributesOf(Model $document): array
    {
        if ($document instanceof ApprovableDocument) {
            return $document->approvalAttributes();
        }

        return array_filter([
            'amount' => $document->getAttribute('total_amount') ?? $document->getAttribute('amount') ?? $document->getAttribute('total_estimated_amount'),
            'currency' => $document->getAttribute('currency'),
            'cost_center_id' => $document->getAttribute('cost_center_id'),
            'company_id' => $document->getAttribute('company_id'),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * A short, non-sensitive label of a document for notifications and confirmation pages: its number or name, never its
     * content (no HR details in an email, link only).
     */
    public function labelOf(?Model $document): string
    {
        if ($document === null) {
            return '-';
        }

        foreach (['number', 'document_number', 'order_number', 'report_number', 'requisition_number', 'request_number', 'quote_number', 'name', 'title'] as $column) {
            $value = $document->getAttribute($column);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return (string) $document->getKey();
    }

    /**
     * The requester of a document, when the contract (or a `created_by` column) tells it.
     */
    public function requesterOf(Model $document): ?string
    {
        $requester = $document instanceof ApprovableDocument ? $document->requesterId() : $document->getAttribute('created_by');

        return $requester === null || $requester === '' ? null : (string) $requester;
    }
}
