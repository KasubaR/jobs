<?php

namespace App\Filament\Resources\Sources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Collection errors: only the runs that failed or were partial, with the message. */
class ErrorsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Collection errors';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['failed', 'partial']))
            ->columns([
                TextColumn::make('started_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('status')->badge()->color(fn (?string $state) => $state === 'failed' ? 'danger' : 'warning'),
                TextColumn::make('http_errors')->label('HTTP errors'),
                TextColumn::make('error_message')->label('Error')->wrap()->limit(300),
            ])
            ->defaultSort('started_at', 'desc')
            ->emptyStateHeading('No errors')
            ->emptyStateDescription('Failed and partial runs appear here.');
    }
}
