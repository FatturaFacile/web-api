<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use Database\Seeders\Translations\PlmFrontendTranslations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Plm\CQRS\Commands\ApproveEcoAndExecuteRevisionCutoverCommand;
use Modules\Plm\CQRS\Commands\ImportCadBomAndCompareWithMbomCommand;
use Modules\Plm\CQRS\Handlers\ApproveEcoAndExecuteRevisionCutoverHandler;
use Modules\Plm\CQRS\Handlers\GetBomRevisionDiffAndCostRollupHandler;
use Modules\Plm\CQRS\Handlers\GetWhereUsedImpactHandler;
use Modules\Plm\CQRS\Handlers\ImportCadBomAndCompareWithMbomHandler;
use Modules\Plm\CQRS\Queries\GetBomRevisionDiffAndCostRollupQuery;
use Modules\Plm\CQRS\Queries\GetWhereUsedImpactQuery;
use Modules\Plm\Enums\ApprovalDepartment;
use Modules\Plm\Enums\CadSourceSystem;
use Modules\Plm\Enums\ChangeReason;
use Modules\Plm\Enums\EcoWorkflowStage;
use Modules\Plm\Enums\RevisionLifecycleState;
use Modules\Plm\Enums\StockDispositionRule;
use Modules\Plm\Http\Controllers\Concerns\InteractsWithPlm;
use Modules\Plm\Http\Requests\CadImportRequest;
use Modules\Plm\Http\Requests\ComplianceUpdateRequest;
use Modules\Plm\Http\Requests\DepartmentSignoffRequest;
use Modules\Plm\Http\Resources\CadImportSessionResource;
use Modules\Plm\Http\Resources\ChangeOrderResource;
use Modules\Plm\Models\PlmCadImportSession;
use Modules\Plm\Models\PlmChangeOrder;
use Modules\Plm\Models\PlmItemRevision;
use Modules\Plm\Models\PlmTechnicalDossier;
use Modules\Plm\Services\ChangeOrderService;
use Modules\Plm\Strategies\PlmRegulatoryComplianceStrategyFactory;
use OpenApi\Attributes as OA;

/**
 * PLM Mod 168 engineering endpoints: dictionary, departmental sign-offs, stage transitions,
 * revision cutover execution, CAD-to-mBOM import, multi-level BOM diff & cost roll-up,
 * multi-level Where-Used impact, CE/UKCA/OMacch compliance dossier, and 3D/PDF viewer manifest.
 */
final class PlmEngineeringController extends Controller
{
    use InteractsWithPlm;

