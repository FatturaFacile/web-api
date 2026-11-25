<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Actions\Banking\ImportBankStatement;
use App\DTOs\Banking\CsvStatementMapping;
use App\Exceptions\BankStatementException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BankStatement;
use App\Services\Banking\Parsers\CsvStatementParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Treasury\Http\Requests\StatementImportRequest;
use Modules\Treasury\Http\Requests\StoreCsvProfileRequest;
use Modules\Treasury\Models\StatementCsvProfile;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class StatementImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ImportBankStatement $import,
        private readonly CsvStatementParser $csv,
    ) {}

    #[OA\Post(
        path: '/api/v1/treasury/statement-imports',
        operationId: 'importTreasuryStatement',
        description: 'Imports a camt.053 statement, camt.054 notification, CBI text file or CSV (with a saved or inline column mapping). The same file, and movements already imported, are never stored twice; incoming lines can be reconciled on import.',
        summary: 'Import a bank statement file',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data')),
        tags: ['Treasury Statement Imports'],
        responses: [
            new OA\Response(response: 201, description: 'Import summary', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Invalid file or mapping', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function import(StatementImportRequest $request): JsonResponse
    {
        $content = (string) file_get_contents($request->file('file')->getRealPath());
        $format = (string) $request->input('format');
        $autoReconcile = $request->boolean('auto_reconcile', true);

        try {
            $result = $format === 'csv'
                ? $this->importCsv($request, $content, $autoReconcile)
                : $this->import->execute($content, $autoReconcile);
        } catch (BankStatementException $e) {
            return $this->problemDetailsResponse(__('banking.problem_titles.import'), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $statement = $result['statement'];
        $stored = $statement->lines()->count();
        $reconciled = $statement->lines()->where('reconciled', true)->count();

        return $this->successResponse([
            'statement_id' => $statement->id,
            'format' => $statement->source_format,
            'account_iban' => $statement->account_iban,
            'currency' => $statement->currency,
            'statement_date' => CarbonImmutable::parse($statement->statement_date)->toDateString(),
            'opening_balance' => $statement->opening_balance !== null ? (float) $statement->opening_balance : null,
            'closing_balance' => $statement->closing_balance !== null ? (float) $statement->closing_balance : null,
            'entries' => (int) $statement->total_entries,
            'stored' => $stored,
            'duplicates' => $result['duplicates'],
            'reconciled' => $reconciled,
            'unmatched' => $stored - $reconciled,
            'status' => $statement->status,
        ], __('banking.messages.imported'), Response::HTTP_CREATED);
    }

    /**
     * @return array{statement: BankStatement, reconciliation: array{reconciled: int, unmatched: int}|null, duplicates: int}
     */
    private function importCsv(StatementImportRequest $request, string $content, bool $autoReconcile): array
    {
        $content = mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        $profileId = $request->input('csv_profile_id');
        $mapping = $profileId !== null
            ? $this->profileMapping(StatementCsvProfile::query()->findOrFail($profileId))
            : CsvStatementMapping::fromArray((array) $request->input('csv', []));

        return $this->import->executeParsed($this->csv->parseWith($content, $mapping), hash('sha256', trim($content)), $autoReconcile);
    }

    private function profileMapping(StatementCsvProfile $profile): CsvStatementMapping
    {
        return CsvStatementMapping::fromArray([
            'columns' => $profile->columns,
            'account_iban' => $profile->account_iban,
            'currency' => $profile->currency,
            'delimiter' => $profile->delimiter,
            'has_header' => $profile->has_header,
            'date_format' => $profile->date_format,
            'decimal_separator' => $profile->decimal_separator,
            'thousands_separator' => $profile->thousands_separator,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/treasury/csv-profiles',
        operationId: 'listTreasuryCsvProfiles',
        description: 'Saved column mappings of bank CSV exports.',
        summary: 'List CSV profiles',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Statement Imports'],
        responses: [new OA\Response(response: 200, description: 'Profiles', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function profiles(): JsonResponse
    {
        return $this->successResponse(StatementCsvProfile::query()->orderBy('name')->get()->map(fn (StatementCsvProfile $profile): array => $this->presentProfile($profile))->all());
    }

    #[OA\Post(
        path: '/api/v1/treasury/csv-profiles',
        operationId: 'createTreasuryCsvProfile',
        description: 'Saves a CSV column mapping so later files of the same bank are imported without repeating it.',
        summary: 'Create a CSV profile',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        tags: ['Treasury Statement Imports'],
        responses: [new OA\Response(response: 201, description: 'Profile', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function storeProfile(StoreCsvProfileRequest $request): JsonResponse
    {
        $data = $request->validated();
        $profile = StatementCsvProfile::create($data);

        return $this->successResponse($this->presentProfile($profile), __('treasury_flows.messages.csv_profile_saved'), Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/treasury/csv-profiles/{id}',
        operationId: 'deleteTreasuryCsvProfile',
        description: 'Deletes a saved mapping.',
        summary: 'Delete a CSV profile',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Statement Imports'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function destroyProfile(string $id): JsonResponse
    {
        StatementCsvProfile::query()->findOrFail($id)->delete();

        return $this->successResponse(null, __('treasury_flows.messages.csv_profile_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentProfile(StatementCsvProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'account_iban' => $profile->account_iban,
            'currency' => $profile->currency,
            'delimiter' => $profile->delimiter,
            'has_header' => (bool) $profile->has_header,
            'date_format' => $profile->date_format,
            'decimal_separator' => $profile->decimal_separator,
            'thousands_separator' => $profile->thousands_separator,
            'columns' => $profile->columns,
        ];
    }
}
