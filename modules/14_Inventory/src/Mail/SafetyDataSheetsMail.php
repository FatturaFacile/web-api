<?php

declare(strict_types=1);

namespace Modules\Inventory\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Inventory\Models\SafetyDataSheetVersion;

final class SafetyDataSheetsMail extends Mailable
{
    /**
     * @param  list<SafetyDataSheetVersion>  $versions
     */
    public function __construct(
        public readonly string $ddtNumber,
        public readonly array $versions
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('inventory.safety_sheets.subject', ['number' => $this->ddtNumber]));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('inventory.safety_sheets.body', ['number' => $this->ddtNumber])).'</p>');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (SafetyDataSheetVersion $version): Attachment => Attachment::fromStorageDisk($version->disk, $version->path)->as($version->original_name)->withMime($version->mime_type),
            $this->versions
        );
    }
}
