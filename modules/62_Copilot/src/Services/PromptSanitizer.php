<?php

declare(strict_types=1);

namespace Modules\Copilot\Services;

/**
 * Keeps untrusted text from breaking out of the delimiters the system prompt relies on. Everything the Copilot reads from
 * the ERP or the knowledge base reaches the model inside `<retrieved_erp_data>` / `<knowledge_passage>` blocks that the
 * system prompt declares to be data, never instructions; text that tries to close or forge those blocks is defused here.
 */
final class PromptSanitizer
{
    private const string Pattern = '#<\s*(/?)\s*(retrieved_erp_data|knowledge_passage|retrieved_knowledge|system|assistant)\b#i';

    /**
     * The text a user typed: no control characters, no forged delimiters, bounded length.
     */
    public function prompt(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = $this->defuse(trim($text));

        return mb_substr($text, 0, (int) config('copilot.limits.max_prompt_chars', 4000));
    }

    /**
     * A tool result as the model sees it: JSON inside the data delimiters, with forged delimiters in the values defused.
     *
     * @param  array<string, mixed>  $data
     */
    public function wrapErpData(string $toolName, array $data): string
    {
        $json = $this->defuse((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return '<retrieved_erp_data tool="'.$toolName.'">'.$json.'</retrieved_erp_data>';
    }

    /**
     * A knowledge passage as the model sees it.
     */
    public function wrapPassage(string $reference, string $content): string
    {
        return '<knowledge_passage ref="'.str_replace('"', "'", $this->defuse($reference)).'">'.$this->defuse($content).'</knowledge_passage>';
    }

    private function defuse(string $text): string
    {
        return (string) preg_replace(self::Pattern, '&lt;$1$2', $text);
    }
}
