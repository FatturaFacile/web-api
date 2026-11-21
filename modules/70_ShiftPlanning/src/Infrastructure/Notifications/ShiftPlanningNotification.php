<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An in-app notification (push through the database channel read by the mobile app). The client translates the key.
 */
final class ShiftPlanningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(private readonly string $tenantId, private readonly string $translationKey, private readonly array $params = []) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['module' => 'shift_planning', 'tenant_id' => $this->tenantId, 'translation_key' => $this->translationKey, 'params' => $this->params];
    }
}
