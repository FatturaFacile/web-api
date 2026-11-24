<?php

declare(strict_types=1);

namespace Modules\Copilot\Embeddings;

use Modules\Copilot\Contracts\EmbeddingProviderInterface;

/**
 * Model-less embedding by feature hashing: lowercase word tokens (accents folded) and their character trigrams are hashed
 * into a fixed number of buckets and the vector is normalised. It is deterministic, multilingual by construction (it only
 * compares surface forms) and sends nothing outside the installation. It finds lexically similar passages, not synonyms.
 */
final class LocalHashEmbeddingProvider implements EmbeddingProviderInterface
{
    private const int Dimensions = 256;

    public function name(): string
    {
        return 'local';
    }

    public function isExternal(): bool
    {
        return false;
    }

    public function embed(string $text): array
    {
        $vector = array_fill(0, self::Dimensions, 0.0);
        $folded = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text));
        $tokens = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $vector[$this->bucket('w:'.$token)] += 2.0;

            $padded = '^'.$token.'$';
            for ($i = 0, $length = strlen($padded) - 2; $i < $length; $i++) {
                $vector[$this->bucket('t:'.substr($padded, $i, 3))] += 1.0;
            }
        }

        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));

        if ($norm > 0.0) {
            $vector = array_map(static fn (float $value): float => $value / $norm, $vector);
        }

        return array_values($vector);
    }

    private function bucket(string $feature): int
    {
        return (int) (hexdec(substr(md5($feature), 0, 8)) % self::Dimensions);
    }
}
