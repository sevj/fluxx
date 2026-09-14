(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var catalog = document.querySelector('[data-run-catalog]');

        if (!catalog) {
            return;
        }

        var content = document.querySelector('[data-run-catalog-content]');
        var toggle = document.querySelector('[data-run-catalog-poll-toggle]');
        var toggleLabel = toggle ? toggle.querySelector('[data-run-catalog-button-label]') : null;
        var loader = document.querySelector('[data-run-catalog-poll-loader]');
        var stateTarget = document.querySelector('[data-run-catalog-state]');
        var refreshTarget = document.querySelector('[data-run-catalog-refresh]');

        var pollUrl = catalog.dataset.runCatalogPollUrl || '';
        var pollInterval = parseInt(catalog.dataset.runCatalogPollInterval || '1000', 10);
        if (!isFinite(pollInterval) || pollInterval < 1000) {
            pollInterval = 1000;
        }

        var pauseLabel = catalog.dataset.runCatalogPollPauseLabel || 'Pause polling';
        var resumeLabel = catalog.dataset.runCatalogPollResumeLabel || 'Resume polling';
        var liveLabel = catalog.dataset.runCatalogPollStateLiveLabel || 'Live';
        var errorStateLabel = catalog.dataset.runCatalogPollStateErrorLabel || 'Error';
        var offlineStateLabel = catalog.dataset.runCatalogPollStateOfflineLabel || 'Offline';
        var refreshFailedLabel = catalog.dataset.runCatalogPollRefreshFailedLabel || 'Refresh failed';

        var storageKey = 'fluxx-run-catalog-polling-enabled:' + window.location.pathname;
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

        if (content) {
            content.addEventListener('click', function (event) {
                var action = event.target.closest('[data-run-catalog-action]');

                if (!action) {
                    return;
                }

                stopRefresh();
            });

            content.addEventListener('submit', function (event) {
                var form = event.target.closest('.execution-action-form');

                if (!form) {
                    return;
                }

                stopRefresh();
            });
        }

        syncUi();
        scheduleRefresh();
    });
}());
