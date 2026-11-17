<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Controllers;

use App\Actions\Purchases\ReceivePassiveInvoice;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Modules\SDI\Http\Requests\ImportPassiveInvoiceRequest;
use Modules\SDI\Services\FatturaPaHtmlRenderer;
use Modules\SDI\Services\P7mExtractor;
use Symfony\Component\HttpFoundation\Response;

class SdiPassiveImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReceivePassiveInvoice $receive,
        private readonly P7mExtractor $p7m,
        private readonly FatturaPaHtmlRenderer $renderer,
    ) {}

    /**
     * Read a supplier FatturaPA (.xml, or CAdES .xml.p7m) into received-invoice drafts, with a readable preview.
     */
    public function __invoke(ImportPassiveInvoiceRequest $request): JsonResponse
    {
        $file = $request->file('file');

        try {
            $opened = $this->p7m->open((string) file_get_contents($file->getRealPath()));
            $preview = $this->renderer->render($opened['xml']);

            if ($request->boolean('preview_only')) {
                return $this->successResponse(['preview_html' => $preview, 'signature_verified' => $opened['signature_verified']], __('purchases.messages.invoices_received'));
            }

            $fileName = (string) preg_replace('/\.p7m$/i', '', $file->getClientOriginalName());
            $results = $this->receive->execute($opened['xml'], $fileName);
        } catch (InvalidArgumentException $e) {
            return $this->problemDetailsResponse(
                title: __('purchases.invalid_fatturapa_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse([
            'invoices' => array_map(fn (array $result): array => [
                'duplicate' => $result['duplicate'],
                'invoice' => (new CatalogResource($result['invoice']))->resolve($request),
            ], $results),
            'signature_verified' => $opened['signature_verified'],
            'preview_html' => $preview,
        ], __('purchases.messages.invoices_received'), Response::HTTP_CREATED);
    }
}
