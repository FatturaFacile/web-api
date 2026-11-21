<?php

declare(strict_types=1);

use App\Contracts\MobilePushGateway;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionApprovalLog;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionApprovalRequestedMail;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionApprovedMail;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionBudgetBlockedMail;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionBudgetWarningMail;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionRejectedMail;
use App\Models\MobileDeviceSession;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\Supplier;

uses(RefreshDatabase::class);

/**
 * In-memory spy capturing outbound mobile push notifications dispatched through MobilePushGateway.
 */
final class RecordingMobilePushGateway implements MobilePushGateway
{
    /** @var list<array{provider: string, token: string, title: string, body: string, data: array<string, mixed>, options: array<string, mixed>}> */
    public array $messages = [];

    public function send(string $provider, string $token, string $title, string $body, array $data = [], array $options = []): bool
    {
        $this->messages[] = [
            'provider' => $provider,
            'token' => $token,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'options' => $options,
        ];

        return true;
    }
}

/**
 * Creates a tenant with owner, requester, department manager, division director, CFO, HR cost center, project, supplier, and product.
 *
 * @return array{
 *     tenant: Tenant,
 *     owner: User,
 *     requester: User,
 *     deptManager: User,
 *     divDirector: User,
 *     cfo: User,
 *     buyer: User,
 *     costCenterId: string,
 *     projectId: string,
 *     supplier: Supplier,
 *     product: Product,
 *     pushGateway: RecordingMobilePushGateway
 * }
 */
