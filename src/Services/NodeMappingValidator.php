<?php

namespace PelicanDev\PelicanPangolin\Services;

use DomainException;
use Illuminate\Validation\ValidationException;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Support\SanitizesErrors;
use Throwable;

final class NodeMappingValidator
{
    use SanitizesErrors;

    public function __construct(private readonly PangolinClient $client) {}

    public static function validTarget(string $host): bool
    {
        return ! in_array($host, ['', '0.0.0.0', '::', '127.0.0.1', '::1'], true)
            && (filter_var($host, FILTER_VALIDATE_IP) !== false
                || (strlen($host) <= 253 && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $host)));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function selectedSite(array $data): array
    {
        abort_unless(user()?->accessibleNodes()->whereKey($data['node_id'])->exists() ?? false, 403);
        $sites = array_values(array_filter($this->client->listSites(), fn (array $site): bool => (int) ($site['siteId'] ?? 0) === (int) $data['site_id']));
        if (count($sites) !== 1 || empty($sites[0]['niceId']) || ! self::validTarget((string) ($data['target_host'] ?? ''))) {
            throw ValidationException::withMessages(['data.site_id' => 'Choose an existing organisation site and a concrete reachable wildcard target.']);
        }
        $data['site_nice_id'] = $sites[0]['niceId'];

        return $data;
    }

    public function validate(NodeMapping $mapping): bool
    {
        try {
            if (! self::validTarget($mapping->target_host)) {
                throw new DomainException('Configure a site-reachable host or IP, not a wildcard, URL or port.');
            }
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
