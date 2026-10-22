'use strict';

const uiCatalog = JSON.parse(document.querySelector('[data-ui-translations]')?.value || '{}');
window.wikiTranslate = (text) => uiCatalog[text] || text;
const t = window.wikiTranslate;

const pagePermissions = document.querySelector('[data-page-permissions]');
if (pagePermissions) {
    const form = pagePermissions.querySelector('[data-page-rule-form]');
    const heading = form.querySelector('[data-rule-form-heading]');
    const status = form.querySelector('[data-rule-editing]');
    const cancel = form.querySelector('[data-cancel-page-rule]');
    const identitySelects = ['principal', 'permission'].map(name => form.querySelector('select[name="' + name + '"]'));
    pagePermissions.querySelectorAll('a[href="#page-rule-form"]').forEach(link => link.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const recipient = form.querySelector('[name="principal"]');
        if (!recipient) return;
        event.preventDefault();
        if (!status.hidden) resetEditing();
        if (location.hash !== '#page-rule-form') history.pushState(null, '', '#page-rule-form');
        form.scrollIntoView({behavior: 'auto', block: 'start'});
        recipient.focus({preventScroll: true});
    }));
    const resetEditing = () => {
        form.querySelectorAll('[data-rule-identity]').forEach(input => input.remove());
        identitySelects.forEach(select => { select.disabled = false; });
        form.reset(); heading.textContent = t('Add or update rule'); status.hidden = true; cancel.hidden = true;
        pagePermissions.querySelectorAll('.is-editing').forEach(row => row.classList.remove('is-editing'));
    };
    pagePermissions.querySelectorAll('[data-edit-page-rule]').forEach(button => {
        button.hidden = false;
        button.addEventListener('click', () => {
            resetEditing();
            for (const name of ['principal', 'permission']) {
                const select = identitySelects.find(select => select.name === name), value = button.dataset[name];
                if (!Array.from(select.options).some(option => option.value === value)) {
                    select.add(new Option(name === 'principal' ? button.dataset.principalName : value, value));
                }
                select.value = value; select.disabled = true;
                const identity = document.createElement('input');
                identity.type = 'hidden'; identity.name = name; identity.value = value; identity.dataset.ruleIdentity = '';
                form.append(identity);
            }
            form.elements.effect.value = button.dataset.effect;
            form.elements.inherit_to_children.checked = button.dataset.children === '1';
            heading.textContent = t('Edit rule'); status.textContent = t('Recipient') + ': ' + button.dataset.principalName;
            status.hidden = false; cancel.hidden = false; button.closest('[data-permission-rule]').classList.add('is-editing');
            form.scrollIntoView({block: 'start'}); form.elements.effect.focus({preventScroll: true});
        });
    });
    cancel.addEventListener('click', () => { resetEditing(); identitySelects[0].focus(); });
}

document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(t(form.dataset.confirm))) event.preventDefault();
}));
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const open = event.target.closest('[data-close-on-escape][open]');
    if (open) {
        open.open = false;
        open.querySelector('summary').focus();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented) return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        const search = document.querySelector('#global-search');
        if (search) {
            event.preventDefault();
            search.focus();
            search.select();
        }
    }
});

try { localStorage.setItem('openwiki:active-user', document.body.dataset.userId || ''); } catch {}

document.querySelectorAll('[data-document-content] img[data-attachment-id]').forEach((image) => {
    image.classList.add('document-image--attachment');
    image.setAttribute('tabindex', '0');
    image.setAttribute('role', 'link');

    const openPreview = () => {
        const id = image.dataset.attachmentId;
        if (!id) return;
        window.open('/attachments/' + encodeURIComponent(id) + '/preview', '_blank', 'noopener');
    };

    image.addEventListener('click', openPreview);
    image.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openPreview();
        }
    });
});

