<?php

namespace PelicanDev\PelicanPangolin;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\HtmlString;
use PelicanDev\PelicanPangolin\Contracts\PangolinClient;
use PelicanDev\PelicanPangolin\Filament\Admin\Pages\Infrastructure;
use PelicanDev\PelicanPangolin\Services\HttpPangolinClient;
use PelicanDev\PelicanPangolin\Services\PluginSettings;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;
use PelicanDev\PelicanPangolin\Services\PublicPortAllocator;
use PelicanDev\PelicanPangolin\Support\SanitizesErrors;

final class PelicanPangolinPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;
    use SanitizesErrors;

    public function getId(): string
    {
        return 'pelican-pangolin';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([Infrastructure::class]);
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
            TextInput::make('organization_id')->label('Organization ID')->required()->helperText(new HtmlString('Find it in Pangolin organisation General Settings, at the top of the info card, or the first dashboard URL path slug. <a href="https://docs.pangolin.net/manage/organizations/org-id" target="_blank" rel="noopener noreferrer">Official Org-ID help</a>.')),
            TextInput::make('token')
                ->label('Integration API token')
                ->password()
                ->helperText('Use an organisation integration key, no root key. Required actions: listSites, listResources, getResource, createResource, updateResource, deleteResource, listTargets, createTarget, updateTarget. Only HTTP also needs listOrgDomains. Leave blank to retain the token. Read checks do not prove write permission; no probe resources are created.'),
            TextInput::make('default_domain')->label('Advanced: default HTTP domain (optional)')->helperText('Only for explicitly selected HTTP/HTTPS allocations; triggers a domain-inventory read check.'),
            TextInput::make('public_host')->label('Public game host (IPv4, IPv6 or domain)')->helperText('Required for raw TCP/UDP only. Player-facing Pangolin host, not the API URL or internal allocation target. Raw public ports equal allocation ports. Infrastructure application and external reachability are separate checks.'),
            TextInput::make('connect_timeout')->numeric()->minValue(1)->maxValue(60)->required(),
            TextInput::make('timeout')->numeric()->minValue(1)->maxValue(60)->required(),
        ];
    }

    public function saveSettings(array $data): void
    {
        $settings = new PluginSettings;
        $environment = $settings->environment($data, (string) config('pelican-pangolin.token'));
        $validationData = array_merge($data, ['token' => $environment['PANGOLIN_API_TOKEN']]);
        try {
            $settings->validate($validationData);
            $client = new HttpPangolinClient(
                (string) $environment['PANGOLIN_API_URL'],
                (string) $environment['PANGOLIN_ORGANIZATION_ID'],
                (string) $environment['PANGOLIN_API_TOKEN'],
                (int) $environment['PANGOLIN_CONNECT_TIMEOUT'],
                (int) $environment['PANGOLIN_TIMEOUT'],
            );
            $client->listSites();
            $client->listResources();
            if ($environment['PANGOLIN_DEFAULT_DOMAIN'] !== '') {
                $client->listDomains();
            }
        } catch (\Throwable $exception) {
            Notification::make()->title('Pangolin read check failed')->danger()
                ->body('Settings were not saved. Check the API URL, organisation ID and org-key read actions (listSites/listResources; listOrgDomains only for HTTP). '.$this->sanitizeError($exception->getMessage()))->send();

            return;
        }

        $this->writeToEnvironment($environment);
        config()->set('pelican-pangolin', [
            'api_url' => $environment['PANGOLIN_API_URL'],
            'organization_id' => $environment['PANGOLIN_ORGANIZATION_ID'],
            'token' => $environment['PANGOLIN_API_TOKEN'],
            'default_domain' => $environment['PANGOLIN_DEFAULT_DOMAIN'],
            'public_host' => $environment['PANGOLIN_PUBLIC_HOST'],

            'connect_timeout' => $environment['PANGOLIN_CONNECT_TIMEOUT'],
            'timeout' => $environment['PANGOLIN_TIMEOUT'],
        ]);
        app()->forgetInstance(PangolinClient::class);
        app()->forgetInstance(PublicPortAllocator::class);
        Artisan::call('queue:restart');
        app(PublicationRefreshService::class)->refreshAll();

        Notification::make()->title('Settings saved; sites and resources readable')->body('Write actions, target reachability and infrastructure application have not been tested. Domain inventory is checked only when a default HTTP domain is configured.')->success()->send();
    }
}
