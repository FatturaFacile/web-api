<?php

declare(strict_types=1);

namespace Modules\Workshop\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Workshop\Contracts\WorkshopStockGateway;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;
use Modules\Workshop\Models\WorkshopPartConsumption;

/**
 * Parts a folder takes from stock, through the stock gateway only: a part is reserved when it is set aside, issued when
 * it is fitted (the reservation is released and the stock movement written) or released when it is not used. Folders that
 * are cancelled give back what they reserved. Untracked products are refused: they never move stock.
 */
final class PartConsumptionService
{
    public function __construct(private readonly WorkshopStockGateway $stock) {}

    /**
     * Consumptions of a folder, oldest first.
     *
     * @return Collection<int, WorkshopPartConsumption>
     */
    public function forFolder(WorkshopFolder $folder): Collection
    {
        return WorkshopPartConsumption::query()->where('folder_id', $folder->id)->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * Quantity the product can still be promised, for the part picker of the folder.
     *
     * @return array{product_id: string, tracked: bool, available: string}
     */
    public function availability(string $productId): array
    {
        $tracked = $this->stock->isTracked($productId);

        return ['product_id' => $productId, 'tracked' => $tracked, 'available' => $tracked ? $this->stock->availableQuantity($productId) : '0.0000'];
    }

    /**
     * Sets a quantity of a part aside for a folder that is open or in progress.
     *
     * @param  array{product_id: string, quantity: string, folder_line_id?: ?string}  $data
     */
    public function reserve(WorkshopFolder $folder, array $data): WorkshopPartConsumption
    {
        return DB::transaction(function () use ($folder, $data): WorkshopPartConsumption {
            $locked = WorkshopFolder::query()->lockForUpdate()->findOrFail($folder->id);

            if (! in_array($locked->status, ['open', 'in_progress'], true)) {
                throw new BusinessRuleException('Parts are reserved only on a folder that is open or in progress.');
            }

            if (isset($data['folder_line_id']) && ! WorkshopFolderLine::query()->whereKey($data['folder_line_id'])->where('folder_id', $locked->id)->exists()) {
                throw new BusinessRuleException('The line belongs to another folder.');
            }

            if (! $this->stock->isTracked($data['product_id'])) {
                throw new BusinessRuleException('The product is not stock-tracked.');
            }

            $consumption = new WorkshopPartConsumption;
            $consumption->fill([
                'folder_id' => $locked->id,
                'folder_line_id' => $data['folder_line_id'] ?? null,
                'product_id' => $data['product_id'],
                'quantity' => Decimal::normalize($data['quantity']),
                'status' => 'reserved',
            ]);
            $consumption->save();

            $consumption->update(['stock_reservation_id' => $this->stock->reserve($data['product_id'], (string) $consumption->quantity, $consumption->id)]);

            return $consumption;
        });
    }

    /**
     * Issues a reserved part out of stock: the part is fitted on the vehicle.
     */
    public function issue(WorkshopPartConsumption $consumption): WorkshopPartConsumption
    {
        return DB::transaction(function () use ($consumption): WorkshopPartConsumption {
            $locked = $this->lock($consumption);

            if ($locked->status !== 'reserved') {
                throw new BusinessRuleException("A {$locked->status} part cannot be issued.");
            }

            $folder = WorkshopFolder::query()->lockForUpdate()->findOrFail($locked->folder_id);

            if (! in_array($folder->status, ['open', 'in_progress', 'completed'], true)) {
                throw new BusinessRuleException("Parts cannot be issued on a {$folder->status} folder.");
            }

            $movement = $this->stock->issue($locked->product_id, (string) $locked->quantity, $folder->id, $locked->stock_reservation_id);
            $locked->update(['status' => 'issued', 'stock_movement_id' => $movement, 'issued_at' => now()]);

            return $locked;
        });
    }

    /**
     * Gives a reserved part back to stock.
     */
    public function release(WorkshopPartConsumption $consumption): WorkshopPartConsumption
    {
        return DB::transaction(function () use ($consumption): WorkshopPartConsumption {
            $locked = $this->lock($consumption);

            if ($locked->status !== 'reserved') {
                throw new BusinessRuleException("A {$locked->status} part cannot be released.");
            }

            $this->releaseReservation($locked);

            return $locked;
        });
    }

    /**
     * Releases every reservation of a folder (called when the folder is cancelled); issued parts stay issued. Runs in the
     * transaction of the caller.
     */
    public function releaseAllFor(WorkshopFolder $folder): void
    {
        WorkshopPartConsumption::query()->where('folder_id', $folder->id)->where('status', 'reserved')->lockForUpdate()->get()
            ->each(fn (WorkshopPartConsumption $consumption) => $this->releaseReservation($consumption));
    }

    /**
     * Quantity of each product already issued for the folder, keyed by product id: the delivery note of the folder leaves
     * these out so the stock is not moved twice.
     *
     * @return array<string, string>
     */
    public function issuedQuantities(WorkshopFolder $folder): array
    {
        $issued = [];

        foreach (WorkshopPartConsumption::query()->where('folder_id', $folder->id)->where('status', 'issued')->get() as $consumption) {
            $issued[$consumption->product_id] = Decimal::add($issued[$consumption->product_id] ?? '0', (string) $consumption->quantity);
        }

        return $issued;
    }

    private function releaseReservation(WorkshopPartConsumption $consumption): void
    {
        if ($consumption->stock_reservation_id !== null) {
            $this->stock->release($consumption->stock_reservation_id);
        }

        $consumption->update(['status' => 'released', 'released_at' => now()]);
    }

    private function lock(WorkshopPartConsumption $consumption): WorkshopPartConsumption
    {
        return WorkshopPartConsumption::query()->lockForUpdate()->findOrFail($consumption->id);
    }
}