document.querySelectorAll('[data-document-content] pre').forEach((pre) => {
    const code = pre.querySelector('code');
    if (!code) {
        return;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'code-copy';
    button.textContent = t('Copy');
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(code.innerText);
            button.textContent = t('Copied');
            window.setTimeout(() => {
                button.textContent = t('Copy');
            }, 1500);
        } catch {
            button.textContent = t('Copy failed');
            window.setTimeout(() => {
                button.textContent = t('Copy');
            }, 1500);
        }
    });

    pre.appendChild(button);
});

const copyToClipboard = async (value) => {
    try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard API unavailable');
        await navigator.clipboard.writeText(value);
        return;
    } catch {
        const helper = document.createElement('textarea');
        helper.value = value;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.left = '-9999px';
        document.body.appendChild(helper);
        let copied = false;
        try {
            helper.select();
            copied = document.execCommand('copy');
        } finally {
            helper.remove();
        }
        if (!copied) throw new Error('Clipboard copy failed');
    }
};

document.querySelectorAll('[data-copy-target]').forEach((button) => {
    const source = document.getElementById(button.dataset.copyTarget);
    const status = button.closest('[data-copy-field]')?.querySelector('[data-copy-status]');
    const label = button.textContent;
    let resetTimer;
    if (!source) return;

    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            await copyToClipboard('value' in source ? source.value : source.textContent);
            button.textContent = t('Copied');
            if (status) status.textContent = t('Copied');
        } catch {
            button.textContent = t('Copy failed');
            if (status) status.textContent = t('Copy failed');
        }
        window.clearTimeout(resetTimer);
        resetTimer = window.setTimeout(() => {
            button.textContent = label;
            button.disabled = false;
            if (status) status.textContent = '';
        }, 1800);
    });
});


