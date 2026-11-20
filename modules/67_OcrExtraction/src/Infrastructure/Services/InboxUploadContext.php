<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\SourceChannel;

/**
 * Tells the DMS upload hook where a file in the AI Inbox folder comes from, so a mobile capture or an inbound e-mail is
 * recorded with its own channel and category hint instead of as a plain DMS upload.
 */
final class InboxUploadContext
{
    private SourceChannel $channel = SourceChannel::DmsUpload;

    private ?DocumentCategory $hint = null;

    private ?string $requestedBy = null;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(SourceChannel $channel, ?DocumentCategory $hint, ?string $requestedBy, callable $callback): mixed
    {
        [$previousChannel, $previousHint, $previousUser] = [$this->channel, $this->hint, $this->requestedBy];
        [$this->channel, $this->hint, $this->requestedBy] = [$channel, $hint, $requestedBy];

        try {
            return $callback();
        } finally {
            [$this->channel, $this->hint, $this->requestedBy] = [$previousChannel, $previousHint, $previousUser];
        }
    }

    public function channel(): SourceChannel
    {
        return $this->channel;
    }

    public function hint(): ?DocumentCategory
    {
        return $this->hint;
    }

    public function requestedBy(): ?string
    {
        return $this->requestedBy;
    }
}