function setupMod044Tenant(
    string $budgetPolicy = 'warning',
    string $annualBudget = '50000.00',
    string $country = 'CH',
): array {
    $pushGateway = new RecordingMobilePushGateway;
    app()->instance(MobilePushGateway::class, $pushGateway);

    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'country' => $country,
        'default_currency' => 'CHF',
    ]);

    ProcurementSetting::query()->create([
        'tenant_id' => $tenant->id,
        'budget_enforcement_policy' => $budgetPolicy,
    ]);

    $createMember = function (string $name, string $role, string $locale = 'en') use ($tenant): User {
        /** @var User $user */
        $user = User::factory()->create([
            'name' => $name,
            'default_tenant_id' => $tenant->id,
            'locale' => $locale,
        ]);
        $tenant->users()->attach($user->id, [
            'role' => $role,
            'is_default' => true,
        ]);
        MobileDeviceSession::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'device_id' => 'dev-'.Str::uuid()->toString(),
            'device_name' => "{$name} Phone",
            'platform' => MobileDeviceSession::PLATFORM_IOS,
            'push_provider' => 'fcm',
            'push_token' => 'push-token-'.$user->id,
            'is_revoked' => false,
            'last_active_at' => now(),
        ]);

        return $user;
    };

    $owner = $createMember('Tenant Owner', 'owner', 'en');
    $requester = $createMember('Mario Rossi', 'member', 'it');
    $deptManager = $createMember('Hans Mueller', 'department_manager', 'de');
    $divDirector = $createMember('Claire Laurent', 'division_director', 'fr');
    $cfo = $createMember('Chief Financial Officer', 'cfo', 'en');
    $buyer = $createMember('Procurement Buyer', 'procurement_manager', 'en');

    // Assign reporting manager relationship in HR / reporting hierarchy if employee record is not needed:
    // role-based Mod 006 routing picks department_manager / division_director / cfo cleanly.
    $costCenterId = (string) Str::uuid();
    DB::table('hr_cost_centers')->insert([
        'id' => $costCenterId,
        'tenant_id' => $tenant->id,
        'code' => 'CC-OPS-01',
        'name' => 'Operations & Engineering',
        'annual_budget' => $annualBudget,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contactId = (string) Str::uuid();
    DB::table('contacts')->insert([
        'id' => $contactId,
        'tenant_id' => $tenant->id,
        'name' => 'Internal Client Contact',
        'type' => 'company',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = (string) Str::uuid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'tenant_id' => $tenant->id,
        'cost_center_id' => $costCenterId,
        'contact_id' => $contactId,
        'project_number' => 'PRJ-2026-001',
        'name' => 'Zurich Factory Automation',
        'status' => 'active',
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addMonths(6)->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var Supplier $supplier */
    $supplier = Supplier::query()->create([
        'tenant_id' => $tenant->id,
        'supplier_code' => 'SUP-'.strtoupper(Str::random(5)),
        'company_name' => 'Swiss Precision AG',
        'currency' => 'CHF',
        'status' => 'active',
        'is_approved_vendor' => true,
        'is_locked' => false,
    ]);

    /** @var Product $product */
    $product = Product::query()->create([
        'tenant_id' => $tenant->id,
        'sku' => 'SKU-CNC-400',
        'name' => 'Precision Linear Actuator',
        'description' => 'Precision Linear Actuator 24V',
        'unit' => 'PCS',
        'purchase_price' => '250.00',
        'sale_price' => '400.00',
        'is_active' => true,
    ]);

    return [
        'tenant' => $tenant,
        'owner' => $owner,
        'requester' => $requester,
        'deptManager' => $deptManager,
        'divDirector' => $divDirector,
        'cfo' => $cfo,
        'buyer' => $buyer,
        'costCenterId' => $costCenterId,
        'projectId' => $projectId,
        'supplier' => $supplier,
        'product' => $product,
        'pushGateway' => $pushGateway,
    ];
}

test('full CRUD, catalog and free-text resolution, filtering, pagination, and draft-only delete rules', function (): void {
    Mail::fake();
    $fx = setupMod044Tenant();

    // 1. Create PR with mixed catalog_item + free_text lines + project link
    $createResponse = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fx['costCenterId'],
            'project_id' => $fx['projectId'],
            'urgency' => 'high',
            'requested_delivery_date' => '2026-12-15',
            'economic_justification' => 'Line retrofit spare components',
            'currency' => 'CHF',
            'lines' => [
                [
                    'item_type' => 'catalog_item',
                    'catalog_item_id' => $fx['product']->id,
                    'quantity' => 2,
                ],
                [
                    'item_type' => 'free_text',
                    'description' => 'Custom anodized mounting bracket',
                    'quantity' => 5,
                    'unit_of_measure' => 'SET',
                    'estimated_unit_price' => 80.00,
                    'project_id' => $fx['projectId'],
                ],
            ],
        ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.currency', 'CHF')
        ->assertJsonPath('data.total_estimated_amount', '900.00')
        ->assertJsonPath('data.project.project_number', 'PRJ-2026-001');

    $prId = (string) $createResponse->json('data.id');
    $prNumber = (string) $createResponse->json('data.requisition_number');
    expect($prNumber)->toMatch('/^PR-\d{4}-\d{4,}$/');

    // 2. Filter & paginated index check
    $listResponse = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson("/api/v1/procurement/purchase-requisitions?status=draft&urgency=high&project_id={$fx['projectId']}&search=bracket");

    $listResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $prId);

    // 3. Show detail
    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson("/api/v1/procurement/purchase-requisitions/{$prId}")
        ->assertOk()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.approval_steps', []);

    // 4. Update in draft
    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->putJson("/api/v1/procurement/purchase-requisitions/{$prId}", [
            'urgency' => 'critical',
            'lines' => [
                [
                    'item_type' => 'free_text',
                    'description' => 'Updated assembly kit',
                    'quantity' => 4,
                    'unit_of_measure' => 'SET',
                    'estimated_unit_price' => 150.00,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.urgency', 'critical')
        ->assertJsonPath('data.total_estimated_amount', '600.00');

    // 5. Submit PR -> cannot update or delete when pending_approval
    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/submit", ['channel' => 'web'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval');

    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->putJson("/api/v1/procurement/purchase-requisitions/{$prId}", ['title' => 'Attempt Edit'])
        ->assertStatus(422);

    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->deleteJson("/api/v1/procurement/purchase-requisitions/{$prId}")
        ->assertStatus(422);

    // 6. Draft PR can be deleted cleanly
    $draft2 = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fx['costCenterId'],
            'currency' => 'CHF',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Disposable draft', 'quantity' => 1, 'estimated_unit_price' => 50],
            ],
        ])
        ->assertCreated();

    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->deleteJson('/api/v1/procurement/purchase-requisitions/'.$draft2->json('data.id'))
        ->assertOk();
});

test('real-time budget preview and hard_block vs warning enforcement policies', function (): void {
    // Part A: hard_block policy (annual budget 500.00 CHF)
    Mail::fake();
    $fx = setupMod044Tenant(budgetPolicy: 'hard_block', annualBudget: '500.00');

    // Live preview over-budget without mutating DB
    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions/budget-preview', [
            'cost_center_id' => $fx['costCenterId'],
            'currency' => 'CHF',
            'fiscal_year' => now()->year,
            'lines' => [
                ['quantity' => 2, 'estimated_unit_price' => 400.00],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.is_over_budget', true)
        ->assertJsonPath('data.enforcement_policy', 'hard_block')
        ->assertJsonPath('data.overage_amount', '300.00');

    expect(PurchaseRequisition::query()->count())->toBe(0);

    // Create over-budget PR (800.00 CHF > 500.00 CHF) and submit -> blocked!
    $prRes = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fx['costCenterId'],
            'currency' => 'CHF',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Over-budget generator', 'quantity' => 2, 'estimated_unit_price' => 400.00],
            ],
        ])
        ->assertCreated();

    $prId = (string) $prRes->json('data.id');

    $submitBlocked = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/submit", ['channel' => 'web']);

    $submitBlocked->assertOk()
        ->assertJsonPath('budget_blocked', true)
        ->assertJsonPath('data.status', 'budget_exceeded_blocked')
        ->assertJsonPath('data.budget_check_snapshot.is_over_budget', true);

    Mail::assertQueued(RequisitionBudgetBlockedMail::class, static fn ($mail): bool => $mail->hasTo($fx['requester']->email));
    expect($fx['pushGateway']->messages)->not->toBeEmpty()
        ->and($fx['pushGateway']->messages[0]['data']['type'])->toBe('purchase_requisition_budget_blocked');

    // Edit budget_exceeded_blocked PR down to 450.00 CHF and re-submit -> succeeds!
    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->putJson("/api/v1/procurement/purchase-requisitions/{$prId}", [
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Scaled generator', 'quantity' => 1, 'estimated_unit_price' => 450.00],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');

    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/submit", ['channel' => 'web'])
        ->assertOk()
        ->assertJsonPath('budget_blocked', false)
        ->assertJsonPath('data.status', 'pending_approval');

    // Part B: warning policy flags over-budget, notifies requester, and proceeds to pending_approval
    Mail::fake();
    $fxWarn = setupMod044Tenant(budgetPolicy: 'warning', annualBudget: '500.00');

    $warnPr = $this->actingAs($fxWarn['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fxWarn['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fxWarn['costCenterId'],
            'currency' => 'CHF',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Emergency pump', 'quantity' => 1, 'estimated_unit_price' => 750.00],
            ],
        ])
        ->assertCreated();

    $warnPrId = (string) $warnPr->json('data.id');

    $this->actingAs($fxWarn['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fxWarn['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$warnPrId}/submit", ['channel' => 'mobile'])
        ->assertOk()
        ->assertJsonPath('budget_blocked', false)
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.budget_warning_acknowledged', true)
        ->assertJsonPath('data.budget_check_snapshot.overage_amount', '250.00');

    Mail::assertQueued(RequisitionBudgetWarningMail::class);
    Mail::assertQueued(RequisitionApprovalRequestedMail::class);
});

test('multi-level CHF/EUR delegation routing, four-eyes rule, pending inbox, and rejection', function (): void {
    Mail::fake();
    $fx = setupMod044Tenant(annualBudget: '100000.00');

    // Mid-tier EUR requisition (3,500 EUR => Level 1 Dept Manager + Level 2 Division Director)
    $prRes = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fx['costCenterId'],
            'currency' => 'EUR',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Industrial PLC Controller', 'quantity' => 7, 'estimated_unit_price' => 500.00],
            ],
        ])
        ->assertCreated();

    $prId = (string) $prRes->json('data.id');

    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/submit", ['channel' => 'web'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.current_approval_level', 1);

    // Pending approvals inbox visible to Dept Manager, NOT Div Director yet
    $this->actingAs($fx['deptManager'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson('/api/v1/procurement/purchase-requisitions/pending-approvals')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $prId);

    $this->actingAs($fx['divDirector'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson('/api/v1/procurement/purchase-requisitions/pending-approvals')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Four-eyes check: requester cannot approve own PR even with elevated workflow privilege
    $fx['tenant']->users()->updateExistingPivot($fx['requester']->id, ['role' => 'department_manager']);
    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/approve", ['channel' => 'web'])
        ->assertStatus(422);

    // Wrong role (CFO trying to decide Level 1 assigned to department_manager) is rejected
    $this->actingAs($fx['cfo'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/approve", ['channel' => 'web'])
        ->assertStatus(422);

    // Level 1 Dept Manager approves -> advances to Level 2 Div Director
    $this->actingAs($fx['deptManager'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/approve", ['channel' => 'web', 'comment' => 'L1 approved'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.current_approval_level', 2);

    // Div Director sees it in pending-approvals
    $this->actingAs($fx['divDirector'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson('/api/v1/procurement/purchase-requisitions/pending-approvals')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Div Director approves Level 2 -> fully approved!
    $this->actingAs($fx['divDirector'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/approve", ['channel' => 'mobile', 'comment' => 'L2 OK'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    Mail::assertQueued(RequisitionApprovedMail::class);

    // Verify immutable audit logs captured budget_checked, submitted, L1 approved, L2 approved
    $logs = PurchaseRequisitionApprovalLog::query()
        ->where('purchase_requisition_id', $prId)
        ->orderBy('acted_at')
        ->get();

    expect($logs->pluck('action')->all())->toBe(['budget_checked', 'submitted', 'approved', 'approved'])
        ->and($logs->pluck('channel')->all())->toBe(['web', 'web', 'web', 'mobile']);

    // High-tier CHF (> 10,000 CHF => 3 tiers including CFO) rejected at Level 1 requires comment and marks rejected
    $highPr = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fx['costCenterId'],
            'currency' => 'CHF',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'CNC Milling Center', 'quantity' => 1, 'estimated_unit_price' => 15000.00],
            ],
        ])
        ->assertCreated();

    $highPrId = (string) $highPr->json('data.id');
    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$highPrId}/submit")
        ->assertOk();

    // Detail should show 3 approval tiers (Dept Manager -> Division Director -> CFO / CEO)
    $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson("/api/v1/procurement/purchase-requisitions/{$highPrId}")
        ->assertOk()
        ->assertJsonCount(3, 'data.approval_steps');

    // Rejection without comment fails 422
    $this->actingAs($fx['deptManager'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$highPrId}/reject", ['channel' => 'web'])
        ->assertStatus(422);

    // Valid rejection
    $this->actingAs($fx['deptManager'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$highPrId}/reject", [
            'channel' => 'web',
            'comment' => 'Postpone CapEx until Q2',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Postpone CapEx until Q2');

    Mail::assertQueued(RequisitionRejectedMail::class);
});

test('single-use signed one-click Email & Push tokens enforce replay, mismatch, tamper, and expiry protection', function (): void {
    Mail::fake();
    $fx = setupMod044Tenant(annualBudget: '100000.00');

    // 2,500 CHF PR (Level 1 + Level 2)
    $prRes = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fx['costCenterId'],
            'currency' => 'CHF',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Optical Sensor Array', 'quantity' => 5, 'estimated_unit_price' => 500.00],
            ],
        ])
        ->assertCreated();

    $prId = (string) $prRes->json('data.id');

    $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/submit", ['channel' => 'web'])
        ->assertOk();

    /** @var RequisitionApprovalRequestedMail $l1Mail */
    $l1Mail = Mail::queued(RequisitionApprovalRequestedMail::class)->first();
    $emailApproveToken = $l1Mail->approveToken;

    // 1. Tampered token rejected
    $this->postJson('/api/v1/procurement/purchase-requisitions/one-click-action', [
        'token' => $emailApproveToken.'tampered',
        'action' => 'approve',
    ])->assertStatus(422);

    // 2. Action mismatch (approve token sent with action=reject) rejected
    $this->postJson('/api/v1/procurement/purchase-requisitions/one-click-action', [
        'token' => $emailApproveToken,
        'action' => 'reject',
        'comment' => 'Mismatch test',
    ])->assertStatus(422);

    // 3. Valid 1-click email approve advances PR to Level 2 and records email_one_click channel
    $this->postJson('/api/v1/procurement/purchase-requisitions/one-click-action', [
        'token' => $emailApproveToken,
        'action' => 'approve',
        'comment' => 'Approved via email link',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.current_approval_level', 2);

    // 4. Replay of consumed email token is blocked
    $this->postJson('/api/v1/procurement/purchase-requisitions/one-click-action', [
        'token' => $emailApproveToken,
        'action' => 'approve',
    ])->assertStatus(422);

    // 5. Extract Level 2 push token from MobilePushGateway spy
    $pushMessages = array_values(array_filter(
        $fx['pushGateway']->messages,
        static fn (array $m): bool => ($m['data']['type'] ?? '') === 'purchase_requisition_approval_required'
            && ($m['data']['step_level'] ?? 0) === 2
    ));
    expect($pushMessages)->not->toBeEmpty();
    $l2PushApproveToken = (string) $pushMessages[0]['data']['approve_token'];

    // 6. Expiry test on a cloned expired timestamp: travel past TTL -> token rejected, travel back -> succeeds!
    $this->travel(49)->hours();
    $this->postJson('/api/v1/procurement/purchase-requisitions/one-click-action', [
        'token' => $l2PushApproveToken,
        'action' => 'approve',
    ])->assertStatus(422);
    $this->travelBack();

    // 7. Valid Push 1-click approve completes Level 2 with push_one_click channel in audit log
    $this->postJson('/api/v1/procurement/purchase-requisitions/one-click-action', [
        'token' => $l2PushApproveToken,
        'action' => 'approve',
        'comment' => 'Push 1-click ok',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $channels = PurchaseRequisitionApprovalLog::query()
        ->where('purchase_requisition_id', $prId)
        ->where('action', 'approved')
        ->orderBy('approval_step_level')
        ->pluck('channel')
        ->all();

    expect($channels)->toBe(['email_one_click', 'push_one_click']);
});

test('conversion blocks unapproved PRs, supports partial splitting, multi-PR grouping, and bidirectional Mod 045/046 links', function (): void {
    Mail::fake();
    $fx = setupMod044Tenant(annualBudget: '200000.00');

    // Create & submit PR-A (10 units @ 80 CHF = 800 CHF) -> approve L1
    $prARes = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fx['costCenterId'],
            'currency' => 'CHF',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Servo Motor 400W', 'quantity' => 10, 'unit_of_measure' => 'PCS', 'estimated_unit_price' => 80.00],
            ],
        ])->assertCreated();
    $prAId = (string) $prARes->json('data.id');
    $lineAId = (string) $prARes->json('data.lines.0.id');

    // Create draft PR-B (still draft -> conversion must be blocked!)
    $prBRes = $this->actingAs($fx['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fx['costCenterId'],
            'currency' => 'CHF',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Servo Cable 5m', 'quantity' => 6, 'unit_of_measure' => 'PCS', 'estimated_unit_price' => 50.00],
            ],
        ])->assertCreated();
    $prBId = (string) $prBRes->json('data.id');
    $lineBId = (string) $prBRes->json('data.lines.0.id');

    // Unapproved conversion blocked
    $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions/convert', [
            'target_type' => 'po',
            'supplier_id' => $fx['supplier']->id,
            'items' => [['requisition_line_id' => $lineAId, 'quantity' => 2]],
        ])->assertStatus(422);

    // Submit & approve PR-A and PR-B
    foreach ([$prAId, $prBId] as $id) {
        $this->actingAs($fx['requester'], 'sanctum')
            ->withHeader('X-Tenant-Id', $fx['tenant']->id)
            ->postJson("/api/v1/procurement/purchase-requisitions/{$id}/submit")
            ->assertOk();
        $this->actingAs($fx['deptManager'], 'sanctum')
            ->withHeader('X-Tenant-Id', $fx['tenant']->id)
            ->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve", ['channel' => 'web'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    // 1. Partial split + Multi-PR grouping into Mod 045 RFQ: 4 of 10 from PR-A + 2 of 6 from PR-B
    $rfqConvert = $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions/convert', [
            'target_type' => 'rfq',
            'supplier_ids' => [$fx['supplier']->id],
            'title' => 'Grouped Servo Sourcing RFQ',
            'items' => [
                ['requisition_line_id' => $lineAId, 'quantity' => 4],
                ['requisition_line_id' => $lineBId, 'quantity' => 2],
            ],
        ]);

    $rfqConvert->assertCreated()
        ->assertJsonPath('data.target_type', 'rfq')
        ->assertJsonCount(2, 'data.links');

    $rfqId = (string) $rfqConvert->json('data.target_document_id');

    // PR-A and PR-B are now partially_converted
    $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson("/api/v1/procurement/purchase-requisitions/{$prAId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_converted')
        ->assertJsonPath('data.lines.0.converted_quantity', '4.0000')
        ->assertJsonPath('data.lines.0.remaining_convertible_quantity', '6.0000')
        ->assertJsonPath('data.lines.0.conversion_status', 'partially_converted')
        ->assertJsonPath('data.document_links.0.target_document_type', 'rfq_mod_045');

    // Bidirectional link visible from Mod 045 RFQ endpoint
    $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson("/api/v1/procurement/rfqs/{$rfqId}")
        ->assertOk()
        ->assertJsonCount(2, 'data.source_requisition_links');

    // 2. Over-converting remaining quantity (> 6) fails 422
    $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions/convert', [
            'target_type' => 'po',
            'supplier_id' => $fx['supplier']->id,
            'items' => [['requisition_line_id' => $lineAId, 'quantity' => 7]],
        ])->assertStatus(422);

    // 3. Convert remaining quantities (6 from PR-A + 4 from PR-B) into Mod 046 PO
    $poConvert = $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions/convert', [
            'target_type' => 'po',
            'supplier_id' => $fx['supplier']->id,
            'items' => [
                ['requisition_line_id' => $lineAId, 'quantity' => 6],
                ['requisition_line_id' => $lineBId, 'quantity' => 4],
            ],
        ]);

    $poConvert->assertCreated()
        ->assertJsonPath('data.target_type', 'po');

    $poId = (string) $poConvert->json('data.target_document_id');

    // Both PR-A and PR-B are now fully converted
    $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson("/api/v1/procurement/purchase-requisitions/{$prAId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'converted')
        ->assertJsonPath('data.lines.0.conversion_status', 'fully_converted')
        ->assertJsonCount(2, 'data.document_links');

    // Bidirectional link visible on Mod 046 Purchase Order endpoint
    $this->actingAs($fx['buyer'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fx['tenant']->id)
        ->getJson("/api/v1/procurement/orders/{$poId}")
        ->assertOk()
        ->assertJsonCount(2, 'source_requisition_links');
});

