document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-profile-photo-form]');
    const input = document.querySelector('[data-profile-photo-input]');
    const status = document.querySelector('[data-profile-photo-status]');
    const maximumBytes = 5 * 1024 * 1024;
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

    if (!(form instanceof HTMLFormElement)
        || !(input instanceof HTMLInputElement)
        || !(status instanceof HTMLElement)) {
        return;
    }

    input.addEventListener('change', () => {
        const image = input.files?.[0];
        if (!image) {
            return;
        }
        if (!allowedTypes.includes(image.type)) {
            status.textContent = 'Format non accepté. Choisissez une image JPEG, PNG ou WEBP.';
            input.value = '';
            return;
        }
        if (image.size < 1 || image.size > maximumBytes) {
            status.textContent = 'Choisissez une image valide de 5 Mio maximum.';
            input.value = '';
            return;
        }

        status.textContent = 'Import de votre photo en cours…';
        form.requestSubmit();
    });
});
