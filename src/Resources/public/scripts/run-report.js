(function () {
    // The panels are all rendered: without this script the whole report shows, one section after another.
    const nav = document.querySelector('[data-report-tabs]');

    if (!nav) {
        return;
    }

    const panels = Array.from(document.querySelectorAll('[data-report-panel]'));
    const tabs = Array.from(nav.querySelectorAll('[data-report-tab]'));
    const panelIds = panels.map(function (panel) { return panel.id; });

    const panelFor = function (hash) {
        const id = (hash || '').replace(/^#/, '');

        if (panelIds.indexOf(id) !== -1) {
            return { panel: id, target: null };
        }

        const target = id !== '' ? document.getElementById(id) : null;
        const holder = target ? target.closest('[data-report-panel]') : null;

        return { panel: holder ? holder.id : panelIds[0], target: holder ? target : null };
    };

    const show = function (hash) {
        const resolved = panelFor(hash);

        panels.forEach(function (panel) {
            panel.hidden = panel.id !== resolved.panel;
        });
        tabs.forEach(function (tab) {
            const active = tab.dataset.reportTab === resolved.panel;
            tab.classList.toggle('is-active', active);
            if (active) {
                tab.setAttribute('aria-current', 'page');
            } else {
                tab.removeAttribute('aria-current');
            }
        });

        if (resolved.target) {
            resolved.target.scrollIntoView({ block: 'center' });
            resolved.target.classList.remove('is-highlighted');
            void resolved.target.offsetWidth;
            resolved.target.classList.add('is-highlighted');
        }
    };

    window.addEventListener('hashchange', function () {
        show(window.location.hash);
    });

    show(window.location.hash);
})();
