<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * One thing to ask a provider, in no provider's vocabulary.
 *
 * The prompts are written once and sent to whichever vendor is configured, so what
 * passes between the prompts and a transport has to be neutral. This is that: the text,
 * how much room the answer needs, and any images that belong with it.
 *
 * There is no "reply in JSON" flag. All seven calls want a JSON object every time, and a
 * parameter that never varies is noise. Transports are told so by the AiTransport
 * docblock and honour it in whatever way their vendor allows.
 */
final readonly class AiRequest
{
    /** @param  array<int, AiImage>  $images */
    private function __construct(
        public string $prompt,
        public int $maxTokens,
        public array $images = [],
    ) {}

    public static function for(string $prompt, int $maxTokens): self
    {
        return new self($prompt, $maxTokens);
    }

    /** Returns a new request. The object is readonly, so nothing is mutated in place. */
    public function withImage(AiImage $image): self
    {
        return new self($this->prompt, $this->maxTokens, [...$this->images, $image]);
    }
}
