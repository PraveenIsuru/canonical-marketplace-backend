<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * An image travelling with a prompt.
 *
 * Two of the seven calls send one, and they get hold of it in different ways: matching
 * has a path to a temporarily stored upload, verification has the raw bytes and never
 * learns where the file lived. Both end up here, so a transport only ever deals with
 * bytes and a media type and never has to know which of the two it was handed.
 *
 * The read happens here rather than in a transport on purpose. Every provider needs the
 * same bytes and fails in the same way when the file has gone, so doing it per vendor
 * would be the same guard and the same message written twice.
 */
final readonly class AiImage
{
    private function __construct(
        public string $bytes,
        public string $mimeType,
    ) {}

    /**
     * A photograph the caller already holds in memory.
     *
     * Nothing here retains it. The caller deletes the file the moment the call it
     * belongs to returns, and this object goes out of scope with the request.
     */
    public static function fromBytes(string $bytes, string $mimeType): self
    {
        return new self($bytes, $mimeType);
    }

    /**
     * A temporarily stored upload, read now rather than at send time.
     *
     * Reading while the request is being built means a missing file fails before
     * anything is sent, so a vanished upload never costs a provider call.
     *
     * @throws AiUnavailable
     */
    public static function fromPath(string $path): self
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw AiUnavailable::because('the uploaded image could not be read');
        }

        return new self($bytes, (string) (mime_content_type($path) ?: 'image/jpeg'));
    }

    public function base64(): string
    {
        return base64_encode($this->bytes);
    }
}
