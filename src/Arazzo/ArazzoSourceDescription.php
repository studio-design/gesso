<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

/**
 * One `sourceDescriptions[]` entry. `resolvedUrl` is the source location
 * resolved against the description's base URI: an absolute http(s) URL or a
 * dot-segment-normalised filesystem path.
 *
 * @internal Arazzo runner implementation detail; not public API.
 */
final readonly class ArazzoSourceDescription
{
    public function __construct(
        public string $name,
        public string $url,
        public string $type,
        public string $resolvedUrl,
    ) {}

    public function isRemote(): bool
    {
        return ArazzoDescription::isHttpUrl($this->resolvedUrl);
    }
}
