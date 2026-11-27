<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows\Pain002;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * Reads a pain.002 status report. The reader is namespace agnostic, so the `.001.03` and `.001.10` generations used
 * by Swiss banks are both accepted. A transaction without a status of its own inherits the one of its payment
 * information block, which in turn falls back to the status of the whole file.
 */
final class Pain002Parser
{
    private const string INVALID = 'The file is not a valid pain.002 document.';

    /**
     * @throws InvalidArgumentException when the document is not a pain.002 report
     */
    public function parse(string $xml): Pain002Report
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");

        if ($xml === '' || preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new InvalidArgumentException(self::INVALID);
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new InvalidArgumentException(self::INVALID);
        }

        $xpath = new DOMXPath($document);
        $original = $this->node($xpath, "//*[local-name()='OrgnlGrpInfAndSts']");
        $originalMessageId = $original instanceof DOMElement ? $this->text($xpath, "*[local-name()='OrgnlMsgId']", $original) : null;

        if (! $original instanceof DOMElement || $originalMessageId === null) {
            throw new InvalidArgumentException(self::INVALID);
        }

        $groupStatus = $this->text($xpath, "*[local-name()='GrpSts']", $original);
        [$groupReason, $groupInfo] = $this->reason($xpath, $original);
        $paymentInformation = [];
        $transactions = [];

        foreach ($xpath->query("//*[local-name()='OrgnlPmtInfAndSts']") ?: [] as $block) {
            if (! $block instanceof DOMElement) {
                continue;
            }

            $blockStatus = $this->text($xpath, "*[local-name()='PmtInfSts']", $block) ?? $groupStatus;
            [$blockReason, $blockInfo] = $this->reason($xpath, $block);
            $blockReason ??= $groupReason;
            $blockInfo ??= $groupInfo;
            $blockId = $this->text($xpath, "*[local-name()='OrgnlPmtInfId']", $block);

            if ($blockId !== null && $blockStatus !== null) {
                $paymentInformation[$blockId] = ['status' => $blockStatus, 'reason_code' => $blockReason, 'additional_info' => $blockInfo];
            }

            foreach ($xpath->query("*[local-name()='TxInfAndSts']", $block) ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $status = $this->text($xpath, "*[local-name()='TxSts']", $node) ?? $blockStatus;

                if ($status === null) {
                    continue;
                }

                [$reason, $info] = $this->reason($xpath, $node);
                $transactions[] = new Pain002Transaction(
                    endToEndId: $this->text($xpath, "*[local-name()='OrgnlEndToEndId']", $node),
                    instructionId: $this->text($xpath, "*[local-name()='OrgnlInstrId']", $node),
                    status: $status,
                    reasonCode: $reason ?? $blockReason,
                    additionalInfo: $info ?? $blockInfo,
                );
            }
        }

        return new Pain002Report(
            messageId: $this->text($xpath, "//*[local-name()='GrpHdr']/*[local-name()='MsgId']") ?? $originalMessageId,
            originalMessageId: $originalMessageId,
            originalMessageType: $this->text($xpath, "*[local-name()='OrgnlMsgNmId']", $original),
            groupStatus: $groupStatus ?? ($paymentInformation === [] ? null : array_values($paymentInformation)[0]['status']),
            groupReasonCode: $groupReason,
            groupAdditionalInfo: $groupInfo,
            paymentInformation: $paymentInformation,
            transactions: $transactions,
        );
    }

    /**
     * The reason of a status: the code of the first `StsRsnInf` (coded or proprietary) and its additional information.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function reason(DOMXPath $xpath, DOMElement $context): array
    {
        $code = $this->text($xpath, "*[local-name()='StsRsnInf']/*[local-name()='Rsn']/*[local-name()='Cd']", $context)
            ?? $this->text($xpath, "*[local-name()='StsRsnInf']/*[local-name()='Rsn']/*[local-name()='Prtry']", $context);
        $info = [];

        foreach ($xpath->query("*[local-name()='StsRsnInf']/*[local-name()='AddtlInf']", $context) ?: [] as $node) {
            $value = trim($node->textContent);

            if ($value !== '') {
                $info[] = $value;
            }
        }

        return [$code === null ? null : mb_substr($code, 0, 35), $info === [] ? null : mb_substr(implode(' ', $info), 0, 500)];
    }

    private function node(DOMXPath $xpath, string $query, ?DOMElement $context = null): ?DOMNode
    {
        $list = $context === null ? $xpath->query($query) : $xpath->query($query, $context);

        return $list === false ? null : $list->item(0);
    }

    private function text(DOMXPath $xpath, string $query, ?DOMElement $context = null): ?string
    {
        $node = $this->node($xpath, $query, $context);
        $value = $node === null ? '' : trim($node->textContent);

        return $value === '' ? null : $value;
    }
}
