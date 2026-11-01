<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles;

use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\Pages\ListEggProfiles;
use PelicanDev\PelicanPangolin\Models\EggProfile;

/** Legacy metadata is retained for rollback, never evaluated as allocation approval. */
final class EggProfileResource extends Resource
{
    protected static ?string $model = EggProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-egg';

    protected static ?string $navigationLabel = 'Pangolin legacy profiles (inert)';

    public static function canViewAny(): bool
    {
        return self::canManageGlobalConfiguration(user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canManageGlobalConfiguration(?User $user): bool
    {
        return $user?->isRootAdmin() ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('egg_uuid')->label('Egg UUID')->copyable(),
            TextColumn::make('rules_count')->counts('rules')->label('Retained rules (inert)'),
            TextColumn::make('reapproval')->state('Retired: approve actual allocations in the server action. Profile-only resources are cleaned up on reconciliation.')->wrap(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEggProfiles::route('/')];
    }
}
