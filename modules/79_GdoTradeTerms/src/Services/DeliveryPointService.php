<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoDeliveryPoint;
use Modules\GdoTradeTerms\Models\GdoInvoiceDeliveryPoint;
use Modules\GdoTradeTerms\Support\NotNull;

/**
 * Stores, Ce.Di. and head offices of a retailer, and the assignment of its invoices to them, which is what a scale or a
 * promotion limited to delivery points counts.
 */
final class DeliveryPointService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, string $tenantId, ?string $userId, ?string $id = null): GdoDeliveryPoint
    {
        $point = $id === null ? new GdoDeliveryPoint(['tenant_id' => $tenantId, 'created_by' => $userId]) : GdoDeliveryPoint::query()->findOrFail($id);
        $contactId = (string) ($data['contact_id'] ?? $point->contact_id);

        if (Contact::query()->find($contactId) === null) {
            throw new GdoTradeTermsException('contact_not_found');
        }

        $code = (string) ($data['code'] ?? $point->code);
        $taken = GdoDeliveryPoint::query()->where('contact_id', $contactId)->where('code', $code)->whereKeyNot($point->id)->exists();

        if ($taken) {
            throw new GdoTradeTermsException('delivery_point_code_taken', 409);
        }

        $point->fill([...NotNull::strip(array_intersect_key($data, array_flip(['contact_id', 'code', 'name', 'point_type', 'city', 'postal_code', 'country', 'is_active'])), ['point_type', 'country', 'is_active']), 'updated_by' => $userId])->save();

        return $point->refresh();
    }

    public function delete(GdoDeliveryPoint $point, ?string $userId): void
    {
        $point->update(['deleted_by' => $userId]);
        $point->delete();
    }

    /**
     * @param  list<array{invoice_id: string, delivery_point_id: string}>  $assignments
     * @return int the number of invoices assigned
     */
    public function assignInvoices(array $assignments, string $tenantId, ?string $userId): int
    {
        return DB::transaction(function () use ($assignments, $tenantId, $userId): int {
            $count = 0;

            foreach ($assignments as $assignment) {
                $point = GdoDeliveryPoint::query()->find($assignment['delivery_point_id']);
                $invoice = DB::table('invoices')->where('tenant_id', $tenantId)->where('id', $assignment['invoice_id'])->whereNull('deleted_at')->first(['id', 'contact_id']);

                if ($point === null || $invoice === null) {
                    throw new GdoTradeTermsException('assignment_target_not_found', 404);
                }

                $this->assertSameChain($tenantId, (string) $invoice->contact_id, $point);

                GdoInvoiceDeliveryPoint::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'invoice_id' => $assignment['invoice_id']],
                    ['delivery_point_id' => $point->id, 'updated_by' => $userId],
                );
                $count++;
            }

            return $count;
        });
    }

    /**
     * The invoice must be addressed to the retailer of the delivery point or to one of its member contacts.
     */
    private function assertSameChain(string $tenantId, string $invoiceContactId, GdoDeliveryPoint $point): void
    {
        if ($invoiceContactId === (string) $point->contact_id) {
            return;
        }

        $parent = DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $invoiceContactId)->value('parent_id');

        if ($parent === null || (string) $parent !== (string) $point->contact_id) {
            throw new GdoTradeTermsException('assignment_contact_mismatch', 422);
        }
    }
}
