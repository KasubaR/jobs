<?php

namespace App\Filament\Resources\Sources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Collection history: every attempt, newest first. */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Collection history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('trigger')->badge()->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'partial' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('items_found')->label('Found'),
                TextColumn::make('items_new')->label('New'),
                TextColumn::make('items_updated')->label('Updated'),
                TextColumn::make('duration')
                    ->state(fn ($record) => $record->finished_at
                        ? $record->started_at->diffInSeconds($record->finished_at).' s'
                        : null)
                    ->placeholder('-'),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'success' => 'Success',
                    'partial' => 'Partial',
                    'failed' => 'Failed',
                    'running' => 'Running',
                ]),
            ]);
    }
}
