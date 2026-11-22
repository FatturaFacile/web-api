<?php

declare(strict_types=1);
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\EmployeeShift;
use App\Models\Expense;
use App\Models\PayslipDocument;
use App\Models\Product;
use App\Models\TimeEntry;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkSite;

return [
    /*
    | Minimum app version accepted by the API; the bootstrap payload tells the
    | client to force an update when it is older.
    */
    'min_app_version' => env('MOBILE_MIN_APP_VERSION', '1.0.0'),

    'supported_locales' => ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'],

    'platforms' => ['ios', 'android', 'android_rugged_zebra'],

    'push' => [
        // log | relay (HTTP relay in front of FCM / APNs)
        'driver' => env('MOBILE_PUSH_DRIVER', 'log'),
        'relay_url' => env('MOBILE_PUSH_RELAY_URL'),
        'relay_token' => env('MOBILE_PUSH_RELAY_TOKEN'),
    ],

    'pkce' => [
        'code_ttl_seconds' => 120,
    ],

    /*
    | Entities exposed to the offline delta sync. `public` entities are
    | reference data readable by any tenant member, otherwise the Eloquent
    | policy `viewAny` is enforced. `own` restricts rows to the user column.
    */
    'sync_entities' => [
        'products' => ['model' => Product::class, 'public' => true],
        'warehouses' => ['model' => Warehouse::class, 'public' => true],
        'contacts' => ['model' => Contact::class],
        'work_orders' => ['model' => WorkOrder::class],
        'appointments' => ['model' => Appointment::class],
        'expenses' => ['model' => Expense::class, 'own' => 'user_id'],
        'time_entries' => ['model' => TimeEntry::class, 'own' => 'user_id', 'public' => true],
        'work_sites' => ['model' => WorkSite::class, 'public' => true],
        'employee_shifts' => ['model' => EmployeeShift::class, 'own' => 'user_id', 'public' => true],
        'payslip_documents' => ['model' => PayslipDocument::class, 'own' => 'user_id', 'public' => true],
    ],

    'sync_page_size' => 200,

    /*
    | Role-adaptive workspaces. Users with the `admin` role (or tenant admin/owner)
    | see all of them; the `employee` workspace (ESS) is always available.
    */
    'workspace_roles' => [
        'executive' => ['executive', 'ceo', 'cfo', 'director'],
        'manager' => ['manager', 'hr', 'approver', 'team_leader'],
        'warehouse' => ['warehouse', 'warehouse_operator', 'magazziniere'],
        'field_technician' => ['technician', 'field_technician', 'tecnico'],
        'sales_agent' => ['sales', 'sales_agent', 'agent', 'agente'],
    ],

    'workspace_modules' => [
        'employee' => ['clock', 'shifts', 'leave', 'expenses', 'payslips', 'italian_payroll_self'],
        'manager' => ['approvals_inbox'],
        'warehouse' => ['barcode_scanner', 'inventory_counts', 'pick_lists'],
        'field_technician' => ['work_orders', 'service_reports', 'appointments'],
        'sales_agent' => ['customer_360', 'quotes', 'appointments'],
        'executive' => ['kpi_dashboard', 'executive_dss', 'approvals_inbox', 'copilot'],
    ],
];
