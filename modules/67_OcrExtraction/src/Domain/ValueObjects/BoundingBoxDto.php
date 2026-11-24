<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\ValueObjects;

/**
 * Region of a page as ratios (0.0–1.0) of its width and height, so it renders at any zoom.
 */
final readonly class BoundingBoxDto
{
    public function __construct(
        public int $page,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    /**
     * Builds a box from a provider answer, clamping it to the page and rejecting empty or malformed regions.
     *
     * @param  array<string, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! isset($data[$key]) || ! is_numeric($data[$key])) {
                return null;
            }
        }

        $x = min(1.0, max(0.0, (float) $data['x']));
        $y = min(1.0, max(0.0, (float) $data['y']));
        $width = min(1.0 - $x, max(0.0, (float) $data['width']));
        $height = min(1.0 - $y, max(0.0, (float) $data['height']));

        if ($width <= 0.0 || $height <= 0.0) {
            return null;
        }

        return new self(max(1, (int) ($data['page'] ?? 1)), round($x, 5), round($y, 5), round($width, 5), round($height, 5));
    }

    /**
     * @return array{page: int, x: float, y: float, width: float, height: float}
     */
    public function toArray(): array
    {
        return ['page' => $this->page, 'x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }
}
