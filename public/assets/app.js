'use strict';

document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        const search = document.querySelector('#global-search');
        if (search) {
            event.preventDefault();
            search.focus();
            search.select();
        }
    }
});

const editorRoot = document.querySelector('[data-wiki-editor]');
if (editorRoot) {
    const formatInput = editorRoot.querySelector('[data-content-format]');
    const visualEditor = editorRoot.querySelector('[data-visual-editor]');
    const markdownEditor = editorRoot.querySelector('[data-markdown-editor]');
    const htmlField = editorRoot.querySelector('[data-html-field]');
    const form = editorRoot.querySelector('[data-editor-form]');
    const saveButton = editorRoot.querySelector('[data-save-button]');
    const saveState = editorRoot.querySelector('[data-save-state]');
    const lockWarning = editorRoot.querySelector('[data-edit-lock-warning]');
    const recoveryBanner = editorRoot.querySelector('[data-recovery-banner]');
    const recoveryText = editorRoot.querySelector('[data-recovery-text]');
    const tabs = Array.from(editorRoot.querySelectorAll('[data-editor-mode]'));
    const panes = Array.from(editorRoot.querySelectorAll('[data-pane]'));
    const isEdit = editorRoot.dataset.isEdit === '1';
    const csrfToken = form.querySelector('input[name="_token"]').value;
    const baseVersionField = form.querySelector('input[name="base_version"]');
    const recoveryKey = 'openwiki:draft:' + window.location.pathname;
    let lockToken = '';
    let dirty = false;
    let autosaveTimer = null;
    let localSaveTimer = null;

    const setMode = (mode) => {
        const safeMode = mode === 'markdown' ? 'markdown' : 'visual';
        formatInput.value = safeMode;
        tabs.forEach((tab) => {
            const active = tab.dataset.editorMode === safeMode;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panes.forEach((pane) => {
            pane.hidden = pane.dataset.pane !== safeMode;
        });
    };

    const snapshot = () => ({
        savedAt: new Date().toISOString(),
        title: form.elements.title.value,
        slug: form.elements.slug.value,
        parentId: form.elements.parent_id.value,
        status: form.elements.status.value,
        format: formatInput.value,
        html: visualEditor.innerHTML,
        markdown: markdownEditor.value
    });

    const persistLocalRecovery = () => {
        try {
            localStorage.setItem(recoveryKey, JSON.stringify(snapshot()));
            saveState.textContent = 'Recovery copy saved locally.';
        } catch {
            saveState.textContent = 'Local recovery storage is unavailable.';
        }
    };

    const markDirty = () => {
        dirty = true;
        window.clearTimeout(localSaveTimer);
        localSaveTimer = window.setTimeout(persistLocalRecovery, 500);
    };

    const restoreSnapshot = (data) => {
        if (!data) return;
        if (typeof data.title === 'string') form.elements.title.value = data.title;
        if (typeof data.slug === 'string') form.elements.slug.value = data.slug;
        if (typeof data.parentId === 'string') form.elements.parent_id.value = data.parentId;
        if (typeof data.status === 'string') form.elements.status.value = data.status;
        if (typeof data.html === 'string') visualEditor.innerHTML = data.html;
        if (typeof data.markdown === 'string') markdownEditor.value = data.markdown;
        setMode(data.format);
        markDirty();
    };

    let recovery = null;
    try {
        const local = localStorage.getItem(recoveryKey);
        if (local) recovery = JSON.parse(local);
    } catch {
        recovery = null;
    }

    if (!recovery) {
        const serverDraft = editorRoot.querySelector('[data-server-draft]');
        if (serverDraft) {
            recovery = {
                savedAt: serverDraft.dataset.savedAt,
                format: serverDraft.dataset.format,
                html: serverDraft.querySelector('[data-server-draft-html]').value,
                markdown: serverDraft.querySelector('[data-server-draft-markdown]').value
            };
        }
    }

    if (recovery) {
        recoveryText.textContent = 'Recovered editor content from ' + (recovery.savedAt || 'an earlier autosave') + ' is available.';
        recoveryBanner.hidden = false;
        editorRoot.querySelector('[data-restore-recovery]').addEventListener('click', () => {
            restoreSnapshot(recovery);
            recoveryBanner.hidden = true;
            saveState.textContent = 'Recovered content restored. Save the page to create a revision.';
        });
        editorRoot.querySelector('[data-hide-recovery]').addEventListener('click', () => {
            recoveryBanner.hidden = true;
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            setMode(tab.dataset.editorMode);
            markDirty();
        });
    });

    editorRoot.querySelectorAll('[data-command]').forEach((button) => {
        button.addEventListener('click', () => {
            visualEditor.focus();
            const command = button.dataset.command;
            const value = button.dataset.commandValue || null;
            document.execCommand(command, false, value);
            markDirty();
        });
    });

    const templateSelector = editorRoot.querySelector('[data-template-selector]');
    if (templateSelector) {
        templateSelector.addEventListener('change', () => {
            const option = templateSelector.options[templateSelector.selectedIndex];
            if (!option || !option.value) return;
            visualEditor.innerHTML = option.dataset.html || '<p></p>';
            markdownEditor.value = option.dataset.markdown || '';
            setMode(markdownEditor.value ? 'markdown' : 'visual');
            markDirty();
        });
    }

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);

    const acquireLock = async () => {
        if (!isEdit || !editorRoot.dataset.lockUrl) return;

        const body = new URLSearchParams({_token: csrfToken});
        try {
            const response = await fetch(editorRoot.dataset.lockUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body,
                credentials: 'same-origin'
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload?.error?.message || 'Unable to verify edit lock.');
            }

            if (!payload.data.acquired) {
                lockWarning.textContent = (payload.data.holder || 'Another user') + ' is currently editing this page. Saving is disabled until the edit lock is released.';
                lockWarning.hidden = false;
                saveButton.disabled = true;
                return;
            }

            lockToken = payload.data.token || lockToken;
            lockWarning.hidden = true;
            saveButton.disabled = false;
        } catch {
            lockWarning.textContent = 'The active editor lock could not be verified. Optimistic locking will still prevent silent overwrites.';
            lockWarning.hidden = false;
            saveButton.disabled = false;
        }
    };

    const autosave = async () => {
        if (!isEdit || !dirty || !editorRoot.dataset.autosaveUrl || !baseVersionField) return;

        htmlField.value = visualEditor.innerHTML;
        const body = new URLSearchParams({
            _token: csrfToken,
            base_version: baseVersionField.value,
            content_format: formatInput.value,
            content_html: visualEditor.innerHTML,
            content_markdown: markdownEditor.value
        });

        try {
            const response = await fetch(editorRoot.dataset.autosaveUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body,
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload?.error?.message || 'Autosave failed.');

            if (payload.data.stale) {
                saveState.textContent = 'Autosave kept a recovery copy, but the page has a newer revision. Reload before saving.';
            } else {
                saveState.textContent = 'Autosaved at ' + payload.data.saved_at + '.';
            }
            dirty = false;
        } catch {
            saveState.textContent = 'Server autosave failed; local recovery copy remains available.';
        }
    };

    form.addEventListener('submit', () => {
        htmlField.value = visualEditor.innerHTML;
        try {
            localStorage.removeItem(recoveryKey);
        } catch {
            // No action is required when storage is unavailable.
        }
    });

    if (isEdit) {
        acquireLock();
        window.setInterval(acquireLock, 30000);
        autosaveTimer = window.setInterval(autosave, 10000);

        window.addEventListener('pagehide', () => {
            window.clearInterval(autosaveTimer);
            if (!lockToken || !editorRoot.dataset.unlockUrl) return;
            const body = new URLSearchParams({_token: csrfToken, lock_token: lockToken});
            navigator.sendBeacon(editorRoot.dataset.unlockUrl, body);
        });
    }

    setMode(formatInput.value);
}

document.querySelectorAll('[data-document-content] pre').forEach((pre) => {
    const code = pre.querySelector('code');
    if (!code) {
        return;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'code-copy';
    button.textContent = 'Copy';
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(code.innerText);
            button.textContent = 'Copied';
            window.setTimeout(() => {
                button.textContent = 'Copy';
            }, 1500);
        } catch {
            button.textContent = 'Copy failed';
            window.setTimeout(() => {
                button.textContent = 'Copy';
            }, 1500);
        }
    });

    pre.appendChild(button);
});
