@php
    $mm = fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') . 'mm';
    $number = fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

    $cols = $config->cols;
    // Slightly shorter than the physical page, so rounding never pushes a full grid onto an extra blank page.
    $pageHeight = $config->pageHeightMm - 0.3;
    $barcodeHeight = max(5.0, $config->labelHeightMm * ($options['show_name'] || $options['show_price'] ? 0.36 : 0.5));
@endphp
    <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('labels.title') }}</title>
    <style>
        @page {
            size: {{ $number($config->pageWidthMm) }}mm {{ $number($config->pageHeightMm) }}mm;
            margin: 0;
        }

        html, body { margin: 0; padding: 0; }

        body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $number($options['font_size_pt']) }}pt; color: #000; }

        .page { width: {{ $mm($config->pageWidthMm) }}; height: {{ $mm($pageHeight) }}; overflow: hidden; }

        .page.break { page-break-after: always; }

        .grid { margin: {{ $mm($config->marginTopMm) }} 0 0 {{ $mm($config->marginLeftMm) }}; width: {{ $mm($config->gridWidthMm() + 0.2) }}; }

        .label { float: left; width: {{ $mm($config->labelWidthMm) }}; height: {{ $mm($config->labelHeightMm) }}; overflow: hidden; page-break-inside: avoid; }

        .label.gap-x { margin-right: {{ $mm($config->gapXMm) }}; }

        .label.gap-y { margin-bottom: {{ $mm($config->gapYMm) }}; }

        .inner { padding: 1mm 1.5mm; }

        .name { font-weight: bold; line-height: 1.15; max-height: {{ $number($options['font_size_pt'] * 1.15 * 2) }}pt; overflow: hidden; }

        .meta { font-size: 0.85em; line-height: 1.2; }

        .price { font-weight: bold; font-size: 1.35em; line-height: 1.2; }

        .price small { font-weight: normal; font-size: 0.55em; }

        .barcode { text-align: center; margin-top: 0.6mm; }

        .barcode img { height: {{ $mm($barcodeHeight) }}; max-width: 100%; }

        .barcode img.matrix { width: {{ $mm($barcodeHeight) }}; }

        .barcode-text { text-align: center; font-size: 0.75em; line-height: 1.1; }

        .clear { clear: both; height: 0; line-height: 0; font-size: 0; }
    </style>
</head>
<body>
@foreach ($pages as $cells)
    <div class="page @if (! $loop->last) break @endif">
        <div class="grid">
            @foreach ($cells as $index => $label)
                @php
                    $column = $index % $cols;
                    $row = intdiv($index, $cols);
                    $classes = trim(
                        ($column < $cols - 1 && $config->gapXMm > 0 ? 'gap-x ' : '')
                        .($row < $config->rows - 1 && $config->gapYMm > 0 ? 'gap-y' : '')
                    );
                @endphp
                <div class="label {{ $classes }}" data-cell="{{ $label === null ? 'empty' : 'label' }}">
                    @if ($label !== null)
                        <div class="inner">
                            @if ($options['show_name'])
                                <div class="name">{{ $label['name'] }}</div>
                            @endif
                            @if ($options['show_sku'] || $label['brand'])
                                <div class="meta">
                                    @if ($options['show_sku'])
                                        {{ __('labels.fields.sku') }}: {{ $label['sku'] }}
                                    @endif
                                    @if ($label['brand'])
                                        · {{ __('labels.fields.brand') }}: {{ $label['brand'] }}
                                    @endif
                                </div>
                            @endif
                            @if ($label['price'])
                                <div class="price">{{ $label['price'] }} <small>{{ $label['price_note'] }}</small></div>
                            @endif
                            @if ($label['barcode_image'])
                                <div class="barcode"><img class="{{ $label['barcode_is_matrix'] ? 'matrix' : '' }}"
                                                          src="{{ $label['barcode_image'] }}" alt=""></div>
                                @unless ($label['barcode_is_matrix'])
                                    <div class="barcode-text">{{ $label['barcode_text'] }}</div>
                                @endunless
                            @endif
                        </div>
                    @endif
                </div>
                @if ($column === $cols - 1)
                    <div class="clear"></div>
                @endif
            @endforeach
            <div class="clear"></div>
        </div>
    </div>
@endforeach
</body>
</html>
