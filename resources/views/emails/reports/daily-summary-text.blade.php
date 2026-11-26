@php
    $sections = $summary['sections'] ?? [];
    $reportDate = $summary['report_date'] ?? date('Y-m-d');
@endphp
@if($isTest)[TEST] @endif{{ $tenant->name }} — {{ $t('SCHEDULED_REPORT.EMAIL.TITLE') }} ({{ $reportDate }})
========================================================================

{{ $t('SCHEDULED_REPORT.EMAIL.GREETING', ['name' => $user->name]) }}
{{ $t('SCHEDULED_REPORT.EMAIL.INTRO', ['company' => $tenant->name, 'date' => $reportDate]) }}

@if(isset($sections['insights']) && !empty($sections['insights']['items']))
{{ $t('SCHEDULED_REPORT.MODULE.INSIGHTS') }}
------------------------------------------------------------------------
@foreach($sections['insights']['items'] as $insight)
- [{{ strtoupper((string) ($insight['severity'] ?? 'info')) }}] {{ $insight['title'] }}: {{ $insight['description'] }}
@endforeach

@endif
@if(isset($sections['dashboard']))
@php $d = $sections['dashboard']; @endphp
{{ $t('SCHEDULED_REPORT.MODULE.DASHBOARD') }}
------------------------------------------------------------------------
- {{ $t('SCHEDULED_REPORT.KPI.REVENUE_TODAY') }}: {{ $money((float) ($d['revenue_today'] ?? 0)) }}
- {{ $t('SCHEDULED_REPORT.KPI.REVENUE_MTD') }}: {{ $money((float) ($d['revenue_mtd'] ?? 0)) }}
- {{ $t('SCHEDULED_REPORT.KPI.REVENUE_YTD') }}: {{ $money((float) ($d['revenue_ytd'] ?? 0)) }}
- {{ $t('SCHEDULED_REPORT.KPI.ISSUED_DOCS') }}: {{ $d['issued_documents_count'] ?? 0 }}
- {{ $t('SCHEDULED_REPORT.KPI.CUSTOMERS') }}: {{ $d['active_customers_count'] ?? 0 }}

@endif
@if(isset($sections['billing']))
@php $b = $sections['billing']; @endphp
{{ $t('SCHEDULED_REPORT.MODULE.BILLING') }}
------------------------------------------------------------------------
- {{ $t('SCHEDULED_REPORT.BILLING.ISSUED') }}: {{ $b['issued_invoices_count'] ?? 0 }} ({{ $money((float) ($b['issued_invoices_total'] ?? 0)) }})
- {{ $t('SCHEDULED_REPORT.BILLING.PAID') }}: {{ $b['paid_invoices_count'] ?? 0 }} ({{ $money((float) ($b['paid_invoices_total'] ?? 0)) }})
- {{ $t('SCHEDULED_REPORT.BILLING.UNPAID') }}: {{ $b['unpaid_invoices_count'] ?? 0 }} ({{ $money((float) ($b['unpaid_invoices_total'] ?? 0)) }})
- {{ $t('SCHEDULED_REPORT.BILLING.OVERDUE') }}: {{ $b['overdue_invoices_count'] ?? 0 }} ({{ $money((float) ($b['overdue_invoices_total'] ?? 0)) }})

@endif
{{ $t('SCHEDULED_REPORT.EMAIL.CTA_OPEN_ERP') }}: {{ $dashboardUrl }}
{{ $t('SCHEDULED_REPORT.EMAIL.MANAGE_PREFERENCES') }}: {{ $preferencesUrl }}
@if($unsubscribeUrl !== '')
{{ $t('SCHEDULED_REPORT.EMAIL.UNSUBSCRIBE') }}: {{ $unsubscribeUrl }}
@endif
