<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\MarketingAutomation\Http\Requests\ProviderWebhookRequest;
use Modules\MarketingAutomation\Services\Delivery\MailProviderRegistry;
use Modules\MarketingAutomation\Services\MarketingCopy;
use Modules\MarketingAutomation\Services\TrackingService;
use OpenApi\Attributes as OA;

/**
 * Public endpoints (no login): open pixel, click redirect, one-click unsubscribe (GET and RFC 8058 POST) and the
 * provider webhooks for hard / soft bounces and spam complaints. Every URL carries an unguessable message token.
 */
final class TrackingController extends Controller
{
    /** 1x1 transparent GIF. */
    private const string PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    #[OA\Get(path: '/api/v1/marketing/t/o/{token}.gif', operationId: 'marketingTrackOpen', summary: 'Open tracking pixel', tags: ['Marketing Tracking'], responses: [new OA\Response(response: 200, description: 'GIF')])]
    public function open(string $token, TrackingService $tracking): Response
    {
        $message = $tracking->findByToken($token);
        if ($message !== null) {
            $tracking->registerOpen($message);
        }

        return response(base64_decode(self::PIXEL), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, max-age=0']);
    }

    #[OA\Get(path: '/api/v1/marketing/t/c/{token}', operationId: 'marketingTrackClick', summary: 'Click redirect keeping UTM and attribution token', tags: ['Marketing Tracking'], responses: [new OA\Response(response: 302, description: 'Redirect')])]
    public function click(string $token, Request $request, TrackingService $tracking): RedirectResponse|Response
    {
        $target = $tracking->decodeTarget((string) $request->query('u', ''), (string) $request->query('s', ''));
        if ($target === null) {
            // An unsigned target would be an open redirect.
            return response('', 404);
        }

        $message = $tracking->findByToken($token);
        if ($message !== null) {
            $tracking->registerClick($message);
        }

        return redirect()->away($target);
    }

    #[OA\Get(path: '/api/v1/marketing/u/{token}', operationId: 'marketingUnsubscribe', summary: 'One-click unsubscribe (link)', tags: ['Marketing Tracking'], responses: [new OA\Response(response: 200, description: 'Confirmation page in the language of the recipient')])]
    public function unsubscribe(string $token, Request $request, TrackingService $tracking, MarketingCopy $copy): Response
    {
        $message = $tracking->findByToken($token);
        $locale = $message !== null ? $message->locale : 'it';

        if ($message !== null) {
            $tracking->unsubscribe($message, ['ip_address' => $request->ip(), 'user_agent' => (string) $request->userAgent(), 'source' => 'unsubscribe_link']);
        }

        $title = e($copy->get('unsubscribe.title', $locale));
        $body = e($copy->get($message !== null ? 'unsubscribe.done' : 'unsubscribe.invalid', $locale));

        return response('<!doctype html><html lang="'.e($locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title></head>'
            .'<body style="font-family:Arial,sans-serif;max-width:520px;margin:64px auto;padding:0 16px;"><h1>'.$title.'</h1><p>'.$body.'</p></body></html>', $message !== null ? 200 : 404, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    #[OA\Post(path: '/api/v1/marketing/u/{token}', operationId: 'marketingUnsubscribeOneClick', summary: 'RFC 8058 one-click unsubscribe (List-Unsubscribe-Post)', tags: ['Marketing Tracking'], responses: [new OA\Response(response: 200, description: 'Unsubscribed')])]
    public function unsubscribeOneClick(string $token, Request $request, TrackingService $tracking): JsonResponse
    {
        $message = $tracking->findByToken($token);
        if ($message === null) {
            return response()->json(['success' => false], 404);
        }

        $tracking->unsubscribe($message, ['ip_address' => $request->ip(), 'user_agent' => (string) $request->userAgent(), 'source' => 'rfc8058_one_click']);

        return response()->json(['success' => true]);
    }

    #[OA\Post(path: '/api/v1/marketing/webhooks/{provider}', operationId: 'marketingProviderWebhook', summary: 'Bounce, complaint (FBL) and delivery events of a mail provider', tags: ['Marketing Tracking'], responses: [new OA\Response(response: 202, description: 'Accepted')])]
    public function webhook(string $provider, ProviderWebhookRequest $request, MailProviderRegistry $providers, TrackingService $tracking): JsonResponse
    {
        $handled = 0;
        foreach ($providers->adapter($provider)->parseWebhook($request->all()) as $event) {
            $handled += $tracking->registerProviderEvent($event) !== null ? 1 : 0;
        }

        return response()->json(['success' => true, 'handled' => $handled], 202);
    }
}
