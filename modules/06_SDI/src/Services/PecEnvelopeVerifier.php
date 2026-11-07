<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use DOMDocument;
use DOMXPath;

/**
 * Decides whether a message read from the certified mailbox really is a certified message sent by SDI.
 *
 * The PEC provider of the mailbox wraps every message it delivers in its own transport envelope (regole tecniche PEC,
 * DM 2 November 2005): the header X-Trasporto says posta-certificata only for a message that travelled between
 * certified mailboxes, and daticert.xml names the certified sender, authenticated by the provider that accepted the
 * message. An ordinary e-mail reaches the mailbox wrapped as an anomaly (X-Trasporto: errore) and a certified message
 * from any other mailbox carries its own address as sender, so neither can pass for SDI. The signature of the provider
 * on the envelope (smime.p7s) is not verified: no trusted list of the accredited providers is configured here.
 */
final class PecEnvelopeVerifier
{
    /**
     * Certified mailboxes of SDI: sdi01 for the first contact, then the dedicated sdiNN address (Allegato A, 1.3).
     */
    public const string SdiSenderPattern = '/^sdi\d+@pec\.fatturapa\.it$/i';

    /**
     * Reasons a message is not accepted as coming from SDI.
     */
    public const string NotCertified = 'pec_not_certified';

    public const string MissingCertificationData = 'pec_missing_daticert';

    public const string SenderNotSdi = 'pec_sender_not_sdi';

    public const string SenderMismatch = 'pec_sender_mismatch';

    /**
     * @param  array{headers: array<string, string>, envelope: array{daticert: ?string, inner_headers: ?array<string, string>}}  $message  As returned by PecMessageParser
     * @return string|null null when the message is a certified message of SDI, otherwise the reason it is not
     */
    public function reasonToDistrust(array $message): ?string
    {
        if (strtolower(trim($message['headers']['x-trasporto'] ?? '')) !== 'posta-certificata') {
            return self::NotCertified;
        }

        $certifiedSender = $this->certifiedSender($message['envelope']['daticert'] ?? null);

        if ($certifiedSender === null) {
            return self::MissingCertificationData;
        }

        if (preg_match(self::SdiSenderPattern, $certifiedSender) !== 1) {
            return self::SenderNotSdi;
        }

        $innerSender = $this->address((string) ($message['envelope']['inner_headers']['from'] ?? ''));

        if ($innerSender === null || strcasecmp($innerSender, $certifiedSender) !== 0) {
            return self::SenderMismatch;
        }

        return null;
    }

    /**
     * The sender in daticert.xml of a certified message: postacert[@tipo='posta-certificata']/intestazione/mittente.
     */
    private function certifiedSender(?string $daticert): ?string
    {
        if ($daticert === null || trim($daticert) === '') {
            return null;
        }

        $document = new DOMDocument;

        if (! @$document->loadXML($daticert, LIBXML_NONET)) {
            return null;
        }

        $root = $document->documentElement;

        if ($root === null || $root->localName !== 'postacert' || $root->getAttribute('tipo') !== 'posta-certificata') {
            return null;
        }

        $node = (new DOMXPath($document))->query('/*[local-name()="postacert"]/*[local-name()="intestazione"]/*[local-name()="mittente"]')->item(0);
        $sender = $node !== null ? trim($node->textContent) : '';

        return $sender === '' ? null : $sender;
    }

    /**
     * The address of a From header, with or without a display name.
     */
    private function address(string $header): ?string
    {
        if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $header, $match) === 1) {
            return $match[1];
        }

        $trimmed = trim($header);

        return filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false ? $trimmed : null;
    }
}
