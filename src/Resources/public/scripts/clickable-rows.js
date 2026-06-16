(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const clickableRows = Array.from(document.querySelectorAll('[data-row-href]'));

        clickableRows.forEach(function (row) {
            const openRow = function () {
                const href = row.dataset.rowHref;

                if (href) {
                    window.location.href = href;
                }
            };

            const isInteractiveTarget = function (target) {
                return Boolean(target.closest('a, button, input, select, textarea, summary, [data-ignore-row-click]'));
            };

            row.addEventListener('click', function (event) {
                if (isInteractiveTarget(event.target)) {
                    return;
                }

                openRow();
            });

            row.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                if (isInteractiveTarget(event.target)) {
                    return;
                }

                event.preventDefault();
                openRow();
            });
        });
    });
}());
