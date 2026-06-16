(function () {
    const storageKey = 'fluxx-theme';
    const root = document.documentElement;
    const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

    const getStoredTheme = function () {
        try {
            return localStorage.getItem(storageKey);
        } catch (error) {
            return null;
        }
    };

    const applyTheme = function (theme, toggle) {
        root.dataset.theme = theme;

        if (toggle) {
            const label = toggle.querySelector('[data-theme-toggle-label]');

            if (label) {
                label.textContent = theme === 'dark' ? toggle.dataset.labelLight : toggle.dataset.labelDark;
            }

            toggle.setAttribute('aria-pressed', String(theme === 'dark'));
        }
    };

    const resolveTheme = function () {
        return getStoredTheme() || (darkQuery.matches ? 'dark' : 'light');
    };

    applyTheme(resolveTheme(), null);

    const handleSystemThemeChange = function (event) {
        if (getStoredTheme() !== null) {
            return;
        }

        applyTheme(event.matches ? 'dark' : 'light', document.querySelector('[data-theme-toggle]'));
    };

    if (typeof darkQuery.addEventListener === 'function') {
        darkQuery.addEventListener('change', handleSystemThemeChange);
    } else if (typeof darkQuery.addListener === 'function') {
        darkQuery.addListener(handleSystemThemeChange);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const toggle = document.querySelector('[data-theme-toggle]');

        if (!toggle) {
            return;
        }

        applyTheme(resolveTheme(), toggle);

        toggle.addEventListener('click', function () {
            const nextTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';

            try {
                localStorage.setItem(storageKey, nextTheme);
            } catch (error) {
                // Ignore storage failures and keep the in-memory theme state.
            }

            applyTheme(nextTheme, toggle);
        });
    });
}());
