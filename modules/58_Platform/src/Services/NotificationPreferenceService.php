<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\User;
use Modules\Platform\Models\NotificationPreference;

/**
 * Per-user, per-channel, per-category notification choices. A missing row means enabled. Notifications call
 * channelsFor() in their via() method so a user who switched a channel off no longer gets it.
 */
final class NotificationPreferenceService
{
    /**
     * @return array{channels: list<string>, categories: list<string>, preferences: list<array{channel: string, category: string, enabled: bool}>}
     */
    public function matrix(User $user): array
    {
        /** @var list<string> $channels */
        $channels = config('platform.notification_channels');
        /** @var list<string> $categories */
        $categories = config('platform.notification_categories');
        $stored = NotificationPreference::query()->where('user_id', $user->id)->get()->mapWithKeys(
            static fn (NotificationPreference $row): array => ["{$row->channel}|{$row->category}" => $row->enabled],
        );

        $preferences = [];

        foreach ($channels as $channel) {
            foreach ($categories as $category) {
                $preferences[] = ['channel' => $channel, 'category' => $category, 'enabled' => (bool) ($stored["{$channel}|{$category}"] ?? true)];
            }
        }

        return ['channels' => $channels, 'categories' => $categories, 'preferences' => $preferences];
    }

    /**
     * @param  list<array{channel: string, category: string, enabled: bool}>  $changes
     */
    public function update(User $user, array $changes): void
    {
        foreach ($changes as $change) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'channel' => $change['channel'], 'category' => $change['category']],
                ['enabled' => (bool) $change['enabled']],
            );
        }
    }

    /**
     * The channels of $channels the user left on for the category.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    public function channelsFor(User $user, string $category, array $channels): array
    {
        $disabled = NotificationPreference::query()->where('user_id', $user->id)->where('category', $category)->where('enabled', false)->pluck('channel')->all();

        return array_values(array_diff($channels, $disabled));
    }
}
