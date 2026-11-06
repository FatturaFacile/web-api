<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

/**
 * Reads a certified e-mail. The attachments sent by SDI sit inside postacert.eml, a message nested in the transport
 * envelope of the PEC provider, so nested messages are walked as well.
 */
final class PecMessageParser
{
    /**
     * Files of the PEC envelope itself: they carry no SDI content.
     *
     * @var list<string>
     */
    private const array EnvelopeFiles = ['daticert.xml', 'smime.p7s', 'postacert.eml'];

    /**
     * @return array{headers: array<string, string>, text: string, attachments: list<array{name: string, content: string}>}
     */
    public function parse(string $raw): array
    {
        $result = ['headers' => [], 'text' => '', 'attachments' => []];
        [$headers] = $this->split($raw);
        $result['headers'] = $headers;

        $this->walk($raw, $result);

        return $result;
    }

    /**
     * @param  array{headers: array<string, string>, text: string, attachments: list<array{name: string, content: string}>}  $result
     */
    private function walk(string $raw, array &$result): void
    {
        [$headers, $body] = $this->split($raw);
        $contentType = $headers['content-type'] ?? 'text/plain';
        $type = strtolower(trim(explode(';', $contentType)[0]));

        if (str_starts_with($type, 'multipart/')) {
            foreach ($this->parts($body, $this->parameter($contentType, 'boundary')) as $part) {
                $this->walk($part, $result);
            }

            return;
        }

        $content = $this->decode($body, strtolower(trim($headers['content-transfer-encoding'] ?? '')));

        if ($type === 'message/rfc822') {
            $result['text'] .= "\n".implode("\n", $this->split($content)[0]);
            $this->walk($content, $result);

            return;
        }

        $name = $this->fileName($headers);

        if ($name === null) {
            if (str_starts_with($type, 'text/')) {
                $result['text'] .= "\n".$content;
            }

            return;
        }

        if (! in_array(strtolower($name), self::EnvelopeFiles, true)) {
            $result['attachments'][] = ['name' => $name, 'content' => $content];
        }
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function split(string $raw): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $block = preg_replace('/\r?\n[ \t]+/', ' ', $parts[0] ?? '') ?? '';
        $headers = [];

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] ??= trim($value);
            }
        }

        return [$headers, $parts[1] ?? ''];
    }

    /**
     * @return list<string>
     */
    private function parts(string $body, ?string $boundary): array
    {
        if ($boundary === null || $boundary === '') {
            return [];
        }

        $chunks = preg_split('/^--'.preg_quote($boundary, '/').'(?:--)?[ \t]*\r?$/m', $body) ?: [];

        // The first chunk is the preamble and the last one the epilogue.
        return array_values(array_map(ltrim(...), array_slice($chunks, 1, max(0, count($chunks) - 2))));
    }

    private function decode(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', true),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function fileName(array $headers): ?string
    {
        foreach (['content-disposition', 'content-type'] as $header) {
            $value = $headers[$header] ?? '';

            foreach (['filename', 'name'] as $parameter) {
                $name = $this->parameter($value, $parameter);

                if ($name !== null && $name !== '') {
                    return basename(str_replace('\\', '/', $name));
                }
            }

            if (preg_match('/filename\*=(?:[\w-]*\'[\w-]*\')?"?([^";]+)"?/i', $value, $extended) === 1) {
                return basename(str_replace('\\', '/', rawurldecode($extended[1])));
            }
        }

        return null;
    }

    private function parameter(string $header, string $name): ?string
    {
        if (preg_match('/(?:^|;)\s*'.$name.'\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $header, $match) !== 1) {
            return null;
        }

        return $match[1] !== '' ? $match[1] : ($match[2] ?? null);
    }
}
