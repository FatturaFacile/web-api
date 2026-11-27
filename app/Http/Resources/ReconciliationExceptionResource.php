<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankStatementLine;
use App\Services\Banking\ReconciliationExceptionClassifier;
use Illuminate\Http\Request;

/**
 * An open bank movement of the reconciliation review queue: the movement, why it stayed open, and the document it
 * probably belongs to.
 *
 * @mixin BankStatementLine
 */
class ReconciliationExceptionResource extends BankStatementLineResource
{
    /**
     * @param  array{id: string, invoice_number: string, kind: string, due_amount: float, currency: string}|null  $candidate
     */
    public function __construct(BankStatementLine $resource, private readonly ?array $candidate = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'exception_code' => $this->exception_code ?? ReconciliationExceptionClassifier::UNMATCHED,
            'exception_candidate' => $this->candidate,
        ];
    }
}