document.querySelectorAll('[data-install-wizard]').forEach((wizard) => {
    const panels = Array.from(wizard.querySelectorAll('[data-wizard-panel]'));
    const indicators = Array.from(wizard.querySelectorAll('[data-wizard-indicator]'));
    const form = wizard.querySelector('[data-wizard-form]');
    const requirementsOk = wizard.dataset.requirementsOk === '1';
    const databaseTest = wizard.querySelector('[data-database-test]');
    const databaseTestButton = databaseTest?.querySelector('[data-database-test-button]') || null;
    const databaseTestStatus = databaseTest?.querySelector('[data-database-test-status]') || null;
    const databaseNext = wizard.querySelector('[data-database-next]');
    const createDatabase = form?.elements.namedItem('create_database') || null;
    const fullDatabaseReinstall = form?.elements.namedItem('full_database_reinstall') || null;
    const reinstallWarning = wizard.querySelector('[data-reinstall-warning]');
    const reviewReinstallWarning = wizard.querySelector('[data-review-reinstall-warning]');
    const databaseModeSummary = wizard.querySelector('[data-database-mode-summary]');
    const currentStepLabel = wizard.querySelector('[data-current-step]');
    const sidebarProgress = wizard.querySelector('[data-sidebar-progress]');
    let currentStep = 1;
    let databaseTestPassed = databaseNext === null;
    const connectionSnapshot = () => JSON.stringify(['db_host', 'db_port', 'db_database', 'db_username', 'db_password', 'create_database', 'full_database_reinstall'].map(name => {
        const field = form?.elements.namedItem(name);
        return field?.type === 'checkbox' ? field.checked : field?.value;
    }));
    const password = form?.elements.namedItem('admin_password');
    const confirmation = form?.elements.namedItem('admin_password_confirmation');
    const validatePassword = () => {
        confirmation?.setCustomValidity(confirmation.value !== password?.value ? t('Administrator passwords do not match.') : '');
        password?.setCustomValidity(Array.from(password.value).length < 12 || new TextEncoder().encode(password.value).length > 72
            ? t('Administrator password must contain at least 12 characters, at most 72 bytes and no null characters.') : '');
    };
    password?.addEventListener('input', validatePassword);
    confirmation?.addEventListener('input', validatePassword);

    const updateDatabaseMode = () => {
        const fullReinstallSelected = fullDatabaseReinstall?.checked === true;
        if (reinstallWarning) reinstallWarning.hidden = !fullReinstallSelected;
        if (reviewReinstallWarning) reviewReinstallWarning.hidden = !fullReinstallSelected;
        if (databaseModeSummary) {
            databaseModeSummary.textContent = t(fullReinstallSelected
                ? 'Full reinstall (drop and recreate)'
                : (createDatabase?.checked ? 'Create if missing' : 'Use existing empty database'));
        }
    };

    const updateSummary = () => {
        if (!form) return;
        wizard.querySelectorAll('[data-wizard-summary]').forEach((target) => {
            const name = target.dataset.wizardSummary || '';
            const field = form.elements.namedItem(name);
            if (!field || !('value' in field)) return;
            target.textContent = String(field.value || '—');
        });
        updateDatabaseMode();
    };

    const showStep = (step, shouldScroll = true) => {
        currentStep = Math.max(1, Math.min(panels.length, step));

        panels.forEach((panel) => {
            panel.classList.toggle('is-active', Number(panel.dataset.wizardPanel) === currentStep);
        });

        indicators.forEach((indicator) => {
            const indicatorStep = Number(indicator.dataset.wizardIndicator);
            indicator.classList.toggle('is-active', indicatorStep === currentStep);
            indicator.classList.toggle('is-complete', indicatorStep < currentStep);
            if (indicatorStep === currentStep) indicator.setAttribute('aria-current', 'step');
            else indicator.removeAttribute('aria-current');
        });

        if (currentStepLabel) currentStepLabel.textContent = String(currentStep);
        if (sidebarProgress) sidebarProgress.textContent = `${currentStep} / ${panels.length}`;

        if (currentStep === panels.length) {
            updateSummary();
        }

        if (shouldScroll) wizard.scrollIntoView({behavior: 'smooth', block: 'start'});
    };

    const validateCurrentStep = () => {
        const panel = panels.find((item) => Number(item.dataset.wizardPanel) === currentStep);
        if (!panel) return true;

        const fields = Array.from(panel.querySelectorAll('input, select, textarea'));
        for (const field of fields) {
            if (field.disabled) continue;
            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();
                return false;
            }
        }

        return true;
    };

    const setDatabaseTestStatus = (state, message) => {
        if (!databaseTestStatus) return;
        databaseTestStatus.hidden = false;
        databaseTestStatus.classList.toggle('is-success', state === 'success');
        databaseTestStatus.classList.toggle('is-error', state === 'error');
        databaseTestStatus.classList.toggle('is-pending', state === 'pending');
        databaseTestStatus.textContent = t(message);
    };

    const resetDatabaseTest = () => {
        if (!databaseNext) return;
        const hadStatus = databaseTestStatus !== null && !databaseTestStatus.hidden;
        databaseTestPassed = false;
        databaseNext.disabled = true;
        if (hadStatus) {
            setDatabaseTestStatus('pending', 'Connection settings changed. Test the connection again.');
        }
    };

    wizard.classList.add('is-enhanced');

    wizard.querySelectorAll('[data-wizard-next]').forEach((button) => {
        button.addEventListener('click', () => {
            if (currentStep === 1 && !requirementsOk) return;
            if (button.matches('[data-database-next]') && !databaseTestPassed) {
                setDatabaseTestStatus('error', 'Test the database connection successfully before continuing.');
                return;
            }
            if (!validateCurrentStep()) return;
            showStep(currentStep + 1);
        });
    });

    wizard.querySelectorAll('[data-wizard-prev]').forEach((button) => {
        button.addEventListener('click', () => showStep(currentStep - 1));
    });

    if (form) {
        form.noValidate = true;
        form.addEventListener('submit', event => {
            validatePassword();
            for (const panel of panels) {
                const invalid = Array.from(panel.querySelectorAll('input, select, textarea')).find(field => !field.disabled && !field.checkValidity());
                if (invalid) {
                    event.preventDefault();
                    showStep(Number(panel.dataset.wizardPanel));
                    invalid.reportValidity();
                    return;
                }
            }
            if (!requirementsOk || !databaseTestPassed) {
                event.preventDefault();
                showStep(requirementsOk ? 3 : 1);
                setDatabaseTestStatus('error', t('Test the database connection successfully before continuing.'));
                return;
            }
            form.querySelector('[type="submit"]').disabled = true;
            wizard.querySelector('[data-install-status]').textContent = t('Installing OpenWiki. Keep this page open until setup finishes.');
        });
        form.addEventListener('input', updateSummary);
        form.addEventListener('change', updateSummary);

        ['db_host', 'db_port', 'db_database', 'db_username', 'db_password', 'create_database', 'full_database_reinstall'].forEach((name) => {
            const field = form.elements.namedItem(name);
            if (!field || !('addEventListener' in field)) return;
            field.addEventListener('input', resetDatabaseTest);
            field.addEventListener('change', resetDatabaseTest);
        });
    }

    databaseTestButton?.addEventListener('click', async () => {
        if (!form || !databaseTest) return;
        if (!validateCurrentStep()) return;

        databaseTestPassed = false;
        if (databaseNext) databaseNext.disabled = true;
        databaseTestButton.disabled = true;
        const originalLabel = databaseTestButton.textContent;
        databaseTestButton.textContent = 'Testing...';
        setDatabaseTestStatus('pending', 'Checking database connection...');
        const snapshot = connectionSnapshot();
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 15000);

        try {
            const response = await fetch(databaseTest.dataset.testUrl || '/install/test-database', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
                credentials: 'same-origin',
                signal: abort.signal,
            });

            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload?.data?.ok !== true) {
                throw new Error(t(payload?.error?.message) || 'Database connection test failed.');
            }

            if (snapshot !== connectionSnapshot()) {
                resetDatabaseTest();
                return;
            }

            databaseTestPassed = true;
            if (databaseNext) databaseNext.disabled = false;
            setDatabaseTestStatus('success', payload.data.message || 'Database connection successful.');
        } catch (error) {
            databaseTestPassed = false;
            if (databaseNext) databaseNext.disabled = true;
            setDatabaseTestStatus(
                'error',
                error instanceof Error ? error.message : 'Database connection test failed.'
            );
        } finally {
            clearTimeout(timeout);
            databaseTestButton.disabled = false;
            databaseTestButton.textContent = originalLabel;
        }
    });

    updateSummary();
    showStep(1, false);
});

