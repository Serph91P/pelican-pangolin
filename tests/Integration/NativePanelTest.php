<?php

use App\Enums\ServerState;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PelicanDev\PelicanPangolin\Filament\Admin\Pages\Infrastructure;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Tests\NativePanelTestCase;
use PelicanDev\PelicanPangolin\Tests\Support\PangolinApiFake;

uses(NativePanelTestCase::class);

it('runs the native admin action through the database queue to confirmed raw endpoints and opt-out cleanup', function () {
    Artisan::call('migrate', ['--force' => true]);
    $this->withoutVite();
    $root = User::factory()->create();
    Role::findOrCreate(Role::ROOT_ADMIN, 'web');
    $root->assignRole(Role::ROOT_ADMIN);
    $this->actingAs($root);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $server = Server::factory()->create();
    $allocation = $server->allocation;
    $allocation->forceFill(['server_id' => $server->id, 'node_id' => $server->node_id, 'ip' => '10.0.0.42', 'port' => 25565, 'ip_alias' => null])->save();
    DB::table('pangolin_node_mappings')->insert([
        'node_id' => $server->node_id, 'site_id' => 7, 'site_nice_id' => 'test-site', 'target_host' => '10.0.0.42',
        'enabled' => true, 'validated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    config()->set(['pelican-pangolin.api_url' => 'https://api.example.com', 'pelican-pangolin.organization_id' => 'test-org', 'pelican-pangolin.token' => 'test-fixture-only']);
    $api = new PangolinApiFake;
    $api->install();
    $page = Livewire\Livewire::test(EditServer::class, ['record' => $server->id]);
    $page->callAction('manage_pangolin_publication', data: ['opted_in' => true, 'overrides' => [
        ['allocation_id' => 2147483647, 'mode' => 'tcp', 'purpose' => 'game'],
    ]])->assertHasActionErrors(['overrides.0.allocation_id']);
    expect(ServerPublication::count())->toBe(0)->and($api->resources)->toBe([]);
    $page->unmountAction();
    $page->mountAction('manage_pangolin_publication')->setActionData(['opted_in' => true, 'overrides' => [
        ['allocation_id' => $allocation->id, 'mode' => 'both', 'purpose' => 'game'],
    ]])->assertActionDataSet(['opted_in' => true])->callMountedAction()->assertHasNoActionErrors();
    $publication = ServerPublication::where('server_id', $server->id)->firstOrFail();
    expect($publication->status->value)->toBe('pending')
        ->and($allocation->fresh()->ip_alias)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);
    expect(Artisan::call('queue:work', ['--once' => true, '--tries' => 1]))->toBe(0);
    expect($publication->fresh()->status->value)->toBe('synced')
        ->and($publication->resources()->count())->toBe(2)
        ->and($publication->resources()->pluck('confirmed_endpoint')->all())->toBe(['games.example.com:25565', 'games.example.com:25565'])
        ->and(array_column($api->resources, 'mode'))->toBe(['tcp', 'udp'])
        ->and(array_column($api->targets, 'port'))->toBe([25565, 25565])
        ->and($allocation->fresh()->address)->toBe('games.example.com:25565')
        ->and($allocation->fresh()->ip)->toBe('10.0.0.42');
    $server->update(['status' => ServerState::Suspended]);
    expect($allocation->fresh()->ip_alias)->toBeNull();
    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);
    expect($publication->fresh()->status->value)->toBe('disabled')
        ->and(array_unique(array_column($api->resources, 'enabled')))->toBe([false]);
    $server->update(['status' => null]);
    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);
    expect($publication->fresh()->status->value)->toBe('synced')
        ->and($allocation->fresh()->address)->toBe('games.example.com:25565');
    Livewire\Livewire::test(Infrastructure::class)->assertSee('Copy Traefik')->assertSee('services.gerbil.ports');
    $page->callAction('manage_pangolin_publication', data: ['opted_in' => false, 'overrides' => []])->assertHasNoActionErrors();
    expect($allocation->fresh()->ip_alias)->toBeNull();
    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);
    expect($api->resources)->toBe([])->and($api->targets)->toBe([])
        ->and($publication->fresh()->resources()->count())->toBe(0)
        ->and($publication->fresh()->deletion_pending)->toBeFalse();
});

it('denies the native infrastructure page to a non-root user', function () {
    Artisan::call('migrate', ['--force' => true]);
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire\Livewire::test(Infrastructure::class)->assertForbidden();
});

it('boots the packaged plugin on the actual rc.2 application with native discovery and migrations', function () {
    Http::preventStrayRequests();
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
    expect(Plugin::findOrFail('pelican-pangolin')->status->value)->toBe('enabled')
        ->and(Schema::hasTable('pangolin_managed_resources'))->toBeTrue()
        ->and(Filament::getPanel('admin')->hasPlugin('pelican-pangolin'))->toBeTrue();
});
