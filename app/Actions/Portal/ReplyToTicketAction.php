<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Events\Portal\TicketRepliedByCustomer;
use App\Models\Attachment;
use App\Models\PortalAccessToken;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Portal\PortalTokenService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReplyToTicketAction
{
    public function __construct(private readonly PortalTokenService $tokenService) {}

    /**
     * Customer posts a reply to a support ticket via portal.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function execute(Ticket $ticket, PortalAccessToken $token, string $message, ?string $authorName = null, array $attachments = []): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $token, $message, $authorName, $attachments): TicketMessage {
            $ticket = Ticket::lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->client_id !== $token->contact_id || $ticket->tenant_id !== $token->tenant_id) {
                throw ValidationException::withMessages(['ticket' => 'Unauthorized ticket access.']);
            }

            $customerName = $authorName
                ?: ($token->contact->company_name ?: trim(($token->contact->first_name ?? '').' '.($token->contact->last_name ?? '')))
                ?: 'Customer';

            /** @var TicketMessage $ticketMessage */
            $ticketMessage = TicketMessage::create([
                'tenant_id' => $token->tenant_id,
                'ticket_id' => $ticket->id,
                'author_type' => 'customer',
                'author_id' => null,
                'author_name' => $customerName,
                'message' => $message,
                'is_internal' => false,
            ]);

            // If ticket was resolved or closed, customer reply reopens it
            if (in_array($ticket->status, ['resolved', 'closed', 'pending'], true)) {
                $ticket->update(['status' => 'open']);
            }

            // Handle optional attachments
            $disk = config('filesystems.private_disk', config('filesystems.default', 'local'));
            foreach ($attachments as $file) {
                if ($file instanceof UploadedFile) {
                    $filename = 'ticket_reply_'.time().'_'.bin2hex(random_bytes(4)).'.'.$file->getClientOriginalExtension();
                    $path = "tenants/{$token->tenant_id}/tickets/{$ticket->id}/{$filename}";
                    Storage::disk($disk)->put($path, $file->getContent());

                    Attachment::create([
                        'tenant_id' => $token->tenant_id,
                        'attachable_type' => Ticket::class,
                        'attachable_id' => $ticket->id,
                        'original_name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                        'size' => $file->getSize(),
                    ]);
                }
            }

            $this->tokenService->logActivity(
                token: $token,
                action: 'ticket_replied',
                subjectType: 'ticket',
                subjectId: $ticket->id,
                metadata: [
                    'ticket_subject' => $ticket->subject,
                    'author_name' => $customerName,
                    'attachments_count' => count($attachments),
                ]
            );

            event(new TicketRepliedByCustomer($ticket, $ticketMessage, $token));

            return $ticketMessage;
        });
    }
}
