<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\ValueObjects;

/**
 * One step of a spotlight tour: the element to highlight and the text to show. The texts are keyed by locale.
 */
final readonly class GuidedTourStep
{
    /**
     * @param  array<string, string>  $texts  Instruction by locale tag.
     */
    public function __construct(
        public string $route,
        public string $selector,
        public array $texts,
        public string $placement = 'bottom',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $texts */
        $texts = (array) ($data['texts'] ?? []);

        return new self(
            route: (string) ($data['route'] ?? ''),
            selector: (string) ($data['selector'] ?? ''),
            texts: $texts,
            placement: (string) ($data['placement'] ?? 'bottom'),
        );
    }

    /**
     * @return array{route: string, selector: string, texts: array<string, string>, placement: string}
     */
    public function toArray(): array
    {
        return ['route' => $this->route, 'selector' => $this->selector, 'texts' => $this->texts, 'placement' => $this->placement];
    }
}
