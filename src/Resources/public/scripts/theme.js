(function () {
    const root = document.documentElement;
    const storageKey = 'fluxx-theme';
    let theme = 'dark';

    try {
        const stored = localStorage.getItem(storageKey);

        if (stored === 'light' || stored === 'dark') {
            theme = stored;
        }
    } catch (error) {
        // Storage may be unavailable: keep the default theme.
    }

    root.dataset.theme = theme;
    delete root.dataset.themeVariant;

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                const next = root.dataset.theme === 'dark' ? 'light' : 'dark';

                root.dataset.theme = next;

                try {
                    localStorage.setItem(storageKey, next);
                } catch (error) {
                    // Storage may be unavailable: the choice lasts for this page only.
                }
            });
        });
    });
}());
