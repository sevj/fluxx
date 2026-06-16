(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const runtimeMonitor = document.querySelector('[data-runtime-monitor]');

        if (!runtimeMonitor) {
            return;
        }

        const snapshotUrl = runtimeMonitor.dataset.runtimeSnapshotUrl;
        const labels = {
            backlog: runtimeMonitor.dataset.runtimeLabelBacklog || 'Backlog',
            inFlight: runtimeMonitor.dataset.runtimeLabelInFlight || 'In flight',
            consumers: runtimeMonitor.dataset.runtimeLabelConsumers || 'Consumers',
            activeLocks: runtimeMonitor.dataset.runtimeLabelActiveLocks || 'Active locks',
            oldestPending: runtimeMonitor.dataset.runtimeLabelOldestPending || 'Oldest pending',
            transport: runtimeMonitor.dataset.runtimeLabelTransport || 'Transport',
            stream: runtimeMonitor.dataset.runtimeLabelStream || 'Redis stream',
            group: runtimeMonitor.dataset.runtimeLabelGroup || 'Redis group',
            noWorkers: runtimeMonitor.dataset.runtimeLabelNoWorkers || 'No active consumer was detected.',
            noMessages: runtimeMonitor.dataset.runtimeLabelNoMessages || 'No message is currently visible in the fluxx queue.',
            stateLive: runtimeMonitor.dataset.runtimeLabelStateLive || 'Live',
            stateError: runtimeMonitor.dataset.runtimeLabelStateError || 'Error',
            stateOffline: runtimeMonitor.dataset.runtimeLabelStateOffline || 'Offline',
            queued: runtimeMonitor.dataset.runtimeLabelMessageQueued || 'Queued',
            inFlightState: runtimeMonitor.dataset.runtimeLabelMessageInFlight || 'In flight',
            refreshFailed: runtimeMonitor.dataset.runtimeLabelRefreshFailed || 'Snapshot request failed',
            workerStateProcessing: runtimeMonitor.dataset.runtimeLabelWorkerStateProcessing || 'Processing',
            workerStateActive: runtimeMonitor.dataset.runtimeLabelWorkerStateActive || 'Active',
            workerStateIdle: runtimeMonitor.dataset.runtimeLabelWorkerStateIdle || 'Idle',
            workerStateStopped: runtimeMonitor.dataset.runtimeLabelWorkerStateStopped || 'Stopped',
            workerName: runtimeMonitor.dataset.runtimeLabelWorkerName || 'Worker',
            workerState: runtimeMonitor.dataset.runtimeLabelWorkerState || 'State',
            workerPending: runtimeMonitor.dataset.runtimeLabelWorkerPending || 'Pending',
            workerLastSeen: runtimeMonitor.dataset.runtimeLabelWorkerLastSeen || 'Last seen',
            workerCurrentEmpty: runtimeMonitor.dataset.runtimeLabelWorkerCurrentEmpty || 'No message is currently being handled.',
            workerMessage: runtimeMonitor.dataset.runtimeLabelWorkerMessage || 'Message',
            workerCurrent: runtimeMonitor.dataset.runtimeLabelWorkerCurrent || 'Current message',
            workerRuntime: runtimeMonitor.dataset.runtimeLabelWorkerRuntime || 'Runtime',
            noLocks: runtimeMonitor.dataset.runtimeLabelNoLocks || 'No active workflow lock was detected.',
            lockWorkflow: runtimeMonitor.dataset.runtimeLabelLockWorkflow || 'Workflow',
            lockRun: runtimeMonitor.dataset.runtimeLabelLockRun || 'Run',
            lockScope: runtimeMonitor.dataset.runtimeLabelLockScope || 'Scope',
            lockKey: runtimeMonitor.dataset.runtimeLabelLockKey || 'Lock key',
            lockAcquired: runtimeMonitor.dataset.runtimeLabelLockAcquired || 'Acquired',
            pausePolling: runtimeMonitor.dataset.runtimeLabelPausePolling || 'Pause polling',
            resumePolling: runtimeMonitor.dataset.runtimeLabelResumePolling || 'Resume polling',
        };
        const summaryTarget = runtimeMonitor.querySelector('[data-runtime-summary]');
        const queueTarget = runtimeMonitor.querySelector('[data-runtime-queue]');
        const workersTarget = runtimeMonitor.querySelector('[data-runtime-workers]');
        const locksTarget = runtimeMonitor.querySelector('[data-runtime-locks]');
        const messagesTarget = runtimeMonitor.querySelector('[data-runtime-messages]');
        const stateTarget = runtimeMonitor.querySelector('[data-runtime-state]');
        const refreshTarget = runtimeMonitor.querySelector('[data-runtime-refresh]');
        const errorTarget = runtimeMonitor.querySelector('[data-runtime-error]');
        const runtimeLoader = runtimeMonitor.querySelector('[data-runtime-loader]');
        const runtimePollingToggle = runtimeMonitor.querySelector('[data-runtime-poll-toggle]');
        const runtimePollingToggleLabel = runtimePollingToggle
            ? runtimePollingToggle.querySelector('[data-button-label]')
            : null;
        const runtimePollingStorageKey = 'fluxx-runtime-polling-enabled:' + window.location.pathname;
        let requestInFlight = false;
        let runtimePollingEnabled = true;
        let runtimeRefreshTimer = null;
        let runtimePollingGeneration = 0;

        try {
            const storedRuntimePollingState = sessionStorage.getItem(runtimePollingStorageKey);

            if (storedRuntimePollingState === 'false') {
                runtimePollingEnabled = false;
            }
        } catch (error) {
            // Ignore storage failures and keep polling enabled by default.
        }

        const escapeHtml = function (value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        };

        const formatDateTime = function (value) {
            if (!value) {
                return '-';
            }

            return new Date(value).toLocaleString();
        };

        const formatDuration = function (milliseconds) {
            if (milliseconds === null || milliseconds === undefined) {
                return '-';
            }

            if (milliseconds < 1000) {
                return milliseconds + ' ms';
            }

            return (milliseconds / 1000).toFixed(1) + ' s';
        };

        const formatMemory = function (bytes) {
            if (bytes === null || bytes === undefined) {
                return '-';
            }

            return (bytes / 1048576).toFixed(1) + ' MB';
        };

        const setState = function (state, label) {
            if (!stateTarget) {
                return;
            }

            stateTarget.className = 'status-badge ' + state;
            stateTarget.textContent = label;
        };

        const setError = function (message) {
            if (!errorTarget) {
                return;
            }

            if (!message) {
                errorTarget.hidden = true;
                errorTarget.textContent = '';
                return;
            }

            errorTarget.hidden = false;
            errorTarget.textContent = message;
        };

        const setRuntimeLoading = function (loading) {
            if (!runtimeLoader) {
                return;
            }

            runtimeLoader.hidden = !loading;
        };

        const syncRuntimePollingUi = function () {
            if (runtimePollingToggle && runtimePollingToggleLabel) {
                runtimePollingToggleLabel.textContent = runtimePollingEnabled ? labels.pausePolling : labels.resumePolling;
                runtimePollingToggle.setAttribute('aria-pressed', String(runtimePollingEnabled));
            }

            setRuntimeLoading(runtimePollingEnabled);
        };

        const stopRuntimeRefresh = function () {
            if (runtimeRefreshTimer !== null) {
                window.clearTimeout(runtimeRefreshTimer);
                runtimeRefreshTimer = null;
            }
        };

        const renderSummary = function (summary) {
            if (!summaryTarget) {
                return;
            }

            summaryTarget.innerHTML = [
                [labels.backlog, summary.backlogCount],
                [labels.inFlight, summary.inFlightCount],
                [labels.consumers, summary.consumerCount],
                [labels.activeLocks, summary.activeLockCount],
                [labels.oldestPending, formatDuration(summary.oldestPendingAgeMs)],
            ].map(function (item) {
                return '<article class="runtime-overview-card">'
                    + '<div class="runtime-overview-label">' + escapeHtml(item[0]) + '</div>'
                    + '<div class="runtime-overview-value">' + escapeHtml(item[1]) + '</div>'
                    + '</article>';
            }).join('');
        };

        const renderLocks = function (locks) {
            if (!locksTarget) {
                return;
            }

            if (!locks.length) {
                locksTarget.innerHTML = '<tr><td colspan="5" class="runtime-table-empty">' + escapeHtml(labels.noLocks) + '</td></tr>';
                return;
            }

            locksTarget.innerHTML = locks.map(function (lock) {
                const workflow = (lock.workflowName || lock.workflowCode || '-')
                    + '<div class="item-code">' + escapeHtml(lock.workflowCode || '-') + '</div>';
                const runStatus = lock.status
                    ? '<div class="table-note table-note-compact">' + escapeHtml(lock.status) + '</div>'
                    : '';
                const scope = escapeHtml(lock.scope || '-');

                return '<tr>'
                    + '<td data-label="' + escapeHtml(labels.lockWorkflow) + '">' + workflow + '</td>'
                    + '<td data-label="' + escapeHtml(labels.lockRun) + '"><span class="run-id">' + escapeHtml(lock.runId || '-') + '</span>' + runStatus + '</td>'
                    + '<td data-label="' + escapeHtml(labels.lockScope) + '"><span class="system-chip">' + scope + '</span></td>'
                    + '<td data-label="' + escapeHtml(labels.lockKey) + '"><span class="item-code">' + escapeHtml(lock.lockKey || '-') + '</span>'
                    + (lock.businessPartitionKey ? '<div class="table-note table-note-compact">' + escapeHtml(lock.businessPartitionKey) + '</div>' : '')
                    + '</td>'
                    + '<td data-label="' + escapeHtml(labels.lockAcquired) + '"><span class="metric-value">' + escapeHtml(formatDateTime(lock.acquiredAt)) + '</span></td>'
                    + '</tr>';
            }).join('');
        };

        const renderQueue = function (queue) {
            if (!queueTarget) {
                return;
            }

            queueTarget.innerHTML = ''
                + '<section class="definition-block definition-block-compact">'
                + '<div class="definition-label">' + escapeHtml(labels.transport) + '</div>'
                + '<div class="definition-inline-list"><div class="definition-inline-item"><span class="definition-inline-value">' + escapeHtml(queue.name || '-') + '</span></div></div>'
                + '</section>'
                + '<section class="definition-block definition-block-compact">'
                + '<div class="definition-label">' + escapeHtml(labels.stream) + '</div>'
                + '<div class="definition-inline-list"><div class="definition-inline-item"><span class="definition-inline-value">' + escapeHtml(queue.stream || '-') + '</span></div></div>'
                + '</section>'
                + '<section class="definition-block definition-block-compact">'
                + '<div class="definition-label">' + escapeHtml(labels.group) + '</div>'
                + '<div class="definition-inline-list"><div class="definition-inline-item"><span class="definition-inline-value">' + escapeHtml(queue.group || '-') + '</span></div></div>'
                + '</section>';
        };

        const renderWorkers = function (workers) {
            if (!workersTarget) {
                return;
            }

            if (!workers.length) {
                workersTarget.innerHTML = '<tr><td colspan="6" class="runtime-table-empty">' + escapeHtml(labels.noWorkers) + '</td></tr>';
                return;
            }

            workersTarget.innerHTML = workers.map(function (worker) {
                const stateLabelMap = {
                    processing: labels.workerStateProcessing,
                    active: labels.workerStateActive,
                    idle: labels.workerStateIdle,
                    stopped: labels.workerStateStopped,
                };
                const statusClassMap = {
                    processing: 'status-badge-running',
                    active: 'status-badge-running',
                    idle: 'status-badge-pending',
                    stopped: 'status-badge-failed',
                };
                const statusClass = statusClassMap[worker.state] || 'status-badge-pending';
                const workerMeta = [];
                const currentToneStyle = worker.stepTypeToneStyle ? ' style="' + escapeHtml(worker.stepTypeToneStyle) + '"' : '';
                const currentMessage = worker.currentMessageClass
                    ? '<div class="runtime-step-cell step-tone-' + escapeHtml(worker.stepTypeTone || 'custom') + ' step-tone-surface"' + currentToneStyle + '>'
                        + (worker.stepTypeLabel
                            ? '<span class="step-tone-pill">' + escapeHtml(worker.stepTypeLabel) + '</span>'
                            : '')
                        + '<div class="runtime-step-name">' + escapeHtml(worker.stepName || worker.stepCode || labels.workerMessage) + '</div>'
                        + (worker.workflowName || worker.workflowCode
                            ? '<div class="table-note table-note-compact">' + escapeHtml(worker.workflowName || worker.workflowCode || '-') + '</div>'
                            : '')
                        + (worker.runId
                            ? '<div class="item-code">Run ' + escapeHtml(worker.runId) + '</div>'
                            : '')
                        + (worker.currentTransportMessageId
                            ? '<div class="item-code">' + escapeHtml(worker.currentTransportMessageId) + '</div>'
                            : '')
                        + '<div class="table-note table-note-compact">' + escapeHtml(worker.currentMessageClass) + '</div>'
                    + '</div>'
                    : '<div class="runtime-worker-empty">' + escapeHtml(labels.workerCurrentEmpty) + '</div>';

                if (worker.host) {
                    workerMeta.push(escapeHtml(worker.host));
                }

                if (worker.pid) {
                    workerMeta.push('PID ' + escapeHtml(worker.pid));
                }

                return '<tr>'
                    + '<td data-label="' + escapeHtml(labels.workerName) + '"><span class="run-id">' + escapeHtml(worker.name) + '</span>'
                    + (workerMeta.length ? '<div class="table-note table-note-compact">' + workerMeta.join(' / ') + '</div>' : '')
                    + '</td>'
                    + '<td data-label="' + escapeHtml(labels.workerState) + '"><span class="status-badge ' + statusClass + '">' + escapeHtml(stateLabelMap[worker.state] || worker.state || '-') + '</span></td>'
                    + '<td data-label="' + escapeHtml(labels.workerPending) + '"><span class="metric-value">' + escapeHtml(worker.pendingCount) + '</span></td>'
                    + '<td data-label="' + escapeHtml(labels.workerCurrent) + '">' + currentMessage + '</td>'
                    + '<td data-label="' + escapeHtml(labels.workerRuntime) + '"><span class="metric-value">' + escapeHtml(formatDuration(worker.processingDurationMs)) + '</span>'
                    + '<div class="table-note table-note-compact">' + escapeHtml(formatMemory(worker.memoryBytes)) + '</div></td>'
                    + '<td data-label="' + escapeHtml(labels.workerLastSeen) + '"><span class="metric-value">' + escapeHtml(formatDateTime(worker.lastSeenAt)) + '</span>'
                    + '<div class="table-note table-note-compact">' + escapeHtml(formatDuration(worker.idleMs)) + '</div></td>'
                    + '</tr>';
            }).join('');
        };

        const renderMessages = function (messages) {
            if (!messagesTarget) {
                return;
            }

            if (!messages.length) {
                messagesTarget.innerHTML = '<tr><td colspan="7" class="runtime-table-empty">' + escapeHtml(labels.noMessages) + '</td></tr>';
                return;
            }

            messagesTarget.innerHTML = messages.map(function (message) {
                const stateClass = message.state === 'in_flight' ? 'status-badge-running' : 'status-badge-pending';
                const consumer = message.consumerName || '-';
                const delivery = message.deliveryCount === null ? '-' : message.deliveryCount;
                const workflow = (message.workflowName || message.workflowCode || '-') + '<div class="item-code">' + escapeHtml(message.workflowCode || '-') + '</div>';
                const runtime = message.durationMs !== null || message.memoryPeakBytes !== null
                    ? '<div class="table-note table-note-compact">' + escapeHtml(formatDuration(message.durationMs)) + ' / ' + escapeHtml(formatMemory(message.memoryPeakBytes)) + '</div>'
                    : '';
                const error = message.errorMessage
                    ? '<div class="table-note metric-error">' + escapeHtml(message.errorMessage) + '</div>'
                    : '';
                const toneStyle = message.stepTypeToneStyle ? ' style="' + escapeHtml(message.stepTypeToneStyle) + '"' : '';

                return '<tr>'
                    + '<td><span class="run-id">' + escapeHtml(message.runId) + '</span><div class="item-code">' + escapeHtml(message.id) + '</div></td>'
                    + '<td><span class="status-badge ' + stateClass + '">' + escapeHtml(message.state === 'in_flight' ? labels.inFlightState : labels.queued) + '</span></td>'
                    + '<td>' + workflow + '</td>'
                    + '<td><div class="runtime-step-cell step-tone-' + escapeHtml(message.stepTypeTone || 'custom') + ' step-tone-surface"' + toneStyle + '>'
                    + '<span class="step-tone-pill">' + escapeHtml(message.stepTypeLabel || message.stepType || '-') + '</span>'
                    + '<div class="runtime-step-name">' + escapeHtml(message.stepName || message.stepCode || '-') + '</div>'
                    + '<div class="item-code">' + escapeHtml(message.stepCode || '-') + '</div>'
                    + runtime
                    + error
                    + '</div></td>'
                    + '<td><span class="run-id">' + escapeHtml(consumer) + '</span></td>'
                    + '<td><span class="metric-value">' + escapeHtml(delivery) + '</span></td>'
                    + '<td><span class="metric-value">' + escapeHtml(formatDuration(message.ageMs)) + '</span><div class="table-note table-note-compact">' + escapeHtml(formatDateTime(message.enqueuedAt)) + '</div></td>'
                    + '</tr>';
            }).join('');
        };

        const renderSnapshot = function (snapshot) {
            renderSummary(snapshot.summary || {});
            renderQueue(snapshot.queue || {});
            renderWorkers(snapshot.workers || []);
            renderLocks(snapshot.activeLocks || []);
            renderMessages(snapshot.messages || []);

            if (refreshTarget) {
                refreshTarget.textContent = snapshot.refreshedAt ? new Date(snapshot.refreshedAt).toLocaleTimeString() : '-';
            }

            if (snapshot.ok) {
                setError(null);
                setState('status-badge-completed', labels.stateLive);
            } else {
                setError(snapshot.error || labels.refreshFailed);
                setState('status-badge-failed', labels.stateError);
            }
        };

        const refreshSnapshot = async function (generation) {
            if (!snapshotUrl || requestInFlight) {
                return;
            }

            if (!runtimePollingEnabled || generation !== runtimePollingGeneration) {
                syncRuntimePollingUi();

                return;
            }

            requestInFlight = true;
            syncRuntimePollingUi();

            try {
                const response = await fetch(snapshotUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    cache: 'no-store',
                });
                const snapshot = await response.json();

                renderSnapshot(snapshot);
            } catch (error) {
                setError(labels.refreshFailed);
                setState('status-badge-failed', labels.stateOffline);

                if (refreshTarget) {
                    refreshTarget.textContent = labels.refreshFailed;
                }
            } finally {
                requestInFlight = false;
                if (generation !== runtimePollingGeneration || !runtimePollingEnabled) {
                    syncRuntimePollingUi();
                    return;
                }

                syncRuntimePollingUi();
                runtimeRefreshTimer = window.setTimeout(function () {
                    refreshSnapshot(generation);
                }, 1000);
            }
        };

        if (runtimePollingToggle) {
            runtimePollingToggle.addEventListener('click', function () {
                runtimePollingEnabled = !runtimePollingEnabled;
                runtimePollingGeneration += 1;

                try {
                    sessionStorage.setItem(runtimePollingStorageKey, String(runtimePollingEnabled));
                } catch (error) {
                    // Ignore storage failures and keep the in-memory state.
                }

                stopRuntimeRefresh();
                syncRuntimePollingUi();

                if (runtimePollingEnabled) {
                    refreshSnapshot(runtimePollingGeneration);
                }
            });
        }

        syncRuntimePollingUi();
        refreshSnapshot(runtimePollingGeneration);
    });
}());
