<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HRDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            HRReferenceSeeder::class,
        ]);

        $tenant = Tenant::first();
        if ($tenant === null) {
            return;
        }

        $year = (int) date('Y');
        $annualLeaveTypeId = DB::table('hr_leave_types')
            ->where('tenant_id', $tenant->id)
            ->where('code', 'ANNUAL_LEAVE')
            ->value('id');

        $isoCourseId = DB::table('hr_training_courses')
            ->where('tenant_id', $tenant->id)
            ->where('course_code', 'TRN_ISO_45001')
            ->value('id');

        // 4 Enterprise Workforce Profiles: Manufacturing, Construction, Professional Services, Retail
        $workforceProfiles = [
            // 1. Manufacturing Workforce (Manager + Lead Engineer)
            [
                'employee_number' => 'EMP-MFG-001',
                'first_name' => 'Alessandro',
                'last_name' => 'Ferretti',
                'work_email' => 'alessandro.ferretti@enterprise-mfg.it',
                'dep_code' => 'DEP_MFG',
                'pos_code' => 'POS_MFG_DIR',
                'grade_code' => 'JG_L4',
                'skill_code' => 'SK_EXEC_LEAD',
                'base_annual_salary' => 92000.00,
                'is_manager' => true,
                'cert_code' => 'CERT-ISO45001-MFG1',
                'cert_name' => 'ISO 45001 Industrial Plant Safety Director',
            ],
            [
                'employee_number' => 'EMP-MFG-002',
                'first_name' => 'Matteo',
                'last_name' => 'Colombo',
                'work_email' => 'matteo.colombo@enterprise-mfg.it',
                'dep_code' => 'DEP_MFG',
                'pos_code' => 'POS_CNC_LEAD',
                'grade_code' => 'JG_L3',
                'skill_code' => 'SK_CNC_5AXIS',
                'base_annual_salary' => 58000.00,
                'manager_number' => 'EMP-MFG-001',
                'is_manager' => false,
                'cert_code' => 'CERT-SINUMERIK-MFG2',
                'cert_name' => 'Siemens SINUMERIK 5-Axis Master Specialist',
            ],
            // 2. Construction Workforce
            [
                'employee_number' => 'EMP-CONST-001',
                'first_name' => 'Roberto',
                'last_name' => 'Moretti',
                'work_email' => 'roberto.moretti@enterprise-const.it',
                'dep_code' => 'DEP_CONST',
                'pos_code' => 'POS_CONST_MGR',
                'grade_code' => 'JG_L4',
                'skill_code' => 'SK_BIM_CIVIL',
                'base_annual_salary' => 84000.00,
                'is_manager' => true,
                'cert_code' => 'CERT-CSE-CONST1',
                'cert_name' => 'Coordinatore Sicurezza Cantieri (D.Lgs 81/08)',
            ],
            // 3. Professional Services Workforce
            [
                'employee_number' => 'EMP-PRO-001',
                'first_name' => 'Chiara',
                'last_name' => 'Visconti',
                'work_email' => 'chiara.visconti@enterprise-advisory.it',
                'dep_code' => 'DEP_PRO',
                'pos_code' => 'POS_PRO_ARCH',
                'grade_code' => 'JG_L4',
                'skill_code' => 'SK_ERP_ARCH',
                'base_annual_salary' => 96000.00,
                'is_manager' => true,
                'cert_code' => 'CERT-TOGAF-PRO1',
                'cert_name' => 'TOGAF 10 Enterprise Architecture Certified',
            ],
            // 4. Retail Workforce
            [
                'employee_number' => 'EMP-RET-001',
                'first_name' => 'Giulia',
                'last_name' => 'Marchesi',
                'work_email' => 'giulia.marchesi@enterprise-retail.it',
                'dep_code' => 'DEP_RET',
                'pos_code' => 'POS_RET_MGR',
                'grade_code' => 'JG_L3',
                'skill_code' => 'SK_OMNICHANNEL',
                'base_annual_salary' => 54000.00,
                'is_manager' => true,
                'cert_code' => 'CERT-HACCP-RET1',
                'cert_name' => 'Retail Store Safety & Operations Compliance',
            ],
        ];

        $employeeIdsByNumber = [];

        foreach ($workforceProfiles as $profile) {
            $dep = DB::table('hr_departments')
                ->where('tenant_id', $tenant->id)
                ->where('code', $profile['dep_code'])
                ->first();

            $pos = DB::table('hr_positions')
                ->where('tenant_id', $tenant->id)
                ->where('code', $profile['pos_code'])
                ->first();

            $gradeId = DB::table('hr_job_grades')
                ->where('tenant_id', $tenant->id)
                ->where('code', $profile['grade_code'])
                ->value('id');

            $existingEmpId = DB::table('hr_employees')
                ->where('tenant_id', $tenant->id)
                ->where('employee_number', $profile['employee_number'])
                ->value('id');

            $empId = $existingEmpId ?: (string) Str::uuid7();
            $managerId = isset($profile['manager_number'])
                ? ($employeeIdsByNumber[$profile['manager_number']] ?? null)
                : null;

            $hourlyCost = round(($profile['base_annual_salary'] / 2080) * 1.35, 2);

            DB::table('hr_employees')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'employee_number' => $profile['employee_number'],
                ],
                [
                    'id' => $empId,
                    'company_id' => $tenant->id,
                    'business_unit_id' => $dep->business_unit_id ?? null,
                    'department_id' => $dep->id ?? null,
                    'position_id' => $pos->id ?? null,
                    'job_grade_id' => $gradeId,
                    'cost_center_id' => $dep->cost_center_id ?? null,
                    'manager_id' => $managerId,
                    'first_name' => $profile['first_name'],
                    'last_name' => $profile['last_name'],
                    'full_name' => $profile['first_name'].' '.$profile['last_name'],
                    'work_email' => $profile['work_email'],
                    'employment_status' => 'active',
                    'employment_type' => 'full_time',
                    'hire_date' => '2024-01-15',
                    'probation_end_date' => '2024-04-15',
                    'confirmation_date' => '2024-04-15',
                    'base_annual_salary' => $profile['base_annual_salary'],
                    'hourly_cost_rate' => $hourlyCost,
                    'fte_ratio' => 1.00,
                    'currency' => 'EUR',
                    'work_schedule_code' => 'STD-40H',
                    'approval_status' => 'approved',
                    'is_locked' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $employeeIdsByNumber[$profile['employee_number']] = $empId;

            if ($profile['is_manager'] && $dep !== null) {
                DB::table('hr_departments')
                    ->where('id', $dep->id)
                    ->update(['manager_employee_id' => $empId]);
            }

            // Employment Contract
            $contractNumber = 'CNT-'.$profile['employee_number'];
            $existingContractId = DB::table('hr_employment_contracts')
                ->where('tenant_id', $tenant->id)
                ->where('contract_number', $contractNumber)
                ->value('id');

            DB::table('hr_employment_contracts')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'contract_number' => $contractNumber,
                ],
                [
                    'id' => $existingContractId ?: (string) Str::uuid7(),
                    'company_id' => $tenant->id,
                    'employee_id' => $empId,
                    'contract_type' => 'permanent',
                    'collective_agreement' => 'CCNL Enterprise Standard',
                    'job_title' => $pos->title ?? 'Senior Specialist',
                    'job_grade_id' => $gradeId,
                    'start_date' => '2024-01-15',
                    'weekly_hours' => 40.00,
                    'fte_percentage' => 100.00,
                    'base_annual_salary' => $profile['base_annual_salary'],
                    'hourly_rate' => round($profile['base_annual_salary'] / 2080, 2),
                    'loaded_hourly_cost' => $hourlyCost,
                    'currency' => 'EUR',
                    'status' => 'active',
                    'is_active' => true,
                    'is_locked' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // Employee Skill Matrix
            $skillId = DB::table('hr_skills')
                ->where('tenant_id', $tenant->id)
                ->where('code', $profile['skill_code'])
                ->value('id');

            if ($skillId) {
                $existingEmpSkillId = DB::table('hr_employee_skills')
                    ->where('tenant_id', $tenant->id)
                    ->where('employee_id', $empId)
                    ->where('skill_id', $skillId)
                    ->value('id');

                DB::table('hr_employee_skills')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'employee_id' => $empId,
                        'skill_id' => $skillId,
                    ],
                    [
                        'id' => $existingEmpSkillId ?: (string) Str::uuid7(),
                        'proficiency_level' => 5,
                        'verified_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // Employee Certification
            $existingCertId = DB::table('hr_certifications')
                ->where('tenant_id', $tenant->id)
                ->where('employee_id', $empId)
                ->where('code', $profile['cert_code'])
                ->value('id');

            DB::table('hr_certifications')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'employee_id' => $empId,
                    'code' => $profile['cert_code'],
                ],
                [
                    'id' => $existingCertId ?: (string) Str::uuid7(),
                    'company_id' => $tenant->id,
                    'name' => $profile['cert_name'],
                    'issuing_authority' => 'European Certification Board',
                    'certificate_number' => 'ECB-'.$profile['employee_number'],
                    'issue_date' => '2024-06-01',
                    'expiry_date' => now()->addDays(25)->toDateString(),
                    'status' => 'active',
                    'is_mandatory' => true,
                    'translations' => json_encode([
                        'it' => $profile['cert_name'],
                        'en' => $profile['cert_name'],
                        'de' => $profile['cert_name'],
                        'fr' => $profile['cert_name'],
                    ]),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // Leave Balance & Leave Request
            if ($annualLeaveTypeId) {
                $existingBalId = DB::table('hr_leave_balances')
                    ->where('tenant_id', $tenant->id)
                    ->where('employee_id', $empId)
                    ->where('leave_type_id', $annualLeaveTypeId)
                    ->where('fiscal_year', $year)
                    ->value('id');

                DB::table('hr_leave_balances')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'employee_id' => $empId,
                        'leave_type_id' => $annualLeaveTypeId,
                        'fiscal_year' => $year,
                    ],
                    [
                        'id' => $existingBalId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'entitlement_hours' => 208.00,
                        'carryover_hours' => 32.00,
                        'accrued_hours' => 176.00,
                        'used_hours' => 40.00,
                        'pending_hours' => 0.00,
                        'available_hours' => 168.00,
                        'last_accrued_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $reqNumber = 'LR-DEMO-'.$profile['employee_number'];
                $existingReqId = DB::table('hr_leave_requests')
                    ->where('tenant_id', $tenant->id)
                    ->where('request_number', $reqNumber)
                    ->value('id');

                DB::table('hr_leave_requests')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'request_number' => $reqNumber,
                    ],
                    [
                        'id' => $existingReqId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'employee_id' => $empId,
                        'leave_type_id' => $annualLeaveTypeId,
                        'start_date' => "{$year}-08-10",
                        'end_date' => "{$year}-08-14",
                        'total_hours' => 40.00,
                        'total_days' => 5.00,
                        'status' => 'approved',
                        'reason' => 'Summer annual vacation',
                        'approved_at' => now(),
                        'is_locked' => false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // Performance Review
            $revNumber = 'PR-DEMO-'.$profile['employee_number'];
            $existingRevId = DB::table('hr_performance_reviews')
                ->where('tenant_id', $tenant->id)
                ->where('review_number', $revNumber)
                ->value('id');

            DB::table('hr_performance_reviews')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'review_number' => $revNumber,
                ],
                [
                    'id' => $existingRevId ?: (string) Str::uuid7(),
                    'company_id' => $tenant->id,
                    'employee_id' => $empId,
                    'reviewer_employee_id' => $managerId,
                    'review_period' => "{$year}-ANNUAL",
                    'review_type' => 'annual',
                    'review_date' => "{$year}-12-15",
                    'overall_rating' => 4.60,
                    'goals_score' => 4.70,
                    'competencies_score' => 4.45,
                    'status' => 'completed',
                    'promotion_recommended' => true,
                    'recommended_salary_increase_percent' => 7.50,
                    'successor_readiness' => 'ready_now',
                    'completed_at' => now(),
                    'is_locked' => false,
                    'goals' => json_encode([
                        ['goal' => 'Operational KPI Excellence', 'score' => 4.7],
                    ]),
                    'competencies' => json_encode([
                        ['competency' => 'Domain Mastery & Safety Leadership', 'score' => 4.5],
                    ]),
                    'strengths' => 'Consistently exceeds operational and quality targets.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // Mandatory ISO Training Record
            if ($isoCourseId) {
                $enrollNumber = 'TRN-DEMO-'.$profile['employee_number'];
                $existingTrnId = DB::table('hr_training_records')
                    ->where('tenant_id', $tenant->id)
                    ->where('enrollment_number', $enrollNumber)
                    ->value('id');

                DB::table('hr_training_records')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'enrollment_number' => $enrollNumber,
                    ],
                    [
                        'id' => $existingTrnId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'employee_id' => $empId,
                        'course_id' => $isoCourseId,
                        'assigned_date' => '2025-01-10',
                        'scheduled_date' => '2025-01-25',
                        'completed_date' => '2025-01-28',
                        'status' => 'completed',
                        'score' => 98.00,
                        'cost_amount' => 850.00,
                        'currency' => 'EUR',
                        'is_locked' => false,
                        'certificate_issued' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
