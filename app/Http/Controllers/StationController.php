<?php

namespace App\Http\Controllers;

use App\Models\Station;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StationController extends Controller
{
    public function home(): RedirectResponse|View
    {
        $stations = Station::active()->orderBy('name')->get();

        // Solange es nur eine Station gibt, direkt zu ihr.
        if ($stations->count() === 1) {
            return redirect()->route('stations.show', $stations->first());
        }

        return $this->index();
    }

    public function index(): View
    {
        return view('stations.index', [
            'stations' => Station::active()
                ->withCount('publishedSubmissions')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(Station $station): View
    {
        abort_unless($station->is_active, 404);

        $photos = $station->publishedSubmissions()->paginate(48);

        return view('stations.show', [
            'station' => $station,
            'series' => $station->latestSeries,
            'photos' => $photos,
            'first' => $station->publishedSubmissions()->first(),
            'last' => $station->publishedSubmissions()->reorder()->orderByRaw('coalesce(taken_at, created_at) desc')->first(),
        ]);
    }
}
