<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\ServerPublications;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use PelicanDev\PelicanPangolin\Enums\SyncStatus;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\ServerPublications\Pages\ListServerPublications;
use PelicanDev\PelicanPangolin\Jobs\RemoveServerPublication;
use PelicanDev\PelicanPangolin\Models\ServerPublication;
use PelicanDev\PelicanPangolin\Services\AllocationAliasManager;
use PelicanDev\PelicanPangolin\Services\PublicationRefreshService;

final class ServerPublicationResource extends Resource
{
    protected static ?string $model = ServerPublication::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-world-share';

    protected static ?string $navigationLabel = 'Pangolin publications';

    public static function getEloquentQuery(): Builder
    {
        $nodeIds = user()?->accessibleNodes()->pluck('id') ?? collect();

        return parent::getEloquentQuery()->where(function (Builder $query) use ($nodeIds): void {
            $query->whereHas('server', fn (Builder $servers): Builder => $servers->whereIn('node_id', $nodeIds));
            if (user()?->isRootAdmin()) {
                $query->orWhereDoesntHave('server');
            }
        });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('server_id')->label('Server ID')->sortable(),
            TextColumn::make('status')->badge(),
            IconColumn::make('opted_in')->boolean(),
            IconColumn::make('suspended')->label('Pelican server suspended')->boolean(),
            IconColumn::make('deletion_pending')->label('Cleanup pending (remote exposure may remain)')->boolean(),
            TextColumn::make('desired_state.legacy_reapproval')->label('Legacy profiles retired; reapprove allocations')->formatStateUsing(fn ($state): string => $state ? 'Reapproval required' : ''),
            TextColumn::make('resources.pangolin_resource_id')->label('Resource IDs')->badge(),
            TextColumn::make('resources.public_port')->label('Public ports')->badge(),
            TextColumn::make('confirmed_endpoints')->label('API-synchronized endpoints (reachability not tested)')->state(fn (ServerPublication $record): array => $record->status === SyncStatus::Synced && $record->opted_in && ! $record->suspended ? $record->resources()->where('enabled', true)->whereNotNull('confirmed_endpoint')->pluck('confirmed_endpoint')->all() : [])->listWithLineBreaks()->copyable(),
            TextColumn::make('last_synced_at')->dateTime()->placeholder('Never'),
            TextColumn::make('last_error')->color('danger')->wrap()->limit(120),
        ])->filters([
            SelectFilter::make('status')->options(collect(SyncStatus::cases())->mapWithKeys(fn (SyncStatus $status): array => [$status->value => ucfirst($status->value)])),
        ])->recordActions([
            Action::make('retry')->icon('tabler-refresh')->authorize(fn (ServerPublication $record): bool => $record->server === null ? (user()?->isRootAdmin() ?? false) : (user()?->can('update', $record->server) ?? false))->action(function (ServerPublication $record): void {
                if ($record->deletion_pending && ! $record->opted_in) {
                    RemoveServerPublication::dispatch($record->id, $record->server_id, $record->desired_revision);
                } elseif ($record->server !== null) {
                    app(PublicationRefreshService::class)->refreshServer($record->server);
                }
                Notification::make()->title('Pangolin reconciliation queued')->success()->send();
            }),

            Action::make('opt_out')->color('danger')->requiresConfirmation()->authorize(fn (ServerPublication $record): bool => $record->server !== null && (user()?->can('update', $record->server) ?? false))->action(function (ServerPublication $record): void {
                app(AllocationAliasManager::class)->restore($record);
                $record->forceFill([
                    'opted_in' => false,
                    'deletion_pending' => true,
                    'desired_revision' => $record->desired_revision + 1,
                    'status' => SyncStatus::Removing,
                ])->save();
                RemoveServerPublication::dispatch($record->id, $record->server_id, $record->desired_revision);
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServerPublications::route('/')];
    }
}
