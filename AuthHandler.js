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


	/**
     * Sets the current language code.
     * @param {string} code - Optional language code (e.g. 'hu', 'en')
     */
    setLang (code = 'en') {

        this.langCode = code;

    }


	/**
     * Returns a translated string for the given key and current language.
     * @param {string} key
     * @param {string} fallback
     * @returns {string}
     */
    getText (key, fallback = '') {

        return this.langData[key]?.[this.langCode] || fallback;

    }


	/**
     * Merges the given language object into the existing language data.
     * Only overrides keys and languages that are provided.
     * @param {Object} langObject - Partial language data to merge
     */
    setLangData (langObject) {

        if (typeof langObject !== 'object') return;

		for (const key in langObject) {
			if (!this.langData[key]) this.langData[key] = {};
			for (const lang in langObject[key]) {
				this.langData[key][lang] = langObject[key][lang];
			}
		}

    }


    /**
     * Sets the user token.
     * @param {string|null} token - The user token (or null if not logged in)
     */
    setUserToken (token) {

        this.userToken = token;

    }


    /**
     * Checks if the user is logged in.
     * @returns {boolean}
     */
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
            const btn = this._createButton(this.getText('logout', 'Logout'), () => this.logout(), 'authhandler-trigger-logout-button');
            container.appendChild(btn);
        } else {
            const loginBtn = this._createButton(this.getText('login_submit', 'Sign in'), () => this.login(), 'authhandler-trigger-login-button');
            const regBtn = this._createButton(this.getText('registration_submit', 'Sign up'), () => this.registration(), 'authhandler-trigger-register-button');
            const resetBtn = this._createButton(this.getText('reset_submit', 'Reset password'), () => this.resetPassword(), 'authhandler-trigger-reset-button');

            container.appendChild(loginBtn);
            container.appendChild(resetBtn);
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
     * Initiates the password reset process by rendering the reset form.
     *
     * @returns {void}
     */
    resetPassword () {

        this._renderForm('reset1');

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

        const formSelector = `.authhandler-${formType}-form`;
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
        if (formType === 'verify' || formType === 'reset2') fieldIds = ['code'];

        fieldIds.forEach(fieldId => {

            const noticeWrap = formEl.querySelector(`[data-feedback-for="${fieldId}"]`);
            const p = noticeWrap?.querySelector('p');

            if (!noticeWrap || !p) return;

            const hasError = typeof result === 'object' && result[fieldId];

            if (hasError) {
                const errorKey = result[fieldId];
                p.textContent = this.getText(errorKey, 'An error occurred.');
                noticeWrap.classList.add('authhandler-error-notice', 'active');

                if (formType === 'login') {
                    noticeWrap.style.display = 'block';
                }
            } else {
                if (formType === 'login') {
                    if (!noticeWrap.hasAttribute('data-persistent')) {
                        p.textContent = '';
                    }
                    noticeWrap.classList.remove('authhandler-error-notice', 'active');
                    if (formType === 'login') {
                        if (!noticeWrap.hasAttribute('data-persistent')) {
                            noticeWrap.style.display = 'none';
                        }
                    }
                } else {
                    const defaultKey = `${formType}_${fieldId}_notice`;
                    p.textContent = this.getText(defaultKey, '');
                    noticeWrap.classList.remove('authhandler-error-notice');
                    noticeWrap.classList.add('active');
                }
            }
        });

    }


    /**
     * Shows feedback (error or success message) in a modal.
     * @param {object} result - The result object from the server
     * @returns {void}
     */
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


	/**
	 * Loads the language file.
	 * @returns {Promise<void>}
	 */
	async _loadLang () {

        const langUrl = this.modulePath + 'lang.json?';

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
     * Renders and injects a form based on the type.
     * Delegates actual form generation to helper methods.
     *
     * @param {string} type - The form type ('login', 'registration', etc.)
     * @param {object|null} data - Optional additional data
     */
    _renderForm (type, data = null) {

        if (!['login', 'registration', 'verify', 'reset1', 'reset2', 'reset3'].includes(type)) {
            console.warn('Unknown form type:', type);
            return;
        }

        let formEl = null;

        switch (type) {

            case 'login':
                formEl = this._renderLoginForm(data);
                break;

            case 'registration':
                formEl = this._renderRegistrationForm(data);
                break;

            case 'verify':
                formEl = this._renderVerifyForm(data);
                break;

            case 'reset1':
                formEl = this._renderReset1Form(data);
                break;

            case 'reset2':
                formEl = this._renderReset2Form(data);
                break;

            case 'reset3':
                formEl = this._renderReset3Form(data);
                break;

        }

        if (this.config.mode === 'inline') {
            const container = this._resolveTarget(this.config.target);
            if (container) {
                container.innerHTML = '';
                container.appendChild(formEl);
            }
        } else {
            this._showModal(type, formEl);
        }

    }


    /**
     * Renders the login form.
     * @param {object|null} data - Optional data to pre-fill the form
     * @returns {HTMLElement|null} The form element or null if not applicable
     */
    _renderLoginForm (data = null) {

        const form = document.createElement('form');
        form.className = 'authhandler-login-form';

        const emailNotice = document.createElement('div');
        emailNotice.className = 'authhandler-notice';
        emailNotice.setAttribute('data-feedback-for', 'email');
        emailNotice.style.display = 'none';

        if (data?.email) {
            emailNotice.setAttribute('data-persistent', '1');
            emailNotice.style.display = '';
            if (data.from === 'verify') emailNotice.innerHTML = `<p>${this.getText('verify_success', 'Your email has been successfully verified. You can now log in.')}</p>`;
            if (data.from === 'reset') emailNotice.innerHTML = `<p>${this.getText('reset_success', 'Your email has been successfully verified. You can now log in.')}</p>`;
        } else {
            emailNotice.innerHTML = `<p></p>`;
        }

        const passwordNotice = document.createElement('div');
        passwordNotice.className = 'authhandler-notice';
        passwordNotice.setAttribute('data-feedback-for', 'password');
        passwordNotice.style.display = 'none';
        passwordNotice.innerHTML = `<p></p>`;

        const email = this._createInput('login_email', 'email', this.getText('login_email', 'Email'));
        const password = this._createInput('login_password', 'password', this.getText('login_password', 'Password'));

        if (data?.email) {
            email.value = data.email;
            email.readOnly = true;
        }

        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.className = 'authhandler-form-button';
        btn.textContent = this.getText('login_submit', 'Sign in');

        form.append(emailNotice, email, passwordNotice, password, btn);

        const providers = this._renderProviderButtons('login_providers_notice', 'You can log in with one of the following providers:');
        if (providers) form.appendChild(providers);

        form.addEventListener('submit', e => {
            e.preventDefault();
            this._handleLoginSubmit(form);
        });

        return form;
    }


    /**
     * Renders the registration form.
     * @param {object|null} data - Optional data to pre-fill the form
     * @returns {HTMLElement|null} The form element or null if not applicable
     */
    _renderRegistrationForm (data = null) {

        if (!this.config.allowRegistration) return null;

        const form = document.createElement('form');
        form.className = 'authhandler-registration-form';

        const emailNotice = document.createElement('div');
        emailNotice.className = 'authhandler-notice';
        emailNotice.setAttribute('data-feedback-for', 'email');
        emailNotice.innerHTML = `<p>${this.getText('registration_email_notice', 'Please enter a valid email address to use the system.')}</p>`;

        const passwordNotice = document.createElement('div');
        passwordNotice.className = 'authhandler-notice';
        passwordNotice.setAttribute('data-feedback-for', 'password');
        passwordNotice.innerHTML = `<p>${this.getText('registration_password_notice', 'Password must be at least 8 characters and include upper, lower case letters and numbers.')}</p>`;

        const email = this._createInput('register_email', 'email', this.getText('register_email', 'Email'));
        const password = this._createInput('register_password', 'password', this.getText('register_password', 'Password'));
        const confirm = this._createInput('register_confirm', 'password', this.getText('register_confirm', 'Confirm Password'));

        if (data?.email) {
            email.value = data.email;
            email.readOnly = true;
        }

        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.className = 'authhandler-form-button';
        btn.textContent = this.getText('registration_submit', 'Sign up');
        form.append(emailNotice, email, passwordNotice, password, confirm, btn);

        const providers = this._renderProviderButtons('registration_providers_notice', 'You can also register with one of the following providers:');
        if (providers) form.appendChild(providers);

        form.addEventListener('submit', e => {
            e.preventDefault();
            this._handleRegistrationSubmit(form);
        });

        return form;

    }


    /**
     * Renders the verification form.
     * @param {object|null} data - Optional data to pre-fill the form
     * @returns {HTMLElement|null} The form element or null if not applicable
     */
    _renderVerifyForm (data = null) {

        if (!this.config.allowRegistration) return null;

        const form = document.createElement('form');
        form.className = 'authhandler-verify-form';

        const notice = document.createElement('div');
        notice.setAttribute('data-feedback-for', 'code');
        notice.className = 'authhandler-notice';
        notice.innerHTML = `<p>${this.getText('verify_code_notice', 'Please enter the verification code sent to your email address.')}</p>`;

        const codeBox = document.createElement('div');
        codeBox.className = 'authhandler-code-box';

        for (let i = 0; i < 4; i++) {
            const input = document.createElement('input');
            input.type = 'text';
            input.maxLength = 1;
            input.inputMode = 'numeric';
            input.className = 'form-control text-center authhandler-code-digit';
            input.setAttribute('data-code-index', i);

            input.onfocus = function () {
                this.value = '';
            };

            input.addEventListener('input', (e) => {
                if (e.inputType === 'insertText' && input.value.length === 1 && i < 3) {
                    const next = form.querySelector(`[data-code-index="${i + 1}"]`);
                    if (next) next.focus();
                }
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && i > 0) {
                    const prev = form.querySelector(`[data-code-index="${i - 1}"]`);
                    if (prev) prev.focus();
                }
            });

            codeBox.appendChild(input);
        }

        const emailHidden = document.createElement('input');
        emailHidden.type = 'hidden';
        emailHidden.name = 'verify_email';
        emailHidden.value = data?.email || '';

        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.className = 'authhandler-form-button';
        btn.textContent = this.getText('verify_submit', 'Send code');

        form.append(notice, codeBox, emailHidden, btn);

        form.addEventListener('submit', e => {
            e.preventDefault();
            this._handleVerificationSubmit(form);
        });

        return form;

    }


    /**
     * Renders the reset step 1. form.
     * @returns {HTMLElement|null} The form element or null if not applicable
     */
    _renderReset1Form () {

        const form = document.createElement('form');
        form.className = 'authhandler-reset1-form';

        const notice = document.createElement('div');
        notice.setAttribute('data-feedback-for', 'email');
        notice.className = 'authhandler-notice';
        notice.innerHTML = `<p>${this.getText('reset1_email_notice', 'Enter your email address and we\'ll send you a verification code.')}</p>`;

        const email = this._createInput('reset_email', 'email', this.getText('reset_email_label', 'Email address'));

        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.className = 'authhandler-form-button';
        btn.textContent = this.getText('reset1_submit', 'Send code');

        form.append(notice, email, btn);

        const providers = this._renderProviderButtons('reset_providers_notice', 'If you registered with one of the following providers, you do not need to manage your password, just click the button for the respective provider:');
        if (providers) form.appendChild(providers);

        form.addEventListener('submit', e => {
            e.preventDefault();
            this._handleReset1Submit(form);
        });

        return form;

    }


    /**
     * Renders the reset step 2. form.
     * @param {object|null} data - Optional data to pre-fill the form
     * @returns {HTMLElement|null} The form element or null if not applicable
     */
    _renderReset2Form (data = null) {

        if (!data?.email) return null;

        const form = document.createElement('form');
        form.className = 'authhandler-reset2-form';

        const notice = document.createElement('div');
        notice.setAttribute('data-feedback-for', 'code');
        notice.className = 'authhandler-notice';
        notice.innerHTML = `<p>${this.getText('reset2_code_notice', 'Enter the 4-digit code we sent to your email.')}</p>`;

        const codeBox = document.createElement('div');
        codeBox.className = 'authhandler-code-box';

        for (let i = 0; i < 4; i++) {
            const input = document.createElement('input');
            input.type = 'text';
            input.maxLength = 1;
            input.inputMode = 'numeric';
            input.className = 'form-control text-center authhandler-code-digit';
            input.setAttribute('data-code-index', i);

            input.onfocus = function () {
                this.value = '';
            };

            input.addEventListener('input', (e) => {
                if (e.inputType === 'insertText' && input.value.length === 1 && i < 3) {
                    const next = form.querySelector(`[data-code-index="${i + 1}"]`);
                    if (next) next.focus();
                }
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && i > 0) {
                    const prev = form.querySelector(`[data-code-index="${i - 1}"]`);
                    if (prev) prev.focus();
                }
            });

            codeBox.appendChild(input);
        }

        const emailHidden = document.createElement('input');
        emailHidden.type = 'hidden';
        emailHidden.name = 'reset_email';
        emailHidden.value = data.email;

        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.className = 'authhandler-form-button';
        btn.textContent = this.getText('reset2_submit', 'Verify code');

        form.append(notice, codeBox, emailHidden, btn);

        form.addEventListener('submit', e => {
            e.preventDefault();
            this._handleReset2Submit(form);
        });

        return form;

    }


    /**
     * Renders the reset step 3. form.
     * @param {object|null} data - Optional data to pre-fill the form
     * @returns {HTMLElement|null} The form element or null if not applicable
     */
    _renderReset3Form (data = null) {

        if (!data?.email || !data?.code) return null;

        const form = document.createElement('form');
        form.className = 'authhandler-reset3-form';

        const email = this._createInput('reset_email', 'email', this.getText('reset_email_label', 'Email address'));
        email.readOnly = true;
        email.value = data.email;

        const codeHidden = document.createElement('input');
        codeHidden.type = 'hidden';
        codeHidden.name = 'reset_code';
        codeHidden.value = data.code;

        const notice = document.createElement('div');
        notice.setAttribute('data-feedback-for', 'password');
        notice.className = 'authhandler-notice';
        notice.innerHTML = `<p>${this.getText('reset3_password_notice', 'Set a new password for your account.')}</p>`;

        const password = this._createInput('reset_password', 'password', this.getText('reset_password_label', 'New password'));
        const confirm  = this._createInput('reset_confirm', 'password', this.getText('reset_confirm_label', 'Confirm new password'));

        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.className = 'authhandler-form-button';
        btn.textContent = this.getText('reset3_submit', 'Set new password');

        form.append(email, codeHidden, notice, password, confirm, btn);

        form.addEventListener('submit', e => {
            e.preventDefault();
            this._handleReset3Submit(form);
        });

        return form;

    }

    
    /**
     * Creates a container with provider login/registration buttons.
     * Used in login and registration forms.
     *
     * @returns {HTMLElement} A div element containing all provider buttons
     */
    _renderProviderButtons (textKey, textDefault) {

        if (!this.config.providers?.length) return null;

        const container = document.createElement('div');
        container.className = 'authhandler-provider-buttons';

        const noticeProviders = document.createElement('div');
        noticeProviders.className = 'authhandler-notice';
        noticeProviders.innerHTML = `<p>${this.getText(textKey, textDefault)}</p>`;
        container.appendChild(noticeProviders);

        this.config.providers.forEach(provider => {

            const pbtn = document.createElement('button');
            pbtn.type = 'button';
            pbtn.className = `authhandler-provider-button authhandler-provider-${provider.toLowerCase()}`;

            const iconSpan = document.createElement('span');
            iconSpan.className = `authhandler-provider-icon authhandler-provider-icon-${provider.toLowerCase()}`;

            const textSpan = document.createElement('span');
            textSpan.className = `authhandler-provider-buttontext authhandler-provider-buttontext-${provider.toLowerCase()}`;
            const key = `registration_with_provider_${provider.toLowerCase()}`;
            textSpan.textContent = this.getText(key, `${provider}`);

            pbtn.append(iconSpan, textSpan);
            pbtn.onclick = () => {
                window.location.href = this.siteUrl + this.siteScript + `?provider=${provider}`;
            };

            container.appendChild(pbtn);

        });

        return container;

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

            switch (type) {

                case 'login':
                    titleKey = 'login_title';
                    titleDefault = 'Sign in';
                    break;

                case 'registration':
                    titleKey = 'registration_title';
                    titleDefault = 'Sign up';
                    break;

                case 'verify':
                    titleKey = 'verify_title';
                    titleDefault = 'Email Verification';
                    break;

                case 'reset1':
                case 'reset2':
                case 'reset3':
                	titleKey = 'reset_title';
	                titleDefault = 'Reset password';
                    break;
            
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
        input.autocomplete = "one-time-code";
        input.className = 'form-control authhandler-input';

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
            ah_action: 'login',
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
            ah_action: 'register',
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
                const emailVal = email?.value.trim();
                if (emailVal) {
                    this._renderForm('verify', { email: emailVal });
                }
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
    _handleVerificationSubmit (form) {

        const inputs = form.querySelectorAll('.authhandler-code-digit');
        const code = Array.from(inputs).map(i => i.value.trim()).join('');
        const email = form.querySelector('input[name="verify_email"]')?.value.trim();

        const payload = {
            ah_action: 'verify',
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
                this._renderForm('login', { email: email, from: 'verify' });
            },
            null,
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
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>` + this.getText('processing', 'Processing...');
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
     * Handles the submission of the reset step 1 form.
     * @param {HTMLFormElement} form - The form being submitted
     */
    _handleReset1Submit (form) {
    
        const email = form.querySelector('input[type="email"]').value.trim() || '';

        const payload = {
            ah_action: 'reset1',
            email: email
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
                this._renderForm('reset2', { email: email });
            },
            null,
            'reset1'
        );

    }


    /**
     * Handles the submission of the reset step 2 form.
     * @param {HTMLFormElement} form - The form being submitted
     */
    _handleReset2Submit (form) {

        const inputs = form.querySelectorAll('.authhandler-code-digit');
        const code = Array.from(inputs).map(i => i.value.trim()).join('');
        const email = form.querySelector('input[name="reset_email"]')?.value.trim() || '';

        const payload = {
            ah_action: 'reset2',
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
                this._renderForm('reset3', { email: email, code: code });
            },
            null,
            'reset2'
        );

    }


    /**
     * Handles the submission of the reset step 3 form.
     * @param {HTMLFormElement} form - The form being submitted
     */
    _handleReset3Submit (form) {

        const emailInput = form.querySelector('input[name="reset_email"]');
        const codeInput  = form.querySelector('input[name="reset_code"]');
        const passInput  = form.querySelector('input[name="reset_password"]');
        const confInput  = form.querySelector('input[name="reset_confirm"]');

        const payload = {
            ah_action: 'reset3',
            email: emailInput?.value.trim() || '',
            code: codeInput?.value.trim() || '',
            password: passInput?.value || '',
            confirm: confInput?.value || ''
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
                this._renderForm('login', { email: payload.email, from: 'reset' });
            },
            null,
            'reset3'
        );

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
