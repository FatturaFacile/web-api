<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Starts WebhookTestServer.php (a throwaway HTTPS listener on 127.0.0.1) with a self-signed certificate generated for
 * the test, and reads back what it received. The certificate is trusted by the client through the testing-only option
 * webhooks.testing_http_options (see config/webhooks.php); nothing of this exists outside the testing environment.
 */
final class WebhookTestListener
{
    public readonly int $port;

    public readonly string $certificate;

    private mixed $process;

    private string $directory;

    /**
     * @param  list<array{status: int, body?: string, location?: string}>  $responses
     */
    public function __construct(array $responses)
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ffwh_'.bin2hex(random_bytes(6));
        mkdir($this->directory);

        $pem = $this->makeCertificate();
        $this->certificate = $this->directory.DIRECTORY_SEPARATOR.'ca.pem';
        file_put_contents($this->certificate, $this->certificatePem);

        $ready = $this->directory.DIRECTORY_SEPARATOR.'ready';
        $command = [PHP_BINARY, __DIR__.DIRECTORY_SEPARATOR.'WebhookTestServer.php', $pem, $ready, $this->logFile(), json_encode($responses, JSON_THROW_ON_ERROR)];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->directory.DIRECTORY_SEPARATOR.'out.txt', 'w'], 2 => ['file', $this->directory.DIRECTORY_SEPARATOR.'err.txt', 'w']], $pipes);

        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline && ! (is_file($ready) && trim((string) file_get_contents($ready)) !== '')) {
            usleep(50_000);
        }

        $port = trim((string) @file_get_contents($ready));

        if (! ctype_digit($port)) {
            $this->stop();

            throw new RuntimeException('The test listener did not start: '.$port.' '.@file_get_contents($this->directory.DIRECTORY_SEPARATOR.'err.txt'));
        }

        $this->port = (int) $port;
    }

    public function url(string $path = '/hook'): string
    {
        return "https://127.0.0.1:{$this->port}{$path}";
    }

    /**
     * @return list<array{request_line: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $file = $this->logFile();

        if (! is_file($file)) {
            return [];
        }

        return array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private string $certificatePem = '';

    private function logFile(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'requests.jsonl';
    }

    /**
     * A self-signed certificate valid for the IP 127.0.0.1, written with its key to one PEM file (returned).
     */
    private function makeCertificate(): string
    {
        $config = $this->directory.DIRECTORY_SEPARATOR.'openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=v3\nprompt=no\n[dn]\nCN=127.0.0.1\n[v3]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=CA:TRUE\n");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'x509_extensions' => 'v3'];

        $key = openssl_pkey_new($options);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
        $certificate = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 1, $options);

        if ($certificate === false) {
            throw new RuntimeException('The test certificate could not be generated: '.openssl_error_string());
        }

        openssl_x509_export($certificate, $this->certificatePem);
        openssl_pkey_export($key, $privateKey, null, ['config' => $config]);

        $pem = $this->directory.DIRECTORY_SEPARATOR.'server.pem';
        file_put_contents($pem, $this->certificatePem.$privateKey);

        return $pem;
    }
}
