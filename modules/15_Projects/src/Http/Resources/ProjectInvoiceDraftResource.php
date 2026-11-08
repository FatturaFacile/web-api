<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * The draft invoice produced by project billing (schema ProjectsInvoiceDraft). Its number is a placeholder until
 * Invoicing issues it with the next number of the gapless sequence.
 *
 * @mixin SalesInvoice
 */
final class ProjectInvoiceDraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SalesInvoice $invoice */
        $invoice = $this->resource;

        return [
            'id' => $invoice->getKey(),
            'invoice_number' => $invoice->getAttribute('invoice_number'),
            'status' => $invoice->getAttribute('status'),
            'project_id' => $invoice->getAttribute('project_id'),
            'contact_id' => $invoice->getAttribute('contact_id'),
            'total_net' => (string) $invoice->getAttribute('total_net'),
            'total_tax' => (string) $invoice->getAttribute('total_tax'),
            'total_gross' => (string) $invoice->getAttribute('total_gross'),
        ];
    }
}
