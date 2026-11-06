<?php

declare(strict_types=1);

use App\Exceptions\SdiProviderException;
use Modules\SDI\Services\NativeImapMailbox;
use Modules\SDI\Services\P7mExtractor;
use Modules\SDI\Services\PecMessageParser;
use Modules\SDI\Services\SdiReceiptParser;

/**
 * A real CAdES envelope around the sample below, made once with: openssl cms -sign -binary -nodetach -outform DER
 */
function signedInvoice(): string
{
    return (string) base64_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/sdi/signed-invoice.p7m.b64'));
}

$sample = '<?xml version="1.0"?><p:FatturaElettronica xmlns:p="urn:x" versione="FPR12"><Numero>1</Numero></p:FatturaElettronica>';

test('a plain XML file passes through, BOM included', function () use ($sample): void {
    expect((new P7mExtractor)->extract("\xEF\xBB\xBF".$sample))->toBe($sample);
});

test('a signed file in binary form is opened', function () use ($sample): void {
    expect((new P7mExtractor)->extract(signedInvoice()))->toBe($sample);
});

test('a signed file encoded in base64 is opened', function () use ($sample): void {
    expect((new P7mExtractor)->extract(chunk_split(base64_encode(signedInvoice()))))->toBe($sample);
});

test('garbage is refused', function (): void {
    (new P7mExtractor)->extract('this is not an invoice');
})->throws(InvalidArgumentException::class);

test('a receipt name and its content are read', function (): void {
    $xml = '<n:NotificaScarto xmlns:n="urn:x"><NomeFile>IT1_00001.xml.p7m</NomeFile><ListaErrori><Errore><Codice>00404</Codice><Descrizione>Duplicata</Descrizione></Errore></ListaErrori></n:NotificaScarto>';

    $receipt = (new SdiReceiptParser)->parse('IT1_00001_NS_001.xml', $xml);

    expect($receipt['uuid'])->toBe('IT1_00001')
        ->and($receipt['event_type'])->toBe('NS')
        ->and($receipt['message'])->toContain('00404');
});

test('the outcome of a public administration decides the event', function (string $esito, string $event): void {
    $xml = "<n:NotificaEsito xmlns:n=\"urn:x\"><NomeFile>IT1_00001.xml</NomeFile><EsitoCommittente><Esito>{$esito}</Esito></EsitoCommittente></n:NotificaEsito>";

    expect((new SdiReceiptParser)->parse('IT1_00001_NE_001.xml', $xml)['event_type'])->toBe($event);
})->with([['EC01', 'NEC1'], ['EC02', 'NEC2']]);

test('metadata files are receipts that change nothing and invoices are not receipts', function (): void {
    $parser = new SdiReceiptParser;

    expect($parser->isReceipt('IT1_00001_MT_001.xml'))->toBeTrue()
        ->and($parser->parse('IT1_00001_MT_001.xml', '<x/>'))->toBeNull()
        ->and($parser->isReceipt('IT01234567890_00001.xml'))->toBeFalse()
        ->and($parser->isReceipt('IT01234567890_00001.xml.p7m'))->toBeFalse();
});

test('attachments nested in postacert.eml are found and the envelope files ignored', function (): void {
    $encoded = base64_encode('<xml/>');
    $raw = "From: a@b\r\nX-Trasporto: posta-certificata\r\nContent-Type: multipart/signed; boundary=\"s\"\r\n\r\n"
        ."--s\r\nContent-Type: multipart/mixed; boundary=\"m\"\r\n\r\n"
        ."--m\r\nContent-Type: text/plain\r\n\r\nCorpo\r\n"
        ."--m\r\nContent-Type: application/xml\r\nContent-Disposition: attachment; filename=daticert.xml\r\n\r\n<d/>\r\n"
        ."--m\r\nContent-Type: message/rfc822\r\n\r\nFrom: sdi24@pec.fatturapa.it\r\nContent-Type: multipart/mixed; boundary=\"i\"\r\n\r\n"
        ."--i\r\nContent-Type: application/octet-stream; name=\"IT1_00001.xml\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encoded}\r\n--i--\r\n"
        ."\r\n--m--\r\n"
        ."--s\r\nContent-Type: application/pkcs7-signature; name=smime.p7s\r\nContent-Transfer-Encoding: base64\r\n\r\nAAAA\r\n--s--\r\n";

    $message = (new PecMessageParser)->parse($raw);

    expect($message['attachments'])->toBe([['name' => 'IT1_00001.xml', 'content' => '<xml/>']])
        ->and($message['headers']['x-trasporto'])->toBe('posta-certificata')
        ->and($message['text'])->toContain('sdi24@pec.fatturapa.it');
});

/**
 * @return array{0: resource, 1: resource} the client side and the scripted server side of a connection
 */
function imapConnection(string $script): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($pair[1], $script);

    return [$pair[0], $pair[1]];
}

test('the IMAP client lists new UIDs and downloads a message untouched', function (): void {
    $body = "From: a@b\r\n\r\nBody {42}\r\n";
    [$client, $server] = imapConnection(
        "* OK ready\r\nA1 OK logged in\r\n* 2 EXISTS\r\nA2 OK selected\r\n"
        ."* SEARCH 5 7 9\r\nA3 OK done\r\n"
        .'* 2 FETCH (UID 7 BODY[] {'.strlen($body)."}\r\n{$body})\r\nA4 OK done\r\n"
    );

    $mailbox = NativeImapMailbox::login($client, 'user', 'pa"ss');

    expect($mailbox->uidsAfter(5))->toBe([7, 9])
        ->and($mailbox->fetch(7))->toBe($body);

    $commands = fread($server, 4096);

    expect($commands)->toContain('A1 LOGIN "user" "pa\\"ss"')
        ->and($commands)->toContain('A3 UID SEARCH UID 6:*')
        ->and($commands)->toContain('A4 UID FETCH 7 BODY.PEEK[]');
});

test('a refused login stops the client', function (): void {
    // Keep the server side open for the whole test: if it were garbage collected, the client write would hit a broken pipe.
    [$client, $server] = imapConnection("* OK ready\r\nA1 NO invalid credentials\r\n");

    try {
        NativeImapMailbox::login($client, 'user', 'wrong');
    } finally {
        fclose($server);
    }
})->throws(SdiProviderException::class);
