(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const workflowTabStorageKey = 'fluxx-workflow-detail-tab';
        const tabs = document.querySelector('[data-tabs]');

        if (!tabs) {
            return;
        }

        const buttons = Array.from(tabs.querySelectorAll('[data-tab-button]'));
        const panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
        const panelTargets = {};
        const availableTabs = buttons.map(function (button) {
            return button.dataset.tabButton;
        });
        const pageStorageKey = workflowTabStorageKey + ':' + window.location.pathname;
        const tabBaseUrl = tabs.dataset.tabBaseUrl || '';
        const loadingLabel = tabs.dataset.tabLoadingLabel || 'Loading';
        const errorLabel = tabs.dataset.tabErrorLabel || 'Unable to load this tab.';
        const pausePollingLabel = tabs.dataset.tabPollPauseLabel || 'Pause polling';
        const resumePollingLabel = tabs.dataset.tabPollResumeLabel || 'Resume polling';
        const executionLiveLabel = tabs.dataset.tabPollStateLiveLabel || 'Live';
        const executionErrorStateLabel = tabs.dataset.tabPollStateErrorLabel || 'Error';
        const executionOfflineStateLabel = tabs.dataset.tabPollStateOfflineLabel || 'Offline';
        const executionRefreshFailedLabel = tabs.dataset.tabPollRefreshFailedLabel || 'Execution refresh failed';
        const tabState = {};
        let activeTabName = null;
        let executionRefreshTimer = null;
        const executionPollingToggle = document.querySelector('[data-executions-poll-toggle]');
        const executionPollingToggleLabel = executionPollingToggle
            ? executionPollingToggle.querySelector('[data-button-label]')
            : null;
        const executionPollingLoader = document.querySelector('[data-executions-poll-loader]');
        const executionStateTarget = document.querySelector('[data-executions-state]');
        const executionRefreshTarget = document.querySelector('[data-executions-refresh]');
        const executionPollingStorageKey = pageStorageKey + ':executions-polling-enabled';
        let executionPollingEnabled = true;
        let executionPollingGeneration = 0;

        try {
            const storedExecutionPollingState = sessionStorage.getItem(executionPollingStorageKey);

            if (storedExecutionPollingState === 'false') {
                executionPollingEnabled = false;
            }
        } catch (error) {
            // Ignore storage failures and keep polling enabled by default.
        }

        const syncExecutionPollingUi = function () {
            if (executionPollingToggle && executionPollingToggleLabel) {
                executionPollingToggleLabel.textContent = executionPollingEnabled ? pausePollingLabel : resumePollingLabel;
                executionPollingToggle.setAttribute('aria-pressed', String(executionPollingEnabled));
            }

            if (executionPollingLoader) {
                executionPollingLoader.hidden = !(executionPollingEnabled && activeTabName === 'executions');
            }
        };

        const setExecutionState = function (state, label) {
            if (!executionStateTarget) {
                return;
            }

            executionStateTarget.className = 'status-badge ' + state;
            executionStateTarget.textContent = label;
        };

        const setExecutionRefresh = function (label) {
            if (!executionRefreshTarget) {
                return;
            }

            executionRefreshTarget.textContent = label;
        };

        panels.forEach(function (panel) {
            const tabName = panel.dataset.tabPanel;

            if (!tabName) {
                return;
            }

            const target = panel.querySelector('[data-tab-content="' + tabName + '"]');

            if (target) {
                panelTargets[tabName] = target;
            }
        });

        const renderTabState = function (tabName, html) {
            const target = panelTargets[tabName];

            if (!target) {
                return;
            }

            target.innerHTML = html;
        };

        const tabUrl = function (tabName, url) {
            if (url) {
                return url;
            }

            if (!tabBaseUrl) {
                return '';
            }

            return tabBaseUrl.replace('__tab__', tabName);
        };

        const loadTab = async function (tabName, options) {
            const config = options || {};
            const state = tabState[tabName] || {
                loaded: false,
                loading: false,
                currentUrl: tabUrl(tabName),
            };
            const target = panelTargets[tabName];

            if (!target) {
                return;
            }

            if (state.loading) {
                return;
            }

            if (!config.force && state.loaded) {
                return;
            }

            state.loading = true;
            state.currentUrl = tabUrl(tabName, config.url || state.currentUrl);
            tabState[tabName] = state;

            const isExecutionPollingRequest = tabName === 'executions'
                && config.showPollingLoader
                && config.pollingGeneration === executionPollingGeneration
                && executionPollingEnabled;

            syncExecutionPollingUi();

            if (!state.loaded) {
                renderTabState(tabName, '<section class="empty">' + loadingLabel + '</section>');
            }

            try {
                const response = await fetch(state.currentUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html',
                    },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    throw new Error('Tab request failed');
                }

                renderTabState(tabName, await response.text());
                state.loaded = true;

                if (tabName === 'executions') {
                    setExecutionState('status-badge-completed', executionLiveLabel);
                    setExecutionRefresh(new Date().toLocaleTimeString());
                }
            } catch (error) {
                if (!state.loaded || config.force) {
                    renderTabState(tabName, '<section class="empty">' + errorLabel + '</section>');
                }

                if (tabName === 'executions') {
                    const executionStateLabel = error instanceof TypeError ? executionOfflineStateLabel : executionErrorStateLabel;

                    setExecutionState('status-badge-failed', executionStateLabel);
                    setExecutionRefresh(executionRefreshFailedLabel);
                }
            } finally {
                state.loading = false;

                syncExecutionPollingUi();
            }
        };

        const stopExecutionRefresh = function () {
            if (executionRefreshTimer !== null) {
                window.clearTimeout(executionRefreshTimer);
                executionRefreshTimer = null;
            }
        };

        const scheduleExecutionRefresh = function () {
            stopExecutionRefresh();

            if (activeTabName !== 'executions' || !executionPollingEnabled) {
                return;
            }

            const generation = executionPollingGeneration;
            executionRefreshTimer = window.setTimeout(async function () {
                if (activeTabName !== 'executions' || !executionPollingEnabled || generation !== executionPollingGeneration) {
                    return;
                }

                await loadTab('executions', {
                    force: true,
                    showPollingLoader: true,
                    pollingGeneration: generation,
                });

                if (activeTabName !== 'executions' || !executionPollingEnabled || generation !== executionPollingGeneration) {
                    return;
                }

                scheduleExecutionRefresh();
            }, 1000);
        };

        const activateTab = async function (tabName, options) {
            if (!availableTabs.includes(tabName)) {
                return;
            }

            activeTabName = tabName;

            buttons.forEach(function (button) {
                const isActive = button.dataset.tabButton === tabName;

                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-selected', String(isActive));
            });

            panels.forEach(function (panel) {
                panel.hidden = panel.dataset.tabPanel !== tabName;
            });

            try {
                sessionStorage.setItem(pageStorageKey, tabName);
            } catch (error) {
                // Ignore storage failures and keep the in-memory tab state.
            }

            await loadTab(tabName, options);

            if (tabName === 'executions') {
                scheduleExecutionRefresh();
            } else {
                executionPollingGeneration += 1;
                stopExecutionRefresh();
                syncExecutionPollingUi();
            }
        };

        let initialTab = availableTabs[0] || 'steps';

        try {
            const storedTab = sessionStorage.getItem(pageStorageKey);

            if (storedTab && availableTabs.includes(storedTab)) {
                initialTab = storedTab;
            }
        } catch (error) {
            // Ignore storage failures and keep the default tab.
        }

        activateTab(initialTab);

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                activateTab(button.dataset.tabButton);
            });
        });

        if (executionPollingToggle) {
            executionPollingToggle.addEventListener('click', function () {
                executionPollingEnabled = !executionPollingEnabled;
                executionPollingGeneration += 1;

                try {
                    sessionStorage.setItem(executionPollingStorageKey, String(executionPollingEnabled));
                } catch (error) {
                    // Ignore storage failures and keep the in-memory state.
                }

                syncExecutionPollingUi();

                if (executionPollingEnabled) {
                    if (activeTabName === 'executions') {
                        loadTab('executions', { force: true }).then(function () {
                            if (!executionPollingEnabled) {
                                return;
                            }

                            scheduleExecutionRefresh();
                        });
                    }

                    return;
                }

                stopExecutionRefresh();
            });
        }

        panels.forEach(function (panel) {
            panel.addEventListener('click', function (event) {
                const link = event.target.closest('a[data-tab-nav]');

                if (!link || link.getAttribute('aria-disabled') === 'true') {
                    return;
                }

                event.preventDefault();
                activateTab(link.dataset.tabNav || panel.dataset.tabPanel, {
                    force: true,
                    url: link.href,
                }).then(function () {
                    if ((link.dataset.tabNav || panel.dataset.tabPanel) === 'executions') {
                        scheduleExecutionRefresh();
                    }
                });
            });

            panel.addEventListener('submit', function (event) {
                const form = event.target.closest('form[data-tab-form]');

                if (!form) {
                    return;
                }

                event.preventDefault();

                const url = new URL(form.action || window.location.href, window.location.origin);
                const formData = new FormData(form);

                url.search = '';

                formData.forEach(function (value, key) {
                    if (typeof value !== 'string' || value === '') {
                        return;
                    }

                    url.searchParams.set(key, value);
                });

                activateTab(form.dataset.tabForm || panel.dataset.tabPanel, {
                    force: true,
                    url: url.pathname + url.search,
                }).then(function () {
                    if ((form.dataset.tabForm || panel.dataset.tabPanel) === 'executions') {
                        scheduleExecutionRefresh();
                    }
                });
            });
        });

        syncExecutionPollingUi();
    });
}());
