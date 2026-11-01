<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Client;

use Pumukit\OpencastBundle\Exception\OpencastException;

final readonly class OpencastResponse
{
    public function __construct(
        public string $url,
        public int $status,
        public string $body,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new OpencastException(sprintf('Invalid JSON from %s: %s', $this->url, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($data)) {
            throw new OpencastException(sprintf('Unexpected JSON from %s', $this->url));
        }

        return $data;
    }

    public function xml(): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->body, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if (false === $xml) {
            throw new OpencastException(sprintf('Invalid XML from %s', $this->url));
        }

        return json_decode(json_encode($xml, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function expect(int ...$statuses): self
    {
        if (!in_array($this->status, $statuses, true)) {
            throw new OpencastException(sprintf('Opencast returned %d for %s: %s', $this->status, $this->url, mb_substr(trim(strip_tags($this->body)), 0, 300)), $this->status);
        }

        return $this;
    }
}
