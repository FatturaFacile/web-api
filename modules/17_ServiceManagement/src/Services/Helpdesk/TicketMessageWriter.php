<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Enums\MessageAuthorType;
use Modules\ServiceManagement\Enums\MessageVisibility;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketAttachment;
use Modules\ServiceManagement\Models\TicketMessage;

/**
 * Stores a message of a ticket and its attachments. Files go to the private disk under the tenant and the ticket, with
 * a generated name: the name given by the sender is kept only as metadata.
 */
final class TicketMessageWriter
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<UploadedFile|array{name: string, content: string, mime?: string}>  $attachments
     */
    public function write(Ticket $ticket, array $attributes, array $attachments = []): TicketMessage
    {
        /** @var TicketMessage $message */
        $message = TicketMessage::query()->create([
            'visibility' => MessageVisibility::Public->value,
            'channel' => 'web',
            ...$attributes,
            'tenant_id' => $ticket->tenant_id,
            'ticket_id' => $ticket->id,
        ]);

        foreach ($attachments as $attachment) {
            $this->storeAttachment($ticket, $message, $attachment);
        }

        return $message;
    }

    public function systemNote(Ticket $ticket, string $body, ?string $actorUserId = null): TicketMessage
    {
        return $this->write($ticket, [
            'author_type' => MessageAuthorType::System->value,
            'author_user_id' => $actorUserId,
            'author_name' => 'System',
            'visibility' => MessageVisibility::Internal->value,
            'channel' => 'system',
            'body' => $body,
        ]);
    }

    public static function disk(): string
    {
        return (string) config('filesystems.private_disk', 'private');
    }

    /**
     * @param  UploadedFile|array{name: string, content: string, mime?: string}  $attachment
     */
    private function storeAttachment(Ticket $ticket, TicketMessage $message, UploadedFile|array $attachment): TicketAttachment
    {
        if ($attachment instanceof UploadedFile) {
            $name = $attachment->getClientOriginalName();
            $content = (string) $attachment->getContent();
            $mime = $attachment->getMimeType() ?? 'application/octet-stream';
        } else {
            $name = $attachment['name'];
            $content = $attachment['content'];
            $mime = $attachment['mime'] ?? 'application/octet-stream';
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $storedName = (string) Str::uuid7().($extension !== '' && preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? ".{$extension}" : '');
        $path = "tenants/{$ticket->tenant_id}/helpdesk/{$ticket->id}/{$storedName}";

        Storage::disk(self::disk())->put($path, $content);

        return TicketAttachment::query()->create([
            'tenant_id' => $ticket->tenant_id,
            'ticket_id' => $ticket->id,
            'message_id' => $message->id,
            'disk' => self::disk(),
            'path' => $path,
            'original_name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
            'mime_type' => mb_substr($mime, 0, 150),
            'size' => strlen($content),
            'checksum' => hash('sha256', $content),
        ]);
    }
}
