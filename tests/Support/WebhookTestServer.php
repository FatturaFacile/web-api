<?php

declare(strict_types=1);

/**
 * A throwaway HTTPS listener on the loopback interface for the real webhook delivery tests. It runs as a separate PHP
 * process (the test and the HTTP client would otherwise block each other) and is started by
 * Tests\Support\WebhookTestListener.
 *
 * Usage: php WebhookTestServer.php <pem file with certificate and key> <ready file> <log file> <responses json>
 *
 * It writes its port to the ready file, then answers each request with the next scripted response (a list of
 * {"status": 200, "body": "ok", "location": "https://..."}; the last one repeats) and appends every request it
 * received to the log file as one JSON line: request line, headers and the raw body.
 */
[, $pem, $readyFile, $logFile, $responsesJson] = $argv + [null, null, null, null, '[]'];

$responses = json_decode((string) $responsesJson, true) ?: [['status' => 200, 'body' => 'ok']];

$context = stream_context_create(['ssl' => ['local_cert' => $pem, 'allow_self_signed' => true, 'verify_peer' => false]]);
$server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    file_put_contents($readyFile, 'error: '.$errstr);
    exit(1);
}

$port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
file_put_contents($readyFile, (string) $port);

$deadline = time() + 120;
$served = 0;

while (time() < $deadline) {
    $connection = @stream_socket_accept($server, 1);

    if ($connection === false) {
        continue;
    }

    stream_set_timeout($connection, 5);
    $head = '';

    while (! str_contains($head, "\r\n\r\n") && ! feof($connection)) {
        $chunk = fread($connection, 8192);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $head .= $chunk;
    }

    [$headText, $body] = array_pad(explode("\r\n\r\n", $head, 2), 2, '');
    $lines = explode("\r\n", $headText);
    $requestLine = array_shift($lines);
    $headers = [];

    foreach ($lines as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($name))] = trim($value);
    }

    $length = (int) ($headers['content-length'] ?? 0);

    while (strlen($body) < $length && ! feof($connection)) {
        $chunk = fread($connection, $length - strlen($body));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    if ($requestLine === null || $requestLine === '') {
        fclose($connection);

        continue;
    }

    file_put_contents($logFile, json_encode(['request_line' => $requestLine, 'headers' => $headers, 'body' => $body], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);

    $response = $responses[min($served, count($responses) - 1)];
    $served++;
    $status = (int) ($response['status'] ?? 200);
    $payload = (string) ($response['body'] ?? '');
    $extra = isset($response['location']) ? "Location: {$response['location']}\r\n" : '';

    fwrite($connection, "HTTP/1.1 {$status} Scripted\r\nContent-Type: text/plain\r\nContent-Length: ".strlen($payload)."\r\n{$extra}Connection: close\r\n\r\n{$payload}");
    fclose($connection);
}
