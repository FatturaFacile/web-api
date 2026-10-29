<?php

declare(strict_types=1);

namespace App\Actions\FieldService;

use App\Actions\Documents\QueueDocumentEmail;
use App\Actions\Sales\ConvertToInvoice;
use App\Models\WorkOrder;
use App\Rules\PngSignature;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SignWorkOrder
{
    public function __construct(
        private readonly DocumentEventRecorder $events,
        private readonly TenantPreferences $preferences,
        private readonly ConvertToInvoice $toInvoice,
        private readonly QueueDocumentEmail $queueEmail,
    ) {}

    /**
     * Store the customer's PNG signature on the private disk and mark the work report as signed.
     * When the tenant enabled `work_orders.auto_invoice`, the work report is invoiced right away.
     */
    public function execute(WorkOrder $workOrder, string $signedBy, string $signatureBase64): WorkOrder
    {
        $signed = DB::transaction(function () use ($workOrder, $signedBy, $signatureBase64): WorkOrder {
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrder->id);

            if ($workOrder->signed_at !== null) {
                throw ValidationException::withMessages(['work_order' => __('work_orders.already_signed')]);
            }

            if (! in_array($workOrder->status, ['draft', 'completed'], true)) {
                throw ValidationException::withMessages(['work_order' => __('work_orders.not_signable')]);
            }

            $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
            $path = "tenants/{$workOrder->tenant_id}/signatures/work-orders/{$workOrder->id}.png";
            Storage::disk($disk)->put($path, (string) PngSignature::decode($signatureBase64));

            $workOrder->update([
                'client_signature_path' => $path,
                'client_signed_by' => $signedBy,
                'signed_at' => now(),
                'status' => 'signed',
            ]);

            $this->events->record($workOrder, 'signed', ['signed_by' => $signedBy]);

            return $workOrder;
        });

        if ((bool) $this->preferences->get('work_orders', 'auto_invoice', false)) {
            $this->toInvoice->fromWorkOrder($signed->load('lines'));
        }

        $signed->load('client');

        if ((bool) $this->preferences->get('work_orders', 'auto_email', false) && $signed->client?->email !== null) {
            $this->queueEmail->execute($signed);
        }

        return $signed->refresh();
    }
}
