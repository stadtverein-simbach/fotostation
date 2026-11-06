import 'altcha';
import 'altcha/i18n/de';

document.addEventListener('DOMContentLoaded', () => {
    // Upload-Formular: Vorschau und Schutz vor Doppelklick.
    const form = document.querySelector('[data-upload-form]');
    if (!form) {
        return;
    }

    const input = form.querySelector('input[type="file"]');
    const preview = form.querySelector('[data-preview]');
    const button = form.querySelector('button[type="submit"]');

    input?.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file || !preview) {
            return;
        }
        preview.src = URL.createObjectURL(file);
        preview.classList.remove('hidden');
    });

    form.addEventListener('submit', () => {
        if (button) {
            button.disabled = true;
            button.textContent = 'Wird hochgeladen …';
        }
    });
});
