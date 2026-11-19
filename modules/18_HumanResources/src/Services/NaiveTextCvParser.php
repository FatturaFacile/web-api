<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Modules\HumanResources\Contracts\CvParserInterface;
use Modules\HumanResources\DTOs\ParsedCvDTO;
use Modules\HumanResources\Enums\CvParseStatus;
use Throwable;
use ZipArchive;

/**
 * Default CV parser used until the AI CV parsing of Mod 092 exists.
 *
 * It extracts plain text (text files, DOCX through `word/document.xml`, PDF through `pdftotext` when installed or a
 * best-effort reading of the PDF text operators otherwise), then finds email and phone with regular expressions,
 * skills and languages with the keyword dictionaries of `config/recruiting.php` and education / experience entries
 * with a simple heading split. It never throws: unreadable documents are reported as `failed`.
 */
final readonly class NaiveTextCvParser implements CvParserInterface
{
    private const array EDUCATION_HEADINGS = ['education', 'istruzione', 'formazione', 'ausbildung', 'bildung', 'formation', 'studi', 'studies'];

    private const array EXPERIENCE_HEADINGS = ['experience', 'work experience', 'professional experience', 'esperienza', 'esperienze', 'esperienza professionale', 'esperienze professionali', 'berufserfahrung', 'erfahrung', 'expérience', 'expérience professionnelle', 'experience professionnelle'];

    private const array OTHER_HEADINGS = ['skills', 'competenze', 'kenntnisse', 'compétences', 'competences', 'languages', 'lingue', 'sprachen', 'langues', 'profile', 'profilo', 'profil', 'summary', 'interests', 'interessi', 'hobby', 'references', 'referenze', 'contact', 'contatti', 'kontakt'];

    public function parse(string $contents, string $mimeType, string $fileName): ParsedCvDTO
    {
        try {
            $text = $this->extractText($contents, $mimeType, $fileName);
        } catch (Throwable) {
            return ParsedCvDTO::failed();
        }

        $text = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text));
        if ($text === '' || mb_strlen($text) < 3) {
            return ParsedCvDTO::failed();
        }

        return $this->parseText($text);
    }

    /**
     * Parse already extracted plain text.
     */
    public function parseText(string $text): ParsedCvDTO
    {
        $email = $this->findEmail($text);
        $phone = $this->findPhone($text);
        $skills = $this->findSkills($text);
        $languages = $this->findLanguages($text);
        $sections = $this->splitSections($text);

        $education = array_map(fn (string $line): array => [
            'institution' => $line,
            'degree' => null,
            'period' => $this->findPeriod($line),
        ], $sections['education']);

        $experience = array_map(fn (string $line): array => [
            'company' => $line,
            'role' => null,
            'period' => $this->findPeriod($line),
            'summary' => null,
        ], $sections['experience']);

        $structured = $skills !== [] || $languages !== [] || $education !== [] || $experience !== [];

        return new ParsedCvDTO(
            status: $structured ? CvParseStatus::Parsed : CvParseStatus::Partial,
            skills: $skills,
            email: $email,
            phone: $phone,
            profile: [
                'education' => $education,
                'experience' => $experience,
                'languages' => $languages,
                'skills' => $skills,
            ]
        );
    }

    private function extractText(string $contents, string $mimeType, string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension === 'pdf' || str_contains($mimeType, 'pdf') || str_starts_with($contents, '%PDF')) {
            return $this->extractPdfText($contents);
        }

        if ($extension === 'docx' || str_contains($mimeType, 'officedocument.wordprocessingml')) {
            return $this->extractDocxText($contents);
        }

        if ($extension === 'doc' || $mimeType === 'application/msword') {
            // Legacy binary Word: keep the printable runs of text, enough for keyword matching.
            preg_match_all('/[\p{L}\p{N}@+.,:;()\/\- ]{4,}/u', (string) mb_convert_encoding($contents, 'UTF-8', 'UTF-8'), $matches);

            return implode("\n", $matches[0]);
        }

        return (string) mb_convert_encoding($contents, 'UTF-8', 'UTF-8');
    }

    private function extractDocxText(string $contents): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }

        $path = tempnam(sys_get_temp_dir(), 'cv');
        if ($path === false) {
            return '';
        }

        try {
            file_put_contents($path, $contents);
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                return '';
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if (! is_string($xml)) {
                return '';
            }

            $xml = (string) preg_replace('/<\/w:p>|<w:br\s*\/>|<w:tab\s*\/>/', "\n", $xml);

            return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } finally {
            @unlink($path);
        }
    }

    private function extractPdfText(string $contents): string
    {
        $binary = (string) config('recruiting.pdftotext_binary', '');
        if ($binary !== '' && is_executable($binary) && function_exists('proc_open')) {
            $text = $this->runPdfToText($binary, $contents);
            if (trim($text) !== '') {
                return $text;
            }
        }

        return $this->readPdfTextOperators($contents);
    }

    private function runPdfToText(string $binary, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cv');
        if ($path === false) {
            return '';
        }

        try {
            file_put_contents($path, $contents);
            $process = proc_open([$binary, '-q', '-enc', 'UTF-8', $path, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                return '';
            }

            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return $exitCode === 0 ? $output : '';
        } finally {
            @unlink($path);
        }
    }

    /**
     * Best-effort reading of the text drawn by `Tj` / `TJ` operators, in plain or Flate-compressed content streams.
     */
    private function readPdfTextOperators(string $contents): string
    {
        $streams = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $contents, $matches) > 0) {
            foreach ($matches[1] as $stream) {
                $inflated = @gzuncompress($stream);
                $streams[] = is_string($inflated) ? $inflated : $stream;
            }
        }

        $lines = [];
        foreach ($streams as $stream) {
            if (preg_match_all('/\[(.*?)\]\s*TJ|\((.*?)(?<!\\\\)\)\s*Tj|(T\*|Td|TD|ET)/s', $stream, $operators, PREG_SET_ORDER) === 0) {
                continue;
            }

            $current = '';
            foreach ($operators as $operator) {
                if (isset($operator[3])) {
                    if (trim($current) !== '') {
                        $lines[] = trim($current);
                    }
                    $current = '';

                    continue;
                }

                if (isset($operator[1]) && $operator[1] !== '') {
                    preg_match_all('/\((.*?)(?<!\\\\)\)/s', $operator[1], $parts);
                    $current .= implode('', array_map($this->unescapePdfString(...), $parts[1]));
                } elseif (isset($operator[2])) {
                    $current .= $this->unescapePdfString($operator[2]);
                }
            }

            if (trim($current) !== '') {
                $lines[] = trim($current);
            }
        }

        return (string) mb_convert_encoding(implode("\n", $lines), 'UTF-8', 'UTF-8');
    }

    private function unescapePdfString(string $value): string
    {
        return strtr($value, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\', '\\n' => "\n", '\\r' => '', '\\t' => ' ']);
    }

    private function findEmail(string $text): ?string
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $match) === 1 ? strtolower($match[0]) : null;
    }

    private function findPhone(string $text): ?string
    {
        if (preg_match('/(?:\+|00)\d{2}[\d \/.\-]{6,16}\d|\b0\d{1,3}[ \/.\-]?\d{3}[ \/.\-]?\d{2,4}[ \/.\-]?\d{0,4}\b/', $text, $match) !== 1) {
            return null;
        }

        return trim((string) preg_replace('/\s+/', ' ', $match[0]));
    }

    /**
     * @return list<string>
     */
    private function findSkills(string $text): array
    {
        $skills = [];
        foreach ((array) config('recruiting.skills_dictionary', []) as $skill) {
            $skill = (string) $skill;
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($skill, '/').'(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text) === 1) {
                $skills[] = $skill;
            }
        }

        return array_values(array_unique($skills));
    }

    /**
     * @return list<array{language: string, level: string|null}>
     */
    private function findLanguages(string $text): array
    {
        $languages = [];
        foreach ((array) config('recruiting.languages_dictionary', []) as $label => $keywords) {
            foreach ((array) $keywords as $keyword) {
                $pattern = '/(?<![\p{L}])'.preg_quote((string) $keyword, '/').'(?![\p{L}])[^\n,;|]{0,40}/iu';
                if (preg_match($pattern, $text, $match) === 1) {
                    $level = null;
                    if (preg_match('/\b([ABC][12])\b/i', $match[0], $levelMatch) === 1) {
                        $level = strtoupper($levelMatch[1]);
                    } elseif (preg_match('/madrelingua|native|muttersprache|langue maternelle|mother tongue/iu', $match[0]) === 1) {
                        $level = 'native';
                    }

                    $languages[] = ['language' => (string) $label, 'level' => $level];

                    break;
                }
            }
        }

        return $languages;
    }

    /**
     * @return array{education: list<string>, experience: list<string>}
     */
    private function splitSections(string $text): array
    {
        $sections = ['education' => [], 'experience' => []];
        $current = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $heading = mb_strtolower(rtrim($line, " :\t"));
            if (in_array($heading, self::EDUCATION_HEADINGS, true)) {
                $current = 'education';

                continue;
            }

            if (in_array($heading, self::EXPERIENCE_HEADINGS, true)) {
                $current = 'experience';

                continue;
            }

            if (in_array($heading, self::OTHER_HEADINGS, true)) {
                $current = null;

                continue;
            }

            if ($current !== null && count($sections[$current]) < 15) {
                $sections[$current][] = mb_substr($line, 0, 250);
            }
        }

        return $sections;
    }

    private function findPeriod(string $line): ?string
    {
        if (preg_match('/\b(19|20)\d{2}\s*[-–]\s*((19|20)\d{2}|present|oggi|heute|aujourd\'hui|today|current)\b/iu', $line, $match) === 1) {
            return $match[0];
        }

        return preg_match('/\b(19|20)\d{2}\b/', $line, $match) === 1 ? $match[0] : null;
    }
}
