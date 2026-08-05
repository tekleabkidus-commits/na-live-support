<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Media;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * T-04's streamed download, THROUGH the egress proxy (SEC-03): the adapter's
 * sourceUrl is hostile input, and this class never talks to it directly — the
 * proxy's filter file is the allowlist, and a non-allowlisted host dies with
 * the proxy's 403 before a byte of it reaches PHP.
 *
 * The size cap is enforced IN-STREAM (T-04: "no full-buffer"; EC-02): bytes are
 * counted as chunks arrive and the transfer aborts the moment the cap is
 * crossed — a Content-Length header is advisory at best and absent or lying at
 * worst, so it is checked first only as a fast-fail courtesy.
 *
 * Returns a rewound php://temp stream (spills to disk past 2 MB — memory stays
 * bounded no matter the cap).
 */
final class MediaFetcher
{
    private const CHUNK_BYTES = 65536;

    public function __construct(private readonly string $proxyUrl) {}

    /**
     * @return resource
     *
     * @throws MediaTooLarge on an EC-02 mid-stream cap crossing
     * @throws RuntimeException on refusal (incl. the proxy's SEC-03 403) or transport failure
     */
    public function fetch(string $sourceUrl, int $capBytes)
    {
        $client = new Client([
            'proxy' => $this->proxyUrl,
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);

        try {
            $response = $client->request('GET', $sourceUrl, ['stream' => true]);
        } catch (GuzzleException $e) {
            throw new RuntimeException(sprintf('media fetch refused or failed (SEC-03 posture): %s', $e->getMessage()), previous: $e);
        }

        $declared = $response->getHeaderLine('Content-Length');

        if ($declared !== '' && is_numeric($declared) && (int) $declared > $capBytes) {
            throw new MediaTooLarge($capBytes);
        }

        $body = $response->getBody();
        $buffer = fopen('php://temp/maxmemory:2097152', 'r+b');

        if ($buffer === false) {
            throw new RuntimeException('could not open a temp stream for the media download.');
        }

        $received = 0;

        while (! $body->eof()) {
            $chunk = $body->read(self::CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            $received += strlen($chunk);

            if ($received > $capBytes) {
                $body->close(); // abort the transfer mid-stream (EC-02)
                fclose($buffer);

                throw new MediaTooLarge($capBytes);
            }

            fwrite($buffer, $chunk);
        }

        rewind($buffer);

        return $buffer;
    }
}
