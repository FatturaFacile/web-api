<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\PracticeManagement\Http\Requests\PracticeRequest;
use Modules\PracticeManagement\Http\Resources\PracticeResource;
use Modules\PracticeManagement\Models\PracticeExpense;
use OpenApi\Attributes as OA;

/**
 * Expenses of a matter (court fees, travel, filings, experts) that are recovered from the client when billable.
 */
final class ExpenseController extends Controller
{
    #[OA\Get(path: '/api/v1/practice/expenses', operationId: 'listPracticeExpenses', description: 'Return the matter expenses, latest first, optionally of one matter or only the billed or not yet billed ones.', summary: 'List matter expenses', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [
        new OA\Parameter(name: 'legal_case_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'is_billed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    ], responses: [
        new OA\Response(response: 200, description: 'Expenses', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function index(PracticeRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PracticeExpense::class);

        return PracticeResource::collection(PracticeExpense::query()
            ->with('legalCase')
            ->when($request->validated('legal_case_id') !== null, fn ($query) => $query->where('legal_case_id', $request->validated('legal_case_id')))
            ->when($request->has('is_billed'), fn ($query) => $query->where('is_billed', $request->boolean('is_billed')))
            ->orderByDesc('expense_date')->orderByDesc('id')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    #[OA\Post(path: '/api/v1/practice/expenses', operationId: 'storePracticeExpense', description: 'Record an expense incurred on a matter. The amount is a decimal string.', summary: 'Record matter expense', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeExpenseRequest')), tags: ['Practice Management'], responses: [
        new OA\Response(response: 201, description: 'Expense recorded', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function store(PracticeRequest $request): JsonResponse
    {
        $this->authorize('create', PracticeExpense::class);
        $expense = new PracticeExpense;
        $expense->fill(['is_billable' => true, 'is_billed' => false, ...$request->validated(), 'user_id' => $request->user()?->getKey()]);
        $expense->save();

        return (new PracticeResource($expense))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/practice/expenses/{id}', operationId: 'updatePracticeExpense', description: 'Change an expense that is not billed yet.', summary: 'Update matter expense', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeExpenseRequest')), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Expense updated', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function update(PracticeRequest $request, string $id): PracticeResource
    {
        $expense = PracticeExpense::query()->findOrFail($id);
        $this->authorize('update', $expense);
        $this->assertNotBilled($expense);
        $expense->fill($request->validated());
        $expense->save();

        return new PracticeResource($expense);
    }

    #[OA\Delete(path: '/api/v1/practice/expenses/{id}', operationId: 'deletePracticeExpense', description: 'Delete an expense that is not billed yet.', summary: 'Delete matter expense', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 204, description: 'Expense deleted'),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $expense = PracticeExpense::query()->findOrFail($id);
        $this->authorize('update', $expense);
        $this->assertNotBilled($expense);
        $expense->delete();

        return response()->noContent();
    }

    private function assertNotBilled(PracticeExpense $expense): void
    {
        if ($expense->is_billed) {
            throw new BusinessRuleException('A billed expense cannot be changed.');
        }
    }
}