    #[OA\Get(path: '/api/v1/plm/dictionary', operationId: 'getPlmDictionary', description: 'Localized PLM dictionary and enum metadata for it-CH, de-CH, fr-CH, en, it-IT.', summary: 'Get PLM dictionary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], responses: [
        new OA\Response(response: 200, description: 'PLM dictionary'),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
    ])]
    public function dictionary(Request $request): JsonResponse
    {
        $requested = (string) ($request->query('locale') ?? app()->getLocale());
        $normalized = Translation::normalizeLocale($requested);
        $base = Translation::baseLocale($normalized);
        $dictionary = PlmFrontendTranslations::all($base);

        if ($normalized === 'de-CH') {
            $dictionary = array_map(static fn (string $v): string => str_replace(['ß', 'ẞ'], ['ss', 'SS'], $v), $dictionary);
        }

        return response()->json([
            'data' => [
                'locale' => $normalized,
                'translations' => $dictionary,
                'enums' => [
                    'lifecycle_states' => RevisionLifecycleState::values(),
                    'workflow_stages' => EcoWorkflowStage::values(),
                    'change_reasons' => ChangeReason::values(),
                    'stock_disposition_rules' => StockDispositionRule::values(),
                    'approval_departments' => ApprovalDepartment::values(),
                    'cad_source_systems' => CadSourceSystem::values(),
                ],
            ],
        ]);
    }

    #[OA\Post(path: '/api/v1/plm/change-orders/{id}/advance-stage', operationId: 'advancePlmChangeOrderStage', summary: 'Advance ECR/ECO/ECN workflow stage', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Stage updated'),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function advanceStage(Request $request, string $id, ChangeOrderService $service): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $order);
        $stage = EcoWorkflowStage::from((string) $request->input('workflow_stage'));

        return new ChangeOrderResource($service->advanceStage($order, $stage));
    }

    #[OA\Post(path: '/api/v1/plm/change-orders/{id}/department-signoff', operationId: 'signoffPlmChangeOrderDepartment', summary: 'Record departmental electronic approval on ECO', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Sign-off recorded'),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function departmentSignoff(DepartmentSignoffRequest $request, string $id, ChangeOrderService $service): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $order);
        $data = $request->validated();

        return new ChangeOrderResource($service->recordDepartmentSignoff(
            $order,
            (string) $this->userId(),
            ApprovalDepartment::from((string) $data['department']),
            (string) $data['decision'],
            $data['comment'] ?? null,
            $data['actor_role'] ?? null,
        ));
    }

    #[OA\Post(path: '/api/v1/plm/change-orders/{id}/execute-cutover', operationId: 'executePlmEcoCutover', summary: 'Execute approved ECO revision cutover (Mod 059/060/061)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Cutover executed'),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function executeCutover(Request $request, string $id, ApproveEcoAndExecuteRevisionCutoverHandler $handler): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $order);

        $updated = $handler->handle(new ApproveEcoAndExecuteRevisionCutoverCommand(
            tenantId: $this->tenantId(),
            changeOrderId: (string) $order->id,
            actorUserId: $this->userId(),
            forceImmediateSafetyCutover: (bool) $request->boolean('force_immediate_safety_cutover', false),
            lotSerialCutoverCode: $request->input('lot_serial_cutover_code') !== null ? (string) $request->input('lot_serial_cutover_code') : null,
        ));

        return new ChangeOrderResource($updated);
    }

    #[OA\Get(path: '/api/v1/plm/cad-imports', operationId: 'listPlmCadImports', summary: 'List CAD-to-mBOM import sessions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], responses: [
        new OA\Response(response: 200, description: 'CAD import sessions'),
    ])]
    public function listCadImports(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlmCadImportSession::class);

        return CadImportSessionResource::collection(
            PlmCadImportSession::query()
                ->with('product:id,item_code,name')
                ->where('tenant_id', $this->tenantId())
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/plm/cad-imports', operationId: 'importPlmCadBom', summary: 'Import CAD BOM (XML/CSV/STEP) and compare eBOM vs mBOM', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], responses: [
        new OA\Response(response: 201, description: 'CAD import session created'),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function importCad(CadImportRequest $request, ImportCadBomAndCompareWithMbomHandler $handler): JsonResponse
    {
        $this->authorize('create', PlmCadImportSession::class);
        $data = $request->validated();

        $session = $handler->handle(new ImportCadBomAndCompareWithMbomCommand(
            tenantId: $this->tenantId(),
            userId: $this->userId(),
            cadSourceSystem: (string) $data['cad_source_system'],
            productCode: (string) $data['product_code'],
            revisionCode: (string) ($data['revision_code'] ?? 'A'),
            rawPayload: $data['raw_payload'] ?? null,
            payloadFormat: $data['payload_format'] ?? null,
            parts: $data['parts'] ?? null,
            sourceFileMetadata: $data['source_file_metadata'] ?? [],
            cadAttachments: $data['cad_attachments'] ?? [],
            autoCreateMissingParts: (bool) ($data['auto_create_missing_parts'] ?? true),
            commitToMbom: (bool) ($data['commit_to_mbom'] ?? false),
            bomName: $data['bom_name'] ?? null,
        ));

        return (new CadImportSessionResource($session))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/plm/cad-imports/{id}', operationId: 'showPlmCadImport', summary: 'Show CAD import session', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'CAD import session'),
    ])]
    public function showCadImport(string $id): CadImportSessionResource
    {
        $session = PlmCadImportSession::query()->with(['product:id,item_code,name', 'targetRevision', 'targetMbom'])->findOrFail($id);
        $this->authorize('view', $session);

        return new CadImportSessionResource($session);
    }

    #[OA\Post(path: '/api/v1/plm/cad-imports/{id}/commit', operationId: 'commitPlmCadImport', summary: 'Commit CAD eBOM import session to Mod 059 mBOM', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Committed to mBOM'),
    ])]
    public function commitCadImport(string $id, BillOfMaterialsServiceInterface $boms): CadImportSessionResource
    {
        $session = PlmCadImportSession::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $session);

        if ($session->product_id !== null) {
            $components = [];
            foreach ($session->parsed_part_list_json as $part) {
                $item = MfgItem::query()->where('tenant_id', $this->tenantId())->where('item_code', (string) ($part['item_code'] ?? ''))->first();
                if ($item instanceof MfgItem && (string) $item->id !== (string) $session->product_id) {
                    $components[] = ['component_item_id' => (string) $item->id, 'quantity_per' => (string) ($part['quantity'] ?? '1')];
                }
            }

            if ($components !== []) {
                $existing = MfgBom::query()->where('tenant_id', $this->tenantId())->where('mfg_item_id', $session->product_id)->where('status', 'draft')->first();
                if ($existing instanceof MfgBom) {
                    $bom = $boms->updateDraft($existing, ['components' => $components], $this->userId());
                } else {
                    $bom = $boms->createBom(new CreateBomDTO(
                        tenantId: $this->tenantId(),
                        mfgItemId: (string) $session->product_id,
                        name: (string) ($session->product->name ?? $session->session_code),
                        components: $components,
                        revision: $session->targetRevision->revision ?? 'A',
                    ), $this->userId());
                }

                $session->update(['target_mbom_id' => $bom->id, 'status' => 'Committed', 'committed_at' => now()]);
                if ($session->targetRevision instanceof PlmItemRevision) {
                    $session->targetRevision->update(['mbom_id' => $bom->id]);
                }
            }
        }

        return new CadImportSessionResource($session->refresh()->load(['product:id,item_code,name', 'targetRevision', 'targetMbom']));
    }

    #[OA\Get(path: '/api/v1/plm/where-used/{itemId}', operationId: 'getPlmWhereUsedImpact', summary: 'Multi-level Where-Used impact (parents, open POs, production orders, stock lots)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Where-used impact'),
    ])]
    public function whereUsedImpact(Request $request, string $itemId, GetWhereUsedImpactHandler $handler): JsonResponse
    {
        $this->authorize('viewAny', PlmItemRevision::class);

        return response()->json([
            'data' => $handler->handle(new GetWhereUsedImpactQuery(
                tenantId: $this->tenantId(),
                mfgItemId: $itemId,
                date: $request->query('date') !== null ? (string) $request->query('date') : null,
            )),
        ]);
    }

    #[OA\Get(path: '/api/v1/plm/bom-diff', operationId: 'getPlmBomDiffAndCostRollup', summary: 'Multi-level BOM diff and Rev A vs Rev B cost roll-up', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], responses: [
        new OA\Response(response: 200, description: 'BOM diff and cost roll-up'),
    ])]
    public function bomDiff(Request $request, GetBomRevisionDiffAndCostRollupHandler $handler): JsonResponse
    {
        $this->authorize('viewAny', PlmItemRevision::class);

        return response()->json([
            'data' => $handler->handle(new GetBomRevisionDiffAndCostRollupQuery(
                tenantId: $this->tenantId(),
                fromRevisionId: $request->query('from_revision_id') !== null ? (string) $request->query('from_revision_id') : null,
                toRevisionId: $request->query('to_revision_id') !== null ? (string) $request->query('to_revision_id') : null,
                fromBomId: $request->query('from_bom_id') !== null ? (string) $request->query('from_bom_id') : null,
                toBomId: $request->query('to_bom_id') !== null ? (string) $request->query('to_bom_id') : null,
                revisionIdForEbomVsMbom: $request->query('revision_id') !== null ? (string) $request->query('revision_id') : null,
            )),
        ]);
    }

    #[OA\Get(path: '/api/v1/plm/revisions/{id}/compliance', operationId: 'getPlmRevisionCompliance', summary: 'Evaluate CE/UKCA/OMacch technical dossier and RoHS/REACH/Conflict Minerals compliance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Compliance evaluation'),
    ])]
    public function getCompliance(Request $request, string $id, PlmRegulatoryComplianceStrategyFactory $factory): JsonResponse
    {
        $revision = PlmItemRevision::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $revision);
        $strategy = $factory->resolve($request->query('strategy') !== null ? (string) $request->query('strategy') : null);

        return response()->json(['data' => $strategy->evaluateDossierAndBomCompliance($revision)]);
    }

    #[OA\Post(path: '/api/v1/plm/revisions/{id}/compliance', operationId: 'updatePlmRevisionCompliance', summary: 'Update CE/UKCA/OMacch technical dossier and compliance flags', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Compliance updated'),
    ])]
    public function updateCompliance(ComplianceUpdateRequest $request, string $id, PlmRegulatoryComplianceStrategyFactory $factory): JsonResponse
    {
        $revision = PlmItemRevision::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $revision);
        $data = $request->validated();

        $revision->update(array_intersect_key($data, array_flip(['rohs_and_reach_compliant', 'conflict_minerals_compliant', 'technical_file_ref'])));

        $strategy = $factory->resolve($data['strategy_code'] ?? null);
        $evaluation = $strategy->evaluateDossierAndBomCompliance($revision);

        PlmTechnicalDossier::updateOrCreate(
            [
                'tenant_id' => $this->tenantId(),
                'revision_id' => $revision->id,
            ],
            [
                'mfg_item_id' => $revision->mfg_item_id,
                'dossier_code' => $data['dossier_code'] ?? ('TECH-'.strtoupper(substr((string) $revision->id, 0, 8))),
                'strategy_code' => $strategy->strategyCode(),
                'machinery_directive_ref' => $data['machinery_directive_ref'] ?? '2006/42/EC + EU 2023/1230 + CH OMacch (RS 819.14)',
                'ce_declaration_status' => $data['ce_declaration_status'] ?? ($evaluation['overall_compliant'] ? 'compliant' : 'in_progress'),
                'ukca_declaration_status' => $data['ukca_declaration_status'] ?? ($evaluation['overall_compliant'] ? 'compliant' : 'in_progress'),
                'omacch_mra_ch_eu_compliant' => (bool) ($data['omacch_mra_ch_eu_compliant'] ?? $evaluation['omacch_mra_ch_eu_compliant']),
                'rohs_compliant' => $evaluation['rohs_compliant'],
                'reach_svhc_compliant' => $evaluation['reach_compliant'],
                'conflict_minerals_compliant' => $evaluation['conflict_minerals_compliant'],
                'iso_audit_standards_json' => ['ISO_9001', 'IATF_16949', 'ISO_13485'],
                'checklist_items_json' => $data['checklist_items_json'] ?? $evaluation['checklist'],
                'bom_compliance_rollup_json' => $evaluation,
                'last_evaluated_at' => now(),
            ]
        );

        return response()->json(['data' => $strategy->evaluateDossierAndBomCompliance($revision->refresh())]);
    }

    #[OA\Get(path: '/api/v1/plm/revisions/{id}/viewer-manifest', operationId: 'getPlmRevisionViewerManifest', summary: 'Get 3D STEP/STL & PDF/DXF drawing viewer manifest with MES obsolescence watermark state', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Viewer manifest'),
    ])]
    public function viewerManifest(string $id): JsonResponse
    {
        $revision = PlmItemRevision::query()->with('item:id,item_code,name')->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $revision);
        $isObsolete = $revision->isObsoleteForMes();

        return response()->json([
            'data' => [
                'revision_id' => (string) $revision->id,
                'item_code' => $revision->item->item_code ?? $revision->engineering_part_number,
                'item_name' => $revision->item->name ?? $revision->description,
                'revision_code' => $revision->revision,
                'lifecycle_state' => $revision->lifecycle_state ?? $revision->lifecycleStateEnum()->value,
                'is_obsolete' => $isObsolete,
                'obsolete_watermark' => $isObsolete,
                'watermark_label' => $isObsolete ? 'SUPERATO / OBSOLETO' : null,
                'technical_file_ref' => $revision->technical_file_ref,
                'attachments' => $revision->cad_drawing_pdf_and_step_file_ids ?? [],
                'ebom_parts' => $revision->ebom_structure_json['parts'] ?? [],
            ],
        ]);
    }
}
