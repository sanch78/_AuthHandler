class AuthHandler {

	constructor ({
	modulePath,
	config = {},
	autoInit = true,
	langData = null
    }) {

        if (!modulePath) {
            throw new Error('SimpleMessage: "modulePath" is required.');
        }

        this.modulePath = modulePath.endsWith('/') ? modulePath : modulePath + '/';

        const defaultConfig = {
            providers: [],
            allowRegistration: true,
            mode: 'modal',
            buttonsTarget: null,
            providersOnRegistration: false,
            langCode: 'en'
        };

        this.config = Object.assign({}, defaultConfig, config);

        this.siteUrl = this.config.siteUrl || window.location.origin + window.location.pathname;
        this.siteScript = this.config.siteScript || window.location.pathname;
        this.token = null;
        this.langCode = this.config.langCode;

        this.langData = {};
        this._loadLang().then(() => {
            if (langData && typeof langData === 'object') {
                this.setLangData(langData);
            }

            if (autoInit) {
                this.init();
            }
        });
    
    }


    /* ----- PUBLIC METHODS ----- */


	/**
     * Initializes the AuthHandler instance with a user token and configuration overrides.
     *
     * @param {string|null} token - The current user token (or null if not logged in)
     * @param {object} options - Optional config overrides (e.g. providers, callbacks)
     * @param {string} modulePath - Optional override for module path (used if constructor didn't set it)
     * @returns {void}
     */
    init (token = null) {

        this.userToken = token;

        let callback = this.config.onInit;

        if (typeof callback === 'string') {
            try {
                eval(window[`${callback}`.trim()](this));
            } catch (e) {
                console.warn('Invalid onInit callback string:', this.config.onInit);
                callback = null;
            }
        }

        if (typeof callback === 'function') {
            callback(this);
        }

    }


	setLang (code = 'en') {

        this.langCode = code;

    }


	getText (key, fallback = '') {

        return this.langData[key]?.[this.langCode] || fallback;

    }


	setLangData (langObject) {

        if (typeof langObject !== 'object') return;

		for (const key in langObject) {
			if (!this.langData[key]) this.langData[key] = {};
			for (const lang in langObject[key]) {
				this.langData[key][lang] = langObject[key][lang];
			}
		}

    }


    setUserToken (token) {

        this.userToken = token;

    }


    isLoggedIn () {

        return !!this.userToken;

    }

    
    /**
     * Injects the appropriate authentication buttons into the target container.
     * Shows "Logout" if user is logged in, otherwise shows "Login" and optionally "Register".
     * 
     * @param {string|Element|null} target - The target container selector or element where buttons will be injected.
     */
    injectButtons (target = this.config.buttonsTarget) {

        if (!target) return;

        const container = this._resolveTarget(target);
        if (!container) return;

        container.innerHTML = '';

        if (this.isLoggedIn()) {
            const btn = this._createButton(this.getText('logout', 'Kilépés'), () => this.logout(), 'authhandler-trigger-logout-button');
            container.appendChild(btn);
        } else {
            const loginBtn = this._createButton(this.getText('login_submit', 'Bejelentkezés'), () => this.login(), 'authhandler-trigger-login-button');
            const regBtn = this._createButton(this.getText('registration_submit', 'Regisztráció'), () => this.registration(), 'authhandler-trigger-register-button');

            container.appendChild(loginBtn);
            if (this.config.allowRegistration) {
                container.appendChild(regBtn);
            }
        }

    }


    /**
     * Initiates the login process by rendering the login form.
     *
     * @returns {void}
     */
    login () {

        this._renderForm('login');
        
    }


    /**
     * Initiates the registration process by rendering the registration form.
     *
     * @returns {void}
     */
    registration () {

        this._renderForm('registration');

    }


    /**
     * Logs out the current user by calling the logout endpoint,
     * clears the user token, and refreshes the injected UI.
     *
     * @returns {void}
     */
    logout () {

        fetch(this.modulePath + 'logout.php', { method: 'POST' }).then(() => {
            this.setUserToken(null);
            this.injectButtons();
        });

    }


    /**
     * Shows feedback (error or success message) on login or registration form.
     * Updates or resets field-level notices depending on the error map or success.
     *
     * @param {string} formType - 'login' or 'registration'
     * @param {object|string} result - 'success' string or object of field => errorKey
     * @returns {void}
     */
    showFormFeedback (formType, result) {

        const formSelector =
            formType === 'login' ? '.authhandler-login-form' :
            formType === 'registration' ? '.authhandler-registration-form' :
            formType === 'verify' ? '.authhandler-verify-form' : null;

        const formEl = document.querySelector(formSelector);
        if (!formEl) return;

        if (result === 'success') {
            const modalSelector = `.authhandler-${formType}-modal`;
            const modalEl = document.querySelector(modalSelector);
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
            return;
        }

        let fieldIds = ['email', 'password'];
        if (formType === 'verify') fieldIds = ['code'];

        fieldIds.forEach(fieldId => {
            const noticeWrap = formEl.querySelector(`[data-feedback-for="${fieldId}"]`);
            const p = noticeWrap?.querySelector('p');

            if (!noticeWrap || !p) return;

            const hasError = typeof result === 'object' && result[fieldId];

            if (hasError) {
                const errorKey = result[fieldId];
                p.textContent = this.getText(errorKey, 'Hiba történt.');
                noticeWrap.classList.add('authhandler-error-notice', 'active');

                if (formType === 'login') {
                    noticeWrap.style.display = 'block';
                }
            } else {
                if (formType === 'registration') {
                    const defaultKey = `registration_${fieldId}_notice`;
                    p.textContent = this.getText(defaultKey, '');
                    noticeWrap.classList.remove('authhandler-error-notice');
                    noticeWrap.classList.add('active');
                } else {
                    p.textContent = '';
                    noticeWrap.classList.remove('authhandler-error-notice', 'active');

                    if (formType === 'login') {
                        noticeWrap.style.display = 'none';
                    }
                }
            }
        });

    }


    showFeedback (result) {

        const modalId = 'authhandler-feedback-modal';
        document.getElementById(modalId)?.remove();

        const isSuccess = !!result.success;

        let key = null;

        if (result.errors && typeof result.errors === 'object') {
            for (const val of Object.values(result.errors)) {
                if (typeof val === 'string') {
                    key = val;
                    break;
                }
            }
        }

        const fallback = isSuccess ? 'Success.' : 'Something went wrong.';
        const message = typeof result.message === 'string' ? result.message : this.getText?.(key, fallback);

        const statusClass = isSuccess ? 'authhandler-feedback-success' : 'authhandler-feedback-error';
        const title = isSuccess
            ? this.getText?.('success_title', 'Success')
            : this.getText?.('error_title', 'Error');

        const modalHtml = this._createModal(
            modalId,
            title,
            `<p>${message}</p>`,
            `authhandler-feedback-modal ${statusClass}`
        );

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        new bootstrap.Modal(document.getElementById(modalId)).show();

    }


    /* ----- PRIVATE METHODS ----- */


	async _loadLang () {

        const langUrl = this.modulePath + 'lang.json?seed=' + Date.now();

		try {
			const res = await fetch(langUrl);
			if (!res.ok) throw new Error('HTTP error ' + res.status);
			const data = await res.json();
			this.langData = data;
		} catch (e) {
			console.warn('Could not load lang.json:', e);
		}

    }


    /**
     * Renders a form based on the given type and shows it inline or in a modal.
     * Supported types: 'login', 'registration', 'verify'
     *
     * @param {string} type - The form type ('login', 'registration', or 'verify')
     * @returns {void}
     */
    _renderForm (type) {

        if (!this.config.providers.length && !this.config.allowRegistration) return;

        if (!['login', 'registration', 'verify'].includes(type)) {
            console.warn('Unknown form type:', type);
            return;
        }

        const form = document.createElement('form');
        form.className = `authhandler-${type}-form`;

        if (type === 'verify') {

            if (!this.config.allowRegistration) return;

            const notice = document.createElement('div');
            notice.setAttribute('data-feedback-for', 'code');
            notice.className = 'authhandler-registration-notice';
            notice.innerHTML = `<p>${this.getText('verify_email_notice', 'Please enter the verification code sent to your email address.')}</p>`;

            const codeBox = document.createElement('div');
            codeBox.className = 'authhandler-code-box d-flex gap-2 mb-3 justify-content-center';

            const codeInputs = [];

            for (let i = 0; i < 4; i++) {
                const input = document.createElement('input');
                input.type = 'text';
                input.maxLength = 1;
                input.inputMode = 'numeric';
                input.onfocus = function () { this.value = ''; };
                input.className = 'form-control text-center authhandler-code-digit';
                input.style = 'width: 3rem; height: 3rem; font-size: 1.5rem; border: 2px solid #ccc;';

                input.addEventListener('input', (e) => {
                    if (e.inputType === 'insertText' && input.value.length === 1 && i < 3) {
                        codeInputs[i + 1].focus();
                    }
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !input.value && i > 0) {
                        codeInputs[i - 1].focus();
                    }
                });

                codeInputs.push(input);
                codeBox.appendChild(input);
            }

            const btn = document.createElement('button');
            btn.type = 'submit';
            btn.className = 'authhandler-modal-button';
            btn.textContent = this.getText('verify_submit', 'Send code');

            form.append(notice, codeBox, btn);

            form.addEventListener('submit', e => {
                e.preventDefault();
                const code = codeInputs.map(i => i.value).join('');
                const email = this.verificationEmail;
                this._handleVerificationSubmit(form, code, email);
            });

        } else {

            if (!this.config.allowRegistration && type === 'registration') return;

            if (this.config.allowRegistration) {

                const emailNotice = document.createElement('div');
                emailNotice.className = 'authhandler-registration-notice';
                emailNotice.setAttribute('data-feedback-for', 'email');
                emailNotice.innerHTML = `<p>${type === 'login' ? '' : this.getText('registration_email_notice', 'Please enter a valid email address to use the system.')}</p>`;
                if (type === 'login') emailNotice.style.display = 'none';

                const passwordNotice = document.createElement('div');
                passwordNotice.className = 'authhandler-registration-notice';
                passwordNotice.setAttribute('data-feedback-for', 'password');
                passwordNotice.innerHTML = `<p>${type === 'login' ? '' : this.getText('registration_password_notice', 'Password must be at least 8 characters and include upper, lower case letters and numbers.')}</p>`;
                if (type === 'login') passwordNotice.style.display = 'none';

                const email = this._createInput('login_email', 'email', this.getText(type === 'login' ? 'login_email' : 'register_email', 'Email'));
                const pass = this._createInput('login_password', 'password', this.getText(type === 'login' ? 'login_password' : 'register_password', 'Password'));

                form.append(emailNotice, email, passwordNotice, pass);

                let confirm = null;
                if (type === 'registration') {
                    confirm = this._createInput('register_confirm', 'password', this.getText('register_confirm', 'Confirm Password'));
                    form.appendChild(confirm);
                }

                const btn = document.createElement('button');
                btn.type = 'submit';
                btn.className = 'authhandler-modal-button';
                btn.textContent = this.getText(`${type}_submit`, type === 'login' ? 'Sign in' : 'Sign up');

                form.appendChild(btn);

            }

            if (
                (type === 'login' || (type === 'registration' && this.config.providersOnRegistration)) &&
                this.config.providers?.length
            ) {
                if (type === 'registration') {
                    const providerNotice = document.createElement('div');
                    providerNotice.className = 'authhandler-registration-notice';
                    providerNotice.innerHTML = `<p>${this.getText('registration_oauth_notice', 'You can also register with one of the following providers:')}</p>`;
                    form.appendChild(providerNotice);
                }

                const providerContainer = document.createElement('div');
                providerContainer.className = 'authhandler-provider-buttons';

                this.config.providers.forEach(provider => {
                    const pbtn = document.createElement('button');
                    pbtn.type = 'button';
                    pbtn.className = `authhandler-button authhandler-provider-button authhandler-provider-${provider.toLowerCase()}`;

                    const iconSpan = document.createElement('span');
                    iconSpan.className = `authhandler-provider-icon authhandler-provider-icon-${provider.toLowerCase()}`;

                    const textSpan = document.createElement('span');
                    textSpan.className = `authhandler-provider-buttontext authhandler-provider-buttontext-${provider.toLowerCase()}`;
                    const key = `registration_with_provider_${provider.toLowerCase()}`;
                    textSpan.textContent = this.getText(key, `${provider} regisztráció`);

                    pbtn.append(iconSpan, textSpan);
                    pbtn.onclick = () => {
                        window.location.href = this.siteUrl + this.siteScript + `?provider=${provider}`;
                    };

                    providerContainer.appendChild(pbtn);
                });

                form.appendChild(providerContainer);
            }

            form.addEventListener('submit', e => {
                e.preventDefault();
                if (type === 'login') {
                    this._handleLoginSubmit(form);
                } else if (type === 'registration') {
                    this._handleRegistrationSubmit(form);
                }
            });

        }

        if (this.config.mode === 'inline') {
            const container = this._resolveTarget(this.config.target);
            if (container) {
                container.innerHTML = '';
                container.appendChild(form);
            }
        } else {
            this._showModal(type, form);
        }

    }


    /**
     * Generates HTML for a Bootstrap modal with given ID, title, body content and extra CSS classes.
     *
     * @param {string} id - Modal element ID
     * @param {string} title - Modal title
     * @param {string} bodyContent - Inner HTML content for modal-body
     * @param {string} extraClass - Additional CSS classes for the outermost modal element
     * @returns {string} - HTML string of the modal
     */
    _createModal (id, title, bodyContent, extraClass = '') {

        const headerClass = extraClass.includes('authhandler-feedback-error')
            ? 'authhandler-feedback-error'
            : extraClass.includes('authhandler-feedback-success')
            ? 'authhandler-feedback-success'
            : '';

        return `
        <div class="modal fade authhandler-modal ${extraClass}" id="${id}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header ${headerClass}">
                        <h5 class="modal-title">${title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${this.getText?.('close', 'Close')}"></button>
                    </div>
                    <div class="modal-body">${bodyContent}</div>
                </div>
            </div>
        </div>`;

    }


    /**
     * Displays a Bootstrap modal with the given content.
     * Creates the modal on first use based on the `type` ('login', 'registration', 'verify').
     *
     * @param {string} type - Modal type identifier (used for DOM class and translation key)
     * @param {HTMLElement} content - The content to insert into the modal body
     * @returns {void}
     */
    _showModal (type, content) {

        const modalId = `authhandler-${type}-modal`;
        const modalSelector = `#${modalId}`;

        let modalEl = document.querySelector(modalSelector);

        if (!modalEl) {
            let titleKey = '';
            let titleDefault = '';

            if (type === 'login') {
                titleKey = 'login_title';
                titleDefault = 'Sign in';
            } else if (type === 'registration') {
                titleKey = 'registration_title';
                titleDefault = 'Sign up';
            } else if (type === 'verify') {
                titleKey = 'verify_title';
                titleDefault = 'Email Verification';
            }

            const modalHtml = this._createModal(
                modalId,
                this.getText(titleKey, titleDefault),
                '<div class="modal-body"></div>',
                `authhandler-${type}-modal`
            );

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            modalEl = document.querySelector(modalSelector);
        }

        const modalBody = modalEl.querySelector('.modal-body');
        modalBody.innerHTML = '';
        modalBody.appendChild(content);

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

    }


    /**
     * Creates and returns an <input> element with default styling.
     *
     * @param {string} type - Input type (e.g. 'email', 'password')
     * @param {string} placeholder - Placeholder text
     * @returns {HTMLInputElement} The generated input element
     */
    _createInput (id, type, placeholder) {

        const input = document.createElement('input');
        input.type = type;
        input.id = id;
        input.name = id;
        input.placeholder = placeholder;
        input.className = 'form-control mb-2';

        return input;

    }


    /**
     * Creates and returns a <button> element with click handler and optional class.
     *
     * @param {string} label - Button text
     * @param {function} onclick - Click event handler
     * @param {string} extraClass - Additional CSS class names
     * @returns {HTMLButtonElement} The generated button element
     */
    _createButton (label, onclick, extraClass = '') {

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `authhandler-button ${extraClass}`.trim();
        btn.textContent = label;
        btn.onclick = onclick;

        return btn;

    }


    /**
     * Resolves a DOM target from a selector string or directly passed element.
     *
     * @param {string|Element|null} target - CSS selector or HTMLElement
     * @returns {HTMLElement|null} The resolved element or null if not found
     */
    _resolveTarget (target) {

        if (typeof target === 'string') return document.querySelector(target);
        if (target instanceof Element) return target;

        return null;

    }


    /**
     * Handles login form submission via AJAX.
     *
     * @param {HTMLFormElement} form - The login form element
     */
    _handleLoginSubmit (form) {

        const emailInput = form.querySelector('input[type="email"]');
        const passInput  = form.querySelector('input[type="password"]');

        const payload = {
            authHandlerAction: 'login',
            email: emailInput?.value.trim() || '',
            password: passInput?.value || ''
        };

        this._submitFormHelper(
            form,
            payload,
            (response) => {
                if (response.token) {
                    this._loginSuccess(response.token);
                }
            },
            null,
            'login'
        );

    }


    /**
     * Submits the registration form via AJAX, displays a loading spinner,
     * and delegates success or field-level errors to showFormFeedback().
     *
     * @param {HTMLFormElement} form - The registration form element
     * @returns {void}
     */
    _handleRegistrationSubmit (form) {

        const email    = form.querySelector('input[type="email"]');
        const password = form.querySelector('input[type="password"]');
        const confirm  = form.querySelectorAll('input[type="password"]')[1];

        const payload = {
            authHandlerAction: 'register',
            email: email?.value.trim() || '',
            password: password?.value || '',
            confirm: confirm?.value || ''
        };

        this._submitFormHelper(
            form,
            payload,
            (response) => {
                const modalEl = form.closest('.authhandler-modal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                this.verificationEmail = email?.value.trim();
                this._renderForm('verify');
            },
            null,
            'registration'
        );

    }


        /**
     * Submits the verification code and handles feedback.
     *
     * @param {HTMLFormElement} form - The verification form element
     * @param {string} code - The 4-digit code input by user
     * @param {string} email - Email address to verify against
     */
    _handleVerificationSubmit (form, code, email) {

        if (!email || !code) return;

        const payload = {
            authHandlerAction: 'verify',
            email: email,
            code: code
        };

        this._submitFormHelper(
            form,
            payload,
            (response) => {
                const modalEl = form.closest('.authhandler-modal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
                this.showFeedback({
                    success: true,
                    message: this.getText('verify_success', 'Verification successful.')
                });
            },
            () => {
                this.showFormFeedback('verify', { code: 'verify_failed' });
            },
            'verify'
        );

    }



    /**
     * Handles generic form submission: disables button, shows spinner,
     * sends POST request with payload, and runs success or error callbacks.
     *
     * @param {HTMLFormElement} form - The form being submitted
     * @param {object} payload - Data to be sent via POST
     * @param {function} onSuccess - Callback if response.success
     * @param {function} onError - Callback if response.errors or error
     * @param {string} formType - Used for feedback (e.g. 'login', 'registration', 'verify')
     */
    _submitFormHelper (form, payload, onSuccess, onError, formType = '') {

        const btn = form.querySelector('button[type="submit"]');
        const modalEl = form.closest('.authhandler-modal');

        if (btn) {
            btn.disabled = true;
            btn.dataset.originalLabel = btn.innerHTML;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>` + this.getText('processing', 'Feldolgozás...');
            if (modalEl) modalEl.classList.add('submitting');
        }

        fetch(this.siteUrl + this.siteScript, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(response => {
            if (modalEl) modalEl.classList.remove('submitting');
            if (response.success) {
                onSuccess(response);
            } else {
                if (typeof onError === 'function') {
                    onError(response);
                } else {
                    this.showFormFeedback(formType, response.errors || {});
                }
            }
        })
        .catch(err => {
            console.error(`${formType} failed:`, err);
            this.showFormFeedback(formType, { email: 'server_error' });
        })
        .finally(() => {
            if (btn && btn.dataset.originalLabel) {
                btn.innerHTML = btn.dataset.originalLabel;
                btn.disabled = false;
                delete btn.dataset.originalLabel;
            }
        });

    }


    /**
     * Handles successful login state: sets token and triggers callbacks.
     *
     * @param {string} token - The authentication token
     */
    _loginSuccess (token) {

        this.setUserToken(token);

        let callback = this.config.onLogin;

        if (typeof callback === 'string') {
            try {
                callback = eval(callback);
            } catch (e) {
                console.warn('Invalid onLogin callback string:', this.config.onLogin);
                callback = null;
            }
        }

        if (typeof callback === 'function') {
            callback(token);
        } else if (this.config.buttonsTarget) {
            this.injectButtons(this.config.buttonsTarget);
        }

    }

}
