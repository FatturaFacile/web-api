<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use DOMDocument;
use DOMXPath;

/**
 * Reads the receipts SDI sends about a transmitted file, named <sent file>_<type>_<progressive>.xml (Allegato A, 5.1).
 */
final class SdiReceiptParser
{
    private const string NamePattern = '/^(?<file>.+?)_(?<type>RC|NS|MC|NE|DT|AT|MT|EC|SE)_[A-Za-z0-9]{1,3}\.xml(\.p7m)?$/';

    /**
     * Receipt types that change the state of an invoice. MT travels with a supplier invoice; EC and SE only concern
     * public administrations answering on their own behalf.
     *
     * @var list<string>
     */
    private const array Handled = ['RC', 'NS', 'MC', 'NE', 'DT', 'AT'];

    public function isReceipt(string $fileName): bool
    {
        return preg_match(self::NamePattern, $fileName) === 1;
    }

    /**
     * @return array{uuid: string, event_type: string, message: ?string}|null null when the receipt changes nothing
     */
    public function parse(string $fileName, string $xml): ?array
    {
        if (preg_match(self::NamePattern, $fileName, $name) !== 1 || ! in_array($name['type'], self::Handled, true)) {
            return null;
        }

        $path = $this->xpath($xml);
        $sentFile = $path !== null ? $this->text($path, 'NomeFile') : null;
        $uuid = preg_replace('/\.xml(\.p7m)?$/i', '', $sentFile ?: $name['file']) ?? $name['file'];

        return [
            'uuid' => $uuid,
            'event_type' => $name['type'] === 'NE' ? $this->outcome($path) : $name['type'],
            'message' => $path !== null ? $this->message($path) : null,
        ];
    }

    private function xpath(string $xml): ?DOMXPath
    {
        $document = new DOMDocument;

        return @$document->loadXML($xml, LIBXML_NONET) ? new DOMXPath($document) : null;
    }

    private function text(DOMXPath $path, string $element): ?string
    {
        $node = $path->query("//*[local-name()='{$element}']")->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    private function outcome(?DOMXPath $path): string
    {
        return $path !== null && $this->text($path, 'Esito') === 'EC02' ? 'NEC2' : 'NEC1';
    }

    private function message(DOMXPath $path): ?string
    {
        $parts = [];

        foreach ($path->query("//*[local-name()='Errore']") as $error) {
            $parts[] = trim(preg_replace('/\s+/', ' ', $error->textContent) ?? '');
        }

        $note = $this->text($path, 'Note') ?? $this->text($path, 'Descrizione');

        if ($parts === [] && $note !== null && $note !== '') {
            $parts[] = $note;
        }

        return $parts === [] ? null : mb_substr(implode(' | ', $parts), 0, 1000);
    }
}
