@php
    /** @var \App\Models\Submission $record */
    $storage = app(\App\Services\PhotoStorage::class);
    $station = $record->station;

    $url = fn (?string $path, bool $public = false) => $path ? $storage->readUrl($path, $public) : null;

    // Ausgerichtetes Bild bevorzugen (dort gelten die Gesichtsboxen), sonst das Original.
    $main = $url($record->aligned_path) ?? $url($record->public_path, true) ?? $url($record->original_path);
    $isAligned = filled($record->aligned_path);
    $reference = $url($station->reference_path);

    $w = max(1, $station->output_width);
    $h = max(1, $station->output_height);
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
        <figure>
            <div style="position: relative;">
                @if ($main)
                    <img src="{{ $main }}" alt="Eingereichtes Foto" style="display: block; width: 100%; border-radius: .5rem;">
                @else
                    <div style="padding: 2rem; text-align: center; opacity: .6;">Keine Datei mehr vorhanden.</div>
                @endif

                @if ($main && $isAligned)
                    @foreach ($record->face_boxes ?? [] as $box)
                        <div title="Erkanntes Gesicht"
                             style="position: absolute; border: 2px solid #dc2626;
                                    left: {{ $box['x'] / $w * 100 }}%; top: {{ $box['y'] / $h * 100 }}%;
                                    width: {{ $box['w'] / $w * 100 }}%; height: {{ $box['h'] / $h * 100 }}%;"></div>
                    @endforeach
                    @foreach ($record->manual_boxes ?? [] as $box)
                        <div title="Von Hand markiert"
                             style="position: absolute; border: 2px dashed #2563eb;
                                    left: {{ $box['x'] }}%; top: {{ $box['y'] }}%;
                                    width: {{ $box['w'] }}%; height: {{ $box['h'] }}%;"></div>
                    @endforeach
                @endif
            </div>
            <figcaption style="margin-top: .25rem; font-size: .875rem; opacity: .7;">
                {{ $isAligned ? (\App\Support\Pipeline::workerEnabled() ? 'Ausgerichtet auf die Referenz' : 'Zugeschnitten auf das Stationsformat') : 'Original' }}
                @if ($isAligned && count($record->face_boxes ?? []))
                    · rot: erkannte Gesichter (werden verpixelt)
                @endif
            </figcaption>
        </figure>

        @if ($reference)
            <figure>
                <img src="{{ $reference }}" alt="Referenzbild der Station" style="display: block; width: 100%; border-radius: .5rem;">
                <figcaption style="margin-top: .25rem; font-size: .875rem; opacity: .7;">Referenzbild der Station</figcaption>
            </figure>
        @endif
    </div>
</x-dynamic-component>
