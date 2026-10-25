<?php

namespace PelicanDev\PelicanPangolin\Filament\Components\Actions;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Jobs\ReconcileServerPublication;
use PelicanDev\PelicanPangolin\Jobs\RemoveServerPublication;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\AllocationAliasManager;
use PelicanDev\PelicanPangolin\Services\PublicationStateSnapshot;

final class ManagePangolinPublicationAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'manage_pangolin_publication';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Pangolin publication')
            ->authorize(fn (Server $record): bool => user()?->can('update', $record) ?? false)
            ->icon('tabler-world-share')
            ->modalHeading('Manage Pangolin publication')
            ->modalDescription('Raw TCP and UDP ports are public and unauthenticated. HTTP publication is created only when explicitly selected.')
            ->fillForm(function (Server $record): array {
                $publication = ServerPublication::query()->where('server_id', $record->id)->first();
                $overrides = $publication === null ? [] : ($publication->desired_state['overrides'] ?? []);
                $formattedOverrides = [];
                if (is_array($overrides)) {
                    foreach ($overrides as $allocationId => $override) {
                        if (is_array($override)) {
                            $formattedOverrides[] = ['allocation_id' => (int) $allocationId, ...$override];
                        }
                    }
                }

                return [
                    'opted_in' => $publication !== null && $publication->opted_in,
                    'overrides' => $formattedOverrides,
                ];
            })
            ->schema([
                Toggle::make('opted_in')->label('Publish this server through Pangolin')->live(),
                Placeholder::make('raw_warning')->content('TCP and UDP publications bypass Pangolin authentication and require prepared firewall, Docker, and Traefik port mappings.'),
                Repeater::make('overrides')->schema([
                    Select::make('allocation_id')
                        ->options(fn (Server $record) => $record->allocations->mapWithKeys(fn ($allocation): array => [$allocation->id => $allocation->address]))
                        ->distinct()->required(),
                    Select::make('mode')->options(collect(PublicationMode::cases())->mapWithKeys(fn (PublicationMode $mode): array => [$mode->value => ucfirst($mode->value)]))->required()->live(),
                    Select::make('purpose')->options(['game' => 'Game', 'query' => 'Query', 'ignored' => 'Do not publish'])->required(),
                    TextInput::make('domain')->visible(fn (Get $get): bool => in_array($get('mode'), ['http', 'https'], true)),
                    TextInput::make('subdomain')->visible(fn (Get $get): bool => in_array($get('mode'), ['http', 'https'], true)),
                ])->columns(4)->columnSpanFull()->visible(fn (Get $get): bool => (bool) $get('opted_in')),
            ])
            ->action(function (Server $record, array $data): void {
                abort_unless(user()?->can('update', $record) ?? false, 403);
                $record->load(['egg', 'allocations']);
                Validator::make($data, [
                    'opted_in' => ['required', 'boolean'],
                    'overrides' => ['array', 'max:1024'],
                    'overrides.*.allocation_id' => ['required', 'integer', 'distinct', Rule::in($record->allocations->modelKeys())],
                    'overrides.*.mode' => ['required', Rule::enum(PublicationMode::class)],
                    'overrides.*.purpose' => ['required', Rule::in(['game', 'query', 'ignored'])],
                    'overrides.*.domain' => ['nullable', 'string', 'max:253'],
                    'overrides.*.subdomain' => ['nullable', 'string', 'max:253'],
                ])->validate();
                DB::transaction(function () use ($record, $data): void {
                    $publication = ServerPublication::query()->firstOrCreate(
                        ['server_id' => $record->id],
                        ['server_uuid' => $record->uuid],
                    );

                    if (! ($data['opted_in'] ?? false)) {
                        app(AllocationAliasManager::class)->restore($publication);
                        $publication->forceFill([
                            'opted_in' => false,
                            'deletion_pending' => true,
                            'desired_revision' => $publication->desired_revision + 1,
                            'status' => SyncStatus::Removing,
                        ])->save();
                        RemoveServerPublication::dispatch($publication->id, $record->id, $publication->desired_revision);

                        return;
                    }

                    $overrides = [];
                    foreach (is_array($data['overrides'] ?? null) ? $data['overrides'] : [] as $override) {
                        if (! is_array($override) || ! isset($override['allocation_id'])) {
                            continue;
                        }

                        $allocationId = (int) $override['allocation_id'];
                        unset($override['allocation_id']);
                        $overrides[$allocationId] = $override;
                    }
                    $publication->forceFill([
                        'opted_in' => true,
                        'suspended' => false,
                        'deletion_pending' => false,
                        'status' => SyncStatus::Pending,
                        'desired_state' => ['overrides' => $overrides],
                    ])->save();
                    app(PublicationStateSnapshot::class)->refresh($record->loadMissing(['egg', 'allocations']), $publication);
                    ReconcileServerPublication::dispatch($publication->id, $record->id, $publication->desired_revision);
                });

                Notification::make()->title('Pangolin publication update queued')->success()->send();
            });
    }
}
