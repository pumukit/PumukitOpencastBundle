<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\MediaPackage;

use Pumukit\OpencastBundle\Exception\OpencastException;

final class MediaPackage
{
    public const PREVIEW_TYPES = ['presenter/search+preview', 'presentation/search+preview', 'presenter/player+preview', 'presentation/player+preview'];
    public const GALICASTER_PROPERTIES = 'galicaster-properties';
    public const EPISODE_CATALOG = 'dublincore/episode';
    public const SEGMENT_PREVIEW_TYPES = ['presentation/segment+preview', 'presenter/segment+preview'];

    private function __construct(private readonly array $data) {}

    public static function fromArray(array $data): self
    {
        $data = self::node($data);
        if (!isset($data['id']) || '' === (string) $data['id']) {
            throw new OpencastException('Mediapackage without id');
        }

        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function title(): ?string
    {
        return $this->string('title');
    }

    public function seriesId(): ?string
    {
        return $this->string('series');
    }

    public function seriesTitle(): ?string
    {
        return $this->string('seriestitle');
    }

    public function language(): ?string
    {
        return $this->string('language');
    }

    public function duration(): int
    {
        return (int) ($this->data['duration'] ?? 0);
    }

    public function start(): ?\DateTimeImmutable
    {
        return $this->date('start');
    }

    public function modified(): ?\DateTimeImmutable
    {
        return $this->date('modified');
    }

    public function withModified(?string $modified): self
    {
        if (null === $modified || '' === $modified) {
            return $this;
        }

        return new self(['modified' => $modified] + $this->data);
    }

    public function tracks(): array
    {
        return self::nodes($this->data['media']['track'] ?? null);
    }

    public function attachments(): array
    {
        return self::nodes($this->data['attachments']['attachment'] ?? null);
    }

    public function catalogs(): array
    {
        return self::nodes($this->data['metadata']['catalog'] ?? null);
    }

    public function segments(): array
    {
        foreach (self::SEGMENT_PREVIEW_TYPES as $type) {
            $previews = [];
            foreach ($this->attachmentsOfType($type) as $attachment) {
                $time = self::segmentTime((string) ($attachment['ref'] ?? ''));
                if (null !== $time && !empty($attachment['url'])) {
                    $previews[$time] = (string) $attachment['url'];
                }
            }
            if ([] === $previews) {
                continue;
            }

            ksort($previews);
            $times = array_keys($previews);
            $segments = [];
            foreach ($times as $index => $time) {
                $end = $times[$index + 1] ?? max($this->duration(), $time);
                $segments[] = ['index' => $index, 'time' => $time, 'duration' => $end - $time, 'preview' => $previews[$time]];
            }

            return $segments;
        }

        return [];
    }

    public function attachmentsOfType(string $type): array
    {
        return array_values(array_filter($this->attachments(), static fn (array $attachment) => ($attachment['type'] ?? null) === $type));
    }

    public function thumbnail(): ?string
    {
        foreach (self::PREVIEW_TYPES as $type) {
            foreach ($this->attachmentsOfType($type) as $attachment) {
                if (!empty($attachment['url'])) {
                    return (string) $attachment['url'];
                }
            }
        }

        return null;
    }

    public function galicasterPropertiesUrl(): ?string
    {
        foreach ($this->attachments() as $attachment) {
            if (self::GALICASTER_PROPERTIES === ($attachment['id'] ?? null) && !empty($attachment['url'])) {
                return (string) $attachment['url'];
            }
        }

        return null;
    }

    public function episodeCatalogUrl(): ?string
    {
        foreach ($this->catalogs() as $catalog) {
            if (self::EPISODE_CATALOG === ($catalog['type'] ?? null) && !empty($catalog['url'])) {
                return (string) $catalog['url'];
            }
        }

        return null;
    }

    public function trackUrls(): array
    {
        $urls = [];
        foreach ($this->tracks() as $track) {
            if (!empty($track['type']) && !empty($track['url'])) {
                $urls[$track['type']] = (string) $track['url'];
            }
        }

        return $urls;
    }

    public static function tags(array $element): array
    {
        $tags = $element['tags']['tag'] ?? [];
        $tags = is_array($tags) ? $tags : [$tags];

        return array_values(array_filter(array_map(static fn ($tag) => is_scalar($tag) ? (string) $tag : '', $tags), static fn (string $tag) => '' !== $tag));
    }

    public static function nodes(mixed $value): array
    {
        if (!is_array($value) || [] === $value) {
            return [];
        }
        $items = array_is_list($value) ? $value : [$value];

        return array_values(array_map(self::node(...), array_filter($items, 'is_array')));
    }

    private static function segmentTime(string $ref): ?int
    {
        if (!preg_match('/time=T(\d+):(\d+):(\d+):(\d+)F(\d+)/', $ref, $match)) {
            return null;
        }

        return (((int) $match[1] * 60 + (int) $match[2]) * 60 + (int) $match[3]) * 1000 + intdiv((int) $match[4] * 1000, max(1, (int) $match[5]));
    }

    private static function node(array $node): array
    {
        if (isset($node['@attributes']) && is_array($node['@attributes'])) {
            $node += $node['@attributes'];
            unset($node['@attributes']);
        }

        return $node;
    }

    private function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    private function date(string $key): ?\DateTimeImmutable
    {
        $value = $this->string($key);
        if (null === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
