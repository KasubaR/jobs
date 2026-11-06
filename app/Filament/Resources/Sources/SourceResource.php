<?php

namespace App\Filament\Resources\Sources;

use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\RelationManagers\AccessLogsRelationManager;
use App\Filament\Resources\Sources\RelationManagers\ErrorsRelationManager;
use App\Filament\Resources\Sources\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\Tables\SourcesTable;
use App\Models\Source;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return SourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RunsRelationManager::class,
            ErrorsRelationManager::class,
            AccessLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'create' => CreateSource::route('/create'),
            'edit' => EditSource::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $failing = Source::query()->where('consecutive_failures', '>', 0)->count();

        return $failing > 0 ? (string) $failing : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
