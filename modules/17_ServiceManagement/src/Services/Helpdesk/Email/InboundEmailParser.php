<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk\Email;

/**
 * Reads the RFC 822 source of an e-mail: decoded headers, the text of the first text/plain part (or the text of the
 * HTML part when there is none), and the attachments. Nested messages are kept as attachments.
 */
final class InboundEmailParser
{
    public function parse(string $raw): InboundEmail
    {
        [$headers, $body] = $this->split($raw);
        $text = null;
        $html = null;
        $attachments = [];

        $this->walk($headers, $body, $text, $html, $attachments);

        $from = $this->address($headers['from'] ?? '');

        return new InboundEmail(
            headers: array_map(fn (string $value): string => $this->decodeHeader($value), $headers),
            messageId: $this->messageIds($headers['message-id'] ?? '')[0] ?? null,
            inReplyTo: $this->messageIds($headers['in-reply-to'] ?? '')[0] ?? null,
            references: $this->messageIds($headers['references'] ?? ''),
            fromEmail: $from['email'],
            fromName: $from['name'],
            subject: trim($this->decodeHeader($headers['subject'] ?? '')),
            body: trim($text ?? ($html === null ? '' : $this->htmlToText($html))),
            attachments: $attachments,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array{name: string, content: string, mime: string}>  $attachments
     */
    private function walk(array $headers, string $body, ?string &$text, ?string &$html, array &$attachments): void
    {
        $contentType = $headers['content-type'] ?? 'text/plain';
        $type = strtolower(trim(explode(';', $contentType)[0]));
        $disposition = strtolower($headers['content-disposition'] ?? '');

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $this->parameter($contentType, 'boundary');

            if ($boundary === null) {
                return;
            }

            foreach ($this->parts($body, $boundary) as $part) {
                [$partHeaders, $partBody] = $this->split($part);
                $this->walk($partHeaders, $partBody, $text, $html, $attachments);
            }

            return;
        }

        $content = $this->decodeBody($body, strtolower(trim($headers['content-transfer-encoding'] ?? '')));
        $name = $this->parameter($headers['content-disposition'] ?? '', 'filename') ?? $this->parameter($contentType, 'name');

        if ($name === null && ! str_starts_with($disposition, 'attachment')) {
            if ($type === 'text/plain' && $text === null) {
                $text = $this->toUtf8($content, $this->parameter($contentType, 'charset'));

                return;
            }

            if ($type === 'text/html' && $html === null) {
                $html = $this->toUtf8($content, $this->parameter($contentType, 'charset'));

                return;
            }

            if ($type !== 'message/rfc822') {
                return;
            }
        }

        $attachments[] = [
            'name' => $name === null ? ($type === 'message/rfc822' ? 'message.eml' : 'attachment.bin') : $this->decodeHeader($name),
            'content' => $content,
            'mime' => $type,
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function split(string $raw): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerBlock = preg_replace('/\r?\n[ \t]+/', ' ', $parts[0] ?? '') ?? '';
        $headers = [];

        foreach (preg_split('/\r?\n/', $headerBlock) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $headers[$name] = isset($headers[$name]) && $name === 'received' ? $headers[$name] : trim($value);
            }
        }

        return [$headers, $parts[1] ?? ''];
    }

    /**
     * @return list<string>
     */
    private function parts(string $body, string $boundary): array
    {
        $sections = preg_split('/\r?\n?--'.preg_quote($boundary, '/').'(?:--)?[ \t]*\r?\n?/', $body) ?: [];

        return array_values(array_filter(array_slice($sections, 1), static fn (string $section): bool => trim($section) !== ''));
    }

    private function parameter(string $header, string $name): ?string
    {
        if (preg_match('/(?:^|;)\s*'.preg_quote($name, '/').'\*?=\s*(?:"([^"]*)"|([^;\s]+))/i', $header, $match) !== 1) {
            return null;
        }

        $value = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

        if (preg_match("/^([\w-]+)''(.*)$/", $value, $extended) === 1) {
            return $this->toUtf8(rawurldecode($extended[2]), $extended[1]);
        }

        return $value;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    private function toUtf8(string $content, ?string $charset): string
    {
        $charset = strtoupper((string) $charset);

        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        $converted = @mb_convert_encoding($content, 'UTF-8', $charset);

        return is_string($converted) ? $converted : $content;
    }

    /**
     * @return array{email: ?string, name: ?string}
     */
    private function address(string $header): array
    {
        $header = $this->decodeHeader($header);

        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $header, $match) === 1) {
            return ['email' => strtolower(trim($match[2])), 'name' => trim($match[1]) !== '' ? trim($match[1]) : null];
        }

        $email = trim($header);

        return ['email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null, 'name' => null];
    }

    /**
     * @return list<string>
     */
    private function messageIds(string $header): array
    {
        preg_match_all('/<([^<>\s]+)>/', $header, $matches);

        return array_values(array_map(static fn (string $id): string => mb_substr($id, 0, 250), $matches[1]));
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</p>|</div>#i', "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
