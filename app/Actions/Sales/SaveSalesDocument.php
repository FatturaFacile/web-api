<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SaveSalesDocument
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totals,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * Create a quote or sales order with auto-numbering, line items and totals.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     */
    public function create(string $modelClass, string $sequenceType, string $numberColumn, array $data): Model
    {
        return DB::transaction(function () use ($modelClass, $sequenceType, $numberColumn, $data): Model {
            $computed = $this->totals->calculate($data['items']);
            unset($data['items']);

            $number = $this->numbering->next($sequenceType, (int) date('Y', strtotime((string) $data['date'])));

            /** @var Model $document */
            $document = new $modelClass;
            $document->fill($data);
            $document->{$numberColumn} = $data[$numberColumn] ?? $number['number'];
            $document->total_net = $computed['total_net'];
            $document->total_tax = $computed['total_tax'];
            $document->total_gross = $computed['total_gross'];
            $document->save();

            $document->items()->createMany($computed['items']);
            $this->events->record($document, 'created');

            return $document->load(['items', 'contact']);
        });
    }

    /**
     * Update header fields and, when provided, replace line items and recompute totals.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Model $document, array $data): Model
    {
        return DB::transaction(function () use ($document, $data): Model {
            if (isset($data['items'])) {
                $computed = $this->totals->calculate($data['items']);
                $document->items()->delete();
                $document->items()->createMany($computed['items']);
                $data['total_net'] = $computed['total_net'];
                $data['total_tax'] = $computed['total_tax'];
                $data['total_gross'] = $computed['total_gross'];
                unset($data['items']);
            }

            $document->update($data);
            $this->events->record($document, 'updated');

            return $document->load(['items', 'contact']);
        });
    }
}
