<?php

namespace App\Filament\Resources\Sources\Tables;

use App\Models\Source;
use App\Services\SourceManager;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use RuntimeException;

class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state) => Source::SOURCE_TYPES[$state] ?? $state)
                    ->toggleable(),
                TextColumn::make('collection_method')
                    ->label('Method')
                    ->formatStateUsing(fn (?string $state) => Source::METHODS[$state] ?? $state),
                TextColumn::make('collector')
                    ->placeholder('none')
                    ->toggleable(),
                TextColumn::make('collection_frequency')
                    ->label('Frequency')
                    ->formatStateUsing(fn (?string $state) => Source::FREQUENCIES[$state] ?? $state),
                TextColumn::make('access_status')
                    ->label('Access')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Source::ACCESS_STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        'approved' => 'success',
                        'restricted' => 'danger',
                        default => 'warning',
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('last_run')
                    ->label('Last run')
                    ->state(fn (Source $record) => $record->runs()->latest('id')->value('status'))
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'partial' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('never'),
                TextColumn::make('last_collection_at')
                    ->label('Last collected')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),
                TextColumn::make('next_collection_at')
                    ->label('Next due')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('consecutive_failures')
                    ->label('Failures in a row')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('access_status')->label('Access')->options(Source::ACCESS_STATUSES),
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('failing')
                    ->label('Failing')
                    ->query(fn ($query) => $query->where('consecutive_failures', '>', 0)),
            ])
            ->recordActions([
                EditAction::make(),
                self::testAccessAction(),
                self::runNowAction(),
                self::addAdAction(),
                ActionGroup::make([
                    self::decideAccessAction(),
                    self::activateAction(),
                    self::deactivateAction(),
                    DeleteAction::make()
                        ->hidden(fn (Source $record) => ! app(SourceManager::class)->canBeDeleted($record))
                        ->modalDescription('Sources that have stored ads cannot be deleted. Deactivate them instead.'),
                ]),
            ]);
    }

    public static function decideAccessAction(): Action
    {
        return Action::make('decideAccess')
            ->label('Decide access')
            ->icon('heroicon-o-shield-check')
            ->modalDescription('Approved means you checked the terms and robots.txt, or have permission. Anything other than Approved also switches the source off.')
            ->schema([
                Select::make('status')
                    ->options(Source::ACCESS_STATUSES)
                    ->required(),
                Textarea::make('note')
                    ->label('What did you check, or who gave permission?')
                    ->required()
                    ->rows(3),
            ])
            ->fillForm(fn (Source $record) => ['status' => $record->access_status])
            ->action(function (Source $record, array $data): void {
                try {
                    app(SourceManager::class)->decideAccess($record, $data['status'], $data['note']);
                    Notification::make()->title("Access for {$record->name} set to {$data['status']}")->success()->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function activateAction(): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon('heroicon-o-play')
            ->visible(fn (Source $record) => ! $record->is_active)
            ->requiresConfirmation()
            ->action(function (Source $record): void {
                try {
                    app(SourceManager::class)->activate($record);
                    Notification::make()->title("{$record->name} is now active")->success()->send();
                } catch (DomainException $e) {
                    Notification::make()->title("{$record->name} was not activated")->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon('heroicon-o-pause')
            ->visible(fn (Source $record) => $record->is_active)
            ->requiresConfirmation()
            ->action(function (Source $record): void {
                app(SourceManager::class)->deactivate($record);
                Notification::make()->title("{$record->name} is now inactive")->success()->send();
            });
    }

    public static function testAccessAction(): Action
    {
        return Action::make('testAccess')
            ->label('Test access')
            ->icon('heroicon-o-signal')
            ->hidden(fn (Source $record) => $record->access_status === 'restricted')
            ->action(function (Source $record): void {
                try {
                    $result = app(SourceManager::class)->testAccess($record);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Cannot test')->body($e->getMessage())->warning()->send();

                    return;
                }

                $note = Notification::make()
                    ->title($result->ok ? 'Reachable' : 'Not accessible')
                    ->body("{$result->message} ({$result->durationMs} ms"
                        .($result->httpStatus ? ", HTTP {$result->httpStatus}" : '')
                        .($result->ok ? ", {$result->itemCount} ads found" : '').')');

                $result->ok ? $note->success()->send() : $note->danger()->send();
            });
    }

    public static function addAdAction(): Action
    {
        return Action::make('addAd')
            ->label('Add ad')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (Source $record) => $record->collection_method === 'manual' && $record->is_active && $record->access_status === 'approved')
            ->modalHeading(fn (Source $record) => "Add an ad to {$record->name}")
            ->modalDescription('Paste an ad you found yourself, for example from a PDF or newspaper. Email addresses are removed when it is stored. Nothing is fetched from the web.')
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(500)
                    ->helperText('Written like the other ads of this source, e.g. "Budget Analyst at World Vision".'),
                TextInput::make('url')
                    ->label('Link (optional)')
                    ->url()
                    ->maxLength(500)
                    ->helperText('Leave empty for a PDF or newspaper notice.'),
                Textarea::make('content')
                    ->label('Ad text')
                    ->required()
                    ->rows(12)
                    ->maxLength(100000),
            ])
            ->action(function (Source $record, array $data): void {
                try {
                    $run = app(SourceManager::class)->submitAd($record, $data);
                } catch (DomainException|RuntimeException $e) {
                    Notification::make()->title('Ad not added')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($run->items_new > 0 ? 'Ad added' : 'Already stored, nothing new')
                    ->success()
                    ->send();
            });
    }

    public static function runNowAction(): Action
    {
        return Action::make('runNow')
            ->label('Run now')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (Source $record) => $record->is_active && $record->access_status === 'approved' && $record->collection_method !== 'manual')
            ->requiresConfirmation()
            ->modalDescription('Collects from this source once, right now.')
            ->action(function (Source $record): void {
                try {
                    $run = app(SourceManager::class)->runNow($record);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Cannot run')->body($e->getMessage())->danger()->send();

                    return;
                }

                $note = Notification::make()
                    ->title("{$record->name}: {$run->status}")
                    ->body("Found {$run->items_found}, new {$run->items_new}, updated {$run->items_updated}".($run->error_message ? ". {$run->error_message}" : ''));

                $run->status === 'failed' ? $note->danger()->send() : $note->success()->send();
            });
    }
}
