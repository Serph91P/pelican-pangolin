<?php

namespace PelicanDev\PelicanPangolin\Services;

use DomainException;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Support\SanitizesErrors;
use Throwable;

final class NodeMappingValidator
{
    use SanitizesErrors;

    public function __construct(private readonly PangolinClient $client) {}

    public function validate(NodeMapping $mapping): bool
    {
        try {
            $sites = array_values(array_filter(
                $this->client->listSites(),
                fn (array $site): bool => (int) ($site['siteId'] ?? 0) === $mapping->site_id
                    && ($site['niceId'] ?? null) === $mapping->site_nice_id,
            ));
            if (count($sites) !== 1) {
                throw new DomainException('The Pangolin site is not uniquely present in the configured organization.');
            }

            $mapping->forceFill(['validated_at' => now(), 'validation_error' => null])->save();

            return true;
        } catch (Throwable $exception) {
            $mapping->forceFill([
                'validated_at' => null,
                'validation_error' => $this->sanitizeError($exception->getMessage()),
            ])->save();

            return false;
        }
    }
}
