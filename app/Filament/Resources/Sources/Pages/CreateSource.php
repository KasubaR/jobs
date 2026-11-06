<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSource extends CreateRecord
{
    protected static string $resource = SourceResource::class;

    /** A new source always starts pending and switched off, whatever the form sends. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return array_merge($data, [
            'access_status' => 'pending',
            'is_active' => false,
            'access_decided_at' => null,
            'access_decision_note' => null,
        ]);
    }
}
