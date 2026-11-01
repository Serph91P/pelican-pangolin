<?php

use App\Enums\ServerState;
use App\Filament\Admin\Resources\Plugins\Pages\ListPlugins;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PelicanDev\PelicanPangolin\Data\DesiredPublication;
use PelicanDev\PelicanPangolin\Data\DesiredResource;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Filament\Admin\Pages\Infrastructure;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages\CreateNodeMapping;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\ServerPublications\Pages\ListServerPublications;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\ServerPublications\ServerPublicationResource;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;
use PelicanDev\PelicanPangolin\Tests\NativePanelTestCase;
use PelicanDev\PelicanPangolin\Tests\Support\PangolinApiFake;

uses(NativePanelTestCase::class);

function drainAllocationQueue(): void
{
    // --once models a fresh worker invocation without retaining daemon shouldQuit state.
    for ($attempt = 0; $attempt < 20 && DB::table('jobs')->where('available_at', '<=', time())->exists(); $attempt++) {
        Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);
    }
    expect(DB::table('jobs')->where('available_at', '<=', time())->count())->toBe(0);
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    $this->withoutVite();
    $root = User::factory()->create();
    Role::findOrCreate(Role::ROOT_ADMIN, 'web');
    $root->assignRole(Role::ROOT_ADMIN);
    $this->actingAs($root);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->api = new PangolinApiFake;
    $this->api->install();
});

