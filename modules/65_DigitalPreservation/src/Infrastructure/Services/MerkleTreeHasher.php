<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use Modules\DigitalPreservation\Domain\ValueObjects\Sha256Hash;

/**
 * SHA-256 leaf hashes and the Merkle root over the files of a batch. An odd node is paired with itself. The root of an
 * empty batch is the hash of the empty string.
 */
final class MerkleTreeHasher
{
    /**
     * Hash of one file.
     */
    public function leaf(string $content): string
    {
        return Sha256Hash::ofContent($content)->hex;
    }

    /**
     * Merkle root over leaf digests (hexadecimal), in the given order.
     *
     * @param  list<string>  $leafHashes
     */
    public function root(array $leafHashes): string
    {
        if ($leafHashes === []) {
            return Sha256Hash::ofContent('')->hex;
        }

        $level = array_map(static fn (string $hex): string => Sha256Hash::fromHex($hex)->binary(), $leafHashes);

        while (count($level) > 1) {
            $next = [];

            for ($i = 0, $n = count($level); $i < $n; $i += 2) {
                $next[] = hash('sha256', $level[$i].($level[$i + 1] ?? $level[$i]), true);
            }

            $level = $next;
        }

        return bin2hex($level[0]);
    }

    /**
     * Whether the leaves still produce the expected root.
     *
     * @param  list<string>  $leafHashes
     */
    public function verify(array $leafHashes, string $expectedRoot): bool
    {
        return hash_equals(strtolower($expectedRoot), $this->root($leafHashes));
    }
}
