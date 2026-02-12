<?php

require_once __DIR__ . '/hybridauth/autoload.php';

class AuthHandler
{

    protected $dbPath;
    protected $pdo;
    protected $config = [];
    protected $sqlConfig = [];
    protected $hybridInstance = null;
    protected $jsObject;
    protected $lang;
    protected $langLoaded = false;
    protected $texts = [];
    protected $textsAll = [];
    protected $modulePath;
    protected $siteUrl;
    protected $siteScript;
    protected $lastActionResult = null;
    protected $lastActionData = null;

    protected $emailProcedure = null;
    protected $emailProcedureSends = false;
    
    public $userData = [];
    public $userId = null;
    public $hasPassword = false;

    public function __construct ($config) {

        if (is_array($config)) {
            $this->config = $config;
        }

        elseif (is_string($config) && file_exists($config)) {
            $config = require $config;

            if (!is_array($config)) {
                throw new Exception("Config file did not return an array");
            }

            $this->config = $config;
        }

        else {
            throw new Exception("AuthHandler requires a config array or a valid path to a config file");
        }

        $this->InitSessionIfNeeded();

        foreach (['on_ready', 'on_login', 'on_logout', 'on_timeout'] as $key) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = [];
            }
            elseif (!is_array($this->config[$key])) {
                $this->config[$key] = [$this->config[$key]];
            }
        }

        $this->modulePath = rtrim($this->config['module_path'] ?? './AuthHandler/', '/') . '/';
        $this->siteUrl = rtrim($this->config['site_url'] ?? '', '/') . '/';
        $this->siteScript = $this->config['site_script'] ?? basename($_SERVER['SCRIPT_NAME']);
        $this->jsObject = $this->config['js_object'] ?? 'authHandler';
        $this->sqlConfig = $this->config['sql_config'] ?? [];
        $this->lang = $this->config['lang'] ?? 'en';
        $this->sessionExpiryTimeoutSecs = $this->config['session_expiry_timeout_secs'] ?? 3600;

        $this->recaptcha = $this->config['recaptcha_config'] ?? [];
		$this->recaptchaType = $this->recaptcha['recaptcha_type'] ?? null;
        $this->recaptchaSiteKey = $this->recaptcha['recaptcha_sitekey'] ?? null;
        $this->recaptchaSecret = $this->recaptcha['recaptcha_secret'] ?? null;
        if (empty($this->recaptchaSecret) || empty($this->recaptchaSiteKey) || !in_array($this->recaptcha['recaptcha_type'] ?? '', ['v2'])) $this->recaptchaEnabled = false;
        else $this->recaptchaEnabled = true;

        if (!empty($this->config['buttons_target'])) {
            $target = json_encode($this->config['buttons_target'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->config['on_ready'][] = "instance.injectButtons({$target});";
        }
        if (empty($this->config['on_login'])) $this->config['on_login'][] = $this->config['on_login'] ?? "window.location.reload();";
        if (empty($this->config['on_logout'])) $this->config['on_logout'][] = $this->config['on_logout'] ?? "window.location.reload();";
        
        $this->LoadLang($this->lang);

        $this->InitializeSqlConnection();

        $this->LoginUser();

        if (($this->config['auto_request_handler'] ?? true)) {
            $this->HandleRequest();
        }

    }


    /**
     * Configure an optional external email procedure.
     *
     * This allows overriding how AuthHandler formats and/or sends emails.
     * You can set this after instantiation (not in the constructor) as requested.
     *
     * Callback signature (suggested):
     *   function (string $type, array $payload, string $action) {
     *     // $type: e.g. 'reset', 'verify'
     *     // $action: 'send' (handler should send) or 'format' (handler should only format)
     *     // return:
     *     //  - bool: sending result (typically for 'send')
     *     //  - string: formatted HTML body (typically for 'format')
     *     //  - array: ['subject' => string|null, 'body' => string|null, 'sent' => bool|null]
     *   }
     *
     * @param callable|null $procedure External handler, or null to clear.
     * @param bool $procedureSends If true, external handler sends the email; otherwise it only formats and AuthHandler posts it.
     * @return void
     */
    public function SetEmailProcedure (?callable $procedure, bool $procedureSends = false): void
    {

        $this->emailProcedure = $procedure;
        $this->emailProcedureSends = ($procedure !== null) ? (bool)$procedureSends : false;

    }


    /**
	 * Loads the language file from lang.json based on the provided code.
	 * Falls back to 'en' if translation not found.
	 *
	 * @param string $langCode
	 * @return void
	 */
	public function LoadLang (string $langCode = 'en'): void
	{

		if ($this->langLoaded) return;

		$path = __DIR__ . '/../lang.json';

		if (!file_exists($path)) {
			trigger_error('lang.json not found', E_USER_WARNING);
			$this->texts = [];
            $this->textsAll = [];
			$this->langLoaded = true;
			return;
		}

		$json = file_get_contents($path);
		$data = json_decode($json, true);

        $this->textsAll = $data;

		if (!is_array($data)) {
			trigger_error('lang.json invalid format', E_USER_WARNING);
			$this->texts = [];
			$this->langLoaded = true;
			return;
		}

		foreach ($data as $key => $translations) {
			$this->texts[$key] = $translations[$langCode] ?? $translations['en'] ?? '';
		}

		$this->langLoaded = true;

        $this->SetLangData($this->config['lang_data'] ?? [], $langCode);

    }


    /**
     * Overrides or extends the current language texts with the given array.
     *
     * @param array $overrides Associative array of key => translation
     * @return void
     */
    public function SetLangData (array $overrides, string $langCode = 'en'): void
    {

		if (empty($overrides)) return;

        if (!$this->langLoaded) {
            $this->LoadLang();
        }

        foreach ($overrides as $key => $value) {
            $this->texts[$key] = $value[$langCode];
			$this->textsAll[$key] = $value;
        }

    }
  

    /**
     * Handles incoming requests and routes them to the appropriate method.
     * @return void
     */
    public function HandleRequest () {

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        $isAjax = is_array($data);

        if (!$isAjax) $data = $_POST ?: $_GET;

        if (!isset($data['ah_action'])) return;

        $action = $data['ah_action'];
        unset($data['ah_action']);

        switch ($action) {

            case 'provider':
                $result = $this->HandleOAuthLogin($data['provider'], !empty($_GET['callback']));
                break;

            case 'remote_verify': {
                $result = $this->ApiVerify($data);
                if ($result['success'] === true || isset($result['errors']['code']) && $result['errors']['code'] === 'already_verified') $this->lastActionData = [
                    'type' => 'verify',
                    'email' => $data['email']
                ];
                break;
            }

            case 'verify':
                $result = $this->ApiVerify($data);
                break;

             case 'register':
                $result = $this->ApiRegister($data);
                break;

            case 'login':
                $result = $this->ApiLogin($data);
                break;

            case 'logout':
                $result = $this->ApiLogout();
                break;

            case 'change_password':
                $result = $this->ApiChangePassword($data);
                break;

            case 'reset1':
                $result = $this->ApiReset1($data);
                break;

            case 'reset2':
                $result = $this->ApiReset2($data);
                break;

            case 'reset3':
                $result = $this->ApiReset3($data);
                break;

            case 'remote_reset': {
                $result = $this->ApiReset2($data);
                if ($result['success'] === true) $this->lastActionData = [
                    'type' => 'reset',
                    'email' => $data['email'],
                    'code' => $data['code']
                ];
                break;
            }


        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }

        $this->lastActionResult = $result ?? null;

    }


    /**
     * Handles user login and returns a token if successful.
     *
     * @param array $data Input data with 'email' and 'password'
     * @return array API response with success status and token or field errors
     */
    public function ApiLogin (array $data): array
    {

        $errors = [];

        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (!$email || !$password) $errors['password'] = 'missing_fields';

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }

        $user = $this->FindUserByFields(['user_email' => $email]);

        if (!$user) $errors['password'] = 'cant_login';

        elseif (empty($user['user_password'])) $errors['password'] = 'password_not_set';
        
        elseif (!password_verify($password, $user['user_password'])) $errors['password'] = 'cant_login';
        
        elseif (!empty($user['user_regkey'])) $errors['password'] = 'not_verified';

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }

        $token = $user['user_token'] ?? null;

        if (!$token) {
            $token = $this->GenerateUniqueToken();
            $this->UpdateUserFieldsById([
                'user_token' => $token,
                'last_login' => date('Y-m-d H:i:s'),
                'last_ip' => $this->GetClientIp()
            ], $user['key']);
        } else {
            $this->UpdateUserFieldsById([
                'last_login' => date('Y-m-d H:i:s'),
                'last_ip' => $this->GetClientIp()
            ], $user['key']);
        }

        $this->LoginUser($token);

        return [
            'success' => true,
            'token'   => $token
        ];

    }



    /**
     * Logs out the user by clearing the session and returning a success response.
     *
     * @return array API response with success status
     */
    public function ApiLogout (): array
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->LogoutUser();
        }

        return [
            'success' => true
        ];

    }


    /**
     * Changes password for the currently logged-in user.
     *
     * Expects: old_password, password, confirm
     *
     * @param array $data
     * @return array
     */
    public function ApiChangePassword (array $data): array
    {

        if (!$this->IsLoggedIn() || empty($_SESSION['user_id'])) {
            return [
                'success' => false,
                'errors' => [
                    'old_password' => 'not_logged_in'
                ]
            ];
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);

        $oldPassword = (string)($data['old_password'] ?? '');
        $password = (string)($data['password'] ?? '');
        $confirm = (string)($data['confirm'] ?? '');

        // 1) First validate the old password (order matters for UX)
        if ($oldPassword === '') {
            return [
                'success' => false,
                'errors' => [
                    'old_password' => 'missing_fields'
                ]
            ];
        }

        $user = $this->FindUserByFields(['key' => $userId]);
        if (!$user || empty($user['user_password'])) {
            return [
                'success' => false,
                'errors' => [
                    'old_password' => 'password_not_set'
                ]
            ];
        }

        if (!password_verify($oldPassword, $user['user_password'])) {
            return [
                'success' => false,
                'errors' => [
                    'old_password' => 'old_password_incorrect'
                ]
            ];
        }

        // 2) Only after old password is verified, validate the new password
        $errors = [];

        if ($password === '' || $confirm === '') {
            $errors['password'] = 'missing_fields';
        }

        if (!isset($errors['password'])) {
            if (strlen($password) < 8) {
                $errors['password'] = 'password_too_short';
            }
            elseif (!empty($this->config['password_pattern'])) {
                $pattern = '#' . $this->config['password_pattern'] . '#u';
                if (!preg_match($pattern, $password)) {
                    $errors['password'] = 'password_invalid';
                }
            }
            elseif ($password !== $confirm) {
                $errors['password'] = 'password_mismatch';
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors' => $errors
            ];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $ok = $this->UpdateUserFieldsById([
            'user_password' => $hash,
            'user_resetkey' => null
        ], $userId);

        if (!$ok) {
            return [
                'success' => false,
                'errors' => [
                    'password' => 'server_error'
                ]
            ];
        }

        return [
            'success' => true
        ];

    }



    /**
     * Handles the registration logic from API input.
     *
     * @param array $data Input data with 'email', 'password', 'confirm'
     * @return array API response format with success and optional errors
     */
    public function ApiRegister (array $data): array
    {

        $errors = [];

        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $confirm = $data['confirm'] ?? '';

        if (empty($email)) $errors['email'] = 'missing_fields';

        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'email_invalid';

        if (empty($password)) $errors['password'] = 'missing_fields';

        elseif (!isset($errors['password']) && !empty($this->config['password_pattern'])) {
            $pattern = '#'.$this->config['password_pattern'].'#u';
            if (!preg_match($pattern, $password)) {
                $errors['password'] = 'password_invalid';
            }
        }

        elseif ($password !== $confirm) $errors['password'] = 'password_mismatch';

        if ($this->recaptchaEnabled) {
            if ($this->recaptchaType === 'v2') {
                if (!isset($_SESSION['registration_seed']) || $data['seed'] !== $_SESSION['registration_seed']) {
                    unset($_SESSION['recaptcha_token']);
                }
                if (!$this->VerifyRecaptchaToken($data['recaptcha_token'])) {
                    $errors['recaptcha'] = 'recaptcha_invalid';
                }
            }
        }

        $_SESSION['registration_seed'] = $data['seed'] ?? null;

        $user = $this->FindUserByFields(['user_email' => $email]);

        if (!$errors && $user) {
            if (!empty($user['user_password'])) {
                $errors['email'] = 'email_exists';
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $regkey = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        if ($user) {

            $this->UpdateUserFieldsById([
                'user_password' => $hash,
                'user_regkey' => $regkey
            ], $user['key']);

        } else {

            $user = $this->InsertUser([
                'user_email' => $email,
                'user_password' => $hash,
                'created_at' => date('Y-m-d H:i:s'),
                'user_regkey' => $regkey
            ]);

            if (!$user || empty($user['key'])) {
                return ['success' => false, 'errors' => ['email' => 'registration_failed']];
            }

        }

        $this->SendRegistrationEmail($email, $regkey);

        return ['success' => true];

    }



    /**
     * Initiates password reset by verifying email existence and setting a reset code.
     *
     * @param array $data Input data with 'email'
     * @return array ['success' => bool, 'message' => string] or ['errors' => array]
     */
    public function ApiReset1 (array $data): array
    {

        $errors = [];

        $email = trim($data['email'] ?? '');

        if (!$email) $errors['email'] = 'missing_fields';

        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'email_invalid';

        elseif (!$this->UserExistsByEmail($email)) return ['success' => true];  

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }

        $regkey = $this->AssignNewResetkeyByEmail($email);

        if (!$regkey) {
            return [
                'success' => false,
                'errors'  => ['email' => 'reset_failed']
            ];
        }

        $this->SendResetEmail($email, $regkey);

        return ['success' => true];
    
    }


    /**
     * Verifies the reset code for the given email.
     *
     * @param array $data Input data with 'email' and 'code'
     * @return array ['success' => bool, 'errors' => array]
     */
    public function ApiReset2 (array $data): array
    {

        $errors = [];

        $email = trim($data['email'] ?? '');
        $code  = trim($data['code'] ?? '');

        if (!$email) $errors['code'] = 'reset_failed';

        if (!$code) $errors['code'] = 'missing_fields';

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }

        $row = $this->GetResetKeyRowByEmail($email);

        if (!$row || !isset($row['resetkey'])) {
            return [
                'success' => false,
                'errors' => ['code' => 'reset_failed']
            ];
        }

        $stored = $row['resetkey'];
        $userId = (int)$row['id'];

        if ($stored === $code || $stored === $code . '_') {
            if ($stored === $code) {
                $ok = $this->UpdateUserFieldsById([
                    'user_resetkey' => $code . '_'
                ], $userId);
                if (!$ok) {
                    return [
                        'success' => false,
                        'errors' => ['code' => 'server_error']
                    ];
                }
            }

            return ['success' => true];
        }

        return [
            'success' => false,
            'errors' => ['code' => 'reset_failed']
        ];

    }


    /**
     * Final step of password reset: verifies code, validates inputs and updates password.
     *
     * @param array $data ['email', 'code', 'password', 'confirm']
     * @return array
     */
    public function ApiReset3 (array $data): array
    {

        $email = trim($data['email'] ?? '');
        $code = trim($data['code'] ?? '');
        $password = $data['password'] ?? '';
        $confirm = $data['confirm'] ?? '';

        $errors = [];

        if (!$email || !$code) {
            $errors['code'] = 'verify_failed';
        }

        if (!$password) $errors['password'] = 'missing_fields';

        elseif (strlen($password) < 8) $errors['password'] = 'password_too_short';
        
        elseif (!empty($this->config['password_pattern'])) {
            $pattern = '#' . $this->config['password_pattern'] . '#u';
            if (!preg_match($pattern, $password)) {
                $errors['password'] = 'password_invalid';
            }
        } 
        
        elseif ($password !== $confirm) $errors['password'] = 'password_mismatch';

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }

        $row = $this->GetResetKeyRowByEmail($email);

        if (!$row || empty($row['resetkey']) || $row['resetkey'] !== $code . '_') {
            return [
                'success' => false,
                'errors' => ['password' => 'reset_failed']
            ];
        }

        $userId = (int)$row['id'];
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $ok = $this->UpdateUserFieldsById([
            'user_password' => $hash,
            'user_resetkey' => null
        ], $userId);
        if (!$ok) {
            return [
                'success' => false,
                'errors' => ['password' => 'server_error']
            ];
        }

        return ['success' => true];
    }


    /**
     * Verifies the email confirmation code and activates the user.
     *
     * @param array $data Input data with 'email' and 'code'
     * @return array ['success' => bool, 'token' => string|null]
     */
    public function ApiVerify (array $data, $doWrite = true): array
    {

        $email = trim($data['email'] ?? '');
        $code  = trim($data['code'] ?? '');

        $errors = [];

        if (!$email) $errors['code'] = 'verify_failed';

        if (!$code)  $errors['code'] = 'missing_fields';

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }

        $row = $this->GetVerifyRowByEmail($email);

        if (!$row || !isset($row['user_regkey'])) {
            return [
                'success' => false,
                'errors' => ['code' => 'verify_failed']
            ];
        }

        if ($row['user_regkey'] === null) {
            return ['success' => true];
        }

        if ($row['user_regkey'] !== $code) {
            return [
                'success' => false,
                'errors' => ['code' => 'verify_failed']
            ];
        }

        if ($doWrite) {
            $ok = $this->UpdateUserFieldsById([
                'user_regkey' => null
            ], (int)$row['key']);
            if (!$ok) {
                return [
                    'success' => false,
                    'errors' => ['code' => 'server_error']
                ];
            }
        }

        return ['success' => true];

    }


    /**
	 * Echoes the required JS and CSS includes for AuthHandler.
	 *
	 * @param string $basePath Relative or absolute path prefix to JS/CSS directory
	 * @return void
	 */
	function AssetsInjector ($modulePath = null, $debug = null, $exclude_libs = false): string
	{

        if (isset($this)) {
            if (empty($modulePath)) $modulePath = $this->modulePath;
        	if (empty($debug)) $debug = $this->config['debug'];
        } else {
			if (empty($modulePath)) $modulePath = './AuthHandler/';
			if (empty($debug)) $debug = false;
		}

		$return = '<link rel="stylesheet" href="' . $modulePath . 'AuthHandler.css' . ($debug ? '?' . time() : '') . '">';
		$return .= '<script src="' . $modulePath . 'AuthHandler.js' . ($debug ? '?' . time() : '') . '"></script>';

        if (!$exclude_libs) $return .= '<script src="https://www.google.com/recaptcha/api.js?render=explicit" async defer></script>'."\n";

		return $return;

	}


    /**
     * Renders a JS snippet that instantiates the class.
     *
     * Automatically sets autoInit = true if not explicitly disabled in config.
     * Skips null config entries to avoid unnecessary JSON noise.
     *
     * @return void
     */
    public function Injector () {

        $autoInit = $this->config['auto_init'] ?? true;

        $this->FeedbackScriptInjector();

        $callbacks = [];

        $map = [
            'on_ready'  => 'onReady',
            'on_login' => 'onLogin',
            'on_logout' => 'onLogout',
            'on_timeout' => 'onTimeout'
        ];

        foreach ($map as $sourceKey => $targetKey) {
            $raw = $this->config[$sourceKey] ?? null;

            if (is_callable($raw)) {
                $raw = $raw($this);
            }

            if (is_array($raw)) {
                foreach ($raw as $line) {
                    if (is_callable($line)) {
                        $line = $line($this);
                    }
                    if (is_string($line) && trim($line)) {
                        $line = trim($line);
                        $callbacks[$targetKey][] = $line . (!preg_match('/;\s*$/', $line) ? ';' : '');
                    }
                }
            } elseif (is_string($raw) && trim($raw)) {
                $raw = trim($raw);
                $callbacks[$targetKey][] = $raw . (!preg_match('/;\s*$/', $raw) ? ';' : '');
            }
        }


        $enabledProviders = [];
        foreach ($this->config['hybridauth_config']['providers'] ?? [] as $name => $p) {
            if (!empty($p['enabled'])) $enabledProviders[] = $name;
        }

        $config = array_filter([
            'modulePath' => $this->modulePath,
            'debug' => $this->config['debug'] ?? false,
            'langCode' => $this->config['lang_code'] ?? 'en',
            'langData' => $this->textsAll,
            'providers' => $enabledProviders,
            'allowRegistration' => $this->config['allow_registration'] ?? false,
            'injectResetButton' => $this->config['inject_reset_button'] ?? false,
            'injectChangePasswordButton' => $this->config['inject_change_password_button'] ?? false,
            'allowPersistentLogin' => $this->config['allow_persistent_login'] ?? false,
            'sessionExpiryTimeoutSecs' => $this->sessionExpiryTimeoutSecs,
            'providersOnRegistration' => $this->config['providers_on_registration'] ?? false,
            'mode' => $this->config['mode'] ?? 'modal',
            'buttonsTarget' => $this->config['buttons_target'] ?? null,
            'autoIninit' => $autoInit,
            'siteUrl' => $this->siteUrl,
            'siteScript' => $this->siteScript,
            'recaptchaSiteKey' => !$this->recaptchaEnabled ? null : $this->recaptchaSiteKey,
            'recaptchaType' => !$this->recaptchaEnabled ? null : $this->recaptchaType
        ], function ($v) {
            return $v !== null;
        });

        // Build JS for callbacks directly (no globals)
        $return = '';

        $return .= "window.{$this->jsObject} = new AuthHandler({";
        $return .= "config: " . json_encode($config, JSON_UNESCAPED_SLASHES|JSON_NUMERIC_CHECK|JSON_UNESCAPED_UNICODE|($this->config['debug'] ? JSON_PRETTY_PRINT : '')) . ", ";
        $return .= "token: '" . ($_SESSION['auth_token'] ?? '') . "', ";
        $return .= "data: " . json_encode($_SESSION['auth_user'] ?? 'null', JSON_UNESCAPED_SLASHES|JSON_NUMERIC_CHECK|JSON_UNESCAPED_UNICODE|($this->config['debug'] ? JSON_PRETTY_PRINT : '')) . ", ";
        $return .= "events: {";
        $eventParts = [];
        foreach ($map as $ev) {
            if (!empty($callbacks[$ev]) && is_array($callbacks[$ev])) {
                $body = implode('', $callbacks[$ev]);
                $eventParts[] = $ev . ": function(instance){ " . $body . " }";
            } else {
                $eventParts[] = $ev . ": null";
            }
        }
        $return .= implode(', ', $eventParts);
        $return .= "}});\n";

        return $return;

    }


    /**
     * Injects feedback script based on the last action result or data.
     * This method modifies the 'on_ready' config to include feedback rendering.
     *
     * @return void
     */
    public function FeedbackScriptInjector () {

        if (empty($this->lastActionResult) && empty($this->lastActionData)) {
            return;
        }

        if (!isset($this->config['on_ready']) || !is_array($this->config['on_ready'])) {
            $this->config['on_ready'] = [];
        }

        if (!empty($this->lastActionData) && $this->lastActionData['type']) {

            switch ($this->lastActionData['type']) {

                case 'verify':
                    $this->config['on_ready'][] = "instance._renderForm('login', ".json_encode($this->lastActionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).");";
                    break;

                case 'reset':
                    $this->config['on_ready'][] = "instance._renderForm('reset3', ".json_encode($this->lastActionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).");";
                    break;

            }
        }

        elseif (!empty($this->lastActionResult)) $this->config['on_ready'][] = "instance.showFeedback(".json_encode($this->lastActionResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).");";

    }


    /**
     * Initializes PHP session if not already active and auto_session is enabled.
     * Sets custom session name if configured.
     *
     * @return void
     */
    public function InitSessionIfNeeded ($doDestroy = false): void
    {

        if (empty($this->config['auto_session']) || !$this->config['auto_session']) return;

        if (session_status() === PHP_SESSION_ACTIVE && !$doDestroy) return;

        $persist = isset($_COOKIE['ah_auth_allow_persistent']) && $_COOKIE['ah_auth_allow_persistent'] === '1';
        $cookie_lifetime   = $persist ? 86400 * ($this->config['session_cookie_lifetime_days'] ?? 365) : 0;
        $inactivity_limit  = $persist ? 86400 * 365 : $this->config['session_expiry_timeout_secs'];
        ini_set('session.gc_maxlifetime', (string)$inactivity_limit);

        if (!$doDestroy) session_start([
            'name' => $this->config['session_name'] ?? $this->config['site_name'],
            'cookie_lifetime' => $cookie_lifetime,
            'cookie_path' => $this->config['session_cookie_path'] ?? '/',
            'cookie_secure' => true,            
            'cookie_httponly' => false, 
            'gc_maxlifetime' => $inactivity_limit
        ]);

        if ($doDestroy || time() - ($_SESSION['last_activity'] ?? 0) > $inactivity_limit) {
            session_unset();
            session_destroy();
            session_start([
                'name' => $this->config['session_name'] ?? $this->config['site_name'],
                'cookie_lifetime' => $cookie_lifetime,
                'cookie_path' => $this->config['session_cookie_path'] ?? '/',
                'cookie_secure' => true,
                'cookie_httponly' => false, 
                'gc_maxlifetime' => $inactivity_limit
            ]);
        }

        $_SESSION['last_activity'] = time();

    }


    /**
     * Checks if the user is currently logged in by verifying the session token.
     *
     * @return bool True if logged in, false otherwise
     */
    public function IsLoggedIn (): bool
    {

        return isset($_SESSION['auth_token']);

    }


    /**
     * Logs in a user by token: loads user data and stores session state.
     *
     * @param string $token The authentication token
     * @return bool True if login succeeded, false otherwise
     */
    public function LoginUser (string $token = null): bool
    {

        if (!empty($_SESSION['auth_token']) && $token === null) $token = $_SESSION['auth_token'];

        if (empty($token)) return false;

        $user = $this->FindUserByFields(['user_token' => $token], true);

        if (!$user) {
            $this->LogoutUser();
            return false;
        }

        $user['user_id'] = $user['key'];

        $hasPassword = !empty($user['user_password']);
        $user['hasPassword'] = $hasPassword;
		$this->hasPassword = $hasPassword;
        

        $_SESSION['auth_token'] = $token;
        $_SESSION['auth_user'] = $user;

        $this->userData = [];
        list($table, $fs) = $this->GetSqlMeta();
        foreach ($fs as $logical => $column) {
            if (isset($user[$logical])) {
                $this->userData[$column] = $user[$logical];
            }
        }
        if (!empty($this->sqlConfig['additional_fields']) && is_array($this->sqlConfig['additional_fields'])) {
            foreach ($this->sqlConfig['additional_fields'] as $fieldName) {
                $this->userData[$fieldName] = $user[$fieldName] ?? null;
            }
        }

        $this->userId = $user['key'];

        $_SESSION['user_id'] = $this->userId;

        return true;

    }


    /**
     * Logs out the current user by clearing session data.
     *
     * @return bool True if logout succeeded
     */
    public function LogoutUser (): bool
    {

        unset($_SESSION['auth_token']);
        unset($_SESSION['auth_user']);
        unset($_SESSION['user_id']);
        unset($_SESSION['HYBRIDAUTH::STORAGE']); 
		$this->hasPassword = false;

        $this->InitSessionIfNeeded(true);

        return true;

    }


    /**
     * Handles the OAuth login process for a specific provider.
     *
     * @param string $providerName The name of the OAuth provider (e.g., Google, Facebook).
     * @param bool $isCallback Indicates if the request is a callback from the provider.
     */
    protected function HandleOAuthLogin ($providerName, $isCallback = false)
    {
        $providerName = ucfirst(strtolower($providerName));

        $config = $this->config['hybridauth_config'] ?? [];

        if (empty($config['providers'][$providerName]['enabled'])) {
            http_response_code(403);
            exit('Provider not enabled.');
        }

        if (!empty($_GET['redirect_query'])) $_SESSION['redirect_query'] = $_GET['redirect_query'];

        if (!$config['providers'][$providerName]['callback']) {
            http_response_code(500);
            exit('Missing callback URL for provider.');
        }

        try {
            $hybridauth = new \Hybridauth\Hybridauth($config);
            $adapter = $hybridauth->authenticate($providerName);
        } catch (Exception $e) {
            http_response_code(500);
            exit('OAuth init failed: ' . $e->getMessage());
        }

        if (!$isCallback) {
            exit;
        }

        try {
            $userProfile = $adapter->getUserProfile();
        } catch (Exception $e) {
            http_response_code(500);
            exit('OAuth profile failed: ' . $e->getMessage());
        }

        $email = $userProfile->email ?? null;
        $providerId = $userProfile->identifier ?? null;

        if (!$providerId) {
            http_response_code(400);
            exit('Missing provider identifier.');
        }

        if (!$email) {
            $email = strtolower($providerName) . '_' . $providerId . '@oauth.local';
        }

        // Try to find user by OAuth provider first
        $user = $this->FindUserByOAuthProvider($providerName, $providerId);

        // User found by OAuth provider - update last login and proceed
        if ($user) {           
            $this->UpdateUserFieldsById([
                'last_login' => date('Y-m-d H:i:s'),
                'last_ip' => $this->GetClientIp()
            ], $user['key']);
            $token = $user['user_token'];

        // User not found by OAuth provider - check if email exists
        } else {
            $existing = $this->FindUserByFields(['user_email' => $email]);

            // Email exists - link this OAuth provider to existing user
            if ($existing) {
                
                $this->LinkOAuthProvider($existing['key'], $providerName, $providerId);
                $this->UpdateUserFieldsById([
                    'last_login' => date('Y-m-d H:i:s'),
                    'last_ip' => $this->GetClientIp()
                ], $existing['key']);

                $token = $existing['user_token'];
                if (!$token) {
                    $token = $this->GenerateUniqueToken();
                    $this->UpdateUserFieldsById(['user_token' => $token], $existing['key']);
                }

            // Create new user and link OAuth provider
            } else {               
                $result = $this->InsertUser([
                    'user_email' => $email,
                    'created_at' => date('Y-m-d H:i:s'),
                    'last_login' => date('Y-m-d H:i:s'),
                    'last_ip' => $this->GetClientIp()
                ]);

                if (!$result || empty($result['user_token'])) {
                    http_response_code(500);
                    exit('User insert failed.');
                }

                $userId = $result['key'];
                $token = $result['user_token'];

                // Link OAuth provider to new user
                $this->LinkOAuthProvider($userId, $providerName, $providerId);
            }
        }

        $this->LoginUser($token);

        $redirect = $this->config['site_url'];
        if (!empty($_SESSION['redirect_query'])) {
            $query = $_SESSION['redirect_query'];
            $query = ltrim($query, '?&');
            $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . $query;
            unset($_SESSION['redirect_query']);
        }

        header('Location: ' . $redirect);
        exit;

    }


    /**
     * Retrieves the SQL table and fieldset metadata.
     *
     * @return array An array containing the table name and fieldset
     */
    protected function GetSqlMeta (): array
    {

        $table = $this->sqlConfig['table'] ?? 'users';
        $fieldset = $this->sqlConfig['fieldset'] ?? [
            'key' => 'id',
            'user_email' => 'email',
            'user_password' => 'password', 
            'user_token' => 'token',
            'user_regkey' => 'regkey',
            'user_resetkey' => 'resetkey',
            'created_at' => 'created_time',
            'last_login' => 'last_login_time',
            'last_ip' => 'last_ip_address',
            'last_update' => 'last_update_time'
        ];

        if (!$table || !is_array($fieldset)) {
            throw new Exception('SQL table or fieldset missing');
        }

        return [ $table, $fieldset ];

    }


    /**
     * Retrieves the OAuth providers SQL table and fieldset metadata.
     *
     * @return array An array containing the providers table name and fieldset
     */
    protected function GetProvidersSqlMeta (): array
    {

        list($mainTable, $fs) = $this->GetSqlMeta();

        $table = $this->sqlConfig['providers_table'] ?? $mainTable . '_providers';
        $fieldset = $this->sqlConfig['providers_fieldset'] ?? [
            'key' => $fs['key'],
            'provider_name' => 'provider_name',
            'provider_id' => 'provider_id',
            'created_at' => $fs['created_at'],
            'last_login' => $fs['last_login'],
            'last_update' => $fs['last_update']
        ];

        return [ $table, $fieldset ];

    }


    /*
	 * Initializes the SQLite database with required tables
	 */
	protected function InitializeSqlConnection ()
    {

        if (!empty($this->sqlConfig['pdo_object']) && $this->sqlConfig['pdo_object'] instanceof PDO) {
            $this->pdo = $this->sqlConfig['pdo_object'];
            return;
        }

        $driver = $this->sqlConfig['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {

            $dbname = $this->sqlConfig['dbname'] ?? null;
            if (!$dbname) throw new Exception('SQLite database path (dbname) is required');

            $needsInit = !file_exists($dbname);

            $this->pdo = new PDO('sqlite:' . $dbname);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($needsInit) $this->CreateSqliteTables();

            return;

        }

        if ($driver === 'mysql') {

            $host = $this->sqlConfig['host'] ?? 'localhost';
            $port = $this->sqlConfig['port'] ?? 3306;
            $dbname = $this->sqlConfig['dbname'] ?? '';
            $user = $this->sqlConfig['user'] ?? '';
            $password = $this->sqlConfig['password'] ?? '';

            if (!$dbname || !$user) {
                throw new Exception('MySQL requires user and dbname');
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $user, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return;

        }

        throw new Exception("Unsupported SQL driver: $driver");

    }


    /**
     * Creates SQLite database tables during initialization.
     * Creates both the main users table and OAuth providers junction table.
     *
     * @return void
     */
    protected function CreateSqliteTables(): void
    {

        // Get main table configuration
        list($table, $fs) = $this->GetSqlMeta();

        // Main users table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$table} (
                {$fs['key']} INTEGER PRIMARY KEY AUTOINCREMENT,
                {$fs['user_email']} TEXT UNIQUE,
                {$fs['user_password']} TEXT,
                {$fs['user_regkey']} TEXT,
                {$fs['user_resetkey']} TEXT,
                {$fs['user_token']} TEXT,
                {$fs['created_at']} TEXT,
                {$fs['last_login']} TEXT,
                {$fs['last_ip']} TEXT,
                {$fs['last_update']} TEXT
            );
        ");

        // Get OAuth providers table configuration
        list($providersTable, $pfs) = $this->GetProvidersSqlMeta();

        // OAuth providers junction table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$providersTable} (
                {$pfs['key']} INTEGER NOT NULL,
                {$pfs['provider_name']} TEXT NOT NULL,
                {$pfs['provider_id']} TEXT NOT NULL,
                {$pfs['created_at']} TEXT DEFAULT CURRENT_TIMESTAMP,
                {$pfs['last_login']} TEXT,
                FOREIGN KEY ({$pfs['key']}) REFERENCES {$table}({$fs['key']}),
                UNIQUE({$pfs['provider_name']}, {$pfs['provider_id']})
            );
        ");

    }


    /**
     * Checks whether a user with the given email already exists.
     *
     * @param string $email Email address to check
     * @return bool True if user exists, false otherwise
     */
    protected function UserExistsByEmail (string $email): bool
    {

        list($table, $fs) = $this->GetSqlMeta();

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$fs['user_email']} = :email";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);

        return (int)$stmt->fetchColumn() > 0;

    }


    /**
     * Finds a user by arbitrary logical field conditions (AND combined).
     *
     * @param array $conditions Associative array of logical field => value pairs
     * @param bool $includeAdditional Whether to include additional fields from sql_config
     * @return array|null Full user row as associative array, or null if not found
     */
    public function FindUserByFields (array $conditions, bool $includeAdditional = false): ?array
    {

        list($table, $fs) = $this->GetSqlMeta();

        if (empty($fs['key']) || empty($conditions)) {
            return null;
        }

        if ($includeAdditional && !empty($this->sqlConfig['additional_fields']) && is_array($this->sqlConfig['additional_fields'])) {
            foreach ($this->sqlConfig['additional_fields'] as $fieldName) {
                $fs[$fieldName] = $fieldName;
            }
        }

        $where = [];
        $params = [];

        foreach ($conditions as $logical => $value) {
            if (!isset($fs[$logical])) continue;
            $col = $fs[$logical];
            $where[] = "{$col} = :{$col}";
            $params[":{$col}"] = $value;
        }

        if (empty($where)) {
            return null;
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        $result = [];

        foreach ($fs as $logical => $column) {
            if (isset($row[$column])) {
                $result[$logical] = $row[$column];
            }
        }

        return $result;

    }
    

    /**
     * Convenience checker: returns true if any user has the given page URL.
     * Uses FindUsersByWhere to search by logical field 'page_url'.
     *
     * @param string $pageUrl
     * @return bool
     */
    public function IsPageUrlInUse (string $pageUrl): bool
    {
        $rows = $this->FindUsersByWhere(['page_url' => $pageUrl], [], true);
        return !empty($rows);
    }


    /**
     * Assigns a new reset key to the user identified by email.
     *
     * @param string $email User's email address
     * @return string|null New reset key or null on failure
     */
    protected function AssignNewResetkeyByEmail (string $email): ?string
    {

        $user = $this->FindUserByFields(['user_email' => $email]);

        if (!$user || empty($user['key'])) {
            return null;
        }

        $userId = (int)$user['key'];
        $resetKey = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $ok = $this->UpdateUserFieldsById([
            'user_resetkey' => $resetKey
        ], $userId);

        return $ok ? $resetKey : null;

    }


    /**
     * Retrieves the user's ID, regkey and token by email for verification.
     *
     * @param string $email
     * @return array|null
     */
    protected function GetVerifyRowByEmail (string $email): ?array
    {
        $user = $this->FindUserByFields(['user_email' => $email]);

        if (!$user) {
            return null;
        }

        return [
            'key' => $user['key'] ?? null,
            'user_regkey' => $user['user_regkey'] ?? null
        ];
    }


    /**
     * Retrieves reset key row (id + resetkey) by email.
     *
     * @param string $email
     * @return array|null
     */
    protected function GetResetKeyRowByEmail (string $email): ?array
    {

        list($table, $fs) = $this->GetSqlMeta();

        if (!isset($fs['user_email'], $fs['user_resetkey'], $fs['key'])) {
            return null;
        }

        $sql = "SELECT {$fs['key']} AS id, {$fs['user_resetkey']} AS resetkey FROM {$table} WHERE {$fs['user_email']} = :email LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;

    }


    /**
     * Generates a unique user token that does not already exist in the users table.
     *
     * @return string Unique token
     */
    protected function GenerateUniqueToken (): string
    {

        list($table, $fs) = $this->GetSqlMeta();
        $column = $fs['user_token'];

        do {
            $token = bin2hex(random_bytes(16));

            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = :token";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':token' => $token]);
        } while ((int)$stmt->fetchColumn() > 0);

        return $token;

    }


    /**
     * Inserts a new user record into the database using fieldset mapping.
     * Automatically generates regkey and token if not supplied, and returns them.
     *
     * @param array $fields Associative array of user fields (must match fieldset keys)
     * @return array|null Returns ['id' => int, 'regkey' => string|null, 'token' => string|null] or null on failure
     */
    protected function InsertUser (array $fields): ?array
    {

        list($table, $fs) = $this->GetSqlMeta();

        if (!isset($fields['user_token'])) {
            $fields['user_token'] = $this->GenerateUniqueToken();
        }

        $columns = [];
        $values  = [];
        $params  = [];

       foreach ($fields as $key => $value) {
            if (!isset($fs[$key])) continue;
            $col = $fs[$key];
            $columns[] = $col;
            $values[]  = ':' . $col;
            $params[':' . $col] = $value;
        }

        if (isset($fs['last_update'])) {
            $col = $fs['last_update'];
            $columns[] = $col;
            $values[]  = 'CURRENT_TIMESTAMP';
        }

        if (empty($columns)) return null;

        $sql = 'INSERT INTO ' . $table .
            ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';


        $stmt = $this->pdo->prepare($sql);
        if (!$stmt->execute($params)) return null;

        return [
            'key' => (int)$this->pdo->lastInsertId(),
            'user_token' => $fields['user_token'] ?? null
        ];

    }


    /**
     * Updates user fields by user ID using fieldset mapping.
     *
     * @param array $fields Associative array of logical field keys => new values
     * @param int|null $userId The user's ID (optional, defaults to $this->userId)
     * @return bool True on success, false on failure
     */
    protected function UpdateUserFieldsById (array $fields, int $userId = null): bool
    {
        
        list($table, $fs) = $this->GetSqlMeta();

        if (!empty($this->sqlConfig['additional_fields']) && is_array($this->sqlConfig['additional_fields'])) {
            foreach ($this->sqlConfig['additional_fields'] as $fieldName) {
                if (!isset($fs[$fieldName])) {
                    $fs[$fieldName] = $fieldName;
                }
            }
        }

        if (!isset($fs['key'])) {
            return false;
        }

        if ($userId === null && $this->userId !== null) {
            $userId = $this->userId;
        }

        if ($userId === null) {
            return false;
        }

        $assignments = [];
        $params = [ ':id' => $userId ];

        foreach ($fields as $logical => $value) {
            if (!isset($fs[$logical])) {
                continue;
            }
            $col = $fs[$logical];
            $assignments[] = "{$col} = :{$col}";
            $params[":{$col}"] = $value;
        }

        if (isset($fs['last_update'])) {
            $assignments[] = $fs['last_update'] . " = CURRENT_TIMESTAMP";
        }

        if (empty($assignments)) {
            return false;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $assignments) . " WHERE {$fs['key']} = :id";
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $pname => $pval) {
            if ($pval === null) {
                $stmt->bindValue($pname, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($pname, $pval);
            }
        }

        return $stmt->execute();
    }


    /**
     * Updates only the additional_fields of the currently logged-in user.
     * Only fields listed in sqlConfig['additional_fields'] are allowed.
     * Also updates $this->userData accordingly.
     *
     * @param array $fields Associative array of allowed fields to update
     * @return bool True on success, false otherwise
     */
    public function UpdateUserData (array $fields): bool
    {
    
        if (!$this->userId) {
            return false;
        }
        if (empty($this->sqlConfig['additional_fields']) || !is_array($this->sqlConfig['additional_fields'])) {
            return false;
        }

        $allowed = array_flip($this->sqlConfig['additional_fields']);
        $toUpdate = [];
        foreach ($fields as $key => $value) {
            if (isset($allowed[$key])) {
                $toUpdate[$key] = $value;
            }
        }
        if (empty($toUpdate)) {
            return false;
        }

        $ok = $this->UpdateUserFieldsById($toUpdate);
        if ($ok) {
            foreach ($toUpdate as $key => $value) {
                $this->userData[$key] = $value;
            }
        }

        return $ok;

    }


    /**
     * Deletes a user account from the database.
     * - Accepts a single parameter: the user ID. If null, defaults to the currently logged-in user.
     * - Removes any linked OAuth provider rows.
     * - If the deleted user is the currently logged-in user, logs them out and clears session state.
     *
     * @param int|null $userId The user's ID to delete, or null for current user
     * @return bool True on success, false otherwise
     */
    public function DeleteUser (int $userId = null): bool
    {
        // Resolve user id
        if ($userId === null) {
            $userId = $this->userId ?? null;
        }
        if ($userId === null) {
            return false;
        }

        list($usersTable, $fs) = $this->GetSqlMeta();
        list($providersTable, $pfs) = $this->GetProvidersSqlMeta();

        try {
            $hasTxn = $this->pdo->inTransaction();
            if (!$hasTxn) {
                $this->pdo->beginTransaction();
            }

            // First delete provider links (no ON DELETE CASCADE defined).
            $stmt = $this->pdo->prepare("DELETE FROM {$providersTable} WHERE {$pfs['key']} = :id");
            $stmt->execute([':id' => $userId]);

            // Then delete the user row.
            $stmt = $this->pdo->prepare("DELETE FROM {$usersTable} WHERE {$fs['key']} = :id");
            $stmt->execute([':id' => $userId]);
            $deleted = $stmt->rowCount();

            if (!$hasTxn) {
                $this->pdo->commit();
            }

            // If we deleted the current user, clear runtime state.
            if ($deleted > 0) {
                if ($this->userId === $userId) {
                    $this->LogoutUser();
                    $this->userId = null;
                    $this->userData = [];
                }
                return true;
            }

            // Nothing deleted -> treat as failure
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }

    }


    /**
     * Sends a password reset email with a unique link.
     *
     * @param string $toEmail Recipient's email address
     * @param string $regkey Unique reset key
     * @return bool True if email sent successfully, false otherwise
     */
    protected function SendResetEmail (string $toEmail, string $regkey): bool
    {

        $link = $this->siteUrl . $this->siteScript . '?ah_action=remote_reset&email=' . urlencode($toEmail) . '&code=' . urlencode($regkey);

        return $this->SendEmailFromTemplate(
            $toEmail,
            __DIR__ . '/templates/email_reset_pasword.html',
            $this->texts['reset_subject'] ?? 'Reset password',
            [
                '{regkey}' => $regkey,
                '{verify_url}' => $link
            ],
            'reset'
        );

    }


    /**
     * Sends a registration verification email with a unique link.
     *
     * @param string $toEmail Recipient's email address
     * @param string $regkey Unique registration key
     * @return bool True if email sent successfully, false otherwise
     */
    protected function SendRegistrationEmail (string $toEmail, string $regkey): bool
    {

        $link = $this->siteUrl . $this->siteScript . '?ah_action=remote_verify&email=' . urlencode($toEmail) . '&code=' . urlencode($regkey);

        return $this->SendEmailFromTemplate(
            $toEmail,
            __DIR__ . '/templates/email_regcheck.html',
            $this->texts['verify_subject'] ?? 'Email verification',
            [
                '{regkey}' => $regkey,
                '{verify_url}' => $link
            ],
            'verify'
        );

    }


    /**
     * Sends an HTML email using a template and external command.
     *
     * @param string $toEmail Recipient's email address
     * @param string $templateFile Absolute path to the HTML template
     * @param string $subject Email subject
     * @param array $placeholders Array of placeholder => replacement (e.g. '{regkey}' => '1234')
     * @return bool True if mail sent successfully, false otherwise
     */
    protected function SendEmailFromTemplate (string $toEmail, string $templateFile, string $subject, array $placeholders, string $emailType = 'generic'): bool 
    {

        $cfg = $this->config['email_config'] ?? null;

        if ($this->emailProcedure === null || !$this->emailProcedureSends) {
            if (!$cfg || empty($cfg['command'])) {
                return false;
            }
        }

        if (!file_exists($templateFile)) {
            return false;
        }

        $templateContent = file_get_contents($templateFile);
        if ($templateContent === false) {
            return false;
        }

        $placeholders['{email}'] = $toEmail;
        $placeholders['{site_name}'] = $this->config['site_name'] ?? '';

        $body = strtr($templateContent, $placeholders);

        $finalSubject = addslashes($subject) . ' - ' . ($this->config['site_name'] ?? '');

        // Optional external email procedure (formatter and/or sender)
        if ($this->emailProcedure !== null) {

            $action = $this->emailProcedureSends ? 'send' : 'format';

            $payload = [
                'type' => $emailType,
                'action' => $action,
                'to' => $toEmail,
                'subject' => $finalSubject,
                'subject_base' => $subject,
                'body' => $body,
                'template_file' => $templateFile,
                'template' => $templateContent,
                'placeholders' => $placeholders,
                'site_name' => $this->config['site_name'] ?? '',
                'site_url' => $this->siteUrl ?? null,
                'site_script' => $this->siteScript ?? null,
                'email_config' => $cfg,
            ];

            try {
                $result = call_user_func($this->emailProcedure, $emailType, $payload, $action);
            } catch (Throwable $e) {
                return false;
            }

            // If handler is responsible for sending, accept a boolean result.
            if ($action === 'send') {
                if (is_bool($result)) {
                    return $result;
                }
                if (is_array($result)) {
                    if (array_key_exists('sent', $result) && is_bool($result['sent'])) {
                        return $result['sent'];
                    }
                    if (array_key_exists('success', $result) && is_bool($result['success'])) {
                        return $result['success'];
                    }
                    // If it returned a formatted message but did not explicitly send, fall back to internal sending.
                    if (isset($result['subject']) && is_string($result['subject']) && $result['subject'] !== '') {
                        $finalSubject = (string)$result['subject'];
                    }
                    if (isset($result['body']) && is_string($result['body'])) {
                        $body = (string)$result['body'];
                    }
                }
            }

            // Formatting-only mode: allow overriding subject/body.
            if ($action === 'format') {
                if (is_string($result)) {
                    $body = $result;
                } elseif (is_array($result)) {
                    if (isset($result['subject']) && is_string($result['subject']) && $result['subject'] !== '') {
                        $finalSubject = (string)$result['subject'];
                    }
                    if (isset($result['body']) && is_string($result['body'])) {
                        $body = (string)$result['body'];
                    }
                }
            }

        }

        // If external handler did not send, fall back to internal sending.
        if (!$cfg || empty($cfg['command'])) {
            return false;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mail_');
        file_put_contents($tmpFile, $body);

        $command = strtr($cfg['command'], [
            '{to}'       => $toEmail,
            '{subject}'  => $finalSubject,
            '{bodyfile}' => $tmpFile
        ]);

        exec($command, $output, $exitCode);

        unlink($tmpFile);

        return $exitCode === 0;

    }

    function VerifyRecaptchaToken ($token) 
    {

        if (!$this->recaptchaEnabled) return true;

        if (!isset($this->recaptchaSecret)) return true;

        if (!isset($this->recaptchaType)) return true;

        if ($this->recaptchaType !== 'v2') return true;

        if (isset($_SESSION['recaptcha_token'])) return true;

        $secret = $this->recaptchaSecret;

        if (empty($token)) return false;

        $response = file_get_contents(
            'https://www.google.com/recaptcha/api/siteverify?secret=' .
            urlencode($secret) . '&response=' . urlencode($token)
        );

        $_SESSION['recaptcha_token'] = $token;

        $result = json_decode($response, true);

        return !empty($result['success']);

    }


    /**
     * Finds a user by OAuth provider and provider ID.
     *
     * @param string $providerName The provider name (e.g., 'Google', 'Facebook')
     * @param string $providerId The provider's user ID
     * @return array|null User data or null if not found
     */
    protected function FindUserByOAuthProvider (string $providerName, string $providerId): ?array
    {

        list($usersTable, $fs) = $this->GetSqlMeta();
        list($providersTable, $pfs) = $this->GetProvidersSqlMeta();

        $sql = "
            SELECT u.* 
            FROM {$usersTable} AS u 
            INNER JOIN {$providersTable} AS p ON u.{$fs['key']} = p.{$pfs['key']} 
            WHERE p.{$pfs['provider_name']} = :provider_name AND p.{$pfs['provider_id']} = :provider_id 
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':provider_name' => $providerName,
            ':provider_id' => $providerId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $result = [];
        foreach ($fs as $logical => $column) {
            if (isset($row[$column])) {
                $result[$logical] = $row[$column];
            }
        }

        return $result;

    }


    /**
     * Links an OAuth provider to a user.
     *
     * @param int $userId The user's ID
     * @param string $providerName The provider name
     * @param string $providerId The provider's user ID
     * @return bool True on success
     */
    protected function LinkOAuthProvider (int $userId, string $providerName, string $providerId): bool
    {

        list($providersTable, $pfs) = $this->GetProvidersSqlMeta();

        // Check if this provider link already exists
        $sql = "SELECT {$pfs['key']} FROM {$providersTable} WHERE {$pfs['key']} = :user_id AND {$pfs['provider_name']} = :provider_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':provider_name' => $providerName
        ]);

        // Update existing link
        if ($stmt->fetch()) $sql = "UPDATE {$providersTable} SET {$pfs['provider_id']} = :provider_id, {$pfs['last_login']} = CURRENT_TIMESTAMP WHERE {$pfs['key']} = :user_id AND {$pfs['provider_name']} = :provider_name";

        // Insert new link
        else $sql = "INSERT INTO {$providersTable} ({$pfs['key']}, {$pfs['provider_name']}, {$pfs['provider_id']}) VALUES (:user_id, :provider_name, :provider_id)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':user_id' => $userId,
            ':provider_name' => $providerName,
            ':provider_id' => $providerId
        ]);

    }


    /**
     * Gets the real client IP address, considering proxy headers.
     *
     * @return string The client's IP address
     */
    public function GetClientIp (): string
    {

        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                
                // If validation fails, try IPv4/IPv6 without private/reserved check
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    }
    
}
