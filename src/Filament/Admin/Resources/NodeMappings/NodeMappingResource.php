<?php

namespace PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages\CreateNodeMapping;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages\EditNodeMapping;
use PelicanDev\PelicanPangolin\Filament\Admin\Resources\NodeMappings\Pages\ListNodeMappings;
use PelicanDev\PelicanPangolin\Models\NodeMapping;
use PelicanDev\PelicanPangolin\Services\NodeMappingValidator;

final class NodeMappingResource extends Resource
{
    protected static ?string $model = NodeMapping::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-network';

    protected static ?string $navigationLabel = 'Pangolin nodes';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('node_id', user()?->accessibleNodes()->pluck('id') ?? collect());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('node_id')
                ->label('Pelican node')
                ->options(fn () => user()?->accessibleNodes()->pluck('name', 'id') ?? collect())
                ->searchable()->required()->unique(ignoreRecord: true),
            TextInput::make('site_id')->label('Pangolin site ID')->numeric()->minValue(1)->required(),
            TextInput::make('site_nice_id')->label('Stable Pangolin site identifier')->required()->maxLength(255),
            TextInput::make('target_host')->helperText('Host reachable from the selected Pangolin site.')->required()->maxLength(255),
            Toggle::make('enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('node_id')->label('Node ID')->sortable(),
            TextColumn::make('site_nice_id')->label('Pangolin site')->searchable(),
            TextColumn::make('target_host')->searchable(),
            IconColumn::make('enabled')->boolean(),
            TextColumn::make('validated_at')->dateTime()->placeholder('Not validated'),
            TextColumn::make('validation_error')->color('danger')->wrap(),
        ])->recordActions([
            Action::make('validate')
                ->icon('tabler-shield-check')
                ->action(function (NodeMapping $record): void {
                    $valid = app(NodeMappingValidator::class)->validate($record);
                    Notification::make()
                        ->title($valid ? 'Node mapping validated' : 'Node mapping validation failed')
                        ->color($valid ? 'success' : 'danger')
                        ->send();
                }),
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNodeMappings::route('/'),
            'create' => CreateNodeMapping::route('/create'),
            'edit' => EditNodeMapping::route('/{record}/edit'),
        ];
    }
}
