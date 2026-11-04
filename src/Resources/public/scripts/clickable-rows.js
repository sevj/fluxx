(function () {
    // Delegated to the document: live refreshes replace the rows.
    const rowOf = function (target) {
        if (target.closest('a, button, input, select, textarea, summary, [data-ignore-row-click]')) {
            return null;
        }

        return target.closest('[data-row-href]');
    };

    document.addEventListener('click', function (event) {
        const row = rowOf(event.target);

        if (row) {
            window.location.href = row.dataset.rowHref;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        const row = rowOf(event.target);

        if (row) {
            event.preventDefault();
            window.location.href = row.dataset.rowHref;
        }
    });
}());
