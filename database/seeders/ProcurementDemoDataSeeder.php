<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProcurementDemoDataSeeder extends Seeder
{
    /**
     * Run the procurement demo and test data seeder.
     */
    public function run(): void
    {
        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            $tenantId = (string) Str::uuid();
            DB::table('tenants')->updateOrInsert(
                ['name' => 'Demo Enterprise Tenant'],
                [
                    'id' => $tenantId,
                    'legal_name' => 'Demo Enterprise S.r.l.',
                    'country' => 'IT',
                    'default_currency' => 'EUR',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $tenants = Tenant::where('id', $tenantId)->get();
        }

        foreach ($tenants as $tenant) {
            $this->seedTenantProcurementDemo($tenant);
        }
    }

    private function seedTenantProcurementDemo(Tenant $tenant): void
    {
        $user = User::first();
        if (! $user) {
            $userId = (string) Str::uuid();
            DB::table('users')->updateOrInsert(
                ['email' => 'admin@procurement-demo.example'],
                [
                    'id' => $userId,
                    'name' => 'Enterprise Procurement Lead',
                    'password' => bcrypt('secret123'),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            if (Schema::hasTable('tenant_user')) {
                DB::table('tenant_user')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'user_id' => $userId],
                    ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                );
            }
        } else {
            $userId = (string) $user->id;
        }

        // 1. Get or create Supplier Category
        $catRow = DB::table('supplier_categories')
            ->where('tenant_id', $tenant->id)
            ->where('code', 'electrical')
            ->first();

        $categoryId = $catRow?->id;
        if (! $categoryId) {
            $categoryId = (string) Str::uuid();
            DB::table('supplier_categories')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'code' => 'electrical'],
                [
                    'id' => $categoryId,
                    'name' => 'Electrical & Electronics',
                    'description' => 'Semiconductors and industrial electronic components',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 2. Upsert Demo Suppliers
        $suppliersData = [
            [
                'code' => 'SUP-0001',
                'name' => 'Acme Electronics Ltd',
                'vat' => 'IT12345678901',
                'currency' => 'EUR',
                'payment_terms' => 'NET30',
                'incoterms' => 'DAP',
                'credit_limit' => 100000.00,
                'rating' => 'A',
                'is_approved' => true,
                'contact_name' => 'Marco Rossi',
                'contact_email' => 'm.rossi@acme-electronics.example',
                'contact_phone' => '+39 02 1234567',
            ],
            [
                'code' => 'SUP-0002',
                'name' => 'Apex Industrial Supplies GmbH',
                'vat' => 'DE987654321',
                'currency' => 'EUR',
                'payment_terms' => 'NET60',
                'incoterms' => 'CIP',
                'credit_limit' => 75000.00,
                'rating' => 'A',
                'is_approved' => true,
                'contact_name' => 'Hans Gruber',
                'contact_email' => 'h.gruber@apex-supplies.example',
                'contact_phone' => '+49 89 7654321',
            ],
            [
                'code' => 'SUP-0003',
                'name' => 'Global Freight & Logistics SpA',
                'vat' => 'IT55566677788',
                'currency' => 'EUR',
                'payment_terms' => 'NET30',
                'incoterms' => 'FCA',
                'credit_limit' => 30000.00,
                'rating' => 'B',
                'is_approved' => true,
                'contact_name' => 'Giulia Bianchi',
                'contact_email' => 'g.bianchi@globalfreight.example',
                'contact_phone' => '+39 06 9988776',
            ],
            [
                'code' => 'SUP-0004',
                'name' => 'FastTech Subcontractors SA',
                'vat' => 'CHE123456789TVA',
                'currency' => 'CHF',
                'payment_terms' => 'IMMEDIATE',
                'incoterms' => 'EXW',
                'credit_limit' => 15000.00,
                'rating' => 'B',
                'is_approved' => false,
                'contact_name' => 'Jean-Luc Picard',
                'contact_email' => 'j.picard@fasttech.example',
                'contact_phone' => '+41 22 3344556',
            ],
        ];

        $primarySupplierId = null;

        foreach ($suppliersData as $s) {
            $existingSupplier = DB::table('suppliers')
                ->where('tenant_id', $tenant->id)
                ->where('supplier_code', $s['code'])
                ->first();

            $supId = $existingSupplier?->id ?? (string) Str::uuid();

            // Ensure contact exists in contacts table to satisfy legacy FK constraint
            if (Schema::hasTable('contacts')) {
                DB::table('contacts')->updateOrInsert(
                    ['id' => $supId],
                    [
                        'tenant_id' => $tenant->id,
                        'type' => 'supplier',
                        'company_name' => $s['name'],
                        'email' => $s['contact_email'],
                        'phone' => $s['contact_phone'],
                        'vat_number' => $s['vat'],
                        'country' => 'IT',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            DB::table('suppliers')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'supplier_code' => $s['code'],
                ],
                [
                    'id' => $supId,
                    'contact_id' => $supId,
                    'company_name' => $s['name'],
                    'vat_number' => $s['vat'],
                    'fiscal_code' => $s['vat'],
                    'supplier_category_id' => $categoryId,
                    'currency' => $s['currency'],
                    'payment_terms_code' => $s['payment_terms'],
                    'incoterms' => $s['incoterms'],
                    'credit_limit' => $s['credit_limit'],
                    'rating' => $s['rating'],
                    'is_approved_vendor' => $s['is_approved'],
                    'status' => 'active',
                    'is_locked' => false,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Upsert Primary Contact
            $contactRow = DB::table('supplier_contacts')
                ->where('tenant_id', $tenant->id)
                ->where('supplier_id', $supId)
                ->where('email', $s['contact_email'])
                ->first();
            $contactId = $contactRow?->id ?? (string) Str::uuid();

            DB::table('supplier_contacts')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'supplier_id' => $supId,
                    'email' => $s['contact_email'],
                ],
                [
                    'id' => $contactId,
                    'name' => $s['contact_name'],
                    'phone' => $s['contact_phone'],
                    'role' => 'Sales Director',
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            if ($s['code'] === 'SUP-0001') {
                $primarySupplierId = $supId;
            }
        }

        if (! $primarySupplierId) {
            return;
        }

        // 3. Upsert Supplier Catalog & Price List
        $catalogRow = DB::table('supplier_catalogs')
            ->where('tenant_id', $tenant->id)
            ->where('supplier_id', $primarySupplierId)
            ->where('supplier_part_number', 'ACM-BEAR-001')
            ->first();

        $catalogId = $catalogRow?->id ?? (string) Str::uuid();

        DB::table('supplier_catalogs')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'supplier_id' => $primarySupplierId,
                'supplier_part_number' => 'ACM-BEAR-001',
            ],
            [
                'id' => $catalogId,
                'internal_sku' => 'SKU-IND-BEAR-01',
                'name' => 'High-Precision Industrial Bearing 50mm',
                'description' => 'Hardened stainless steel bearing for CNC spindle assembly',
                'uom' => 'PCS',
                'lead_time_days' => 7,
                'minimum_order_quantity' => 10.0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $plRow = DB::table('supplier_price_lists')
            ->where('tenant_id', $tenant->id)
            ->where('supplier_catalog_id', $catalogId)
            ->where('min_quantity', 10.0)
            ->first();
        $plId = $plRow?->id ?? (string) Str::uuid();

        DB::table('supplier_price_lists')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'supplier_catalog_id' => $catalogId,
                'min_quantity' => 10.0,
            ],
            [
                'id' => $plId,
                'currency' => 'EUR',
                'unit_price' => 25.00,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 4. Upsert Framework Contract
        $contractRow = DB::table('supplier_contracts')
            ->where('tenant_id', $tenant->id)
            ->where('contract_number', 'CTR-2026-0001')
            ->first();

        $contractId = $contractRow?->id ?? (string) Str::uuid();

        DB::table('supplier_contracts')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'contract_number' => 'CTR-2026-0001',
            ],
            [
                'id' => $contractId,
                'supplier_id' => $primarySupplierId,
                'title' => 'Master Components Supply Agreement 2026',
                'contract_type' => 'framework',
                'total_committed_amount' => 150000.00,
                'consumed_amount' => 25000.00,
                'starts_at' => '2026-01-01',
                'ends_at' => '2026-12-31',
                'payment_terms_code' => 'NET30',
                'incoterms' => 'DAP',
                'status' => 'active',
                'is_locked' => false,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 5. Upsert Demo Purchase Requisition
        $prRow = DB::table('purchase_requisitions')
            ->where('tenant_id', $tenant->id)
            ->where('requisition_number', 'REQ-2026-0001')
            ->first();

        $prId = $prRow?->id ?? (string) Str::uuid();

        DB::table('purchase_requisitions')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'requisition_number' => 'REQ-2026-0001',
            ],
            [
                'id' => $prId,
                'title' => 'Assembly Line Spares Q4',
                'requestor_user_id' => $userId,
                'urgency' => 'medium',
                'required_by_date' => '2026-11-30',
                'status' => 'approved',
                'total_estimated_amount' => 2500.00,
                'currency' => 'EUR',
                'notes' => 'Critical spare bearings for robotic arms',
                'is_locked' => false,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('purchase_requisition_lines')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'purchase_requisition_id' => $prId,
                'line_number' => 1,
            ],
            [
                'id' => (string) Str::uuid(),
                'supplier_catalog_id' => $catalogId,
                'description' => 'High-Precision Industrial Bearing 50mm',
                'quantity_requested' => 100.0,
                'uom' => 'PCS',
                'estimated_unit_price' => 25.00,
                'estimated_total_price' => 2500.00,
                'suggested_supplier_id' => $primarySupplierId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 6. Upsert Demo Purchase Order
        $poRow = DB::table('purchase_orders')
            ->where('tenant_id', $tenant->id)
            ->where('po_number', 'PO-2026-0001')
            ->first();

        $poId = $poRow?->id ?? (string) Str::uuid();

        DB::table('purchase_orders')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'po_number' => 'PO-2026-0001',
            ],
            [
                'id' => $poId,
                'supplier_id' => $primarySupplierId,
                'supplier_contract_id' => $contractId,
                'purchase_requisition_id' => $prId,
                'order_type' => 'standard',
                'order_number' => 'PO-2026-0001',
                'date' => '2026-10-25',
                'expected_delivery_date' => '2026-11-10',
                'delivery_due_date' => '2026-11-10',
                'status' => 'approved',
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'net_amount' => 2500.00,
                'tax_amount' => 550.00,
                'total_amount' => 3050.00,
                'total_net' => 2500.00,
                'total_tax' => 550.00,
                'total_gross' => 3050.00,
                'budget_committed_amount' => 3050.00,
                'payment_terms_code' => 'NET30',
                'incoterms' => 'DAP',
                'is_budget_encumbered' => true,
                'is_locked' => false,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $poLineRow = DB::table('purchase_order_lines')
            ->where('tenant_id', $tenant->id)
            ->where('purchase_order_id', $poId)
            ->where('line_number', 1)
            ->first();

        $poLineId = $poLineRow?->id ?? (string) Str::uuid();

        DB::table('purchase_order_lines')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'purchase_order_id' => $poId,
                'line_number' => 1,
            ],
            [
                'id' => $poLineId,
                'supplier_catalog_id' => $catalogId,
                'description' => 'High-Precision Industrial Bearing 50mm',
                'quantity_ordered' => 100.0,
                'quantity_received' => 100.0,
                'quantity_invoiced' => 100.0,
                'quantity_cancelled' => 0.0,
                'uom' => 'PCS',
                'unit_price' => 25.00,
                'tax_rate' => 22.0,
                'line_net_amount' => 2500.00,
                'line_tax_amount' => 550.00,
                'line_total_amount' => 3050.00,
                'is_closed' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 7. Upsert Goods Receipt Note (GRN)
        $grnRow = DB::table('purchase_receipts')
            ->where('tenant_id', $tenant->id)
            ->where('receipt_number', 'GRN-2026-0001')
            ->first();

        $grnId = $grnRow?->id ?? (string) Str::uuid();

        DB::table('purchase_receipts')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'receipt_number' => 'GRN-2026-0001',
            ],
            [
                'id' => $grnId,
                'purchase_order_id' => $poId,
                'supplier_delivery_note_ref' => 'DDT-2026-90812',
                'received_date' => '2026-10-27',
                'received_by_user_id' => $userId,
                'inspection_status' => 'passed',
                'notes' => 'Received in perfect packaging; batch tested',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $receiptLineRow = DB::table('purchase_receipt_lines')
            ->where('tenant_id', $tenant->id)
            ->where('purchase_receipt_id', $grnId)
            ->where('purchase_order_line_id', $poLineId)
            ->first();
        $receiptLineId = $receiptLineRow?->id ?? (string) Str::uuid();

        DB::table('purchase_receipt_lines')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'purchase_receipt_id' => $grnId,
                'purchase_order_line_id' => $poLineId,
            ],
            [
                'id' => $receiptLineId,
                'quantity_received' => 100.0,
                'quantity_accepted' => 100.0,
                'quantity_rejected' => 0.0,
                'lot_number' => 'LOT-2026-ACM-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 8. Upsert Three-Way Match Log
        $twmRow = DB::table('three_way_match_logs')
            ->where('tenant_id', $tenant->id)
            ->where('purchase_order_id', $poId)
            ->where('purchase_receipt_id', $grnId)
            ->first();
        $twmId = $twmRow?->id ?? (string) Str::uuid();

        DB::table('three_way_match_logs')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'purchase_order_id' => $poId,
                'purchase_receipt_id' => $grnId,
            ],
            [
                'id' => $twmId,
                'passive_invoice_id' => (string) Str::uuid(),
                'match_status' => 'matched',
                'po_total_amount' => 3050.00,
                'grn_total_amount' => 3050.00,
                'invoice_total_amount' => 3050.00,
                'variance_amount' => 0.00,
                'variance_percentage' => 0.00,
                'discrepancy_details' => json_encode(['status' => 'exact_match']),
                'evaluated_at' => now(),
                'approved_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 9. Upsert Supplier Evaluation & Scorecard
        $evalRow = DB::table('supplier_evaluations')
            ->where('tenant_id', $tenant->id)
            ->where('supplier_id', $primarySupplierId)
            ->where('evaluation_period_start', '2026-07-01')
            ->first();
        $evalId = $evalRow?->id ?? (string) Str::uuid();

        DB::table('supplier_evaluations')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'supplier_id' => $primarySupplierId,
                'evaluation_period_start' => '2026-07-01',
                'evaluation_period_end' => '2026-09-30',
            ],
            [
                'id' => $evalId,
                'on_time_delivery_rate' => 98.50,
                'quality_acceptance_rate' => 99.20,
                'price_competitiveness_score' => 90.00,
                'overall_rating' => 'A',
                'is_approved_vendor' => true,
                'evaluator_user_id' => $userId,
                'comments' => 'Top tier component reliability and flawless delivery timing.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $scRow = DB::table('supplier_scorecards')
            ->where('tenant_id', $tenant->id)
            ->where('supplier_id', $primarySupplierId)
            ->where('year', 2026)
            ->where('quarter', 3)
            ->first();
        $scId = $scRow?->id ?? (string) Str::uuid();

        DB::table('supplier_scorecards')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'supplier_id' => $primarySupplierId,
                'year' => 2026,
                'quarter' => 3,
            ],
            [
                'id' => $scId,
                'otif_percentage' => 98.50,
                'defect_ppm' => 120,
                'cost_savings_amount' => 4500.00,
                'compliance_score' => 96.00,
                'risk_level' => 'low',
                'is_avl_active' => true,
                'scorecard_data' => json_encode([
                    'total_deliveries' => 45,
                    'on_time_deliveries' => 44,
                    'zero_defect_orders' => 45,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
