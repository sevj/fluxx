(function () {
    function getGroupItems(group) {
        const items = [];
        let next = group.nextElementSibling;

        while (next && !next.classList.contains('workflow-group-header')) {
            if (next.classList.contains('workflow-group-item')) {
                items.push(next);
            }

            next = next.nextElementSibling;
        }

        return items;
    }

    function toggleGroup(group) {
        const isCollapsed = group.classList.toggle('is-collapsed');
        const button = group.querySelector('.workflow-group-toggle');

        if (button) {
            button.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        }

        getGroupItems(group).forEach(function (item) {
            item.hidden = isCollapsed;
        });
    }

    function bindGroup(group) {
        const button = group.querySelector('.workflow-group-toggle');

        if (button) {
            button.setAttribute('aria-expanded', group.classList.contains('is-collapsed') ? 'false' : 'true');

            button.addEventListener('click', function (event) {
                event.stopPropagation();
                toggleGroup(group);
            });
        }

        group.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, select, textarea')) {
                return;
            }

            toggleGroup(group);
        });

        group.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            if (event.target.closest('a, button, input, select, textarea')) {
                return;
            }

            event.preventDefault();
            toggleGroup(group);
        });
    }

    function init() {
        const groups = Array.from(document.querySelectorAll('.workflow-group-header'));

        if (groups.length === 0) {
            return;
        }

        groups.forEach(function (group) {
            if (group.dataset.workflowGroupBound === '1') {
                return;
            }

            group.dataset.workflowGroupBound = '1';
            bindGroup(group);
        });
    }

    document.addEventListener('DOMContentLoaded', init);
}());
