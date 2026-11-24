<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Modules\Intrastat\Enums\DeclarationStatus;
use Modules\Intrastat\Http\Requests\ExportRequest;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Services\DeclarationValidator;
use Modules\Intrastat\Services\TelematicFileWriter;
use OpenApi\Attributes as OA;

/**
 * The telematic file of one or more validated lists, in the layout of the technical specifications (Allegato XII to
 * determinazione ADM 493869/RU del 23/12/2021). The file still has to be checked and signed by the sender before it is
 * transmitted; this endpoint does not transmit anything.
 */
final class ExportController extends Controller
{
    use ApiResponse;

    /**
     * Entratel values of the record di testa (Agenzia delle Entrate, Allegato I alla determinazione 63336/RU del
     * 7 maggio 2010): user code ZENT, progressivo sede 001.
     */
    private const string EntratelUserCode = 'ZENT';

    private const int EntratelSiteProgressive = 1;

    #[OA\Post(path: '/api/v1/intrastat/exports', operationId: 'exportIntrastatFile', description: 'Write the telematic file of validated or submitted lists: record di testa, then for each list the frontespizio and the rows of sections 1 to 4, ASCII, fixed-position fields, records ending in CR LF. The file is named <user code><MMDD>.I<nn>. Through Entratel (channel entratel) the user code is ZENT and the progressivo sede 001; through the customs telematic service (channel customs) the user code and the progressivo sede of the authorisation are required. Every list is validated again first. The file must still be signed before transmission.', summary: 'Export INTRA telematic file', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IntrastatExportRequest')), tags: ['Intrastat'], responses: [
        new OA\Response(response: 200, description: 'Telematic file', content: new OA\MediaType(mediaType: 'text/plain', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(response: 422, description: 'A list is not validated or has errors', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatValidationProblem')),
    ])]
    public function store(ExportRequest $request, DeclarationValidator $validator, TelematicFileWriter $writer): Response|JsonResponse
    {
        $validated = $request->validated();
        $declarations = IntrastatDeclaration::query()->with('lines')->whereIn('id', $validated['declaration_ids'])->orderBy('reference_number')->get();
        $errors = [];

        foreach ($declarations as $declaration) {
            $this->authorize('view', $declaration);

            if ($declaration->status === DeclarationStatus::Draft) {
                $errors[] = ['declaration_id' => $declaration->id, 'field' => 'status', 'message' => 'Validate the list before exporting it.'];

                continue;
            }

            foreach ($validator->validate($declaration) as $error) {
                $errors[] = ['declaration_id' => $declaration->id, ...$error];
            }
        }

        if ($errors !== []) {
            return $this->problemDetailsResponse('INTRA lists not ready', 'The file is not written until every list is valid.', Response::HTTP_UNPROCESSABLE_ENTITY, invalidParams: $errors);
        }

        $isEntratel = $validated['channel'] === 'entratel';
        $userCode = $isEntratel ? self::EntratelUserCode : strtoupper((string) $validated['user_code']);
        $fileName = TelematicFileWriter::fileName($userCode, Carbon::parse($validated['transmission_date'] ?? now()), $validated['sequence'] ?? '01');

        $content = $writer->write($declarations, [
            'user_code' => $userCode,
            'file_name' => $fileName,
            'customs_section_code' => $validated['customs_section_code'],
            'sender_code' => strtoupper((string) ($validated['sender_code'] ?? $declarations->first()->presenterVatNumber())),
            'site_progressive' => $isEntratel ? self::EntratelSiteProgressive : (int) $validated['site_progressive'],
        ]);

        IntrastatDeclaration::query()->whereIn('id', $declarations->pluck('id'))->update(['last_export_file_name' => $fileName, 'last_exported_at' => now()]);

        return response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=US-ASCII',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
