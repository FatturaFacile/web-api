<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the asset managers that the warranty or the insurance of an asset expires soon. Stored as a database
 * notification of the user.
 */
final class AssetCoverageExpiringNotification extends Notification
{
    use Queueable;

    public const string Warranty = 'warranty';

    public const string Insurance = 'insurance';

    public function __construct(
        public readonly string $coverage,
        public readonly string $assetId,
        public readonly string $assetNumber,
        public readonly string $assetName,
        public readonly string $expiryDate,
        public readonly ?string $provider = null,
    ) {}

    /**
     * Delivery channels: the database notifications of the user.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Data stored with the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'activity' => 'asset_'.$this->coverage.'_expiring',
            'asset_id' => $this->assetId,
            'asset_number' => $this->assetNumber,
            'asset_name' => $this->assetName,
            'coverage' => $this->coverage,
            'expiry_date' => $this->expiryDate,
            'provider' => $this->provider,
        ];
    }
}