document.querySelectorAll('[data-timezone-picker]').forEach((picker) => {
    const input = picker.querySelector('[data-timezone-input]');
    const detectButton = picker.querySelector('[data-timezone-detect]');
    const help = picker.closest('label')?.querySelector('[data-timezone-help]');
    const datalistId = input?.getAttribute('list') || '';
    const datalist = datalistId ? document.getElementById(datalistId) : null;

    if (!input) return;

    const supportedTimezones = new Set(
        datalist
            ? Array.from(datalist.querySelectorAll('option')).map((option) => option.value)
            : []
    );

    const detectTimezone = () => {
        try {
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            if (!timezone) {
                if (help) help.textContent = 'The browser did not return a timezone. Choose one from the list.';
                return false;
            }

            if (supportedTimezones.size > 0 && !supportedTimezones.has(timezone)) {
                if (help) help.textContent = 'Detected ' + timezone + ', but PHP does not support this identifier. Choose one from the list.';
                return false;
            }

            input.value = timezone;
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
            if (help) help.textContent = 'Detected from this device: ' + timezone + '. You can change it manually.';
            return true;
        } catch {
            if (help) help.textContent = 'Automatic timezone detection is unavailable. Choose one from the list.';
            return false;
        }
    };

    detectButton?.addEventListener('click', detectTimezone);

    if (picker.dataset.timezoneAutodetect === '1') {
        detectTimezone();
    }
});