test('strict multi-tenant isolation and 4-language (it, de, fr, en) validation and notification localization', function (): void {
    Mail::fake();
    $fxA = setupMod044Tenant();
    $fxB = setupMod044Tenant();

    // 4-language validation error localization on Accept-Language
    $expectedMessages = [
        'en' => 'Currency must be one of CHF or EUR.',
        'it' => 'La valuta deve essere CHF oppure EUR.',
        'de' => 'Die Währung muss CHF oder EUR sein.',
        'fr' => 'La devise doit être CHF ou EUR.',
    ];

    foreach ($expectedMessages as $locale => $expectedMessage) {
        $this->actingAs($fxA['requester'], 'sanctum')
            ->withHeader('X-Tenant-Id', $fxA['tenant']->id)
            ->withHeader('Accept-Language', $locale)
            ->postJson('/api/v1/procurement/purchase-requisitions', [
                'currency' => 'USD',
                'lines' => [
                    ['item_type' => 'free_text', 'description' => 'Item', 'quantity' => 1, 'estimated_unit_price' => 10],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.currency.0', $expectedMessage);
    }

    // Create PR in Tenant A & submit -> Dept Manager in Tenant A has locale 'de', check German Mail + Push!
    $prRes = $this->actingAs($fxA['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fxA['tenant']->id)
        ->postJson('/api/v1/procurement/purchase-requisitions', [
            'cost_center_id' => $fxA['costCenterId'],
            'currency' => 'CHF',
            'lines' => [
                ['item_type' => 'free_text', 'description' => 'Localized Part', 'quantity' => 2, 'estimated_unit_price' => 100.00],
            ],
        ])
        ->assertCreated();

    $prId = (string) $prRes->json('data.id');

    $this->actingAs($fxA['requester'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fxA['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/submit")
        ->assertOk();

    /** @var RequisitionApprovalRequestedMail $mail */
    $mail = Mail::queued(RequisitionApprovalRequestedMail::class)->first();
    expect($mail->envelope()->subject)->toContain('Genehmigung erforderlich')
        ->and($fxA['pushGateway']->messages[0]['title'])->toContain('wartet auf Ihre Genehmigung')
        ->and($fxA['pushGateway']->messages[0]['data']['actions'][0]['title'])->toBe('Genehmigen');

    // Tenant B user cannot access, update, delete, submit, or approve Tenant A's PR
    $this->actingAs($fxB['owner'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fxB['tenant']->id)
        ->getJson("/api/v1/procurement/purchase-requisitions/{$prId}")
        ->assertNotFound();

    $this->actingAs($fxB['owner'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fxB['tenant']->id)
        ->postJson("/api/v1/procurement/purchase-requisitions/{$prId}/approve", ['channel' => 'web'])
        ->assertNotFound();

    // Cross-tenant header spoofing using Tenant A's X-Tenant-Id by Tenant B user returns 403
    $this->actingAs($fxB['owner'], 'sanctum')
        ->withHeader('X-Tenant-Id', $fxA['tenant']->id)
        ->getJson("/api/v1/procurement/purchase-requisitions/{$prId}")
        ->assertForbidden();
});
