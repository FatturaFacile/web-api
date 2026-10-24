<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Contracts\PdfGenerator;
use App\DTOs\Inventory\LabelSheetConfig;
use App\Enums\BarcodeType;
use App\Exceptions\BarcodeGenerationException;
use App\Exceptions\LabelPrintingException;
use App\Models\Product;
use App\Services\Pdf\RenderedPdf;
use App\Services\Tenancy\TenantContext;

/**
 * Prints product labels: resolves the (tenant scoped) products, prices them, encodes their barcode, lays the labels
 * out on the sheet - skipping `startOffset` cells on partly used sheets - and renders the PDF.
 */
class LabelPrintingService
{
    /**
     * Barcode PNG resolution: a module width of 5 px gives about 200 dpi on a 50 mm wide label (thermal printers).
     */
    private const BARCODE_SCALE = 5;

    private const BARCODE_HEIGHT_PX = 100;

    public function __construct(
        private readonly PdfGenerator $generator,
        private readonly BarcodeService $barcodes,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $options  content options (show_*, barcode_type, price_includes_tax, font_size_pt)
     * @param  array<int, array{product_id: string, quantity: int}>  $items
     *
     * @throws LabelPrintingException when a product cannot be encoded with the chosen barcode type
     */
    public function render(LabelSheetConfig $config, array $options, array $items): RenderedPdf
    {
        $options = $this->normalizeOptions($options);
        $products = Product::with('brand')->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $currency = $this->tenantContext->getTenant()?->default_currency ?: 'EUR';

        $labels = [];
        $failures = [];

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if ($product === null || isset($labels[$product->id]) || isset($failures[$product->id])) {
                continue;
            }

            try {
                $labels[$product->id] = $this->labelData($product, $options, $currency);
            } catch (BarcodeGenerationException $e) {
                $failures[$product->id] = ['product_id' => $product->id, 'sku' => (string) $product->sku, 'reason' => $e->getMessage()];
            }
        }

        if ($failures !== []) {
            throw new LabelPrintingException(array_values($failures));
        }

        // Empty cells first, so a partly used sheet continues where the previous print stopped.
        $cells = array_fill(0, $config->startOffset, null);

        foreach ($items as $item) {
            if (isset($labels[$item['product_id']])) {
                array_push($cells, ...array_fill(0, $item['quantity'], $labels[$item['product_id']]));
            }
        }

        $content = $this->generator->render('documents.labels.sheet', [
            'config' => $config,
            'options' => $options,
            'pages' => array_chunk($cells, $config->labelsPerPage()),
        ]);

        return new RenderedPdf($content, 'Labels-'.now()->format('Ymd-His').'.pdf');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(array $options): array
    {
        foreach (['show_name', 'show_sku', 'show_price', 'price_includes_tax', 'show_barcode'] as $flag) {
            $options[$flag] = filter_var($options[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $options['barcode_type'] = (string) ($options['barcode_type'] ?? 'code128');
        $options['font_size_pt'] = (float) ($options['font_size_pt'] ?? 8);

        return $options;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws BarcodeGenerationException
     */
    private function labelData(Product $product, array $options, string $currency): array
    {
        $price = null;

        if ($options['show_price']) {
            $net = (float) $product->sale_price;
            $amount = $options['price_includes_tax'] ? round($net * (1 + (float) $product->tax_rate / 100), 2) : round($net, 2);
            $price = number_format($amount, 2, '.', '').' '.$currency;
        }

        $barcodeImage = null;
        $barcodeText = null;

        if ($options['show_barcode']) {
            $type = BarcodeType::fromInput($options['barcode_type']);
            $barcodeText = $this->barcodes->normalize($type, (string) ($product->barcode ?: $product->sku));
            $barcodeImage = $this->barcodes->pngDataUri($type, $barcodeText, self::BARCODE_SCALE, self::BARCODE_HEIGHT_PX);
        }

        return [
            'name' => $product->name,
            'sku' => $product->sku,
            'brand' => $product->brand?->name,
            'price' => $price,
            'price_note' => $price !== null ? __($options['price_includes_tax'] ? 'labels.price.including_tax' : 'labels.price.excluding_tax') : null,
            'barcode_image' => $barcodeImage,
            'barcode_text' => $barcodeText,
            'barcode_is_matrix' => $options['show_barcode'] && BarcodeType::fromInput($options['barcode_type'])->isMatrix(),
        ];
    }
}
