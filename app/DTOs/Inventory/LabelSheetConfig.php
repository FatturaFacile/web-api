<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

/**
 * Immutable geometry of a label sheet. All measures are in millimetres.
 * `startOffset` is the number of empty cells skipped at the start, for sheets that were already partly used.
 */
final readonly class LabelSheetConfig
{
    /**
     * Tolerance for floating point rounding when checking that the grid fits on the page.
     */
    private const FIT_TOLERANCE_MM = 0.05;

    public function __construct(
        public float $pageWidthMm,
        public float $pageHeightMm,
        public float $marginTopMm,
        public float $marginRightMm,
        public float $marginBottomMm,
        public float $marginLeftMm,
        public int $rows,
        public int $cols,
        public float $labelWidthMm,
        public float $labelHeightMm,
        public float $gapXMm = 0.0,
        public float $gapYMm = 0.0,
        public int $startOffset = 0,
    ) {}

    /**
     * Build a configuration from a preset or request array with snake_case millimetre keys.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, int $startOffset = 0): self
    {
        return new self(
            pageWidthMm: (float) $data['page_width_mm'],
            pageHeightMm: (float) $data['page_height_mm'],
            marginTopMm: (float) ($data['margin_top_mm'] ?? 0),
            marginRightMm: (float) ($data['margin_right_mm'] ?? 0),
            marginBottomMm: (float) ($data['margin_bottom_mm'] ?? 0),
            marginLeftMm: (float) ($data['margin_left_mm'] ?? 0),
            rows: (int) $data['rows'],
            cols: (int) $data['cols'],
            labelWidthMm: (float) $data['label_width_mm'],
            labelHeightMm: (float) $data['label_height_mm'],
            gapXMm: (float) ($data['gap_x_mm'] ?? 0),
            gapYMm: (float) ($data['gap_y_mm'] ?? 0),
            startOffset: $startOffset,
        );
    }

    public function withStartOffset(int $startOffset): self
    {
        return new self(
            $this->pageWidthMm, $this->pageHeightMm, $this->marginTopMm, $this->marginRightMm, $this->marginBottomMm, $this->marginLeftMm,
            $this->rows, $this->cols, $this->labelWidthMm, $this->labelHeightMm, $this->gapXMm, $this->gapYMm, $startOffset,
        );
    }

    public function labelsPerPage(): int
    {
        return $this->rows * $this->cols;
    }

    /**
     * Width of the label grid including the gaps between columns.
     */
    public function gridWidthMm(): float
    {
        return $this->cols * $this->labelWidthMm + ($this->cols - 1) * $this->gapXMm;
    }

    /**
     * Height of the label grid including the gaps between rows.
     */
    public function gridHeightMm(): float
    {
        return $this->rows * $this->labelHeightMm + ($this->rows - 1) * $this->gapYMm;
    }

    /**
     * Whether the whole grid, margins included, fits on the page.
     */
    public function fits(): bool
    {
        return $this->rows > 0
            && $this->cols > 0
            && $this->labelWidthMm > 0
            && $this->labelHeightMm > 0
            && $this->marginLeftMm + $this->gridWidthMm() + $this->marginRightMm <= $this->pageWidthMm + self::FIT_TOLERANCE_MM
            && $this->marginTopMm + $this->gridHeightMm() + $this->marginBottomMm <= $this->pageHeightMm + self::FIT_TOLERANCE_MM;
    }

    /**
     * @return array<string, float|int>
     */
    public function toArray(): array
    {
        return [
            'page_width_mm' => $this->pageWidthMm,
            'page_height_mm' => $this->pageHeightMm,
            'margin_top_mm' => $this->marginTopMm,
            'margin_right_mm' => $this->marginRightMm,
            'margin_bottom_mm' => $this->marginBottomMm,
            'margin_left_mm' => $this->marginLeftMm,
            'rows' => $this->rows,
            'cols' => $this->cols,
            'label_width_mm' => $this->labelWidthMm,
            'label_height_mm' => $this->labelHeightMm,
            'gap_x_mm' => $this->gapXMm,
            'gap_y_mm' => $this->gapYMm,
            'start_offset' => $this->startOffset,
        ];
    }
}
