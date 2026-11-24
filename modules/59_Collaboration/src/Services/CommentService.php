<?php

declare(strict_types=1);

namespace Modules\Collaboration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Modules\Collaboration\Models\CollabComment;
use Modules\Collaboration\Notifications\MentionedInCommentNotification;

/**
 * Writes the comments of a record and tells the mentioned members. A member is notified once per comment, never about
 * their own mention, and an edit only notifies the members it adds.
 */
final class CommentService
{
    /**
     * @param  list<string>  $mentionedUserIds  members of the company, already validated
     */
    public function create(RecordType $type, Model $record, User $author, string $body, array $mentionedUserIds): CollabComment
    {
        $mentions = $this->mentionsWithoutAuthor($mentionedUserIds, $author);

        $comment = CollabComment::query()->create([
            'tenant_id' => (string) $record->getAttribute('tenant_id'),
            'commentable_type' => $type->key,
            'commentable_id' => (string) $record->getKey(),
            'user_id' => (string) $author->getKey(),
            'body' => $body,
            'mentioned_user_ids' => $mentions,
        ]);

        $this->notify($comment, $type, $record, $author, $mentions);

        return $comment;
    }

    /**
     * @param  list<string>  $mentionedUserIds  members of the company, already validated
     */
    public function update(CollabComment $comment, RecordType $type, Model $record, User $author, string $body, array $mentionedUserIds): CollabComment
    {
        $before = $comment->mentioned_user_ids ?? [];
        $mentions = $this->mentionsWithoutAuthor($mentionedUserIds, $author);

        $comment->forceFill(['body' => $body, 'mentioned_user_ids' => $mentions, 'edited_at' => now()])->save();

        $this->notify($comment, $type, $record, $author, array_values(array_diff($mentions, $before)));

        return $comment;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function mentionsWithoutAuthor(array $ids, User $author): array
    {
        return array_values(array_diff(array_values(array_unique($ids)), [(string) $author->getKey()]));
    }

    /**
     * @param  list<string>  $userIds
     */
    private function notify(CollabComment $comment, RecordType $type, Model $record, User $author, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $userIds)->get(),
            new MentionedInCommentNotification($comment->id, $type->key, (string) $record->getKey(), $type->titleOf($record), $author->name, $comment->body, $type->pathOf($record)),
        );
    }
}
