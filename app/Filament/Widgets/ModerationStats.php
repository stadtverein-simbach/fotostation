<?php

namespace App\Filament\Widgets;

use App\Enums\ReportStatus;
use App\Enums\SubmissionStatus;
use App\Models\Report;
use App\Models\Submission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ModerationStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $since = now()->subDays(30);

        $total = Submission::where('created_at', '>=', $since)->count();
        $autoRejected = Submission::where('created_at', '>=', $since)
            ->where('status', SubmissionStatus::AutoRejected)
            ->count();

        return [
            Stat::make('Wartet auf Freigabe', Submission::where('status', SubmissionStatus::PendingReview)->count())
                ->color('warning'),
            Stat::make('Uploads heute', Submission::where('created_at', '>=', today())->count()),
            Stat::make('Automatisch abgelehnt (30 Tage)', $total > 0 ? round($autoRejected / $total * 100).' %' : '–')
                ->description("{$autoRejected} von {$total} Uploads"),
            Stat::make('Offene Meldungen', Report::where('status', ReportStatus::Open)->count())
                ->color('danger'),
        ];
    }
}
