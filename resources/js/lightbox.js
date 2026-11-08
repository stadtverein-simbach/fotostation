/**
 * Einfache Lightbox ohne Abhängigkeiten.
 *
 * Links mit `data-lightbox` öffnen ihr Ziel (Bild-URL im href) im
 * `<dialog data-lightbox-dialog>` der Seite. Ohne JavaScript bleibt der
 * normale Link erhalten. Bedienung: Pfeiltasten, Wischen, Esc, Klick
 * neben das Bild.
 */
export function initLightbox(root = document) {
    const dialog = root.querySelector('[data-lightbox-dialog]');
    const links = [...root.querySelectorAll('a[data-lightbox]')];
    if (!dialog || links.length === 0) {
        return;
    }

    const image = dialog.querySelector('[data-lightbox-image]');
    const caption = dialog.querySelector('[data-lightbox-caption]');
    const counter = dialog.querySelector('[data-lightbox-counter]');
    const prev = dialog.querySelector('[data-lightbox-prev]');
    const next = dialog.querySelector('[data-lightbox-next]');
    const close = dialog.querySelector('[data-lightbox-close]');
    let index = 0;

    const show = (i) => {
        index = (i + links.length) % links.length;
        const link = links[index];
        image.src = link.href;
        image.alt = link.querySelector('img')?.alt ?? '';
        caption.textContent = link.dataset.caption ?? '';
        counter.textContent = links.length > 1 ? `${index + 1} / ${links.length}` : '';

        // Nachbarbilder vorladen, damit das Blättern flüssig wirkt.
        [index - 1, index + 1].forEach((n) => {
            new Image().src = links[(n + links.length) % links.length].href;
        });
    };

    const hideNav = links.length < 2;
    prev.hidden = hideNav;
    next.hidden = hideNav;

    links.forEach((link, i) => {
        link.addEventListener('click', (event) => {
            // Strg-/Cmd-Klick oder mittlere Maustaste weiterhin im neuen Tab.
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                return;
            }
            event.preventDefault();
            show(i);
            dialog.showModal();
            document.body.style.overflow = 'hidden';
        });
    });

    prev.addEventListener('click', () => show(index - 1));
    next.addEventListener('click', () => show(index + 1));
    close.addEventListener('click', () => dialog.close());

    dialog.addEventListener('close', () => {
        document.body.style.overflow = '';
        image.removeAttribute('src');
        links[index].focus();
    });

    // Klick neben das Bild (auf den Hintergrund) schließt die Lightbox.
    dialog.addEventListener('click', (event) => {
        if (event.target.closest('button, img, figcaption')) {
            return;
        }
        dialog.close();
    });

    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') {
            show(index - 1);
        } else if (event.key === 'ArrowRight') {
            show(index + 1);
        }
    });

    // Wischgesten auf Touch-Geräten.
    let touchX = null;
    dialog.addEventListener('touchstart', (event) => {
        touchX = event.touches[0].clientX;
    }, { passive: true });
    dialog.addEventListener('touchend', (event) => {
        if (touchX === null) {
            return;
        }
        const delta = event.changedTouches[0].clientX - touchX;
        touchX = null;
        if (Math.abs(delta) > 50) {
            show(delta > 0 ? index - 1 : index + 1);
        }
    });
}
