<?php

namespace PelicanDev\PelicanPangolin\Services;

use InvalidArgumentException;

final class PluginSettings
{
    /** @param array<string, mixed> $configured
     * @return array<string, mixed>
     */
    public function formData(array $configured): array
    {
        $configured['token'] = '';

        return $configured;
    }

    /** @param array<string, mixed> $data */
    public function validate(array $data): void
    {
        $parts = parse_url((string) ($data['api_url'] ?? ''));
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Pangolin API URL must use HTTPS.');
        }

        if (trim((string) ($data['organization_id'] ?? '')) === '' || trim((string) ($data['token'] ?? '')) === '') {
            throw new InvalidArgumentException('Pangolin organization ID and API token are required.');
        }

        PublicPortAllocator::from((string) ($data['tcp_ports'] ?? ''), (string) ($data['udp_ports'] ?? ''));

        foreach (['connect_timeout', 'timeout'] as $field) {
            if (isset($data[$field]) && ((int) $data[$field] < 1 || (int) $data[$field] > 60)) {
                throw new InvalidArgumentException('Pangolin timeouts must be between 1 and 60 seconds.');
            }
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function environment(array $data, string $existingToken): array
    {
        $token = trim((string) ($data['token'] ?? ''));

        return [
            'PANGOLIN_API_URL' => rtrim((string) $data['api_url'], '/'),
            'PANGOLIN_ORGANIZATION_ID' => (string) $data['organization_id'],
            'PANGOLIN_API_TOKEN' => $token === '' ? $existingToken : $token,
            'PANGOLIN_DEFAULT_DOMAIN' => (string) ($data['default_domain'] ?? ''),
            'PANGOLIN_TCP_PORTS' => (string) $data['tcp_ports'],
            'PANGOLIN_UDP_PORTS' => (string) $data['udp_ports'],
            'PANGOLIN_CONNECT_TIMEOUT' => (int) ($data['connect_timeout'] ?? 3),
            'PANGOLIN_TIMEOUT' => (int) ($data['timeout'] ?? 10),
        ];
    }
}
