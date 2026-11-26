@php
    $sections = $summary['sections'] ?? [];
    $reportDate = $summary['report_date'] ?? date('Y-m-d');
    $langAttr = str_replace('_', '-', $reportLocale);
@endphp
<!DOCTYPE html>
<html lang="{{ $langAttr }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="x-apple-disable-message-reformatting" content="" />
    <meta name="color-scheme" content="light dark" />
    <meta name="supported-color-schemes" content="light dark" />
    <title>{{ $t('SCHEDULED_REPORT.EMAIL.TITLE') }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1f2937; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .stack-col { display: block !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; }
            .mobile-pad { padding-left: 16px !important; padding-right: 16px !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .email-bg { background-color: #111827 !important; color: #f3f4f6 !important; }
            .email-card { background-color: #1f2937 !important; border-color: #374151 !important; }
            .kpi-box { background-color: #111827 !important; border-color: #374151 !important; }
            .muted-text { color: #9ca3af !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="email-bg" style="background-color:#f3f4f6;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <!--[if mso]>
            <table role="presentation" align="center" border="0" cellspacing="0" cellpadding="0" width="640"><tr><td>
            <![endif]-->
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="email-container email-card" style="max-width:640px; width:100%; background-color:#ffffff; border-radius:8px; border:1px solid #e5e7eb; overflow:hidden;">
                <!-- Header -->
                <tr>
                    <td style="background-color:{{ $secondaryColor }}; padding:24px 28px; color:#ffffff;" class="mobile-pad">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="left" valign="middle">
                                    @if(!empty($settings?->logo_url))
                                        <img src="{{ $settings->logo_url }}" alt="{{ $tenant->name }}" width="140" style="display:block; max-height:42px; width:auto; margin-bottom:10px;" />
                                    @endif
                                    <div style="font-size:12px; text-transform:uppercase; letter-spacing:1px; color:#d1d5db; margin-bottom:4px;">{{ $tenant->name }}</div>
                                    <h1 style="margin:0; font-size:20px; line-height:26px; font-weight:700; color:#ffffff;">
                                        @if($isTest)[TEST] @endif{{ $t('SCHEDULED_REPORT.EMAIL.TITLE') }}
                                    </h1>
                                </td>
                                <td align="right" valign="top" style="font-size:12px; color:#e5e7eb; white-space:nowrap;">
                                    {{ $reportDate }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                @if(!empty($settings?->custom_header_html))
                <tr>
                    <td style="padding:16px 28px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:13px; color:#374151;" class="mobile-pad">
                        {!! strip_tags((string) $settings->custom_header_html, '<p><br><strong><em><a><span><div>') !!}
                    </td>
                </tr>
                @endif

                <!-- Greeting -->
                <tr>
                    <td style="padding:24px 28px 12px 28px;" class="mobile-pad">
                        <p style="margin:0 0 8px 0; font-size:15px; line-height:22px;">
                            {{ $t('SCHEDULED_REPORT.EMAIL.GREETING', ['name' => $user->name]) }}
                        </p>
                        <p style="margin:0; font-size:14px; line-height:20px; color:#4b5563;" class="muted-text">
                            {{ $t('SCHEDULED_REPORT.EMAIL.INTRO', ['company' => $tenant->name, 'date' => $reportDate]) }}
                        </p>
                    </td>
                </tr>

                <!-- Intelligent Insights -->
                @if(isset($sections['insights']) && !empty($sections['insights']['items']))
                <tr>
                    <td style="padding:16px 28px;" class="mobile-pad">
                        <h2 style="margin:0 0 12px 0; font-size:16px; color:{{ $secondaryColor }}; border-bottom:2px solid {{ $primaryColor }}; padding-bottom:6px;">
                            {{ $t('SCHEDULED_REPORT.MODULE.INSIGHTS') }}
                        </h2>
                        @foreach($sections['insights']['items'] as $insight)
                            @php
                                $badgeBg = match($insight['severity'] ?? 'info') {
                                    'critical' => '#fef2f2',
                                    'warning' => '#fffbeb',
                                    'success' => '#ecfdf5',
                                    default => '#eff6ff',
                                };
                                $badgeBorder = match($insight['severity'] ?? 'info') {
                                    'critical' => '#ef4444',
                                    'warning' => '#f59e0b',
                                    'success' => '#10b981',
                                    default => '#3b82f6',
                                };
                            @endphp
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px; background-color:{{ $badgeBg }}; border-left:4px solid {{ $badgeBorder }}; border-radius:4px;">
                                <tr>
                                    <td style="padding:10px 12px;">
                                        <div style="font-size:13px; font-weight:700; color:#111827; margin-bottom:2px;">{{ $insight['title'] }}</div>
                                        <div style="font-size:13px; line-height:18px; color:#374151;">{{ $insight['description'] }}</div>
                                    </td>
                                </tr>
                            </table>
                        @endforeach
                    </td>
                </tr>
                @endif

                <!-- Dashboard Generale -->
                @if(isset($sections['dashboard']))
                @php $d = $sections['dashboard']; @endphp
                <tr>
                    <td style="padding:16px 28px;" class="mobile-pad">
                        <h2 style="margin:0 0 12px 0; font-size:16px; color:{{ $secondaryColor }}; border-bottom:2px solid {{ $primaryColor }}; padding-bottom:6px;">
                            {{ $t('SCHEDULED_REPORT.MODULE.DASHBOARD') }}
                        </h2>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="50%" class="stack-col" style="padding:4px;">
                                    <div class="kpi-box" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:12px;">
                                        <div style="font-size:12px; color:#6b7280;" class="muted-text">{{ $t('SCHEDULED_REPORT.KPI.REVENUE_TODAY') }}</div>
                                        <div style="font-size:18px; font-weight:700; color:#111827;">{{ $money((float) ($d['revenue_today'] ?? 0)) }}</div>
                                    </div>
                                </td>
                                <td width="50%" class="stack-col" style="padding:4px;">
                                    <div class="kpi-box" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:12px;">
                                        <div style="font-size:12px; color:#6b7280;" class="muted-text">{{ $t('SCHEDULED_REPORT.KPI.REVENUE_MTD') }}</div>
                                        <div style="font-size:18px; font-weight:700; color:#111827;">{{ $money((float) ($d['revenue_mtd'] ?? 0)) }}</div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td width="50%" class="stack-col" style="padding:4px;">
                                    <div class="kpi-box" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:12px;">
                                        <div style="font-size:12px; color:#6b7280;" class="muted-text">{{ $t('SCHEDULED_REPORT.KPI.REVENUE_YTD') }}</div>
                                        <div style="font-size:18px; font-weight:700; color:#111827;">{{ $money((float) ($d['revenue_ytd'] ?? 0)) }}</div>
                                    </div>
                                </td>
                                <td width="50%" class="stack-col" style="padding:4px;">
                                    <div class="kpi-box" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:12px;">
                                        <div style="font-size:12px; color:#6b7280;" class="muted-text">{{ $t('SCHEDULED_REPORT.KPI.ISSUED_DOCS') }}</div>
                                        <div style="font-size:18px; font-weight:700; color:#111827;">{{ $d['issued_documents_count'] ?? 0 }}</div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:8px 0 0 0; font-size:13px; color:#4b5563;" class="muted-text">
                            {{ $t('SCHEDULED_REPORT.KPI.CUSTOMERS') }}: <strong>{{ $d['active_customers_count'] ?? 0 }}</strong> &bull;
                            {{ $t('SCHEDULED_REPORT.KPI.SUPPLIERS') }}: <strong>{{ $d['active_suppliers_count'] ?? 0 }}</strong> &bull;
                            {{ $t('SCHEDULED_REPORT.KPI.UPCOMING_DEADLINES') }}: <strong>{{ $d['upcoming_deadlines_count'] ?? 0 }}</strong> &bull;
                            {{ $t('SCHEDULED_REPORT.KPI.OPEN_ACTIVITIES') }}: <strong>{{ $d['open_activities_count'] ?? 0 }}</strong>
                        </p>
                    </td>
                </tr>
                @endif

                <!-- Billing -->
                @if(isset($sections['billing']))
                @php $b = $sections['billing']; @endphp
                <tr>
                    <td style="padding:16px 28px;" class="mobile-pad">
                        <h2 style="margin:0 0 12px 0; font-size:16px; color:{{ $secondaryColor }}; border-bottom:2px solid {{ $primaryColor }}; padding-bottom:6px;">
                            {{ $t('SCHEDULED_REPORT.MODULE.BILLING') }}
                        </h2>
                        <table role="presentation" width="100%" cellpadding="6" cellspacing="0" border="0" style="font-size:13px; border-collapse:collapse;">
                            <tr style="border-bottom:1px solid #e5e7eb;">
                                <td>{{ $t('SCHEDULED_REPORT.BILLING.ISSUED') }} ({{ $b['issued_invoices_count'] ?? 0 }})</td>
                                <td align="right"><strong>{{ $money((float) ($b['issued_invoices_total'] ?? 0)) }}</strong></td>
                            </tr>
                            <tr style="border-bottom:1px solid #e5e7eb;">
                                <td>{{ $t('SCHEDULED_REPORT.BILLING.PAID') }} ({{ $b['paid_invoices_count'] ?? 0 }})</td>
                                <td align="right"><strong>{{ $money((float) ($b['paid_invoices_total'] ?? 0)) }}</strong></td>
                            </tr>
                            <tr style="border-bottom:1px solid #e5e7eb;">
                                <td>{{ $t('SCHEDULED_REPORT.BILLING.UNPAID') }} ({{ $b['unpaid_invoices_count'] ?? 0 }})</td>
                                <td align="right"><strong>{{ $money((float) ($b['unpaid_invoices_total'] ?? 0)) }}</strong></td>
                            </tr>
                            <tr style="border-bottom:1px solid #e5e7eb;">
                                <td style="color:#dc2626;">{{ $t('SCHEDULED_REPORT.BILLING.OVERDUE') }} ({{ $b['overdue_invoices_count'] ?? 0 }})</td>
                                <td align="right" style="color:#dc2626;"><strong>{{ $money((float) ($b['overdue_invoices_total'] ?? 0)) }}</strong></td>
                            </tr>
                            <tr style="border-bottom:1px solid #e5e7eb;">
                                <td>{{ $t('SCHEDULED_REPORT.BILLING.PAYMENTS_RECEIVED') }} ({{ $b['payments_received_count'] ?? 0 }})</td>
                                <td align="right"><strong>{{ $money((float) ($b['payments_received_total'] ?? 0)) }}</strong></td>
                            </tr>
                            <tr>
                                <td>{{ $t('SCHEDULED_REPORT.BILLING.TAXABLE_AND_VAT') }}</td>
                                <td align="right">{{ $money((float) ($b['total_taxable_amount'] ?? 0)) }} / {{ $money((float) ($b['total_vat_amount'] ?? 0)) }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @endif

                <!-- Deadlines -->
                @if(isset($sections['deadlines']))
                @php $dl = $sections['deadlines']; @endphp
                <tr>
                    <td style="padding:16px 28px;" class="mobile-pad">
                        <h2 style="margin:0 0 12px 0; font-size:16px; color:{{ $secondaryColor }}; border-bottom:2px solid {{ $primaryColor }}; padding-bottom:6px;">
                            {{ $t('SCHEDULED_REPORT.MODULE.DEADLINES') }}
                        </h2>
                        <p style="margin:0 0 8px 0; font-size:13px;">
                            {{ $t('SCHEDULED_REPORT.DEADLINES.DUE_7D') }}: <strong>{{ $dl['due_7_days_count'] ?? 0 }}</strong> ({{ $money((float) ($dl['due_7_days_total'] ?? 0)) }}) &bull;
                            {{ $t('SCHEDULED_REPORT.DEADLINES.DUE_30D') }}: <strong>{{ $dl['due_30_days_count'] ?? 0 }}</strong> ({{ $money((float) ($dl['due_30_days_total'] ?? 0)) }})
                        </p>
                        <p style="margin:0; font-size:13px; color:#4b5563;" class="muted-text">
                            {{ $t('SCHEDULED_REPORT.DEADLINES.SUPPLIER_DUE') }}: <strong>{{ $dl['supplier_payments_due_count'] ?? 0 }}</strong> ({{ $money((float) ($dl['supplier_payments_due_total'] ?? 0)) }}) &bull;
                            {{ $t('SCHEDULED_REPORT.DEADLINES.REMINDERS_TO_SEND') }}: <strong>{{ $dl['reminders_to_send_count'] ?? 0 }}</strong>
                        </p>
                    </td>
                </tr>
                @endif

                <!-- CRM, Calendar, Inventory, Support Summary Grid -->
                @if(isset($sections['crm']) || isset($sections['calendar']) || isset($sections['inventory']) || isset($sections['support']))
                <tr>
                    <td style="padding:16px 28px;" class="mobile-pad">
                        @if(isset($sections['crm']))
                            @php $crm = $sections['crm']; @endphp
                            <h2 style="margin:0 0 8px 0; font-size:15px; color:{{ $secondaryColor }};">{{ $t('SCHEDULED_REPORT.MODULE.CRM') }}</h2>
                            <p style="margin:0 0 14px 0; font-size:13px; color:#374151;">
                                {{ $t('SCHEDULED_REPORT.CRM.NEW_CUSTOMERS') }}: <strong>{{ $crm['new_customers_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.CRM.INACTIVE_CUSTOMERS') }}: <strong>{{ $crm['inactive_customers_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.CRM.OPEN_OPPORTUNITIES') }}: <strong>{{ $crm['open_opportunities_count'] ?? 0 }}</strong> ({{ $money((float) ($crm['open_opportunities_value'] ?? 0)) }}) &bull;
                                {{ $t('SCHEDULED_REPORT.CRM.FOLLOWUPS') }}: <strong>{{ $crm['pending_followups_count'] ?? 0 }}</strong>
                            </p>
                        @endif

                        @if(isset($sections['calendar']))
                            @php $cal = $sections['calendar']; @endphp
                            <h2 style="margin:0 0 8px 0; font-size:15px; color:{{ $secondaryColor }};">{{ $t('SCHEDULED_REPORT.MODULE.CALENDAR') }}</h2>
                            <p style="margin:0 0 14px 0; font-size:13px; color:#374151;">
                                {{ $t('SCHEDULED_REPORT.CALENDAR.TODAY') }}: <strong>{{ $cal['today_appointments_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.CALENDAR.WEEK') }}: <strong>{{ $cal['week_appointments_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.CALENDAR.ASSIGNED') }}: <strong>{{ $cal['assigned_activities_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.CALENDAR.DUE_TASKS') }}: <strong>{{ $cal['tasks_due_or_overdue_count'] ?? 0 }}</strong>
                            </p>
                        @endif

                        @if(isset($sections['inventory']))
                            @php $inv = $sections['inventory']; @endphp
                            <h2 style="margin:0 0 8px 0; font-size:15px; color:{{ $secondaryColor }};">{{ $t('SCHEDULED_REPORT.MODULE.INVENTORY') }}</h2>
                            <p style="margin:0 0 14px 0; font-size:13px; color:#374151;">
                                {{ $t('SCHEDULED_REPORT.INVENTORY.BELOW_MIN') }}: <strong>{{ $inv['below_min_stock_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.INVENTORY.OUT_OF_STOCK') }}: <strong>{{ $inv['out_of_stock_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.INVENTORY.EXPIRING') }}: <strong>{{ $inv['expiring_products_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.INVENTORY.VALUATION') }}: <strong>{{ $money((float) ($inv['total_inventory_valuation'] ?? 0)) }}</strong>
                            </p>
                        @endif

                        @if(isset($sections['support']))
                            @php $sup = $sections['support']; @endphp
                            <h2 style="margin:0 0 8px 0; font-size:15px; color:{{ $secondaryColor }};">{{ $t('SCHEDULED_REPORT.MODULE.SUPPORT') }}</h2>
                            <p style="margin:0; font-size:13px; color:#374151;">
                                {{ $t('SCHEDULED_REPORT.SUPPORT.OPEN') }}: <strong>{{ $sup['open_tickets_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.SUPPORT.PENDING') }}: <strong>{{ $sup['pending_tickets_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.SUPPORT.URGENT') }}: <strong>{{ $sup['urgent_tickets_count'] ?? 0 }}</strong> &bull;
                                {{ $t('SCHEDULED_REPORT.SUPPORT.SLA_EXPIRING') }}: <strong>{{ $sup['expiring_sla_tickets_count'] ?? 0 }}</strong>
                            </p>
                        @endif
                    </td>
                </tr>
                @endif

                <!-- System News -->
                @if(isset($sections['news']) && !empty($sections['news']['items']))
                <tr>
                    <td style="padding:16px 28px;" class="mobile-pad">
                        <h2 style="margin:0 0 10px 0; font-size:15px; color:{{ $secondaryColor }}; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">
                            {{ $t('SCHEDULED_REPORT.MODULE.NEWS') }}
                        </h2>
                        @foreach($sections['news']['items'] as $newsItem)
                            <div style="margin-bottom:8px; font-size:13px;">
                                <strong>{{ $newsItem['title'] }}</strong>
                                @if(!empty($newsItem['summary']))
                                    <div style="color:#4b5563;" class="muted-text">{{ $newsItem['summary'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </td>
                </tr>
                @endif

                <!-- CTA Buttons -->
                <tr>
                    <td align="center" style="padding:20px 28px;" class="mobile-pad">
                        <!--[if mso]>
                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $dashboardUrl }}" style="height:40px;v-text-anchor:middle;width:220px;" arcsize="10%" stroke="f" fillcolor="{{ $primaryColor }}">
                            <w:anchorlock/>
                            <center style="color:#ffffff;font-family:sans-serif;font-size:14px;font-weight:bold;">{{ $t('SCHEDULED_REPORT.EMAIL.CTA_OPEN_ERP') }}</center>
                        </v:roundrect>
                        <![endif]-->
                        <!--[if !mso]><!-- -->
                        <a href="{{ $dashboardUrl }}" style="display:inline-block; background-color:{{ $primaryColor }}; color:#ffffff; text-decoration:none; padding:11px 22px; border-radius:6px; font-size:14px; font-weight:600; margin:4px;">
                            {{ $t('SCHEDULED_REPORT.EMAIL.CTA_OPEN_ERP') }}
                        </a>
                        <!--<![endif]-->
                        <a href="{{ $invoicesUrl }}" style="display:inline-block; background-color:#f3f4f6; color:#111827; text-decoration:none; padding:11px 18px; border-radius:6px; font-size:14px; font-weight:600; border:1px solid #d1d5db; margin:4px;">
                            {{ $t('SCHEDULED_REPORT.EMAIL.CTA_VIEW_INVOICES') }}
                        </a>
                    </td>
                </tr>

                @if(!empty($settings?->custom_footer_html))
                <tr>
                    <td style="padding:12px 28px; background-color:#f9fafb; border-top:1px solid #e5e7eb; font-size:12px; color:#4b5563;" class="mobile-pad">
                        {!! strip_tags((string) $settings->custom_footer_html, '<p><br><strong><em><a><span><div>') !!}
                    </td>
                </tr>
                @endif

                <!-- Footer -->
                <tr>
                    <td style="padding:16px 28px; background-color:#f9fafb; border-top:1px solid #e5e7eb; font-size:12px; color:#6b7280; text-align:center;" class="mobile-pad muted-text">
                        <p style="margin:0 0 6px 0;">
                            {{ $t('SCHEDULED_REPORT.EMAIL.FOOTER_NOTICE', ['company' => $tenant->name]) }}
                        </p>
                        <p style="margin:0;">
                            <a href="{{ $preferencesUrl }}" style="color:{{ $primaryColor }}; text-decoration:underline;">{{ $t('SCHEDULED_REPORT.EMAIL.MANAGE_PREFERENCES') }}</a>
                            @if($unsubscribeUrl !== '')
                                &nbsp;&bull;&nbsp;
                                <a href="{{ $unsubscribeUrl }}" style="color:#6b7280; text-decoration:underline;">{{ $t('SCHEDULED_REPORT.EMAIL.UNSUBSCRIBE') }}</a>
                            @endif
                        </p>
                    </td>
                </tr>
            </table>
            <!--[if mso]>
            </td></tr></table>
            <![endif]-->
        </td>
    </tr>
</table>
@if($openPixelUrl !== '')
    <img src="{{ $openPixelUrl }}" width="1" height="1" alt="" style="display:none; width:1px; height:1px; border:0;" />
@endif
</body>
</html>
