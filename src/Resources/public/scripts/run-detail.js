(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var controls = document.querySelector('[data-run-detail]');
        var content = document.querySelector('[data-run-detail-content]');
        var actions = document.querySelector('[data-run-detail-actions]');

        if (!controls || !content || !actions) {
            return;
        }

        var toggle = controls.querySelector('[data-run-detail-poll-toggle]');
        var toggleLabel = controls.querySelector('[data-run-detail-button-label]');
        var loader = controls.querySelector('[data-run-detail-poll-loader]');
        var stateTarget = controls.querySelector('[data-run-detail-state]');
        var refreshTarget = controls.querySelector('[data-run-detail-refresh]');

        var labels = controls.dataset;
        var pollInterval = Math.max(parseInt(labels.runDetailPollInterval, 10) || 2000, 1000);
        var storageKey = 'fluxx-run-detail-polling-enabled:' + window.location.pathname;
        var pollingEnabled = true;
        var refreshTimer = null;

        try {
            pollingEnabled = sessionStorage.getItem(storageKey) !== 'false';
        } catch (error) {
            // Ignore storage failures and keep polling enabled.
        }

        var syncUi = function (loading) {
            toggleLabel.textContent = pollingEnabled ? labels.runDetailPauseLabel : labels.runDetailResumeLabel;
            toggle.setAttribute('aria-pressed', String(pollingEnabled));
            loader.hidden = !loading;
        };

        var setState = function (state, label) {
            stateTarget.className = 'status-badge ' + state;
            stateTarget.textContent = label;
        };

        var isSelectingContent = function () {
            var selection = window.getSelection();

            return Boolean(selection && !selection.isCollapsed && content.contains(selection.anchorNode));
        };

        // The content is rebuilt on each refresh: horizontal scrolling must survive it.
        var replaceContent = function (html) {
            var selector = '.workflow-list';
            var offsets = Array.from(content.querySelectorAll(selector)).map(function (element) {
                return element.scrollLeft;
            });

            content.innerHTML = html;

            content.querySelectorAll(selector).forEach(function (element, index) {
                element.scrollLeft = offsets[index] || 0;
            });
        };

        var apply = function (page) {
            var newContent = page.querySelector('[data-run-detail-content]');
            var newActions = page.querySelector('[data-run-detail-actions]');

            if (!newContent || !newActions) {
                throw new Error('Unexpected refresh response');
            }

            if (!isSelectingContent()) {
                replaceContent(newContent.innerHTML);
            }

            if (newActions.dataset.runStatus !== actions.dataset.runStatus) {
                actions.innerHTML = newActions.innerHTML;
                actions.dataset.runStatus = newActions.dataset.runStatus;
            }

            return page.querySelector('[data-run-detail]') !== null;
        };

        var stop = function () {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        };

        var refresh = async function () {
            syncUi(true);

            try {
                var response = await fetch(window.location.href, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html'
                    },
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Poll request failed');
                }

                var page = new DOMParser().parseFromString(await response.text(), 'text/html');

                if (!apply(page)) {
                    // The run has finished: nothing will change anymore.
                    pollingEnabled = false;
                    controls.remove();

                    return;
                }

                setState('status-badge-completed', labels.runDetailStateLiveLabel);
                refreshTarget.textContent = new Date().toLocaleTimeString();
            } catch (error) {
                setState('status-badge-failed', error instanceof TypeError ? labels.runDetailStateOfflineLabel : labels.runDetailStateErrorLabel);
                refreshTarget.textContent = labels.runDetailRefreshFailedLabel;
            }

            syncUi(false);
        };

        var schedule = function () {
            stop();

            if (!pollingEnabled) {
                return;
            }

            refreshTimer = window.setTimeout(async function () {
                await refresh();
                schedule();
            }, pollInterval);
        };

        toggle.addEventListener('click', function () {
            pollingEnabled = !pollingEnabled;

            try {
                sessionStorage.setItem(storageKey, String(pollingEnabled));
            } catch (error) {
                // Ignore storage failures.
            }

            syncUi(false);
            schedule();
        });

        actions.addEventListener('submit', stop);

        syncUi(false);
        schedule();
    });
}());
