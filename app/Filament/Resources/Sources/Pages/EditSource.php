<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Extraction\ExtractionPreview;
use App\Filament\Resources\Sources\SourceResource;
use App\Services\SourceManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previewExtraction')
                ->label('Preview extraction')
                ->icon('heroicon-o-eye')
                ->modalHeading('Extraction preview')
                ->modalContent(fn () => view('filament.sources.extraction-preview', [
                    // Uses the rules as currently typed, saved or not.
                    'preview' => app(ExtractionPreview::class)->run(
                        $this->getRecord(),
                        $this->data['collector_config']['extraction'] ?? [],
                    ),
                ]))
                ->modalWidth('7xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            DeleteAction::make()
                ->hidden(fn () => ! app(SourceManager::class)->canBeDeleted($this->getRecord())),
        ];
    }
}
