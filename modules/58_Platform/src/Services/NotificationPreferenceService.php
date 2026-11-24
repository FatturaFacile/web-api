<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Platform\Models\NotificationPreference;
use Modules\Platform\Models\NotificationSetting;

/**
 * Per-user, per-channel, per-category notification choices (on/off and immediate vs daily digest) plus do-not-disturb
 * settings. A missing row means enabled and immediate. Notifications call deliveryFor() (or channelsFor()) in their
 * via() method so a user who switched a channel off, asked for a digest or is silenced is respected.
 */
final class NotificationPreferenceService
{
    public const IMMEDIATE = 'immediate';

    public const DIGEST = 'digest';

    public const FREQUENCIES = [self::IMMEDIATE, self::DIGEST];

    /**
     * @return array{channels: list<string>, categories: list<string>, frequencies: list<string>, preferences: list<array{channel: string, category: string, enabled: bool, frequency: string}>}
     */
    public function matrix(User $user): array
    {
        /** @var list<string> $channels */
        $channels = config('platform.notification_channels');
        /** @var list<string> $categories */
        $categories = config('platform.notification_categories');
        $stored = NotificationPreference::query()->where('user_id', $user->id)->get()->mapWithKeys(
            static fn (NotificationPreference $row): array => ["{$row->channel}|{$row->category}" => $row],
        );

        $preferences = [];

        foreach ($channels as $channel) {
            foreach ($categories as $category) {
                $row = $stored["{$channel}|{$category}"] ?? null;
                $preferences[] = [
                    'channel' => $channel,
                    'category' => $category,
                    'enabled' => $row === null ? true : $row->enabled,
                    'frequency' => $row === null ? self::IMMEDIATE : $row->frequency,
                ];
            }
        }

        return ['channels' => $channels, 'categories' => $categories, 'frequencies' => self::FREQUENCIES, 'preferences' => $preferences];
    }

    /**
     * Only the keys present in a change are updated.
     *
     * @param  list<array{channel: string, category: string, enabled?: bool, frequency?: string}>  $changes
     */
    public function update(User $user, array $changes): void
    {
        foreach ($changes as $change) {
            $values = [];

            if (array_key_exists('enabled', $change)) {
                $values['enabled'] = (bool) $change['enabled'];
            }

            if (array_key_exists('frequency', $change)) {
                $values['frequency'] = $change['frequency'];
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'channel' => $change['channel'], 'category' => $change['category']],
                $values,
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

    /**
     * Channels to use right now for a category, split by frequency. Digest channels are collected for the daily
     * summary instead of being sent. While the user is silenced (quiet hours or snooze) only the in-app `database`
     * channel is used, so the centre stays complete but nothing buzzes the user.
     *
     * @param  list<string>  $channels
     * @return array{immediate: list<string>, digest: list<string>, silenced: bool}
     */
    public function deliveryFor(User $user, string $category, array $channels, ?Carbon $now = null): array
    {
        $enabled = $this->channelsFor($user, $category, $channels);
        $digest = NotificationPreference::query()->where('user_id', $user->id)->where('category', $category)->where('frequency', self::DIGEST)->pluck('channel')->all();
        $silenced = $this->isSilenced($user, $now);

        $immediate = array_values(array_diff($enabled, $digest));

        if ($silenced) {
            $immediate = array_values(array_intersect($immediate, ['database']));
        }

        return ['immediate' => $immediate, 'digest' => array_values(array_intersect($enabled, $digest)), 'silenced' => $silenced];
    }

    public function isSilenced(User $user, ?Carbon $now = null): bool
    {
        $setting = NotificationSetting::query()->where('user_id', $user->id)->first();

        if ($setting === null) {
            return false;
        }

        $now ??= Carbon::now();

        if ($setting->muted_until !== null && $setting->muted_until->greaterThan($now)) {
            return true;
        }

        if (! $setting->quiet_hours_enabled) {
            return false;
        }

        $local = $now->copy()->setTimezone($setting->timezone);
        $minutes = $local->hour * 60 + $local->minute;
        $start = $this->minutes($setting->quiet_start);
        $end = $this->minutes($setting->quiet_end);

        if ($start === $end) {
            return false;
        }

        return $start < $end ? ($minutes >= $start && $minutes < $end) : ($minutes >= $start || $minutes < $end);
    }

    public function settings(User $user): NotificationSetting
    {
        return NotificationSetting::query()->firstOrNew(['user_id' => $user->id], [
            'quiet_hours_enabled' => false,
            'quiet_start' => '20:00',
            'quiet_end' => '08:00',
            'timezone' => 'Europe/Rome',
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateSettings(User $user, array $changes): NotificationSetting
    {
        $setting = $this->settings($user);
        $setting->fill($changes)->save();

        return $setting;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }
}
