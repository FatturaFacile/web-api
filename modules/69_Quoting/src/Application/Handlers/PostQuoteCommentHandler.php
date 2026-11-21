<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Commands\PostQuoteCommentCommand;
use Modules\Quoting\Infrastructure\Models\CpqComment;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;

final class PostQuoteCommentHandler
{
    public function __construct(private readonly PublicQuoteGate $gate, private readonly QuoteAuditTrail $audit) {}

    public function handle(PostQuoteCommentCommand $command): CpqComment
    {
        $quote = $this->gate->resolve($command->token, $command->session);

        $comment = CpqComment::query()->create([
            'tenant_id' => $quote->tenant_id,
            'quote_id' => $quote->id,
            'line_id' => $command->lineId,
            'author_type' => 'customer',
            'author_name' => $command->authorName,
            'body' => $command->body,
        ]);
        $this->audit->record($quote, 'comment_posted', $command->authorName ?? 'customer', $command->ip, ['comment_id' => $comment->id]);

        return $comment;
    }
}
