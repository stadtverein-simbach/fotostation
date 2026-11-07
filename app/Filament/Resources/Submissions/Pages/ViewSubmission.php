<?php

namespace App\Filament\Resources\Submissions\Pages;

use App\Filament\Resources\Submissions\SubmissionActions;
use App\Filament\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SubmissionActions::approve()->after(fn () => $this->redirect(SubmissionResource::getUrl('index'))),
            SubmissionActions::reject()->after(fn () => $this->redirect(SubmissionResource::getUrl('index'))),
            SubmissionActions::rescue(),
            SubmissionActions::retry(),
            SubmissionActions::unpublish(),
            SubmissionActions::openOriginal(),
        ];
    }
}
