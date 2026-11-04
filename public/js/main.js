(() => {
    'use strict';

    const initializeMultiStepForms = () => {
        document.querySelectorAll('[data-multi-step]').forEach((form) => {
            const steps = Array.from(form.querySelectorAll('[data-form-step]'));
            const indicators = Array.from(form.querySelectorAll('[data-step-indicator]'));
            const backButton = form.querySelector('[data-step-back]');
            const nextButton = form.querySelector('[data-step-next]');
            const submitButton = form.querySelector('[data-step-submit]');
            const groupSettings = form.querySelector('[data-group-settings]');
            const groupPlaceholder = form.querySelector('[data-group-placeholder]');
            const groupInputs = groupSettings ? Array.from(groupSettings.querySelectorAll('input')) : [];
            const roundRobinSetting = form.querySelector('[data-round-robin-setting]');
            const formatInputs = Array.from(form.querySelectorAll('input[name="format"]'));

            if (steps.length === 0 || !backButton || !nextButton || !submitButton) {
                return;
            }

            let currentStep = 0;

            const selectedFormat = () => form.querySelector('input[name="format"]:checked')?.value ?? 'league';

            const updateFormatFields = () => {
                const hasGroups = selectedFormat() === 'groups_knockout';
                if (groupSettings) {
                    groupSettings.hidden = !hasGroups;
                }
                if (groupPlaceholder) {
                    groupPlaceholder.hidden = hasGroups;
                }
                groupInputs.forEach((input) => {
                    input.disabled = !hasGroups;
                    if (hasGroups && input.value === '') {
                        input.value = '2';
                    }
                    if (!hasGroups) {
                        input.value = '';
                    }
                });
                if (roundRobinSetting) {
                    roundRobinSetting.hidden = selectedFormat() === 'knockout';
                }
            };

            const showStep = (index) => {
                currentStep = Math.max(0, Math.min(index, steps.length - 1));
                steps.forEach((step, stepIndex) => {
                    const active = stepIndex === currentStep;
                    step.hidden = !active;
                    step.querySelectorAll('[required]').forEach((field) => {
                        if (!field.dataset.stepRequired) {
                            field.dataset.stepRequired = 'true';
                        }
                        field.required = active && field.dataset.stepRequired === 'true';
                    });
                });
                indicators.forEach((indicator, stepIndex) => {
                    indicator.classList.toggle('is-current', stepIndex === currentStep);
                    indicator.classList.toggle('is-complete', stepIndex < currentStep);
                    if (stepIndex === currentStep) {
                        indicator.setAttribute('aria-current', 'step');
                    } else {
                        indicator.removeAttribute('aria-current');
                    }
                });
                backButton.hidden = currentStep === 0;
                nextButton.hidden = currentStep === steps.length - 1;
                submitButton.hidden = currentStep !== steps.length - 1;
            };

            const validateCurrentStep = () => {
                const fields = Array.from(steps[currentStep].querySelectorAll('input, select, textarea'));
                for (const field of fields) {
                    if (field.disabled) {
                        continue;
                    }
                    if (!field.checkValidity()) {
                        field.reportValidity();
                        return false;
                    }
                }

                if (currentStep === 1) {
                    const textarea = steps[currentStep].querySelector('textarea[name="teams"]');
                    const names = textarea.value.split(/\r?\n/).map((name) => name.trim()).filter(Boolean);
                    if (names.length < 2 || names.length > 64 || textarea.value.length > 20480) {
                        textarea.setCustomValidity(names.length < 2
                            ? 'Enter at least two team names, one per line.'
                            : (names.length > 64
                                ? 'A tournament can have at most 64 teams.'
                                : 'The team list must not exceed 20 KB.'));
                        textarea.reportValidity();
                        textarea.setCustomValidity('');
                        return false;
                    }
                }

                return true;
            };

            form.classList.add('is-enhanced');
            steps.forEach((step) => {
                step.querySelectorAll('[required]').forEach((field) => {
                    field.dataset.stepRequired = 'true';
                    field.required = false;
                });
            });
            groupInputs.forEach((input) => {
                input.disabled = selectedFormat() !== 'groups_knockout';
                input.value = '';
            });
            formatInputs.forEach((input) => input.addEventListener('change', updateFormatFields));
            backButton.addEventListener('click', () => showStep(currentStep - 1));
            nextButton.addEventListener('click', () => {
                if (validateCurrentStep()) {
                    showStep(currentStep + 1);
                }
            });
            form.addEventListener('submit', (event) => {
                if (!validateCurrentStep()) {
                    event.preventDefault();
                }
            });

            updateFormatFields();
            showStep(0);
        });
    };

    const initializeDeleteDialogs = () => {
        document.querySelectorAll('[data-delete-dialog]').forEach((dialog) => {
            const openButton = document.querySelector('[data-open-delete]');
            if (!(dialog instanceof HTMLDialogElement) || !openButton) {
                return;
            }

            openButton.addEventListener('click', () => {
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', '');
                }
                dialog.querySelector('input[name="admin_password"]')?.focus();
            });
            dialog.querySelectorAll('[data-close-delete]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (typeof dialog.close === 'function') {
                        dialog.close();
                    } else {
                        dialog.removeAttribute('open');
                    }
                });
            });
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        });
    };

    const initializeConfirmForms = () => {
        document.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!window.confirm(form.dataset.confirm ?? 'Are you sure?')) {
                    event.preventDefault();
                }
            });
        });
    };

    const initializeFlashAlerts = () => {
        document.querySelectorAll('[data-flash]').forEach((alert) => {
            const dismiss = alert.querySelector('.alert-dismiss');
            dismiss?.addEventListener('click', () => alert.remove());
            window.setTimeout(() => {
                if (alert.isConnected) {
                    alert.classList.add('is-dismissing');
                    window.setTimeout(() => alert.remove(), 220);
                }
            }, 7000);
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        initializeMultiStepForms();
        initializeDeleteDialogs();
        initializeConfirmForms();
        initializeFlashAlerts();
    });
})();
