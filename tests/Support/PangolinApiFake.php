<?php

namespace PelicanDev\PelicanPangolin\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Stateful API-boundary fixture, deliberately not a live Pangolin server. */
final class PangolinApiFake
{
    public array $resources = [];

    public array $targets = [];

    public bool $failNextTarget = false;

    private int $id = 90;

    public function install(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.example.com/*' => function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $body = $request->data();
            $data = [];
            if ($method === 'GET' && str_ends_with($path, '/public-resources')) {
                $data = ['resources' => array_values($this->resources), 'pagination' => ['total' => count($this->resources), 'pageSize' => 100]];
            } elseif ($method === 'GET' && str_ends_with($path, '/sites')) {
                $data = ['sites' => [['siteId' => 7, 'niceId' => 'test-site']], 'pagination' => ['total' => 1, 'pageSize' => 100]];
            } elseif ($method === 'GET' && str_ends_with($path, '/domains')) {
                $data = ['domains' => [['domainId' => 'domain-1', 'baseDomain' => 'example.com', 'verified' => true]], 'pagination' => ['total' => 1]];
            } elseif ($method === 'PUT' && str_ends_with($path, '/public-resource')) {
                $id = ++$this->id;
                $data = $this->resources[$id] = [...$body, 'resourceId' => $id, 'enabled' => false];
            } elseif (preg_match('~/public-resource/(\d+)/targets$~', $path, $matches) && $method === 'GET') {
                $items = array_values(array_filter($this->targets, fn ($target) => $target['resourceId'] === (int) $matches[1]));
                $data = ['targets' => $items, 'pagination' => ['total' => count($items)]];
            } elseif (preg_match('~/public-resource/(\d+)/target$~', $path, $matches) && $method === 'PUT') {
                if ($this->failNextTarget) {
                    $this->failNextTarget = false;

                    return Http::response(['success' => false, 'message' => 'Temporary target failure'], 503);
                }
                $id = ++$this->id;
                $data = $this->targets[$id] = [...$body, 'targetId' => $id, 'resourceId' => (int) $matches[1]];
            } elseif (preg_match('~/target/(\d+)$~', $path, $matches) && $method === 'POST') {
                $id = (int) $matches[1];
                $data = $this->targets[$id] = [...$this->targets[$id], ...$body];
            } elseif (preg_match('~/public-resource/(\d+)$~', $path, $matches)) {
                $id = (int) $matches[1];
                if (! isset($this->resources[$id])) {
                    return Http::response(['success' => false], 404);
                }
                if ($method === 'POST') {
                    $this->resources[$id] = [...$this->resources[$id], ...$body];
                }
                $data = $this->resources[$id];
                if ($method === 'DELETE') {
                    unset($this->resources[$id]);
                    $this->targets = array_filter($this->targets, fn ($target) => $target['resourceId'] !== $id);
                }
            } else {
                throw new \RuntimeException('Unexpected test API route: '.$method.' '.$path);
            }

            return Http::response(['success' => true, 'data' => $data]);
        }]);
    }
}
