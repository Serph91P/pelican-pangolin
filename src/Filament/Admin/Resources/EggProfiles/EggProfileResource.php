<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles;

use App\Models\Egg;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PelicanDev\PelicanPangolin\Enums\PublicationMode;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\Pages\CreateEggProfile;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\Pages\EditEggProfile;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\EggProfiles\Pages\ListEggProfiles;
use PelicanDev\PelicanPangolin\Models\EggProfile;

final class EggProfileResource extends Resource
{
    protected static ?string $model = EggProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-egg';

    protected static ?string $navigationLabel = 'Pangolin Egg profiles';

    public static function canViewAny(): bool
    {
        return self::canManageGlobalConfiguration(user());
    }

    public static function canCreate(): bool
    {
        return self::canManageGlobalConfiguration(user());
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManageGlobalConfiguration(user());
    }

    public static function canDelete(Model $record): bool
    {
        return self::canManageGlobalConfiguration(user());
    }

    public static function canManageGlobalConfiguration(?User $user): bool
    {
        return $user?->isRootAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('egg_uuid')
                ->label('Pelican Egg')
                ->options(fn () => Egg::query()->whereNotNull('uuid')->pluck('name', 'uuid'))
                ->searchable()->required()->unique(ignoreRecord: true),
            TextInput::make('name')->required()->maxLength(255),
            Toggle::make('enabled')->default(true),
            Repeater::make('rules')->relationship()->schema([
                TextInput::make('allocation_position')->integer()->minValue(0)->required(),
                Select::make('mode')->options(collect(PublicationMode::cases())->mapWithKeys(fn (PublicationMode $mode): array => [$mode->value => ucfirst($mode->value)]))->required()->live(),
                TextInput::make('domain')->visible(fn (Get $get): bool => in_array($get('mode'), ['http', 'https'], true)),
                TextInput::make('subdomain')->visible(fn (Get $get): bool => in_array($get('mode'), ['http', 'https'], true)),
            ])->columns(4)->columnSpanFull()->reorderable(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('egg_uuid')->label('Egg UUID')->copyable(),
            TextColumn::make('rules_count')->counts('rules')->label('Rules'),
            IconColumn::make('enabled')->boolean(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEggProfiles::route('/'),
            'create' => CreateEggProfile::route('/create'),
            'edit' => EditEggProfile::route('/{record}/edit'),
        ];
    }
}
