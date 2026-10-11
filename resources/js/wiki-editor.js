const t = (text) => window.wikiTranslate(text);
import {createRichEditor, selectModelImage} from './rich-editor.js';
import {sanitizeHtml, contentSignature} from './html.js';
import {setupEditorTools} from './editor-tools.js';
'use strict';

const editorRoot = document.querySelector('[data-wiki-editor]');
if (editorRoot) {
    const formatInput = editorRoot.querySelector('[data-content-format]');
    let visualEditor = editorRoot.querySelector('[data-rich-host]');
    let richEditor;
    let sourceOverride = null;
    let modelPending = null;
    let refreshTools = () => {};
    const markdownEditor = editorRoot.querySelector('[data-markdown-editor]');
    const form = editorRoot.querySelector('[data-editor-form]');
    const saveButton = editorRoot.querySelector('[data-save-button]');
    const saveState = editorRoot.querySelector('[data-save-state]');
    const lockWarning = editorRoot.querySelector('[data-edit-lock-warning]');
    const recoveryBanner = editorRoot.querySelector('[data-recovery-banner]');
    const recoveryText = editorRoot.querySelector('[data-recovery-text]');
    const recoverySelect = editorRoot.querySelector('[data-recovery-select]');
    const tabs = Array.from(editorRoot.querySelectorAll('[data-editor-mode]'));
    const panes = Array.from(editorRoot.querySelectorAll('[data-pane]'));
    let isEdit = editorRoot.dataset.isEdit === '1';
    const csrfToken = form.elements._token.value;
    const baseVersionField = form.elements.base_version;
    const userId = editorRoot.dataset.userId;
    const uuid = () => typeof crypto.randomUUID === 'function' ? crypto.randomUUID()
        : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (character) =>
            (Number(character) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(character) / 4).toString(16));
    const editorId = uuid();
    const url = new URL(window.location.href);
    let documentId = url.searchParams.get('document');
    if (!isEdit && !/^[a-f0-9-]{36}$/.test(documentId || '')) {
        documentId = uuid();
        url.searchParams.set('document', documentId);
        history.replaceState(null, '', url);
    }
    let documentKey = isEdit ? 'page:' + editorRoot.dataset.pageId : 'new:' + documentId;
    let recoveryPrefix = 'openwiki:draft:v2:' + userId + ':' + editorRoot.dataset.spaceId + ':' + documentKey + ':';
    let recoveryKey = recoveryPrefix + editorId;
    let generation = 1;
    let draftGeneration = 0;
    let dirty = false;
    let saving = false;
    let converting = false;
    let accountChanged = false;
    let conflict = null;
    let conversionBackup = null;
    let originalMarkdown = null;
    let recoveredSource = null;
    let lockToken = '';
    let autosaveTimer = null;
    let localSaveTimer = null;
    let pendingAutosave = null;
    let pendingConversion = null;
    let pendingImages = 0;
    let lastSaveError = '';
    const recoveryCopies = new Map();
    const undoButton = editorRoot.querySelector('[data-undo-conversion]');
    const conversionDialog = editorRoot.querySelector('[data-conversion-dialog]');
    const conflictPanel = editorRoot.querySelector('[data-conflict-panel]');

    const policy = JSON.parse(editorRoot.querySelector('[data-html-policy]').value);
    const safeHtml = (html) => sanitizeHtml(html, policy);
    const getVisualHtml = () => sourceOverride ?? richEditor.getHTML();
    const setVisualHtml = (html) => {
        const safe = safeHtml(html);
        richEditor.commands.setContent(safe, {emitUpdate: false});
        sourceOverride = null;
        editorRoot.querySelector('[data-retained-html]').hidden = true;
        visualEditor.parentElement.hidden = false;
        const after = richEditor.getHTML();
        modelPending = contentSignature(safe) === contentSignature(after) ? null : {before: safe, after};
        if (modelPending) sourceOverride = safe;
        richEditor.setEditable(!modelPending && !accountChanged, false);
        const banner = editorRoot.querySelector('[data-model-review]');
        banner.hidden = !modelPending;
        if (modelPending) {
            editorRoot.querySelector('[data-model-before]').innerHTML = safeHtml(safe);
            editorRoot.querySelector('[data-model-after]').innerHTML = safeHtml(after);
        }
        updateSaveButton();
    };

    const setMode = (mode) => {
        const selected = mode === 'markdown' ? 'markdown' : 'visual';
        formatInput.value = selected;
        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.editorMode === selected);
            tab.setAttribute('aria-selected', tab.dataset.editorMode === selected ? 'true' : 'false');
            tab.tabIndex = tab.dataset.editorMode === selected ? 0 : -1;
        });
        panes.forEach((pane) => { pane.hidden = pane.dataset.pane !== selected; });
        refreshTools();
    };
    const updateSaveButton = () => {
        saveButton.disabled = saving || converting || pendingImages > 0 || accountChanged || Boolean(conflict) || Boolean(modelPending);
        refreshTools();
    };
    const snapshot = () => ({
        userId, documentKey, editorId, sequence: generation, savedAt: new Date().toISOString(),
        baseVersion: Number(baseVersionField.value), title: form.elements.title.value,
        slug: form.elements.slug.value, parentId: form.elements.parent_id.value,
        status: form.elements.status.value, tags: form.elements.tags.value,
        changeSummary: form.elements.change_summary.value, format: formatInput.value,
        html: getVisualHtml(), markdown: markdownEditor.value, conversionBackup,
        originalMarkdown
    });
    const persistLocalRecovery = () => {
        try { localStorage.setItem(recoveryKey, JSON.stringify(snapshot())); return true; }
        catch { return false; }
    };
    const markDirty = () => {
        generation++;
        dirty = true;
        lastSaveError = '';
        saveState.textContent = t('Unsaved changes. Recovery copy pending.');
        window.clearTimeout(localSaveTimer);
        localSaveTimer = window.setTimeout(() => {
            if (!persistLocalRecovery()) saveState.textContent = t('Local storage is unavailable. Keep this editor open until saving succeeds.');
        }, 350);
        window.clearTimeout(autosaveTimer);
        autosaveTimer = window.setTimeout(autosave, 1500);
        if (conflict) editorRoot.querySelector('[data-conflict-local]').textContent = describe(snapshot());
    };
    const initialHtml = visualEditor.innerHTML;
    visualEditor.replaceChildren();
    richEditor = createRichEditor(visualEditor, safeHtml(initialHtml), safeHtml, markDirty);
    visualEditor = richEditor.view.dom;
    setVisualHtml(initialHtml);
    editorRoot.querySelector('[data-model-accept]').addEventListener('click', () => {
        if (!modelPending) return;
        conversionBackup = {format: 'visual', html: modelPending.before, markdown: ''};
        sourceOverride = null;
        modelPending = null;
        richEditor.setEditable(true, false);
        editorRoot.querySelector('[data-model-review]').hidden = true;
        undoButton.hidden = false;
        markDirty();
        updateSaveButton();
    });
    editorRoot.querySelector('[data-model-keep]').addEventListener('click', () => {
        modelPending = null;
        editorRoot.querySelector('[data-model-review]').hidden = true;
        const retained = editorRoot.querySelector('[data-retained-html]');
        retained.querySelector('[data-retained-content]').innerHTML = safeHtml(sourceOverride);
        retained.hidden = false;
        visualEditor.parentElement.hidden = true;
        saveState.textContent = t('The original HTML is retained. Switch to Markdown with a reviewed conversion to edit unsupported structures.');
        updateSaveButton();
    });
    editorRoot.querySelector('[data-reopen-model-review]').addEventListener('click', () => {
        if (sourceOverride !== null) setVisualHtml(sourceOverride);
    });
    const restoreSnapshot = (data) => {
        for (const key of ['title', 'slug', 'status', 'tags']) {
            if (typeof data[key] === 'string') form.elements[key].value = data[key];
        }
        if (typeof data.parentId === 'string') form.elements.parent_id.value = data.parentId;
        if (typeof data.changeSummary === 'string') form.elements.change_summary.value = data.changeSummary;
        if (Number.isInteger(Number(data.baseVersion)) && Number(data.baseVersion) >= (isEdit ? 1 : 0)) baseVersionField.value = String(data.baseVersion);
        setVisualHtml(data.html || '');
        markdownEditor.value = typeof data.markdown === 'string' ? data.markdown : '';
        conversionBackup = data.conversionBackup || null;
        originalMarkdown = data.originalMarkdown || null;
        undoButton.hidden = !conversionBackup;
        setMode(data.format);
        conflict = null;
        conflictPanel.hidden = true;
        updateSaveButton();
        markDirty();
    };
    const showRecovery = () => {
        const selected = recoverySelect.value;
        recoverySelect.replaceChildren();
        const time = (data) => Date.parse(/T/.test(data.savedAt) ? data.savedAt : data.savedAt?.replace(' ', 'T') + 'Z') || 0;
        for (const [key, data] of [...recoveryCopies].sort((left, right) => time(right[1]) - time(left[1]))) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = (data.title || t('Untitled page')) + ' — ' + data.savedAt + ' — base ' + data.baseVersion;
            recoverySelect.appendChild(option);
        }
        if (recoveryCopies.has(selected)) recoverySelect.value = selected;
        recoveryText.textContent = t('Choose a recovery copy. Restoring retains its original base version.');
        recoveryBanner.hidden = recoveryCopies.size === 0;
    };
    try {
        for (let index = 0; index < localStorage.length; index++) {
            const key = localStorage.key(index);
            if (!key?.startsWith(recoveryPrefix)) continue;
            try {
                const data = JSON.parse(localStorage.getItem(key));
                if (String(data.userId) === userId && data.documentKey === documentKey
                    && typeof data.editorId === 'string' && /^(?:legacy|[a-f0-9-]{36})$/.test(data.editorId)
                    && Number.isInteger(data.sequence) && data.sequence >= 1) recoveryCopies.set(data.editorId, {...data, localKey: key});
            } catch { /* A corrupt copy must not prevent opening the editor. */ }
        }
    } catch { saveState.textContent = t('Local recovery storage is unavailable.'); }
    showRecovery();
    editorRoot.querySelector('[data-restore-recovery]').addEventListener('click', () => {
        const data = recoveryCopies.get(recoverySelect.value);
        if (!data) return;
        recoveredSource = data;
        restoreSnapshot(data);
        recoveryBanner.hidden = true;
        saveState.textContent = t('Recovery restored with base version ') + data.baseVersion + t('. Saving still checks for concurrent changes.');
    });
    editorRoot.querySelector('[data-hide-recovery]').addEventListener('click', () => { recoveryBanner.hidden = true; });

    const fields = (data) => ({
        _token: csrfToken, editor_user_id: userId, document_key: documentKey, editor_id: editorId,
        draft_sequence: data.sequence, base_version: data.baseVersion,
        title: data.title, slug: data.slug, parent_id: data.parentId, status: data.status,
        tags: data.tags, change_summary: data.changeSummary, content_format: data.format,
        content_html: data.html, content_markdown: data.markdown, conversion_backup: data.conversionBackup,
        ...(recoveredSource ? {recovery_editor_id: recoveredSource.editorId, recovery_sequence: recoveredSource.sequence} : {})
    });
    const requestJson = async (target, body = null) => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(target, {method: body === null ? 'GET' : 'POST',
                headers: {'Accept': 'application/json', ...(body === null ? {} : {'Content-Type': 'application/json'})},
                body: body === null ? null : JSON.stringify(body), credentials: 'same-origin', signal: controller.signal});
            if (response.redirected) throw new Error(t('The server redirected this request. Sign in again if your session expired; your document is retained.'));
            let payload;
            try { payload = await response.json(); } catch { throw new Error(t('Unexpected server response (') + response.status + t('). Your document remains in the editor.')); }
            if (!response.ok) {
                const error = new Error(t(payload?.error?.message) || t('Request failed (') + response.status + ').');
                error.conflict = payload?.error?.conflict;
                throw error;
            }
            if (payload.data?.user_id && String(payload.data.user_id) !== userId) throw new Error(t('The signed-in account changed. Reopen the editor.'));
            return payload.data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error(t('The request timed out before confirmation.'));
            if (error instanceof TypeError) throw new Error(t('A network error prevented confirmation.'));
            throw error;
        } finally { window.clearTimeout(timer); }
    };
    const describe = (data) => {
        if (!data) return 'This base revision is unavailable.';
        const metadata = {
            title: data.title, slug: data.slug, parent: data.parentId ?? data.parent_id,
            status: data.status, tags: data.tags ?? data.tags_json,
            changeSummary: data.changeSummary ?? data.change_summary,
            version: data.baseVersion ?? data.version ?? data.revision_number
        };
        return JSON.stringify(metadata, null, 2) + '\n\n' + ((data.format ?? data.content_format) === 'markdown'
            ? (data.markdown ?? data.content_markdown ?? '') : (data.html ?? data.content_html ?? ''));
    };
    const showConflict = (data) => {
        conflict = data;
        editorRoot.querySelector('[data-conflict-base]').textContent = describe(data.base);
        editorRoot.querySelector('[data-conflict-local]').textContent = describe(snapshot());
        editorRoot.querySelector('[data-conflict-server]').textContent = describe(data.server);
        conflictPanel.hidden = false;
        updateSaveButton();
    };
    editorRoot.querySelector('[data-resolve-conflict]').addEventListener('click', () => {
        if (!conflict?.server) return;
        baseVersionField.value = String(conflict.server.version);
        conflict = null;
        conflictPanel.hidden = true;
        markDirty();
        updateSaveButton();
        saveState.textContent = t('Resolved document prepared for saving. Any further server change will cause a new conflict.');
    });
    const initialConflict = editorRoot.querySelector('[data-initial-conflict]');
    if (initialConflict) showConflict(JSON.parse(initialConflict.value));

    const applyConversion = (data, before) => {
        conversionBackup = {format: before.format, html: before.html, markdown: before.markdown};
        setVisualHtml(data.html || '');
        markdownEditor.value = data.markdown || '';
        originalMarkdown = data.format === 'visual' && before.format === 'markdown'
            ? {html: getVisualHtml(), markdown: before.markdown} : null;
        setMode(data.format);
        selectImage(null);
        undoButton.hidden = false;
        markDirty();
    };
    tabs.forEach((tab) => tab.addEventListener('click', async () => {
        const target = tab.dataset.editorMode;
        if (target === formatInput.value || converting || saving || pendingImages > 0 || accountChanged) return;
        const before = snapshot();
        persistLocalRecovery();
        converting = true;
        updateSaveButton();
        saveState.textContent = t('Converting current document…');
        try {
            let data;
            if (target === 'markdown' && originalMarkdown?.html === getVisualHtml()) {
                data = {format: 'markdown', html: getVisualHtml(), markdown: originalMarkdown.markdown, lossy: false};
            } else data = await requestJson(editorRoot.dataset.convertUrl, {...fields(before), target_format: target});
            if (before.sequence !== generation) { saveState.textContent = t('The document changed during conversion. Select the mode again to convert the latest content.'); return; }
            if (data.lossy) {
                pendingConversion = {data, before};
                editorRoot.querySelector('[data-conversion-before]').innerHTML = safeHtml(data.before_html);
                editorRoot.querySelector('[data-conversion-after]').innerHTML = safeHtml(data.html);
                editorRoot.querySelector('[data-conversion-diff]').textContent = '- ' + data.before_html + '\n+ ' + data.html;
                conversionDialog.showModal();
                saveState.textContent = t('Review the conversion before applying it.');
            } else applyConversion(data, before);
        } catch (error) { saveState.textContent = error.message + t(' Current content is retained.'); }
        finally { converting = false; updateSaveButton(); }
    }));
    editorRoot.querySelector('[data-conversion-cancel]').addEventListener('click', () => { pendingConversion = null; conversionDialog.close(); });
    editorRoot.querySelector('[data-conversion-accept]').addEventListener('click', () => {
        if (pendingConversion?.before.sequence === generation) applyConversion(pendingConversion.data, pendingConversion.before);
        else saveState.textContent = t('The document changed. Preview the latest conversion again.');
        pendingConversion = null;
        conversionDialog.close();
    });
    undoButton.addEventListener('click', () => {
        if (!conversionBackup) return;
        const before = {format: formatInput.value, html: getVisualHtml(), markdown: markdownEditor.value};
        setVisualHtml(conversionBackup.html);
        markdownEditor.value = conversionBackup.markdown || '';
        setMode(conversionBackup.format);
        conversionBackup = before;
        originalMarkdown = null;
        markDirty();
    });
    const imagePicker = editorRoot.querySelector('[data-image-picker]');
    const imageFileInput = editorRoot.querySelector('[data-image-file]');
    const imageInspector = editorRoot.querySelector('[data-image-inspector]');
    const imageAltInput = editorRoot.querySelector('[data-image-alt]');
    const imageCaptionInput = editorRoot.querySelector('[data-image-caption]');
    const imageWidthInput = editorRoot.querySelector('[data-image-width]');
    const imageApplyButton = editorRoot.querySelector('[data-image-apply]');
    const imageRemoveButton = editorRoot.querySelector('[data-image-remove]');
    const canUploadImage = editorRoot.dataset.canUploadImage === '1';
    const imageUploadUrl = editorRoot.dataset.imageUploadUrl || '';
    let selectedImage = null;

    const clampImageWidth = (value, fallback = 640) => {
        const parsed = Number.parseInt(String(value || ''), 10);
        if (!Number.isFinite(parsed)) return fallback;
        return Math.max(64, Math.min(1600, parsed));
    };

    const currentFigure = (image) => {
        if (!image) return null;
        const figure = image.closest('figure');
        return figure && visualEditor.contains(figure) ? figure : null;
    };

    const selectImage = (image) => {
        if (!imageInspector || !imageAltInput || !imageCaptionInput || !imageWidthInput) return;
        selectedImage = image;
        if (!image) {
            imageInspector.hidden = true;
            return;
        }

        const figure = currentFigure(image);
        const caption = figure ? figure.querySelector('figcaption') : null;
        imageAltInput.value = image.getAttribute('alt') || '';
        imageCaptionInput.value = caption ? caption.textContent || '' : '';
        imageWidthInput.value = image.getAttribute('width')
            || image.dataset.originalWidth
            || image.naturalWidth
            || 640;
        imageInspector.hidden = false;
    };

    const createImageFigure = (data) => {
        richEditor.view.focus();
        richEditor.chain().insertContent({type: 'imageFigure', attrs: {src: data.src,
            'data-attachment-id': data.id ? String(data.id) : null, 'data-original-width': String(data.width),
            'data-original-height': String(data.height), width: String(clampImageWidth(data.width)), alt: '', loading: 'lazy', figureClass: 'wiki-image'}}).run();
    };

    const readImageFile = (file) => new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onerror = () => reject(new Error(t('Unable to read image.')));
        reader.onload = () => {
            const src = typeof reader.result === 'string' ? reader.result : '';
            if (!src) {
                reject(new Error(t('Unable to read image.')));
                return;
            }

            const probe = new Image();
            probe.onload = () => resolve({
                src,
                width: probe.naturalWidth,
                height: probe.naturalHeight
            });
            probe.onerror = () => reject(new Error(t('Unable to decode image.')));
            probe.src = src;
        };
        reader.readAsDataURL(file);
    });

    const uploadImage = async (file) => {
        if (!imageUploadUrl) {
            return readImageFile(file);
        }

        const body = new FormData();
        body.append('_token', csrfToken);
        body.append('image', file, file.name || 'clipboard-image.png');

        const response = await fetch(imageUploadUrl, {
            method: 'POST',
            headers: {'Accept': 'application/json'},
            body,
            credentials: 'same-origin'
        });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(t(payload?.error?.message) || t('Image upload failed.'));
        }

        return {
            id: payload.data.id,
            src: payload.data.thumbnail_url,
            width: payload.data.width,
            height: payload.data.height
        };
    };

    const handleImageFile = async (file) => {
        if (!canUploadImage || !file) return;

        const allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!allowed.includes(file.type)) {
            saveState.textContent = t('Only PNG, JPEG, GIF and WebP images are supported.');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            saveState.textContent = t('Image exceeds the 10 MiB editor upload limit.');
            return;
        }

        saveState.textContent = imageUploadUrl
            ? t('Uploading image…')
            : t('Preparing pasted image for first save…');

        pendingImages++;
        updateSaveButton();
        try {
            const data = await uploadImage(file);
            createImageFigure(data);
            saveState.textContent = imageUploadUrl
                ? t('Image uploaded. Save the page to reference it in this revision.')
                : t('Image prepared. It will be stored as an attachment when the page is created.');
        } catch (error) {
            saveState.textContent = error instanceof Error
                ? error.message
                : 'Unable to add image.';
        } finally { pendingImages--; updateSaveButton(); }
    };

    if (imagePicker && imageFileInput && canUploadImage) {
        imagePicker.addEventListener('click', () => imageFileInput.click());
        imageFileInput.addEventListener('change', async () => {
            const file = imageFileInput.files && imageFileInput.files[0]
                ? imageFileInput.files[0]
                : null;
            imageFileInput.value = '';
            await handleImageFile(file);
        });

        visualEditor.addEventListener('paste', async (event) => {
            const items = Array.from(event.clipboardData?.items || []);
            const imageItem = items.find((item) => item.kind === 'file' && item.type.startsWith('image/'));
            if (!imageItem) return;

            const file = imageItem.getAsFile();
            if (!file) return;

            event.preventDefault();
            await handleImageFile(file);
        });
    }

    visualEditor.addEventListener('click', (event) => {
        const target = event.target;
        if (target instanceof HTMLImageElement && visualEditor.contains(target)) {
            selectImage(target);
            return;
        }

        if (imageInspector && !imageInspector.contains(target)) {
            selectImage(null);
        }
    });

    if (imageApplyButton) {
        imageApplyButton.addEventListener('click', () => {
            if (!selectedImage) return;
            const values = {width: String(clampImageWidth(imageWidthInput?.value, 640)),
                alt: imageAltInput.value.trim(), caption: imageCaptionInput.value.trim() || null};
            const pos = selectModelImage(richEditor, selectedImage);
            if (pos === null) return;
            const attrs = richEditor.state.doc.nodeAt(pos).attrs;
            const width = Math.min(Number(values.width), Number(attrs['data-original-width']) || 1600);
            richEditor.view.dispatch(richEditor.state.tr.setNodeMarkup(pos, undefined, {...attrs, ...values, width: String(width), height: null,
                src: attrs['data-attachment-id'] ? '/attachments/' + attrs['data-attachment-id'] + '/thumbnail?width=' + width : attrs.src}));
            selectImage(richEditor.view.nodeDOM(pos)?.querySelector('img') || null);
        });
    }

    if (imageRemoveButton) {
        imageRemoveButton.addEventListener('click', () => {
            if (!selectedImage) return;
            const pos = selectModelImage(richEditor, selectedImage);
            if (pos !== null) richEditor.commands.deleteSelection();
            selectedImage = null;
            if (imageInspector) imageInspector.hidden = true;
            markDirty();
        });
    }


    const templateSelector = editorRoot.querySelector('[data-template-selector]');
    if (templateSelector) templateSelector.addEventListener('change', () => {
        const option = templateSelector.options[templateSelector.selectedIndex];
        if (!option?.value) return;
        conversionBackup = {format: formatInput.value, html: getVisualHtml(), markdown: markdownEditor.value};
        setVisualHtml(option.dataset.html || '');
        markdownEditor.value = option.dataset.markdown || '';
        originalMarkdown = null;
        undoButton.hidden = false;
        setMode(markdownEditor.value ? 'markdown' : 'visual');
        markDirty();
    });
    form.addEventListener('input', (event) => { if (!visualEditor.contains(event.target)) markDirty(); });
    form.addEventListener('change', (event) => { if (!visualEditor.contains(event.target)) markDirty(); });

    const acquireLock = async () => {
        if (!isEdit || accountChanged || saving) return;
        try {
            const data = await requestJson(editorRoot.dataset.lockUrl, {_token: csrfToken});
            lockToken = data.token || lockToken;
            lockWarning.hidden = data.acquired;
            lockWarning.textContent = (data.holder || t('Another editor')) + ' is editing this page. Every save still checks your original base version.';
        } catch {
            lockWarning.hidden = false;
            lockWarning.textContent = t('Editor presence could not be verified. Version checks still protect every save.');
        }
    };
    const autosave = async () => {
        if (!dirty || generation === draftGeneration || pendingAutosave || saving || converting || accountChanged) return;
        const captured = snapshot();
        persistLocalRecovery();
        pendingAutosave = requestJson(editorRoot.dataset.draftsUrl, fields(captured));
        try {
            const data = await pendingAutosave;
            if (data.sequence !== captured.sequence) throw new Error(t('The server did not acknowledge this draft snapshot.'));
            draftGeneration = captured.sequence;
            if (generation === captured.sequence) saveState.textContent = data.stale
                ? t('Recovery copy saved with its original base version. The server page has changed; saving will require conflict resolution.')
                : t('Draft saved at ') + data.saved_at + '. Save the page to create a revision.';
            else saveState.textContent = t('An earlier draft was saved. Newer changes are still pending.');
            if (lastSaveError) saveState.textContent += t(' The page save failed: ') + lastSaveError;
        } catch (error) { saveState.textContent = error.message + t(' Server autosave failed; your document remains recoverable locally.'); }
        finally {
            pendingAutosave = null;
            if (generation !== captured.sequence && !saving) autosaveTimer = window.setTimeout(autosave, 250);
        }
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving || converting || pendingImages > 0 || accountChanged || conflict || modelPending) return;
        persistLocalRecovery();
        saving = true;
        updateSaveButton();
        window.clearTimeout(autosaveTimer);
        try {
            if (pendingAutosave) await pendingAutosave.catch(() => {});
            const captured = snapshot();
            const data = await requestJson(form.action, fields(captured));
            baseVersionField.value = String(data.page.version);
            lastSaveError = '';
            if (accountChanged) {
                persistLocalRecovery();
                saveState.textContent = t('The submitted version was saved for the original account. Reopen the editor after signing in to continue.');
                return;
            }
            if (captured.sequence === generation) {
                dirty = false;
                window.clearTimeout(localSaveTimer);
                try {
                    localStorage.removeItem(recoveryKey);
                    if (recoveredSource?.localKey) {
                        const latest = JSON.parse(localStorage.getItem(recoveredSource.localKey) || 'null');
                        if (latest && Number(latest.sequence) <= Number(recoveredSource.sequence)) localStorage.removeItem(recoveredSource.localKey);
                    }
                } catch { /* A retained copy is harmless when deletion is unavailable. */ }
                window.location.assign(data.url);
            } else {
                // Later edits remain based on our now-confirmed revision and are saved separately.
                form.action = data.url;
                const oldKey = recoveryKey;
                isEdit = true;
                editorRoot.dataset.isEdit = '1';
                editorRoot.dataset.pageId = String(data.page.id);
                editorRoot.querySelectorAll('[data-editor-heading]').forEach((element) => { element.textContent = t('Edit page'); });
                editorRoot.querySelector('[data-version-hint]').textContent = t('Based on version ') + data.page.version + '.';
                editorRoot.querySelector('[data-editor-cancel]').href = data.url;
                saveButton.textContent = t('Save page');
                documentKey = 'page:' + data.page.id;
                recoveryPrefix = 'openwiki:draft:v2:' + userId + ':' + editorRoot.dataset.spaceId + ':' + documentKey + ':';
                recoveryKey = recoveryPrefix + editorId;
                editorRoot.dataset.lockUrl = data.url + '/edit-lock';
                editorRoot.dataset.unlockUrl = data.url + '/edit-unlock';
                history.replaceState(null, '', data.url + '/edit');
                const sentHtml = new DOMParser().parseFromString(captured.html, 'text/html');
                const storedHtml = new DOMParser().parseFromString(data.page.content_html, 'text/html');
                const sentImages = Array.from(sentHtml.querySelectorAll('img'));
                const storedImages = Array.from(storedHtml.querySelectorAll('img'));
                const replacements = new Map(sentImages.map((image, index) => [image.getAttribute('src'), storedImages[index]]));
                let tr = richEditor.state.tr;
                richEditor.state.doc.descendants((node, pos) => {
                    const stored = node.type.name === 'imageFigure' && replacements.get(node.attrs.src);
                    if (stored && /^data:image\//i.test(node.attrs.src)) tr = tr.setNodeMarkup(pos, undefined, {...node.attrs, src: stored.getAttribute('src'), 'data-attachment-id': stored.dataset.attachmentId});
                });
                if (tr.docChanged) richEditor.view.dispatch(tr);
                markDirty();
                if (persistLocalRecovery() && oldKey !== recoveryKey) {
                    try { localStorage.removeItem(oldKey); } catch {}
                }
                saveState.textContent = t('The submitted version was saved. Your later changes are still in this editor and need another save.');
            }
        } catch (error) {
            dirty = true;
            lastSaveError = error.message;
            persistLocalRecovery();
            if (error.conflict) showConflict(error.conflict);
            saveState.textContent = error.message + t(' Your document and its original base version are retained.');
        } finally { saving = false; updateSaveButton(); }
    });
    requestJson(editorRoot.dataset.draftsUrl + '?document_key=' + encodeURIComponent(documentKey)).then((data) => {
        for (const draft of data.drafts) {
            const local = recoveryCopies.get(draft.editorId);
            if (!local || Number(local.sequence) < draft.sequence) recoveryCopies.set(draft.editorId, {...draft, localKey: local?.localKey});
        }
        showRecovery();
    }).catch(() => { /* Local recovery remains usable without the server. */ });
    window.addEventListener('storage', (event) => {
        if (event.key === 'openwiki:active-user' && event.newValue !== userId) {
            accountChanged = true;
            richEditor.setEditable(false, false);
            richEditor.view.dispatch(richEditor.state.tr);
            persistLocalRecovery();
            updateSaveButton();
            saveState.textContent = t('The signed-in account changed in another tab. Your document is retained for the original account; reopen the editor after signing in.');
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        persistLocalRecovery();
        event.preventDefault();
        event.returnValue = '';
    });
    window.addEventListener('pagehide', () => {
        window.clearTimeout(autosaveTimer);
        window.clearTimeout(localSaveTimer);
        if (dirty) persistLocalRecovery();
        if (lockToken && editorRoot.dataset.unlockUrl) navigator.sendBeacon(editorRoot.dataset.unlockUrl,
            new URLSearchParams({_token: csrfToken, lock_token: lockToken}));
    });
    if (isEdit) { acquireLock(); window.setInterval(acquireLock, 30000); }
    setMode(formatInput.value);
    refreshTools = setupEditorTools(editorRoot, richEditor, requestJson, snapshot, fields,
        () => formatInput.value === 'visual' && !accountChanged && !modelPending && sourceOverride === null);
}
