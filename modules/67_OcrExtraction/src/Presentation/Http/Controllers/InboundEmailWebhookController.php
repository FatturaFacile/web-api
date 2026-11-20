<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\OcrExtraction\Domain\Enums\SourceChannel;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;
use Modules\OcrExtraction\Infrastructure\Services\DmsSourceReader;
use Modules\OcrExtraction\Infrastructure\Services\InboxIntakeService;
use Modules\OcrExtraction\Presentation\Http\Requests\InboundEmailRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook of the dedicated tenant mailbox (invoices@<tenant>.fatturafacile.ch). The mail gateway signs the body; the
 * tenant is found by the recipient alias, so a message can only ever reach the tenant that owns that address. PDF, XML and
 * image attachments go to the DMS AI Inbox folder and are queued; anything else is skipped.
 */
final class InboundEmailWebhookController extends Controller
{
    use ApiResponse;

    #[OA\Post(path: '/api/v1/webhooks/inbound-email', operationId: 'receiveOcrInboundEmail', description: 'Receives a parsed inbound e-mail signed with HMAC-SHA256 (X-Signature: sha256=...), stores its PDF/XML/image attachments in the DMS and queues their extraction.', summary: 'Inbound e-mail webhook', tags: ['OCR Inbox'], responses: [new OA\Response(response: 202, description: 'Attachments queued'), new OA\Response(response: 401, description: 'Invalid signature'), new OA\Response(response: 404, description: 'Unknown mailbox')])]
    public function __invoke(InboundEmailRequest $request, InboxIntakeService $intake, DmsSourceReader $reader): JsonResponse
    {
        $data = $request->validated();
        $alias = strtolower(trim((string) preg_replace('/^.*<([^>]+)>.*$/', '$1', (string) $data['to'])));
        $tenantId = DB::table('tenant_ocr_configurations')->whereRaw('LOWER(dedicated_inbox_alias) = ?', [$alias])->whereNull('deleted_at')->value('tenant_id');

        if (! is_string($tenantId)) {
            return $this->problemDetailsResponse('Not Found', 'No mailbox matches the recipient.', Response::HTTP_NOT_FOUND);
        }

        $jobs = [];
        $skipped = [];

        foreach ($data['attachments'] as $attachment) {
            $bytes = base64_decode((string) $attachment['content_base64'], true);
            $name = basename((string) $attachment['filename']);

            if ($bytes === false || $bytes === '' || strlen($bytes) > (int) config('ocr_extraction.inbound_max_attachment_bytes')) {
                $skipped[] = $name;

                continue;
            }

            $mime = $reader->mimeType($bytes, (string) ($attachment['content_type'] ?? ''));

            if (! in_array($mime, ['application/pdf', 'application/xml', 'text/xml', 'image/png', 'image/jpeg', 'image/webp'], true)) {
                $skipped[] = $name;

                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'mail');

            if ($path === false) {
                $skipped[] = $name;

                continue;
            }

            file_put_contents($path, $bytes);

            try {
                $job = $intake->intake($tenantId, new UploadedFile($path, $name, $mime, null, true), SourceChannel::TenantEmailInbox, null, null, sha1($data['message_id'].'|'.$name));
                $jobs[] = (string) $job->id;
            } catch (OcrExtractionException) {
                $skipped[] = $name;
            } finally {
                @unlink($path);
            }
        }

        return $this->successResponse(['accepted' => count($jobs), 'job_ids' => $jobs, 'skipped' => $skipped], 'Attachments queued', Response::HTTP_ACCEPTED);
    }
}
