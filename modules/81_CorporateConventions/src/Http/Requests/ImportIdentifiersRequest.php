<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The employer list: either a JSON array of identifiers or a CSV / TXT / JSON file. With dry_run the identifiers are only hashed and
 * previewed, nothing is stored.
 */
final class ImportIdentifiersRequest extends FormRequest
{
    private const array HeaderNames = ['IDENTIFIER', 'MATRICOLA', 'BADGE', 'CARD', 'CODICE', 'ID', 'PERSONALNUMMER', 'MATRICULE'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identifiers' => ['required_without:file', 'array', 'max:50000'],
            'identifiers.*' => ['string', 'max:120'],
            'file' => ['required_without:identifiers', 'file', 'mimes:csv,txt,json', 'max:5120'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    public function identifiers(): array
    {
        if ($this->hasFile('file')) {
            return $this->parseFile((string) file_get_contents($this->file('file')->getRealPath()), strtolower((string) $this->file('file')->getClientOriginalExtension()));
        }

        return array_values(array_map('strval', (array) $this->input('identifiers', [])));
    }

    /**
     * @return list<string>
     */
    private function parseFile(string $content, string $extension): array
    {
        if ($extension === 'json') {
            $decoded = json_decode($content, true);

            return is_array($decoded) ? array_values(array_map(static fn (mixed $item): string => is_array($item) ? (string) (reset($item) ?: '') : (string) $item, $decoded)) : [];
        }

        $identifiers = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($content)) ?: [] as $index => $line) {
            $cell = trim((string) (str_getcsv($line, ',', '"', '')[0] ?? ''));

            if ($cell === '' || ($index === 0 && in_array(mb_strtoupper($cell), self::HeaderNames, true))) {
                continue;
            }

            $identifiers[] = $cell;
        }

        return $identifiers;
    }
}
