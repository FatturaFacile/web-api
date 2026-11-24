@php($t = fn (string $key, array $r = []) => __('esm_residual_report.'.$key, $r, $locale))
<p>{{ $t('greeting', ['customer' => $customerName]) }}</p>
<p>{{ $t('intro', ['contract' => $contract->contract_number]) }}</p>
<ul>
    <li>{{ $t('total') }}: <strong>{{ $contract->included_hours }}</strong></li>
    <li>{{ $t('consumed') }}: <strong>{{ $contract->consumed_hours }}</strong></li>
    <li>{{ $t('remaining') }}: <strong>{{ $remaining }}</strong></li>
</ul>
@if ($lowBalance)
    <p><strong>{{ $t('low_balance') }}</strong></p>
@endif
<p>{{ $t('attachment') }}</p>
