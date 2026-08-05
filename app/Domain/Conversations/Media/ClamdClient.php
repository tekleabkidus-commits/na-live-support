<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Media;

use Illuminate\Support\Facades\Log;

/**
 * The hand-rolled clamd INSTREAM client (ADR 0008, deliberately no composer
 * dependency: the protocol is a length-prefixed byte stream with a one-line
 * verdict, and a third-party wrapper would be a larger supply-chain surface
 * than these lines).
 *
 * Protocol: send `zINSTREAM\0`, then {uint32 big-endian length}{bytes} chunks,
 * then a zero-length chunk; clamd answers `stream: OK` or
 * `stream: {SIGNATURE} FOUND`.
 *
 * Failure posture is EC-11's: any transport problem returns Unavailable — the
 * caller HOLDS the item in `scanning`. There is no default-allow path and no
 * way to add one without deleting the enum case (AC-05's scan-timeout fixture
 * pins the behavior).
 */
final class ClamdClient
{
    private const CHUNK_BYTES = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * @param  resource  $stream  positioned at the start of the bytes to scan
     * @return array{verdict: ScanVerdict, signature: ?string}
     */
    public function scan($stream): array
    {
        $socket = @fsockopen($this->host, $this->port, $errorCode, $errorMessage, $this->timeoutSeconds);

        if ($socket === false) {
            Log::warning('clamd unreachable — media held in scanning (EC-11)', [
                'metric' => 'media.scan_unavailable',
                'error_code' => $errorCode,
            ]);

            return ['verdict' => ScanVerdict::Unavailable, 'signature' => null];
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);

            fwrite($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($socket, pack('N', 0));

            $reply = fgets($socket);

            if (! is_string($reply)) {
                Log::warning('clamd gave no verdict — media held in scanning (EC-11)', [
                    'metric' => 'media.scan_unavailable',
                ]);

                return ['verdict' => ScanVerdict::Unavailable, 'signature' => null];
            }

            $reply = trim($reply, "\x00\n ");

            if (str_ends_with($reply, 'OK')) {
                return ['verdict' => ScanVerdict::Clean, 'signature' => null];
            }

            if (str_ends_with($reply, 'FOUND')) {
                $signature = trim(str_replace(['stream:', 'FOUND'], '', $reply));

                return ['verdict' => ScanVerdict::Infected, 'signature' => $signature];
            }

            // ERROR or anything unrecognized: not a verdict about the file.
            Log::warning('clamd unexpected reply — media held in scanning (EC-11)', [
                'metric' => 'media.scan_unavailable',
                'reply' => $reply,
            ]);

            return ['verdict' => ScanVerdict::Unavailable, 'signature' => null];
        } finally {
            fclose($socket);
        }
    }
}
