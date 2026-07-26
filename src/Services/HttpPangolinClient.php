<?php

namespace PelicanDev\PelicanPangolin\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Exceptions\PangolinApiException;
use Throwable;

final class HttpPangolinClient implements PangolinClient
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $organizationId,
        private readonly string $token,
        private readonly int $connectTimeout = 3,
        private readonly int $timeout = 10,
    ) {
        $parts = parse_url($baseUrl);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The Pangolin API URL must be an unambiguous HTTPS URL.');
        }

        if ($organizationId === '' || $token === '') {
            throw new InvalidArgumentException('The Pangolin organization ID and API token are required.');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $path = str_ends_with($path, '/v1') ? $path : $path.'/v1';
        $this->baseUrl = "https://{$parts['host']}{$port}{$path}";
    }

    public function listSites(int $pageSize = 100): array
    {
        return $this->pageNumberInventory(
            "/org/{$this->organizationId}/sites",
            'sites',
            $pageSize,
        );
    }

    public function listDomains(int $pageSize = 100): array
    {
        return $this->offsetInventory(
            "/org/{$this->organizationId}/domains",
            'domains',
            $pageSize,
        );
    }

    public function listResources(int $pageSize = 100): array
    {
        return $this->pageNumberInventory(
            "/org/{$this->organizationId}/public-resources",
            'resources',
            $pageSize,
        );
    }

    public function getResource(int $resourceId): array
    {
        return $this->request('get', "/public-resource/{$resourceId}");
    }

    public function listTargets(int $resourceId, int $pageSize = 100): array
    {
        return $this->offsetInventory(
            "/public-resource/{$resourceId}/targets",
            'targets',
            $pageSize,
        );
    }

    public function createRawResource(string $name, PublicationMode $mode, int $publicPort): array
    {
        if (! in_array($mode, [PublicationMode::Tcp, PublicationMode::Udp], true)) {
            throw new InvalidArgumentException('Raw resources must use TCP or UDP.');
        }

        return $this->request('put', "/org/{$this->organizationId}/public-resource", [
            'name' => $name,
            'mode' => $mode->value,
            'proxyPort' => $publicPort,
        ]);
    }

    public function createHttpResource(string $name, string $domainId, ?string $subdomain): array
    {
        return $this->request('put', "/org/{$this->organizationId}/public-resource", [
            'name' => $name,
            'mode' => 'http',
            'domainId' => $domainId,
            'subdomain' => $subdomain,
        ]);
    }

    public function updateResource(int $resourceId, array $fields): array
    {
        return $this->request('post', "/public-resource/{$resourceId}", $fields);
    }

    public function deleteResource(int $resourceId): void
    {
        $this->request('delete', "/public-resource/{$resourceId}");
    }

    public function createTarget(int $resourceId, int $siteId, string $host, int $port, PublicationMode $mode): array
    {
        return $this->request('put', "/public-resource/{$resourceId}/target", $this->targetBody($siteId, $host, $port, $mode));
    }

    public function updateTarget(int $targetId, int $siteId, string $host, int $port, PublicationMode $mode): array
    {
        return $this->request('post', "/target/{$targetId}", $this->targetBody($siteId, $host, $port, $mode));
    }

    /** @return array<string, mixed> */
    private function targetBody(int $siteId, string $host, int $port, PublicationMode $mode): array
    {
        $body = [
            'siteId' => $siteId,
            'ip' => $host,
            'port' => $port,
            'mode' => $mode->isHttp() ? 'http' : $mode->value,
            'enabled' => true,
        ];

        if ($mode->isHttp()) {
            $body['method'] = $mode->value;
        }

        return $body;
    }

    /** @return list<array<string, mixed>> */
    private function offsetInventory(string $path, string $key, int $pageSize): array
    {
        $items = [];
        $offset = 0;

        do {
            $data = $this->request('get', $path, ['limit' => $pageSize, 'offset' => $offset]);
            $batch = $data[$key] ?? null;

            if (! is_array($batch)) {
                throw new PangolinApiException("Pangolin returned an invalid {$key} response.");
            }

            array_push($items, ...$batch);
            $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
            $offset += count($batch);
            $total = (int) ($pagination['total'] ?? $offset);
        } while ($batch !== [] && $offset < $total);

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function pageNumberInventory(string $path, string $key, int $pageSize): array
    {
        $items = [];
        $page = 1;

        do {
            $data = $this->request('get', $path, ['pageSize' => $pageSize, 'page' => $page]);
            $batch = $data[$key] ?? null;

            if (! is_array($batch)) {
                throw new PangolinApiException("Pangolin returned an invalid {$key} response.");
            }

            array_push($items, ...$batch);
            $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
            $total = filter_var($pagination['total'] ?? null, FILTER_VALIDATE_INT);
            $responsePageSize = filter_var($pagination['pageSize'] ?? null, FILTER_VALIDATE_INT);
            $totalPages = $total !== false && $responsePageSize !== false && $responsePageSize > 0
                ? (int) ceil($total / $responsePageSize)
                : (int) ($pagination['totalPages'] ?? $page);
            $page++;
        } while ($batch !== [] && $page <= $totalPages);

        return $items;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        try {
            $response = $this->pending()->send(strtoupper($method), $path, [
                $method === 'get' ? 'query' : 'json' => $payload,
            ]);
        } catch (ConnectionException $exception) {
            throw new PangolinApiException('Unable to connect to the Pangolin API.', null);
        } catch (Throwable $exception) {
            throw new PangolinApiException($this->redact($exception->getMessage()), null);
        }

        return $this->unwrap($response);
    }

    private function pending(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->token)
            ->baseUrl($this->baseUrl)
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withoutRedirecting()
            ->withOptions(['verify' => true]);
    }

    /** @return array<string, mixed> */
    private function unwrap(Response $response): array
    {
        $json = $response->json();

        if (! $response->successful() || ! is_array($json) || ($json['success'] ?? false) !== true) {
            $message = is_array($json) && is_string($json['message'] ?? null)
                ? $json['message']
                : "Pangolin API request failed with HTTP {$response->status()}.";

            throw new PangolinApiException($this->redact($message), $response->status());
        }

        $data = $json['data'] ?? [];

        if (! is_array($data)) {
            throw new PangolinApiException('Pangolin returned an invalid response.', $response->status());
        }

        return $data;
    }

    private function redact(string $message): string
    {
        $message = str_replace($this->token, '[REDACTED]', $message);

        return (string) preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message);
    }
}
