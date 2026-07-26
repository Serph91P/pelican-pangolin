<?php

namespace PelicanDev\PelicanPangolin;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\Facades\Artisan;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Services\HttpPangolinClient;
use PelicanDev\PelicanPangolin\Services\PluginSettings;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;

final class PelicanPangolinPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'pelican-pangolin';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            plugin_path($this->getId(), 'src/Filament/Admin/Resources'),
            'PelicanDev\\PelicanPangolin\\Filament\\Admin\\Resources',
        );
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return (new PluginSettings)->formData((array) config('pelican-pangolin'));
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('api_url')->label('Pangolin API URL')->url()->required(),
            TextInput::make('organization_id')->label('Organization ID')->required(),
            TextInput::make('token')
                ->label('Integration API token')
                ->password()
                ->helperText('Leave blank to retain the configured token.'),
            TextInput::make('default_domain')->label('Default HTTP domain'),
            TextInput::make('tcp_ports')->label('TCP public port pool')->required(),
            TextInput::make('udp_ports')->label('UDP public port pool')->required(),
            TextInput::make('connect_timeout')->numeric()->minValue(1)->maxValue(60)->required(),
            TextInput::make('timeout')->numeric()->minValue(1)->maxValue(60)->required(),
        ];
    }

    public function saveSettings(array $data): void
    {
        $settings = new PluginSettings;
        $environment = $settings->environment($data, (string) config('pelican-pangolin.token'));
        $validationData = array_merge($data, ['token' => $environment['PANGOLIN_API_TOKEN']]);
        $settings->validate($validationData);

        $client = new HttpPangolinClient(
            (string) $environment['PANGOLIN_API_URL'],
            (string) $environment['PANGOLIN_ORGANIZATION_ID'],
            (string) $environment['PANGOLIN_API_TOKEN'],
            (int) $environment['PANGOLIN_CONNECT_TIMEOUT'],
            (int) $environment['PANGOLIN_TIMEOUT'],
        );
        $client->listSites();
        $client->listDomains();

        $this->writeToEnvironment($environment);
        config()->set('pelican-pangolin', [
            'api_url' => $environment['PANGOLIN_API_URL'],
            'organization_id' => $environment['PANGOLIN_ORGANIZATION_ID'],
            'token' => $environment['PANGOLIN_API_TOKEN'],
            'default_domain' => $environment['PANGOLIN_DEFAULT_DOMAIN'],
            'tcp_ports' => $environment['PANGOLIN_TCP_PORTS'],
            'udp_ports' => $environment['PANGOLIN_UDP_PORTS'],
            'connect_timeout' => $environment['PANGOLIN_CONNECT_TIMEOUT'],
            'timeout' => $environment['PANGOLIN_TIMEOUT'],
        ]);
        app()->forgetInstance(PangolinClient::class);
        app()->forgetInstance(PublicPortAllocator::class);
        Artisan::call('queue:restart');
        app(PublicationRefreshService::class)->refreshAll();

        Notification::make()->title('Pangolin settings validated and saved')->success()->send();
    }
}
