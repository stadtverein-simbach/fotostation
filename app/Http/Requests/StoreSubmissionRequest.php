<?php

namespace App\Http\Requests;

use App\Rules\Altcha;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $upload = config('fotostation.upload');

        return [
            // HEIC wird bewusst nicht angenommen: iOS liefert bei
            // accept="image/jpeg,image/png,image/webp" automatisch JPEG.
            'photo' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.$upload['max_kb'],
                'dimensions:min_width='.$upload['min_edge'].',min_height='.$upload['min_edge'],
            ],
            'consent' => ['accepted'],
            'name' => ['nullable', 'string', 'max:40'],
            'altcha' => [new Altcha],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Bitte wähle ein Foto aus.',
            'photo.file' => 'Der Upload ist fehlgeschlagen. Bitte versuche es noch einmal.',
            'photo.mimetypes' => 'Bitte lade ein Foto im Format JPEG, PNG oder WebP hoch.',
            'photo.max' => 'Das Foto ist zu groß (maximal :max KB).',
            'photo.dimensions' => 'Das Foto ist zu klein. Bitte nutze die normale Kamera deines Handys.',
            'consent.accepted' => 'Bitte bestätige, dass du das Foto selbst aufgenommen hast und es unter CC0 bereitstellst.',
            'name.max' => 'Der Name darf höchstens 40 Zeichen lang sein.',
        ];
    }
}
