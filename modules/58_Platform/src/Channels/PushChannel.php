<?php

declare(strict_types=1);

namespace Modules\Platform\Channels;

use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Platform\Models\NotificationDevice;
use Modules\Platform\Notifications\PlatformNotification;
use Throwable;

/**
 * Mobile push through Firebase Cloud Messaging HTTP v1 (Android directly, iOS through FCM → APNs). The payload carries
 * the notification id and the deep link so the app lands on the linked object and reports the notification as opened.
 * Tokens FCM reports as unregistered are removed.
 */
final class PushChannel
{
    public function send(User $notifiable, PlatformNotification $notification): void
    {
        try {
            $project = config('platform.push.fcm_project_id');
            $devices = NotificationDevice::query()->where('user_id', $notifiable->id)->get();

            if (! is_string($project) || $project === '' || $devices->isEmpty()) {
                throw new \RuntimeException('Push is not configured or the user has no registered device.');
            }

            $accessToken = $this->accessToken();
            $failures = 0;

            foreach ($devices as $device) {
                $response = Http::withToken($accessToken)->timeout(10)->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", ['message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $notification->title, 'body' => $notification->body ?? ''],
                    'data' => ['notification_id' => (string) $notification->id, 'url' => (string) $notification->url, 'category' => $notification->category],
                ]]);

                if ($response->failed()) {
                    $failures++;

                    if (in_array($response->json('error.status'), ['NOT_FOUND', 'UNREGISTERED'], true)) {
                        $device->delete();
                    }
                }
            }

            if ($failures === $devices->count()) {
                throw new \RuntimeException('FCM rejected the message on every device.');
            }
        } catch (Throwable $e) {
            event(new NotificationFailed($notifiable, $notification, self::class, ['message' => $e->getMessage()]));
        }
    }

    /**
     * OAuth2 token of the service account (JWT bearer grant), cached until shortly before it expires.
     */
    private function accessToken(): string
    {
        return Cache::remember('platform.fcm.access_token', 3300, function (): string {
            $path = config('platform.push.fcm_credentials');
            $credentials = is_string($path) && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            if (! is_array($credentials) || ! isset($credentials['client_email'], $credentials['private_key'])) {
                throw new \RuntimeException('FCM service-account credentials are missing.');
            }

            $b64 = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
            $now = time();
            $unsigned = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Could not sign the FCM token request.');
            }

            return (string) Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$b64($signature),
            ])->throw()->json('access_token');
        });
    }
}
