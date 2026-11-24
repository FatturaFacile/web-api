@extends('documents.layout')

@section('content')
    <h2>{{ __('service_reports.pdf_title', ['number' => $number]) }}</h2>

    <table role="presentation" style="margin-bottom: 15px; width: 100%;">
        <tr>
            <td style="width: 25%"><strong>{{ __('service_reports.date') }}:</strong></td>
            <td style="width: 25%">{{ $report_date }}</td>
            <td style="width: 25%"><strong>{{ __('service_reports.technician') }}:</strong></td>
            <td style="width: 25%">{{ $technician }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('service_reports.job_site') }}:</strong></td>
            <td colspan="3">{{ $site_address ?: __('service_reports.not_specified') }}</td>
        </tr>
        @if($time_start || $time_end)
            <tr>
                <td><strong>{{ __('service_reports.time_start') }}:</strong></td>
                <td>{{ $time_start ?: '-' }}</td>
                <td><strong>{{ __('service_reports.time_end') }}:</strong></td>
                <td>{{ $time_end ?: '-' }}</td>
            </tr>
        @endif
        @if($total_hours > 0 || $travel_distance_km > 0)
            <tr>
                <td><strong>{{ __('service_reports.total_labor_hours') }}:</strong></td>
                <td>{{ number_format((float) $total_hours, 2) }} h</td>
                <td><strong>{{ __('service_reports.travel_distance') }}:</strong></td>
                <td>{{ number_format((float) $travel_distance_km, 2) }} km</td>
            </tr>
        @endif
    </table>

    @include('documents.partials.lines', ['showDiscount' => true, 'showType' => true])
    @include('documents.partials.totals')

    @if (!empty($customer_notes))
        <h3 style="margin-top: 20px;">{{ __('service_reports.customer_notes') }}</h3>
        <div style="background-color: #f9f9f9; padding: 10px; border-left: 3px solid #ccc; margin-bottom: 15px;">
            {{ $customer_notes }}
        </div>
    @endif

    @if (!empty($signature['image']))
        <div style="page-break-inside: avoid; margin-top: 30px;">
            <h3>{{ __('service_reports.acceptance_signature') }}</h3>
            <table role="presentation" style="width: 100%; border: 1px solid #ddd; padding: 10px;">
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        <p><strong>{{ __('service_reports.signer_name') }}:</strong> {{ $signature['signer_name'] }}</p>
                        @if(!empty($signature['signer_role']))
                            <p><strong>{{ __('service_reports.signer_role') }}:</strong> {{ $signature['signer_role'] }}</p>
                        @endif
                        <p><strong>{{ __('service_reports.signed_at') }}:</strong> {{ $signature['signed_at'] }}</p>
                        <p style="font-size: 8pt; color: #888;">{{ __('service_reports.signature_hash') }}: {{ $signature['signature_hash'] ?? '' }}</p>
                    </td>
                    <td style="width: 50%; text-align: center; vertical-align: middle;">
                        <img src="{{ $signature['image'] }}" alt="Customer Signature" style="max-height: 80px; max-width: 240px;" />
                    </td>
                </tr>
            </table>
        </div>
    @endif
@endsection
