<?php

declare(strict_types=1);

namespace Tbank\Invest\Mcp;

final class StdioServer
{
    /** @param resource $input */
    /** @param resource $output */
    public function __construct(
        private readonly Protocol $protocol,
        private $input = STDIN,
        private $output = STDOUT,
    ) {
    }

    public function run(): void
    {
        stream_set_blocking($this->input, true);
        while (!feof($this->input)) {
            $message = $this->readMessage();
            if ($message === null) {
                break;
            }
            if ($message === []) {
                continue;
            }
            $response = $this->protocol->handle($message);
            if ($response !== null) {
                $this->writeMessage($response);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function readMessage(): ?array
    {
        $first = fgets($this->input);
        if ($first === false) {
            return null;
        }
        $first = rtrim($first, "\r\n");
        if ($first === '') {
            return [];
        }

        if (stripos($first, 'Content-Length:') === 0) {
            $headers = [$first];
            while (($line = fgets($this->input)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    break;
                }
                $headers[] = $line;
            }
            $length = 0;
            foreach ($headers as $header) {
                if (stripos($header, 'Content-Length:') === 0) {
                    $length = (int) trim(substr($header, strlen('Content-Length:')));
                }
            }
            $raw = $length > 0 ? stream_get_contents($this->input, $length) : '';
        } else {
            $raw = $first;
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $message */
    private function writeMessage(array $message): void
    {
        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fwrite($this->output, $payload . "\n");
        fflush($this->output);
    }
}
