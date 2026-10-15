(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var board = document.querySelector('[data-run-active]');

        if (!board) {
            return;
        }

        var content = document.querySelector('[data-run-active-content]');
        var toggle = document.querySelector('[data-run-active-poll-toggle]');
        var toggleLabel = toggle ? toggle.querySelector('[data-run-active-button-label]') : null;
        var loader = document.querySelector('[data-run-active-poll-loader]');
        var stateTarget = document.querySelector('[data-run-active-state]');
        var refreshTarget = document.querySelector('[data-run-active-refresh]');

        var pollUrl = board.dataset.runActivePollUrl || '';
        var pollInterval = parseInt(board.dataset.runActivePollInterval || '1000', 10);
        if (!isFinite(pollInterval) || pollInterval < 1000) {
            pollInterval = 1000;
        }

        var pauseLabel = board.dataset.runActivePollPauseLabel || 'Pause polling';
        var resumeLabel = board.dataset.runActivePollResumeLabel || 'Resume polling';
        var liveLabel = board.dataset.runActivePollStateLiveLabel || 'Live';
        var errorStateLabel = board.dataset.runActivePollStateErrorLabel || 'Error';
        var offlineStateLabel = board.dataset.runActivePollStateOfflineLabel || 'Offline';
        var refreshFailedLabel = board.dataset.runActivePollRefreshFailedLabel || 'Refresh failed';

        var storageKey = 'fluxx-run-active-polling-enabled:' + window.location.pathname;
        var pollingEnabled = true;
        var loading = false;
        var refreshTimer = null;

        try {
            var stored = sessionStorage.getItem(storageKey);
            if (stored === 'false') {
                pollingEnabled = false;
            }
        } catch (error) {
            // Ignore storage failures and keep polling enabled.
        }

        var syncUi = function () {
            if (toggle && toggleLabel) {
                toggleLabel.textContent = pollingEnabled ? pauseLabel : resumeLabel;
                toggle.setAttribute('aria-pressed', String(pollingEnabled));
            }

            if (loader) {
                loader.hidden = !pollingEnabled;
            }
        };

        var setState = function (state, label) {
            if (!stateTarget) {
                return;
            }

            stateTarget.className = 'status-badge ' + state;
            stateTarget.textContent = label;
        };

        var setRefresh = function (label) {
            if (refreshTarget) {
                refreshTarget.textContent = label;
            }
        };

        var refresh = async function () {
            if (loading || !pollingEnabled) {
                return;
            }

            loading = true;
            syncUi();

            try {
                var response = await fetch(pollUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html'
                    },
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Poll request failed');
                }

                var html = await response.text();

                if (content) {
                    content.innerHTML = html;
                }

                setState('status-badge-completed', liveLabel);
                setRefresh(new Date().toLocaleTimeString());
            } catch (error) {
                var label = error instanceof TypeError ? offlineStateLabel : errorStateLabel;
                setState('status-badge-failed', label);
                setRefresh(refreshFailedLabel);
            } finally {
                loading = false;
                syncUi();
            }
        };

        var stopRefresh = function () {
            if (refreshTimer !== null) {
                window.clearTimeout(refreshTimer);
                refreshTimer = null;
            }
        };

        var scheduleRefresh = function () {
            stopRefresh();

            if (!pollingEnabled) {
                return;
            }

            refreshTimer = window.setTimeout(async function () {
                await refresh();

                if (pollingEnabled) {
                    scheduleRefresh();
                }
            }, pollInterval);
        };

        if (toggle) {
            toggle.addEventListener('click', function () {
                pollingEnabled = !pollingEnabled;

                try {
                    sessionStorage.setItem(storageKey, String(pollingEnabled));
                } catch (error) {
                    // Ignore storage failures.
                }

                syncUi();

                if (pollingEnabled) {
                    refresh().then(function () {
                        if (pollingEnabled) {
                            scheduleRefresh();
                        }
                    });

                    return;
                }

                stopRefresh();
            });
        }

        syncUi();
        scheduleRefresh();
    });
}());