it('onboards from empty settings via native forms and publishes only explicit live allocations', function () {
    config()->set('pelican-pangolin', ['api_url' => '', 'organization_id' => '', 'token' => '', 'public_host' => '', 'default_domain' => '', 'connect_timeout' => 3, 'timeout' => 10]);
    // EnvironmentWriterTrait uses this isolated dependency checkout, never production.
    $env = $this->app->environmentFilePath();
    $original = is_file($env) ? file_get_contents($env) : null;
    try {
        $settingsPage = Livewire::test(ListPlugins::class)->assertTableActionExists('exclude_settings', record: Plugin::findOrFail('pelican-pangolin'))->mountTableAction('exclude_settings', Plugin::findOrFail('pelican-pangolin'));
        expect($settingsPage->getMountedActionModalHtml())->toContain('General Settings', 'listSites', 'no root key');
        $settingsPage
            ->setTableActionData(['api_url' => 'https://api.example.com', 'organization_id' => 'test-org', 'token' => 'fixture-only', 'public_host' => 'games.example.com', 'default_domain' => '', 'connect_timeout' => 3, 'timeout' => 10])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        expect(config('pelican-pangolin'))->not->toHaveKeys(['tcp_ports', 'udp_ports']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/domains') || $request->method() !== 'GET');
        $server = Server::factory()->create();
        $allocation = $server->allocation;
        $allocation->forceFill(['server_id' => $server->id, 'node_id' => $server->node_id, 'ip' => '10.0.0.42', 'port' => 30000, 'ip_alias' => null])->save();
        $unknown = Allocation::factory()->create(['server_id' => $server->id, 'node_id' => $server->node_id, 'ip' => '10.0.0.43', 'port' => 30001, 'ip_alias' => null]);
        Livewire::test(CreateNodeMapping::class)->fillForm(['node_id' => $server->node_id, 'site_id' => 7, 'target_host' => 'wildcard.internal', 'enabled' => true])->call('create')->assertHasNoFormErrors();
        $mapping = NodeMapping::where('node_id', $server->node_id)->firstOrFail();
        expect($mapping->site_nice_id)->toBe('test-site')->and($mapping->validated_at)->not->toBeNull();
        $page = Livewire::test(EditServer::class, ['record' => $server->id]);
        $page->mountAction('manage_pangolin_publication')->assertActionDataSet(['opted_in' => false]);
        expect($page->getMountedActionModalHtml())->toContain('10.0.0.42', '30000', '10.0.0.43', 'ID '.$allocation->id);
        $page->setActionData(['opted_in' => true, 'overrides' => [['allocation_id' => $allocation->id, 'mode' => 'both', 'purpose' => 'game']]])->callMountedAction()->assertHasNoActionErrors();
        $publication = ServerPublication::where('server_id', $server->id)->firstOrFail();
        expect(DB::table('jobs')->count())->toBe(1);
        drainAllocationQueue();
        expect($publication->fresh()->status->value)->toBe('synced')->and(array_column($this->api->targets, 'ip'))->toBe(['10.0.0.42', '10.0.0.42'])
            ->and($allocation->fresh()->address)->toBe('games.example.com:30000')->and($allocation->fresh()->ip)->toBe('10.0.0.42')->and($unknown->fresh()->ip_alias)->toBeNull();
        $revision = $publication->fresh()->desired_revision;
        $ids = array_keys($this->api->resources);
        $server->update(['name' => 'Renamed']);
        app(PublicationRefreshService::class)->refreshAll();
        expect($publication->fresh()->desired_revision)->toBe($revision)->and($allocation->fresh()->ip_alias)->toBe('games.example.com');
        drainAllocationQueue();
        expect(array_keys($this->api->resources))->toBe($ids);
        $allocation->update(['port' => 30002, 'ip' => '10.0.0.44']);
        drainAllocationQueue();
        expect($publication->fresh()->status->value)->toBe('synced')->and(array_column($this->api->targets, 'ip'))->toBe(['10.0.0.44', '10.0.0.44'])
            ->and(array_column($this->api->resources, 'proxyPort'))->toBe([30002, 30002])->and($allocation->fresh()->address)->toBe('games.example.com:30002');
        Livewire::test(Infrastructure::class)->assertSee('tcp-30002')->assertSee('udp-30002')->assertDontSee('tcp-30001');
        expect(Artisan::call('pangolin:infrastructure'))->toBe(0);
        expect(Artisan::output())->toContain('tcp-30002', 'udp-30002', 'external reachability are not verified');
        $this->api->failNextDelete = true;
        Livewire::test(EditServer::class, ['record' => $server->id])->callAction('manage_pangolin_publication', data: ['opted_in' => false, 'overrides' => []])->assertHasNoActionErrors();
        // Bulk deletion represents the recovery path for a missing native server.
        DB::table('servers')->where('id', $server->id)->delete();
        drainAllocationQueue();
        expect($publication->fresh()->deletion_pending)->toBeTrue()->and($publication->fresh()->last_error)->toContain('Temporary deletion failure')->and($publication->resources()->count())->toBe(2);
        Livewire::test(ListServerPublications::class)->assertCanSeeTableRecords([$publication])->callTableAction('retry', $publication)->assertHasNoTableActionErrors();
        drainAllocationQueue();
        expect($this->api->resources)->toBe([])->and($publication->fresh()->deletion_pending)->toBeFalse();
        expect(Artisan::call('pangolin:infrastructure'))->toBe(0);
        expect(Artisan::output())->toContain('No approved raw allocations', 'Remote rows retained: none');
    } finally {
        if ($original === null) {
            @unlink($env);
        } else {
            file_put_contents($env, $original);
        }
    }
});

it('keeps no selection distinct from suspension and configuration failure', function () {
    config()->set(['pelican-pangolin.api_url' => 'https://api.example.com', 'pelican-pangolin.organization_id' => 'test-org', 'pelican-pangolin.token' => 'fixture-only']);
    $server = Server::factory()->create();
    $allocation = $server->allocation;
    $allocation->forceFill(['server_id' => $server->id, 'ip' => '10.0.0.42', 'port' => 30000])->save();
    Livewire::test(EditServer::class, ['record' => $server->id])->callAction('manage_pangolin_publication', data: ['opted_in' => true, 'overrides' => []])->assertHasNoActionErrors();
    drainAllocationQueue();
    $publication = ServerPublication::where('server_id', $server->id)->firstOrFail();

    expect($publication->fresh()->status->value)->toBe('unselected')->and($publication->fresh()->suspended)->toBeFalse();
    Livewire::test(EditServer::class, ['record' => $server->id])->callAction('manage_pangolin_publication', data: ['opted_in' => true, 'overrides' => [['allocation_id' => $allocation->id, 'mode' => 'tcp', 'purpose' => 'game']]])->assertHasNoActionErrors();
    drainAllocationQueue();
    expect($publication->fresh()->status->value)->toBe('blocked')->and($publication->fresh()->suspended)->toBeFalse()->and($publication->fresh()->last_error)->toContain('mapping');
    $server->update(['status' => ServerState::Suspended]);
    drainAllocationQueue();
    expect($publication->fresh()->status->value)->toBe('disabled')->and($publication->fresh()->suspended)->toBeTrue();
    Livewire::test(ListServerPublications::class)->assertTableColumnStateSet('suspended', true, record: $publication);
});

it('reports invalid settings or failed reads without saving credentials or claiming write permission', function (int $status) {
    config()->set('pelican-pangolin', ['api_url' => '', 'organization_id' => '', 'token' => '', 'public_host' => '', 'default_domain' => '', 'connect_timeout' => 3, 'timeout' => 10]);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.example.com/*' => Http::response(['success' => false, 'message' => 'Read unavailable'], $status ?: 401)]);
    Livewire::test(ListPlugins::class)->mountTableAction('exclude_settings', Plugin::findOrFail('pelican-pangolin'))->setTableActionData(['api_url' => 'https://api.example.com', 'organization_id' => 'test-org', 'token' => $status === 0 ? '' : 'fixture-only', 'public_host' => 'games.example.com', 'default_domain' => '', 'connect_timeout' => 3, 'timeout' => 10])->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Pangolin read check failed');
    expect(config('pelican-pangolin.token'))->toBe('')->and(config('pelican-pangolin.api_url'))->toBe('');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([0, 401, 403, 503]);

it('preserves legacy per-ID approvals but removes profile-only exposure and does not inherit numeric replacements or transfers', function () {
    config()->set(['pelican-pangolin.api_url' => 'https://api.example.com', 'pelican-pangolin.organization_id' => 'test-org', 'pelican-pangolin.token' => 'fixture-only']);
    $server = Server::factory()->create();
    $allocation = $server->allocation;
    $allocation->forceFill(['server_id' => $server->id, 'node_id' => $server->node_id, 'ip' => '10.0.0.42', 'port' => 30000, 'ip_alias' => null])->save();
    NodeMapping::create(['node_id' => $server->node_id, 'site_id' => 7, 'site_nice_id' => 'test-site', 'target_host' => 'node.internal', 'enabled' => true, 'validated_at' => now()]);
    $publication = ServerPublication::create(['server_id' => $server->id, 'server_uuid' => $server->uuid, 'opted_in' => true, 'desired_state' => ['profile_rules' => [['allocation_port' => 30000, 'mode' => 'both', 'purpose' => 'game']], 'overrides' => [$allocation->id => ['purpose' => 'game', 'mode' => 'tcp']]]]);
    app(PublicationReconciler::class)->reconcile($publication, new DesiredPublication(1, 7, 'node.internal', [
        new DesiredResource($allocation->id, 30000, PublicationMode::Tcp, null),
        new DesiredResource($allocation->id, 30000, PublicationMode::Udp, null),
    ]));
    $legacyUdpId = $publication->resources()->where('mode', 'udp')->firstOrFail()->pangolin_resource_id;
    expect(count($this->api->resources))->toBe(2);
    app(PublicationRefreshService::class)->refreshAll();
    drainAllocationQueue();
    expect(isset($this->api->resources[$legacyUdpId]))->toBeFalse();
    expect(array_column($this->api->resources, 'mode'))->toBe(['tcp'])->and($publication->fresh()->desired_state['legacy_reapproval'])->toBeTrue();
    $replacement = Allocation::factory()->create(['node_id' => $server->node_id, 'server_id' => null, 'ip' => '10.0.0.43', 'port' => 30000, 'ip_alias' => null]);
    DB::table('allocations')->where('id', $allocation->id)->update(['server_id' => null]);
    DB::table('allocations')->where('id', $replacement->id)->update(['server_id' => $server->id]);
    DB::table('servers')->where('id', $server->id)->update(['allocation_id' => $replacement->id]);
    app(PublicationRefreshService::class)->refreshAll();
    drainAllocationQueue();
    expect($publication->fresh()->status->value)->toBe('unselected')->and($this->api->resources)->toBe([])->and($replacement->fresh()->ip_alias)->toBeNull();
    Livewire::test(EditServer::class, ['record' => $server->id])->callAction('manage_pangolin_publication', data: ['opted_in' => true, 'overrides' => [['allocation_id' => $replacement->id, 'purpose' => 'query', 'mode' => 'udp']]])->assertHasNoActionErrors();
    drainAllocationQueue();
    expect(array_column($this->api->resources, 'mode'))->toBe(['udp']);
    $node = Node::factory()->create();
    DB::table('servers')->where('id', $server->id)->update(['node_id' => $node->id]);
    DB::table('allocations')->where('id', $replacement->id)->update(['node_id' => $node->id]);
    app(PublicationRefreshService::class)->refreshAll();
    drainAllocationQueue();
    expect($publication->fresh()->desired_state['overrides'])->toBe([])->and($this->api->resources)->toBe([])->and($replacement->fresh()->ip_alias)->toBeNull();
});

it('hides deleted-server ownership from non-root users while keeping it recoverable for root', function () {
    $publication = ServerPublication::create(['server_id' => 2147483647, 'server_uuid' => '3a3c5ce9-8a95-4a44-ac80-78a5ea811886', 'deletion_pending' => true, 'status' => 'error', 'last_error' => 'Cleanup failed']);
    expect(ServerPublicationResource::getEloquentQuery()->whereKey($publication->id)->exists())->toBeTrue();
    $this->actingAs(User::factory()->create());
    expect(ServerPublicationResource::getEloquentQuery()->whereKey($publication->id)->exists())->toBeFalse();
});

it('synchronizes single-protocol setups without unrelated pool or HTTP prerequisites', function (string $mode) {
    config()->set('pelican-pangolin', ['api_url' => 'https://api.example.com', 'organization_id' => 'test-org', 'token' => 'fixture-only', 'public_host' => $mode === 'http' ? '' : '2001:db8::10', 'default_domain' => '', 'connect_timeout' => 3, 'timeout' => 10]);
    $server = Server::factory()->create();
    $allocation = $server->allocation;
    $allocation->forceFill(['server_id' => $server->id, 'node_id' => $server->node_id, 'ip' => '2001:db8::42', 'port' => 30000, 'ip_alias' => 'manual.example.com'])->save();
    NodeMapping::create(['node_id' => $server->node_id, 'site_id' => 7, 'site_nice_id' => 'test-site', 'target_host' => 'wildcard.internal', 'enabled' => true, 'validated_at' => now()]);
    Livewire::test(EditServer::class, ['record' => $server->id])->callAction('manage_pangolin_publication', data: ['opted_in' => true, 'overrides' => [['allocation_id' => $allocation->id, 'purpose' => 'game', 'mode' => $mode, 'domain' => $mode === 'http' ? 'example.com' : null]]])->assertHasNoActionErrors();
    drainAllocationQueue();
    $publication = ServerPublication::where('server_id', $server->id)->firstOrFail();
    expect($publication->status->value)->toBe('synced')->and(array_column($this->api->resources, 'mode'))->toBe([$mode])
        ->and(array_column($this->api->targets, 'ip'))->toBe(['2001:db8::42'])->and($allocation->fresh()->ip_alias)->toBe('manual.example.com')
        ->and($publication->resources()->firstOrFail()->confirmed_endpoint)->toBe($mode === 'http' ? 'http://example.com' : '[2001:db8::10]:30000');
    if ($mode !== 'http') {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/domains'));
    }
})->with(['tcp', 'udp', 'http']);
