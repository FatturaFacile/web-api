<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Exceptions\SdiProviderException;
use Modules\SDI\Contracts\ImapMailbox;

/**
 * Minimal IMAP4rev1 client over a stream, enough to read a certified mailbox: it never changes the state of a message
 * (BODY.PEEK), so messages also read by people or other software stay untouched.
 */
final class NativeImapMailbox implements ImapMailbox
{
    private int $tag = 0;

    /**
     * @param  resource  $stream
     */
    private function __construct(private $stream) {}

    /**
     * @throws SdiProviderException when the mailbox cannot be reached or refuses the login
     */
    public static function connect(string $host, int $port, string $username, string $password): self
    {
        $stream = @stream_socket_client("ssl://{$host}:{$port}", $code, $error, 20);

        if ($stream === false) {
            throw new SdiProviderException("IMAP connection to {$host} failed: {$error}");
        }

        stream_set_timeout($stream, 30);

        return self::login($stream, $username, $password);
    }

    /**
     * @param  resource  $stream
     *
     * @throws SdiProviderException when the server refuses the login
     */
    public static function login($stream, string $username, string $password): self
    {
        $mailbox = new self($stream);
        $mailbox->readLine();
        $mailbox->command('LOGIN '.$mailbox->quote($username).' '.$mailbox->quote($password));
        $mailbox->command('SELECT INBOX');

        return $mailbox;
    }

    public function uidsAfter(int $uid): array
    {
        $uids = [];

        foreach ($this->command('UID SEARCH UID '.($uid + 1).':*') as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $uids = array_merge($uids, array_map(intval(...), preg_split('/\s+/', trim(substr($line, 8)), flags: PREG_SPLIT_NO_EMPTY) ?: []));
            }
        }

        // An IMAP range ending in * always includes the newest message, even when it is older than the start.
        $uids = array_values(array_filter($uids, fn (int $found): bool => $found > $uid));
        sort($uids);

        return $uids;
    }

    public function fetch(int $uid): string
    {
        $tag = $this->send("UID FETCH {$uid} BODY.PEEK[]");
        $body = '';

        while (true) {
            $line = $this->readLine();

            if (preg_match('/\{(\d+)\}$/', rtrim($line), $literal) === 1) {
                $body = $this->readBytes((int) $literal[1]);
            } elseif (str_starts_with($line, $tag.' ')) {
                $this->assertOk($line);

                return $body;
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, 'A'.(++$this->tag)." LOGOUT\r\n");
            fclose($this->stream);
        }
    }

    /**
     * @return list<string>
     */
    private function command(string $command): array
    {
        $tag = $this->send($command);
        $lines = [];

        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag.' ')) {
                $this->assertOk($line);

                return $lines;
            }

            $lines[] = rtrim($line);
        }
    }

    private function send(string $command): string
    {
        $tag = 'A'.(++$this->tag);
        fwrite($this->stream, "{$tag} {$command}\r\n");

        return $tag;
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);

        if ($line === false) {
            throw new SdiProviderException('IMAP connection closed by the server.');
        }

        return $line;
    }

    private function readBytes(int $length): string
    {
        $content = '';

        while (strlen($content) < $length) {
            $chunk = fread($this->stream, $length - strlen($content));

            if ($chunk === false || $chunk === '') {
                throw new SdiProviderException('IMAP connection closed while reading a message.');
            }

            $content .= $chunk;
        }

        return $content;
    }

    private function assertOk(string $line): void
    {
        if (preg_match('/^\S+ OK\b/', $line) !== 1) {
            throw new SdiProviderException('IMAP server refused the command: '.trim($line));
        }
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
