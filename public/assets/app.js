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
    const tabs = Array.from(editorRoot.querySelectorAll('[data-editor-mode]'));
    const panes = Array.from(editorRoot.querySelectorAll('[data-pane]'));

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

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => setMode(tab.dataset.editorMode));
    });

    editorRoot.querySelectorAll('[data-command]').forEach((button) => {
        button.addEventListener('click', () => {
            visualEditor.focus();
            const command = button.dataset.command;
            const value = button.dataset.commandValue || null;
            document.execCommand(command, false, value);
        });
    });

    const templateSelector = editorRoot.querySelector('[data-template-selector]');
    if (templateSelector) {
        templateSelector.addEventListener('change', () => {
            const option = templateSelector.options[templateSelector.selectedIndex];
            if (!option || !option.value) {
                return;
            }

            visualEditor.innerHTML = option.dataset.html || '<p></p>';
            markdownEditor.value = option.dataset.markdown || '';
            setMode(markdownEditor.value ? 'markdown' : 'visual');
        });
    }

    form.addEventListener('submit', () => {
        htmlField.value = visualEditor.innerHTML;
    });

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
