<?php

namespace App\Filament\Resources\Sources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Who changed access or the on/off switch, when, and why. */
class AccessLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'accessLogs';

    protected static ?string $title = 'Access history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('user.email')->label('Who')->placeholder('system'),
                TextColumn::make('field')->formatStateUsing(fn (?string $state) => $state === 'is_active' ? 'Active' : 'Access'),
                TextColumn::make('from_value')->label('From')->placeholder('-'),
                TextColumn::make('to_value')->label('To'),
                TextColumn::make('note')->wrap()->limit(300)->placeholder('-'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
