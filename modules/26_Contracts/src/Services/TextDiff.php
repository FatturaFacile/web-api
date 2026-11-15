<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

/**
 * Line based diff of two texts (longest common subsequence) used to show the redlines between document versions.
 */
final class TextDiff
{
    /**
     * @return list<array{type: 'equal'|'added'|'removed', text: string}>
     */
    public function lines(string $before, string $after): array
    {
        $a = $this->split($before);
        $b = $this->split($after);
        $n = count($a);
        $m = count($b);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['type' => 'equal', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $ops[] = ['type' => 'removed', 'text' => $a[$i++]];
            } else {
                $ops[] = ['type' => 'added', 'text' => $b[$j++]];
            }
        }

        for (; $i < $n; $i++) {
            $ops[] = ['type' => 'removed', 'text' => $a[$i]];
        }

        for (; $j < $m; $j++) {
            $ops[] = ['type' => 'added', 'text' => $b[$j]];
        }

        return $ops;
    }

    /**
     * @return list<string>
     */
    private function split(string $text): array
    {
        return $text === '' ? [] : (preg_split('/\R/', $text) ?: []);
    }
}
