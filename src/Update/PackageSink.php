<?php

namespace SUBandL\Update;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Where the package download goes: appends straight to the .part file and
 * feeds the SHA-256 as the bytes arrive, so a finished download needs no
 * extra pass over the whole file to verify it, and a chunked download needs
 * no temp slice file to copy. Until accept() is called (from on_headers,
 * once the status is known) the body is dropped — an error page never ends
 * up inside the package.
 */
final class PackageSink implements StreamInterface
{
    use StreamDecoratorTrait;

    /** Bytes written to the file during this transfer. */
    public int $written = 0;

    /** The transfer was ended because the chunk's time was up. */
    public bool $budgetSpent = false;

    /** The file could not be written (disk full, permission). */
    public bool $diskError = false;

    private bool $accepting = false;

    /** @var resource */
    private $handle;

    /** @var StreamInterface */
    private $stream;

    /**
     * @param int $offset  the bytes of the .part file already downloaded and hashed;
     *                     anything after them (a killed request's tail) is cut off
     * @param float $deadline  when the chunk's time is up (INF: never). Refusing a write
     *                         is what makes curl stop at once — an exception from
     *                         Guzzle's progress callback does not end the transfer.
     */
    public function __construct(string $path, int $offset, private ?\HashContext $hash, private float $deadline = INF)
    {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException("Could not open '{$path}' for writing.");
        }

        ftruncate($handle, $offset);
        fseek($handle, $offset);
        $this->handle = $handle;
        $this->stream = Utils::streamFor($handle);
    }

    /** The response is the expected bytes: start writing them. */
    public function accept(): void
    {
        $this->accepting = true;
    }

    /** The server sent the whole package instead of the range: start over at byte 0. */
    public function restart(): void
    {
        ftruncate($this->handle, 0);
        fseek($this->handle, 0);
        $this->hash = $this->hash ? hash_init('sha256') : null;
        $this->accepting = true;
    }

    public function hash(): ?\HashContext
    {
        return $this->hash;
    }

    public function write($string): int
    {
        $length = strlen($string);
        if (! $this->accepting || $length === 0) {
            return $length;
        }

        if ($this->written > 0 && microtime(true) >= $this->deadline) {
            $this->budgetSpent = true;

            return 0; // curl: "failure writing output" — ends the transfer here
        }

        $written = fwrite($this->handle, $string);
        if ($written !== $length) {
            // Disk full / permission: a short count makes curl stop the transfer.
            $this->diskError = true;

            return (int) $written;
        }

        $this->hash && hash_update($this->hash, $string);
        $this->written += $length;

        return $length;
    }
}
