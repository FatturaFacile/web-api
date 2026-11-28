<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Support;

/**
 * SHA-256 Merkle tree over the chain hashes of the events of a period. An odd node is paired with itself.
 */
final class MerkleTree
{
    /**
     * @param  list<string>  $leaves  hex hashes in event order
     */
    public static function root(array $leaves): string
    {
        if ($leaves === []) {
            return hash('sha256', '');
        }

        $level = $leaves;

        while (count($level) > 1) {
            $next = [];

            for ($i = 0; $i < count($level); $i += 2) {
                $next[] = hash('sha256', $level[$i].($level[$i + 1] ?? $level[$i]));
            }

            $level = $next;
        }

        return $level[0];
    }
}
