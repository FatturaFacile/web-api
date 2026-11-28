<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\SkiOutdoorSchool\Application\Commands\GeneratePayoutCommand;
use Modules\SkiOutdoorSchool\Application\Handlers\PayoutHandler;
use Modules\SkiOutdoorSchool\Application\Queries\SchoolQueries;
use Modules\SkiOutdoorSchool\Application\Services\DocumentRenderer;
use Modules\SkiOutdoorSchool\Http\Controllers\Concerns\InteractsWithSkiSchool;
use Modules\SkiOutdoorSchool\Http\Requests\GeneratePayoutRequest;
use Modules\SkiOutdoorSchool\Http\Requests\PayoutMonthRequest;
use Modules\SkiOutdoorSchool\Http\Resources\PayoutStatementResource;
use Modules\SkiOutdoorSchool\Models\PayoutStatement;
use OpenApi\Attributes as OA;

/**
 * Monthly payout of the instructors: the dashboard totals, the statements (estratto conto compensi) and their PDF.
 */
final class PayoutController extends Controller
{
    use ApiResponse;
    use InteractsWithSkiSchool;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/ski-school/payouts/summary', operationId: 'skiSchoolPayoutsSummary', summary: 'Monthly payout summary per instructor (hours, gross, secretariat retention, withholding, net)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Summary')])]
    public function summary(PayoutMonthRequest $request, PayoutHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', PayoutStatement::class);

        return $this->successResponse(['month' => $request->validated('month'), 'instructors' => $handler->summary((string) $request->validated('month'))], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/ski-school/payout-statements', operationId: 'skiSchoolPayoutStatementsIndex', summary: 'Payout statements (filter by month, instructor, status)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Statements')])]
    public function index(SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', PayoutStatement::class);

        return $this->successResponse(PayoutStatementResource::collection($queries->statements($this->filters())), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/ski-school/payout-statements/generate', operationId: 'skiSchoolPayoutStatementsGenerate', summary: 'Generate (or rebuild the drafts of) the payout statements of a month', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 201, description: 'Statements')])]
    public function generate(GeneratePayoutRequest $request, PayoutHandler $handler): JsonResponse
    {
        Gate::authorize('create', PayoutStatement::class);
        $result = $handler->generate(GeneratePayoutCommand::fromArray($request->validated(), $this->userId()));

        return $this->createdResponse(['statements' => PayoutStatementResource::collection($result['statements']), 'skipped' => $result['skipped']], $this->message('statements_generated'));
    }

    #[OA\Get(path: '/api/v1/ski-school/payout-statements/{id}', operationId: 'skiSchoolPayoutStatementsShow', summary: 'One statement with its commission lines', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Statement')])]
    public function show(string $id, SchoolQueries $queries): JsonResponse
    {
        $statement = $queries->statement($id);
        Gate::authorize('view', $statement);

        return $this->successResponse(new PayoutStatementResource($statement), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/ski-school/payout-statements/{id}/issue', operationId: 'skiSchoolPayoutStatementsIssue', summary: 'Issue a draft statement', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Statement')])]
    public function issue(string $id, PayoutHandler $handler): JsonResponse
    {
        Gate::authorize('update', PayoutStatement::query()->findOrFail($id));

        return $this->successResponse(new PayoutStatementResource($handler->issue($id)), $this->message('statement_issued'));
    }

    #[OA\Post(path: '/api/v1/ski-school/payout-statements/{id}/pay', operationId: 'skiSchoolPayoutStatementsPay', summary: 'Mark an issued statement as paid', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Statement')])]
    public function pay(string $id, PayoutHandler $handler): JsonResponse
    {
        Gate::authorize('update', PayoutStatement::query()->findOrFail($id));

        return $this->successResponse(new PayoutStatementResource($handler->markPaid($id)), $this->message('statement_paid'));
    }

    #[OA\Delete(path: '/api/v1/ski-school/payout-statements/{id}', operationId: 'skiSchoolPayoutStatementsDestroy', summary: 'Discard a draft statement (its commissions return to the month)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Discarded')])]
    public function destroy(string $id, PayoutHandler $handler): JsonResponse
    {
        Gate::authorize('delete', PayoutStatement::query()->findOrFail($id));
        $handler->discard($id);

        return $this->successResponse(null, $this->message('statement_discarded'));
    }

    #[OA\Get(path: '/api/v1/ski-school/payout-statements/{id}/pdf', operationId: 'skiSchoolPayoutStatementsPdf', summary: 'Printable statement (estratto conto compensi) as PDF (or HTML with ?format=html)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function pdf(Request $request, string $id, SchoolQueries $queries, DocumentRenderer $renderer): Response
    {
        $statement = $queries->statement($id);
        Gate::authorize('view', $statement);
        $locale = app()->getLocale();

        if ($request->query('format') === 'html') {
            return response($renderer->statementHtml($statement, $locale), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return response($renderer->statementPdf($statement, $locale), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$statement->reference.'.pdf"']);
    }
}
