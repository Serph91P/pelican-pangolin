<?php

namespace PelicanDev\PelicanPangolin\Providers;

use App\Enums\HeaderActionPosition;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use PelicanDev\PelicanPangolin\Console\Commands\PangolinInfrastructureCommand;
use PelicanDev\PelicanPangolin\Console\Commands\PangolinPreflightCommand;
use PelicanDev\PelicanPangolin\Console\Commands\PangolinReconcileCommand;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Filament\Components\Actions\ManagePangolinPublicationAction;
use PelicanDev\PelicanPangolin\Models\EggProfile;
use PelicanDev\PelicanPangolin\Models\EggProfileRule;
use PelicanDev\PelicanPangolin\Models\ManagedResource;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Observers\AllocationObserver;
use PelicanDev\PelicanPangolin\Observers\EggProfileObserver;
use PelicanDev\PelicanPangolin\Observers\EggProfileRuleObserver;
use PelicanDev\PelicanPangolin\Observers\NodeMappingObserver;
use PelicanDev\PelicanPangolin\Observers\ServerObserver;
use PelicanDev\PelicanPangolin\Services\HttpPangolinClient;
use PelicanDev\PelicanPangolin\Services\PublicationReconciler;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;
use PelicanDev\PelicanPangolin\Services\ResourceNaming;

final class PelicanPangolinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/pelican-pangolin.php', 'pelican-pangolin');

        $this->app->singleton(PangolinClient::class, fn (): PangolinClient => new HttpPangolinClient(
            (string) config('pelican-pangolin.api_url'),
            (string) config('pelican-pangolin.organization_id'),
            (string) config('pelican-pangolin.token'),
            (int) config('pelican-pangolin.connect_timeout', 3),
            (int) config('pelican-pangolin.timeout', 10),
        ));
        $this->app->singleton(PublicPortAllocator::class, fn (): PublicPortAllocator => PublicPortAllocator::from(
            (string) config('pelican-pangolin.tcp_ports'),
            (string) config('pelican-pangolin.udp_ports'),
        ));
        $this->app->singleton(ResourceNaming::class);
        $this->app->singleton(PublicationReconciler::class);

        EditServer::registerCustomHeaderActions(
            HeaderActionPosition::After,
            ManagePangolinPublicationAction::make(),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        Server::resolveRelationUsing('pangolinPublication', fn (Server $server) => $server->hasOne(ServerPublication::class));
        Node::resolveRelationUsing('pangolinMapping', fn (Node $node) => $node->hasOne(NodeMapping::class));
        Egg::resolveRelationUsing('pangolinProfile', fn (Egg $egg) => $egg->hasOne(EggProfile::class, 'egg_uuid', 'uuid'));
        Allocation::resolveRelationUsing('pangolinResources', fn (Allocation $allocation) => $allocation->hasMany(ManagedResource::class));

        Server::observe(ServerObserver::class);
        Allocation::observe(AllocationObserver::class);
        NodeMapping::observe(NodeMappingObserver::class);
        EggProfile::observe(EggProfileObserver::class);
        EggProfileRule::observe(EggProfileRuleObserver::class);

        Schedule::command(PangolinReconcileCommand::class)->everyFiveMinutes()->withoutOverlapping();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PangolinPreflightCommand::class,
                PangolinReconcileCommand::class,
                PangolinInfrastructureCommand::class,
            ]);
        }
    }
}
