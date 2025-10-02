class AuthHandler {

	constructor ({
	config = {},
    token = null,
    data = null
    }) {

        this.userToken = token;
        this.userData = data;

        const defaultConfig = {
            modulePath: null,
            debug: false,
            langCode: 'en',
            langData: null,
            autoIninit: false,
            providers: [],
            allowRegistration: true,
            providersOnRegistration: false,
            injectResetButton: false,
            mode: 'modal',
            buttonsTarget: null,
            onReady: null,
            onLogin: null,
            onLogout: null,
            siteUrl: window.location.origin + window.location.pathname,
            siteScript: window.location.pathname,
            recaptchaType: null,
            recaptchaSitekey: null
        };

        this.config = Object.assign({}, defaultConfig, config);

        if (!this.config.modulePath) {
            throw new Error('AuthHandler: "modulePath" is required.');
        }
        this.modulePath = this.config.modulePath.endsWith('/') ? this.config.modulePath : this.config.modulePath + '/';

        if (!this.config.recaptchaSitekey) this.config.recaptchaType = null;

        this.ready = false;
        this.debug = this.config.debug;
        this.siteUrl = this.config.siteUrl;
        this.siteScript = this.config.siteScript;
        this.langCode = this.config.langCode;
        this.langData = {};

        if (this.config.autoIninit) this.init(this.config.onReady, this.config.langData);
    
    }


    /* ----- PUBLIC METHODS ----- */


    async init (callback = null, langData = null) {

        this._loadLang(langData).then(() => {
            
            if (typeof callback === 'string') {
                try {
                    const callbackFn = window[callback.trim()];
                    if (typeof callbackFn === 'function') {
                        callbackFn(this);
                    } else {
                        console.warn('Callback is not a function:', callback);
                    }
                } catch (e) {
                    console.warn('Invalid onReady callback string:', callback, e);
                    callback = null;
                }
            }

            this.ready = true;

        });

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
     * Renders the login button.
     * @returns {HTMLElement} The login button element.
     */
    renderLoginButton () {

        const btn = this._createButton(
            this.getText('login_submit', 'Sign in'),
            () => this.login(),
            'authhandler-trigger-login-button'
        );

        return btn;

    }

    /**
     * Renders the registration button.
     * @returns {HTMLElement} The register button element.
     */
    renderRegisterButton () {

        const btn = this._createButton(
            this.getText('registration_submit', 'Sign up'),
            () => this.registration(),
            'authhandler-trigger-register-button'
        );

        return btn;

    }

    /**
     * Renders the reset password button.
     * @returns {HTMLElement} The reset password button element.
     */
    renderResetButton () {

        const btn = this._createButton(
            this.getText('reset_submit', 'Reset password'),
            () => this.resetPassword(),
            'authhandler-trigger-reset-button'
        );

        return btn;

    }

    /**
     * Renders the logout button.
     * @returns {HTMLElement} The logout button element.
     */
    renderLogoutButton () {

        const btn = this._createButton(
            this.getText('logout', 'Logout'),
            () => this.logout(),
            'authhandler-trigger-logout-button'
        );

        return btn;

    }

    /**
     * Injects the appropriate authentication buttons into the target container(s).
     * Shows "Logout" if user is logged in, otherwise shows "Login" and optionally "Register".
     * 
     * @param {string|Element|null} target - The target container selector or element where buttons will be injected.
     */
    injectButtons (target = this.config.buttonsTarget) {

        if (!target) return;

        const containers = this._resolveTarget(target);
        if (!containers) return;

        // Handle both single element and array of elements
        const containerArray = Array.isArray(containers) ? containers : [containers];

        containerArray.forEach(container => {
            if (!container) return;

            container.innerHTML = '';

            if (this.userToken) {
                container.appendChild(this.renderLogoutButton());
            } else {
                container.appendChild(this.renderLoginButton());
                if (this.config.injectResetButton) container.appendChild(this.renderResetButton());
                if (this.config.allowRegistration) container.appendChild(this.renderRegisterButton());
            }
        });

    }


    /**
     * Initiates the login process by rendering the login form.
     *
     * @returns {void}
     */
    login (includeLoginNotice = false) {

        this._renderForm('login', null, includeLoginNotice);
        
    }


    setProviderRedirectGetQuery (query, persistent = false) {

        this.providerRedirectGetQuery = query;

        this.providerRedirectPersistent = persistent;

    }


    /**
     * Handles logout request via AJAX.
     */
    logout () {

        const payload = {
            ah_action: 'logout'
        };

        fetch(this.siteUrl + this.siteScript, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(response => {
            this._logoutSuccess();
        })
        .catch(err => {
            this._logoutSuccess();
        });

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

        const shownErrors = [];

        let fieldIds = ['email', 'password', 'recaptcha'];
        if (formType === 'verify' || formType === 'reset2') fieldIds = ['code'];
        if (formType === 'registration' && this.config.recaptchaType) fieldIds.push('recaptcha');

        fieldIds.forEach(fieldId => {

            const noticeWrap = formEl.querySelector(`[data-feedback-for="${fieldId}"]`);
            const p = noticeWrap?.querySelector('p');

            if (!noticeWrap || !p) return;

            const errorKey = result[fieldId];
            const hasError = typeof result === 'object' && errorKey;

            if (hasError) {

                if (!shownErrors.includes(errorKey)) {
                    shownErrors.push(errorKey);

                    p.textContent = this.getText(errorKey, 'An error occurred.');
                    noticeWrap.classList.add('authhandler-error-notice');

                    if (formType === 'login' || (formType === 'registration' && errorKey === 'email_exists')) {
                        const existing = noticeWrap.querySelector('.authhandler-suggestion');
                        if (existing) existing.remove();

                        const resetElem = this._addSuggestion(errorKey === 'password_not_set' ? 'register' : 'reset');
                        if (resetElem) noticeWrap.appendChild(resetElem);
                    }
                    if (formType === 'login' || (formType === 'registration' && errorKey === 'recaptcha_invalid')) noticeWrap.style.display = 'block';
                }

            } else {

                if (formType === 'login' || (formType === 'registration' && fieldId === 'recaptcha')) {
                    if (!noticeWrap.hasAttribute('data-persistent')) {
                        p.innerHTML  = '';
                    }
                    noticeWrap.classList.remove('authhandler-error-notice');
                    if (!noticeWrap.hasAttribute('data-persistent')) {
                        noticeWrap.style.display = 'none';
                    }

                    if (fieldId === 'recaptcha') {
                        const recaptchaContainer = document.getElementById('recaptcha-container');
                        recaptchaContainer.innerHTML = '';
                        recaptchaContainer.style.display = 'none';
                    }
                } else {
                    const defaultKey = `${formType}_${fieldId}_notice`;
                    p.innerHTML  = this.getText(defaultKey, '');
                    noticeWrap.classList.remove('authhandler-error-notice');
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
	async _loadLang (langData = null) {

        if (this.ready) return true;

        if (window.AuthHandlerTexts) this.langData = window.AuthHandlerTexts;
        else {
            const langUrl = this.modulePath + 'lang.json' + (this.debug ? '?' + Date.now() : '');
            try {
                const res = await fetch(langUrl);
                if (!res.ok) throw new Error('HTTP error ' + res.status);
                const data = await res.json();
                this.langData = data;
                window.AuthHandlerTexts = data;
            } catch (e) {
                console.warn('Could not load lang.json:', e);
            }
        }

        if (langData && typeof langData === 'object') this.setLangData(langData);

        return true;

    }


    /**
     * Renders and injects a form based on the type.
     * Delegates actual form generation to helper methods.
     *
     * @param {string} type - The form type ('login', 'registration', etc.)
     * @param {object|null} data - Optional additional data
     */
    _renderForm (type, data = null, includeLoginNotice = false) {

        if (!['login', 'registration', 'verify', 'reset1', 'reset2', 'reset3'].includes(type)) {
            console.warn('Unknown form type:', type);
            return;
        }

        this.suggestionUsed = false;

        this.passwordResetLinkUsed = false;

        let formEl = null;

        switch (type) {

            case 'login':
                formEl = this._renderLoginForm(data, includeLoginNotice);
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
            const containers = this._resolveTarget(this.config.target);
            if (containers) {
                const container = Array.isArray(containers) ? containers[0] : containers;
                if (container) {
                    container.innerHTML = '';
                    container.appendChild(formEl);
                }
            }
        } else {

            this._showModal(type, formEl);

            const recaptchaContainer = document.getElementById('recaptcha-container');
            if (recaptchaContainer) {
                grecaptcha.render('recaptcha-container', {
                    sitekey: this.config.recaptchaSitekey,
                    theme: 'light'
                });
            }

        }

    }


    /**
     * Renders the login form.
     * @param {object|null} data - Optional data to pre-fill the form
     * @returns {HTMLElement|null} The form element or null if not applicable
     */
    _renderLoginForm (data = null, includeLoginNotice = false) {

        const form = document.createElement('form');
        form.className = 'authhandler-login-form';

        const emailNotice = document.createElement('div');
        emailNotice.className = 'authhandler-notice';
        emailNotice.setAttribute('data-feedback-for', 'email');
        emailNotice.style.display = 'none';

        if (data?.email || includeLoginNotice) {
            emailNotice.setAttribute('data-persistent', '1');
            emailNotice.style.display = 'block';
            emailNotice.className += ' authhandler-success-notice';
            if (data?.type === 'verify') emailNotice.innerHTML = `<p>${this.getText('verify_success', 'Your email has been successfully verified. You can now log in.')}</p>`;
            if (data?.type === 'reset') emailNotice.innerHTML = `<p>${this.getText('reset_success', 'Your email has been successfully verified. You can now log in.')}</p>`;
            if (includeLoginNotice) {
                emailNotice.innerHTML = `<p>${this.getText('login_email_notice', "If you haven't registered yet, click the link or use one of the providers below.")}</p>`;
                const resetElem = this._addSuggestion('signup', true);
                if (resetElem) emailNotice.appendChild(resetElem);
            }
        } else {
            emailNotice.setAttribute('data-persistent', '1');
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

        form.append(emailNotice, email, passwordNotice, password, confirm);

        if (this.config.recaptchaType === 'v2') {
            const captchaNotice = document.createElement('div');
            captchaNotice.className = 'authhandler-notice';
            captchaNotice.setAttribute('data-feedback-for', 'recaptcha');
            captchaNotice.innerHTML = '<p></p>';
            captchaNotice.style.display = 'none';

            const captcha = document.createElement('div');
            captcha.id = 'recaptcha-container';
            form.append(captchaNotice, captcha);
        }

        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.className = 'authhandler-form-button';
        btn.textContent = this.getText('registration_submit', 'Sign up');
        form.appendChild(btn);

        const providers = this._renderProviderButtons('registration_providers_notice', 'You can also register with one of the following providers:');
        if (providers) form.appendChild(providers);

        this.registrationSeed = Math.random().toString(36).substring(2, 15);

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
                let href = this.siteUrl + this.siteScript + `?ah_action=provider&provider=${provider}`;
                if (typeof this.providerRedirectGetQuery === 'string' && this.providerRedirectGetQuery.length > 0) {
                    href += `&redirect_query=` + encodeURIComponent(this.providerRedirectGetQuery);
                    if (!this.providerRedirectPersistent) this.providerRedirectGetQuery = null;
                }
                window.location.href = href;
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
        <div class="modal fade authhandler-modal ${extraClass}" id="${id}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header ${headerClass}">
                        <h5 class="modal-title">${title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
     * @returns {HTMLElement|HTMLElement[]|null} The resolved element(s) or null if not found
     */
    _resolveTarget (target) {

        if (typeof target === 'string') {
            const elements = document.querySelectorAll(target);
            return elements.length === 1 ? elements[0] : Array.from(elements);
        }
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
        const passInput = form.querySelector('input[type="password"]');

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
                    const modalEl = form.closest('.authhandler-modal');
                    if (modalEl) {
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                    }
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

        const email = form.querySelector('input[type="email"]');
        const password = form.querySelector('input[type="password"]');
        const confirm = form.querySelectorAll('input[type="password"]')[1];

        const payload = {
            ah_action: 'register',
            email: email?.value.trim() || '',
            password: password?.value || '',
            confirm: confirm?.value || '',
            seed: this.registrationSeed
        };

        if (this.config.recaptchaType === 'v2') {
            const token = document.querySelector('[name="g-recaptcha-response"]')?.value || '';
            payload.recaptcha_token = token;
        }

        this._submitFormHelper(
            form,
            payload,
            (response) => {
                const modalEl = form.closest('.authhandler-modal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
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
                this._renderForm('login', { email: email, type: 'verify' });
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
    _submitFormHelper (form, payload, onSuccess, onError = null, formType = null) {

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
            if (formType) this.showFormFeedback(formType, { email: 'server_error' });
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
                this._renderForm('login', { email: payload.email, type: 'reset' });
            },
            null,
            'reset3'
        );

    }


    /**
     * Adds a context-aware suggestion link to the modal (e.g. password reset or account extension).
     * @param {'reset'|'register'} type - The type of suggestion to show
     * @returns {HTMLElement|null} The suggestion container element, or null if already used
     */
    _addSuggestion (type, force = false) {

        if (this.suggestionUsed === type && !force) return null;
        this.suggestionUsed = type;

        const container = document.createElement('div');
        container.className = 'authhandler-suggestion';

        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'btn auth-suggestion-btn';

        let textKey, callback;

        if (type === 'reset') {
            textKey = 'password_reset_suggestion';
            callback = this.resetPassword?.bind(this);
        } else if (type === 'register') {
            textKey = 'registration_suggestion';
            callback = this.registration?.bind(this);
        } else if (type === 'signup') {
            textKey = 'signup_suggestion';
            callback = this.registration?.bind(this);
            container.className = 'authhandler-suggestion regular';
            link.className = 'btn auth-suggestion-btn regular';
        } else {
            return null;
        }

        link.textContent = this.getText(textKey, 'Click here');

        link.onclick = (e) => {
            e.preventDefault();

            const modalEl = link.closest('.modal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            if (typeof callback === 'function') {
                callback();
            }
        };

        container.appendChild(link);

        return container;
    }

    logoutUser () {

        this.userToken = null;

    }


    /**
     * Handles successful logout state: clears token and triggers callbacks.
     */
    _logoutSuccess () {

        this.logoutUser();

        let callback = this.config.onLogout;

        if (typeof callback === 'string') {
            try {
                callback = eval(callback);
            } catch (e) {
                console.warn('Invalid onLogout callback string:', this.config.onLogout);
                callback = null;
            }
        }

        if (typeof callback === 'function') callback();
        else if (this.config.buttonsTarget) {
            this.injectButtons();
        }

    }


    /**
     * Handles successful login state: sets token and triggers callbacks.
     *
     * @param {string} token - The authentication token
     */
    _loginSuccess (token) {

        this.userToken = token;

        let callback = this.config.onLogin;

        if (typeof callback === 'string') {
            try {
                callback = eval(callback);
            } catch (e) {
                console.warn('Invalid onLogin callback string:', this.config.onLogin);
                callback = null;
            }
        }

        if (typeof callback === 'function') callback();
        else if (this.config.buttonsTarget) {
            this.injectButtons();
        }

    }

}
