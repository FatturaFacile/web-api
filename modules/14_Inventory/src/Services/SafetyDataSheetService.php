<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Contact;
use App\Models\DeliveryNote;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Inventory\Mail\SafetyDataSheetsMail;
use Modules\Inventory\Models\SafetyDataSheet;
use Modules\Inventory\Models\SafetyDataSheetDelivery;
use Modules\Inventory\Models\SafetyDataSheetVersion;
use Throwable;

/**
 * Keeps the revisions of safety data sheets and sends the current ones with a delivery. A delivery never waits on the
 * mail: a customer without an address or a failed send is logged on the delivery, not raised, and a later send picks
 * up what did not go out. A version already sent for a delivery is not sent again.
 */
final class SafetyDataSheetService
{
    /**
     * @param  array{hazard_classes: list<string>, revision_date: string, signal_word?: ?string, notes?: ?string}  $data
     */
    public function addVersion(SafetyDataSheet $sheet, UploadedFile $file, array $data, string $userId): SafetyDataSheetVersion
    {
        $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
        $path = $file->store("safety-data-sheets/{$sheet->tenant_id}/{$sheet->id}", $disk);

        return DB::transaction(function () use ($sheet, $file, $data, $userId, $disk, $path): SafetyDataSheetVersion {
            $locked = SafetyDataSheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            $number = (int) SafetyDataSheetVersion::query()->where('safety_data_sheet_id', $locked->id)->max('version_number') + 1;

            $version = SafetyDataSheetVersion::create([
                'tenant_id' => $locked->tenant_id,
                'safety_data_sheet_id' => $locked->id,
                'version_number' => $number,
                'revision_date' => $data['revision_date'],
                'hazard_classes' => $data['hazard_classes'],
                'signal_word' => $data['signal_word'] ?? null,
                'notes' => $data['notes'] ?? null,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'created_by' => $userId,
            ]);

            $locked->update(['current_version_id' => $version->id]);

            return $version;
        });
    }

    /**
     * Sends the current sheets of the products on a delivery note that have not gone out with it yet.
     *
     * @return array{sent: int, status: string}
     */
    public function sendForDelivery(string $deliveryNoteId): array
    {
        $note = DeliveryNote::query()->with('items')->findOrFail($deliveryNoteId);
        $contact = Contact::query()->find($note->contact_id);
        $versions = $this->versionsToSend($note, $contact);

        if ($versions === []) {
            return ['sent' => 0, 'status' => 'nothing_to_send'];
        }

        $email = $contact?->email;

        if (empty($email)) {
            $this->log($note, $versions, null, 'skipped', 'The customer has no email address.');

            return ['sent' => 0, 'status' => 'skipped'];
        }

        try {
            Mail::to($email)->send(new SafetyDataSheetsMail((string) $note->ddt_number, $versions));
        } catch (Throwable $e) {
            Log::warning('Safety data sheets not sent.', ['delivery_note_id' => $note->id, 'reason' => $e->getMessage()]);
            $this->log($note, $versions, $email, 'failed', $e->getMessage());

            return ['sent' => 0, 'status' => 'failed'];
        }

        $this->log($note, $versions, $email, 'sent');

        return ['sent' => count($versions), 'status' => 'sent'];
    }

    /**
     * @return list<SafetyDataSheetVersion>
     */
    private function versionsToSend(DeliveryNote $note, ?Contact $contact): array
    {
        $productIds = $note->items->pluck('product_id')->filter()->unique();
        $language = strtolower((string) ($contact->language ?? $contact->locale ?? ''));

        $sheets = SafetyDataSheet::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('current_version_id')
            ->with('currentVersion')
            ->orderBy('language')
            ->get()
            ->groupBy('product_id')
            ->map(static fn ($sheets) => $sheets->firstWhere('language', $language) ?? $sheets->first());

        $alreadySent = SafetyDataSheetDelivery::query()
            ->where('delivery_note_id', $note->id)
            ->where('status', 'sent')
            ->pluck('safety_data_sheet_version_id')
            ->all();

        return $sheets
            ->map(static fn (SafetyDataSheet $sheet) => $sheet->currentVersion)
            ->filter(static fn (?SafetyDataSheetVersion $version): bool => $version !== null && ! in_array($version->id, $alreadySent, true))
            ->values()
            ->all();
    }

    /**
     * @param  list<SafetyDataSheetVersion>  $versions
     */
    private function log(DeliveryNote $note, array $versions, ?string $email, string $status, ?string $error = null): void
    {
        foreach ($versions as $version) {
            SafetyDataSheetDelivery::query()->updateOrCreate(
                ['delivery_note_id' => $note->id, 'safety_data_sheet_version_id' => $version->id],
                [
                    'tenant_id' => $note->tenant_id,
                    'contact_id' => $note->contact_id,
                    'email' => $email,
                    'status' => $status,
                    'error' => $error,
                    'sent_at' => $status === 'sent' ? now() : null,
                ]
            );
        }
    }
}
