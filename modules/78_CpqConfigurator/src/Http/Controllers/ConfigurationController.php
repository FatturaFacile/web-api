<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CpqConfigurator\Http\Requests\EvaluateRequest;
use Modules\CpqConfigurator\Http\Resources\CpqInstanceResource;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Services\ConfigurationConfirmationService;
use Modules\CpqConfigurator\Services\ConfigurationEvaluator;
use Modules\CpqConfigurator\Services\ProposalBuilder;
use Modules\CpqConfigurator\Services\ProposalRenderer;
use Modules\CpqConfigurator\Support\CostRedactor;
use OpenApi\Attributes as OA;

/**
 * The guided configuration: live evaluation, confirmation (part number deduplicated by hash) and the proposal of a
 * selection, plus the configured instances and their proposal in JSON, HTML and PDF.
 */
final class ConfigurationController extends Controller
{
    public function __construct(
        private readonly ConfigurationEvaluator $evaluator,
        private readonly ConfigurationConfirmationService $confirmation,
        private readonly ProposalBuilder $proposals,
        private readonly ProposalRenderer $renderer,
    ) {}

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/evaluate', operationId: 'evaluateCpqConfiguration', summary: 'Evaluate a selection: constraints, super-BOM, routing, cost, price, margin, lead time, visual layers', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Evaluation')])]
    public function evaluate(EvaluateRequest $request, string $id): JsonResponse
    {
        $model = $this->usableModel($request, $id);
        $result = $this->evaluator->evaluateModel($model, $request->selections(), $request->quantity(), $request->discount());

        return response()->json(['data' => $this->visible($request, $result)]);
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/confirm', operationId: 'confirmCpqConfiguration', summary: 'Confirm a configuration: reuse the part number of an identical one or issue a new one', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 201, description: 'New instance'), new OA\Response(response: 200, description: 'Existing instance reused')])]
    public function confirm(EvaluateRequest $request, string $id): JsonResponse
    {
        $model = $this->usableModel($request, $id);
        $outcome = $this->confirmation->confirm($model, $request->selections(), $request->quantity(), $request->discount(), $request->validated('customer_reference'), (string) $request->user()?->id, app()->getLocale());

        return (new CpqInstanceResource($outcome['instance']))
            ->additional(['meta' => ['reused' => $outcome['reused']]])
            ->response()
            ->setStatusCode($outcome['reused'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/proposal', operationId: 'previewCpqProposal', summary: 'Technical-commercial proposal of a selection that is not confirmed', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Proposal dossier and HTML')])]
    public function proposal(EvaluateRequest $request, string $id): JsonResponse
    {
        $model = $this->usableModel($request, $id);
        $dossier = $this->proposals->forSelections($model, $request->selections(), $request->quantity(), $request->discount(), $this->withBreakdown($request), null, $request->validated('customer_reference'));

        return response()->json(['data' => ['dossier' => $dossier, 'html' => $this->renderer->html($dossier)]]);
    }

    #[OA\Get(path: '/api/v1/cpq-configurator/instances', operationId: 'listCpqInstances', summary: 'List the configured instances', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Instances')])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CpqConfiguredInstance::class);

        $instances = CpqConfiguredInstance::query()
            ->when($request->filled('model_id'), static fn ($query) => $query->where('model_id', (string) $request->query('model_id')))
            ->when($request->filled('search'), static fn ($query) => $query->where('part_number', 'like', '%'.(string) $request->query('search').'%'))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return CpqInstanceResource::collection($instances);
    }

    #[OA\Get(path: '/api/v1/cpq-configurator/instances/{id}', operationId: 'showCpqInstance', summary: 'Show a configured instance', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Instance')])]
    public function show(string $id): CpqInstanceResource
    {
        $instance = CpqConfiguredInstance::query()->findOrFail($id);
        $this->authorize('view', $instance);

        return new CpqInstanceResource($instance);
    }

    #[OA\Get(path: '/api/v1/cpq-configurator/instances/{id}/proposal', operationId: 'showCpqInstanceProposal', summary: 'Technical-commercial proposal of a confirmed instance', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Proposal dossier and HTML')])]
    public function instanceProposal(Request $request, string $id): JsonResponse
    {
        $dossier = $this->instanceDossier($request, $id);

        return response()->json(['data' => ['dossier' => $dossier, 'html' => $this->renderer->html($dossier)]]);
    }

    #[OA\Get(path: '/api/v1/cpq-configurator/instances/{id}/proposal/pdf', operationId: 'downloadCpqInstanceProposal', summary: 'PDF of the proposal of a confirmed instance', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'PDF document')])]
    public function instanceProposalPdf(Request $request, string $id): Response
    {
        $dossier = $this->instanceDossier($request, $id);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $dossier['document']['reference']);

        return response($this->renderer->pdf($dossier), Response::HTTP_OK, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="proposal-'.$name.'.pdf"']);
    }

    /**
     * @return array<string, mixed>
     */
    private function instanceDossier(Request $request, string $id): array
    {
        $instance = CpqConfiguredInstance::query()->findOrFail($id);
        $this->authorize('configure', $instance);

        return $this->proposals->forInstance(
            $instance,
            max((float) $request->query('quantity', 1), 0.0001),
            min(max((float) $request->query('discount_percent', 0), 0), 100),
            $request->boolean('include_breakdown') && $request->user()?->can('viewCosts', CpqConfiguredInstance::class) === true,
            null,
            $request->filled('customer_reference') ? (string) $request->query('customer_reference') : null,
        );
    }

    private function usableModel(Request $request, string $id): CpqModel
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('configure', $model);

        abort_if($model->status === CpqModel::Archived || ($model->status === CpqModel::Draft && ! $request->user()?->can('manage', $model)), Response::HTTP_NOT_FOUND);

        return $model;
    }

    private function withBreakdown(Request $request): bool
    {
        return $request->boolean('include_breakdown') && $request->user()?->can('viewCosts', CpqConfiguredInstance::class) === true;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function visible(Request $request, array $result): array
    {
        return $request->user()?->can('viewCosts', CpqConfiguredInstance::class) === true ? $result : CostRedactor::evaluation($result);
    }
}
