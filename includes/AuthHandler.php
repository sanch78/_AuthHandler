<?php

require_once __DIR__ . '/hybridauth/autoload.php';

class AuthHandler
{

    protected $dbPath;
    protected $pdo;
    protected $config = [];
    protected $sqlConfig = [];
    protected $hybridInstance = null;
    protected $jsObject = 'authHandler';
    protected $lang;
    protected $langLoaded = false;
    protected $texts = [];
    protected $modulePath;
    protected $siteUrl;
    protected $siteScript;
    protected $lastActionResult = null;
    protected $lastActionData = null;

    public function __construct($config) {

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

        foreach (['on_init', 'on_login', 'on_error'] as $key) {
            if (isset($this->config[$key]) && is_string($this->config[$key])) {
                $trimmed = trim($this->config[$key]);
                $this->config[$key] = $trimmed ? [ $trimmed ] : [];
            } elseif (!isset($this->config[$key]) || !is_array($this->config[$key])) {
                $this->config[$key] = [];
            }
        }

        if (!empty($this->config['buttons_target'])) {
            $target = json_encode($this->config['buttons_target'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->config['on_init'][] = "instance.injectButtons({$target});";
        }

        $this->modulePath = rtrim($this->config['module_path'] ?? './AuthHandler/', '/') . '/';
        $this->siteUrl = rtrim($this->config['site_url'] ?? '', '/') . '/';
        $this->siteScript = $this->config['site_script'] ?? basename($_SERVER['SCRIPT_NAME']);
        $this->jsObject = $this->config['js_object'] ?? 'authHandler';
        $this->sqlConfig = $this->config['sql_config'] ?? [];
        $this->lang = $this->config['lang'] ?? 'en';

        $this->LoadLang($this->lang);

        $this->InitializeSqlConnection();

        if (($this->config['auto_request_handler'] ?? true)) {
            $this->HandleRequest();
        }
    }


    /**
	 * Loads the language file from lang.json based on the provided code.
	 * Falls back to 'en' if translation not found.
	 *
	 * @param string $langCode
	 * @return void
	 */
	protected function LoadLang (string $langCode = 'en'): void
	{

		if ($this->langLoaded) return;

		$path = __DIR__ . '/../lang.json';

		if (!file_exists($path)) {
			trigger_error('lang.json not found', E_USER_WARNING);
			$this->texts = [];
			$this->langLoaded = true;
			return;
		}

		$json = file_get_contents($path);
		$data = json_decode($json, true);

		if (!is_array($data)) {
			trigger_error('lang.json invalid format', E_USER_WARNING);
			$this->texts = [];
			$this->langLoaded = true;
			return;
		}

		$texts = [];
		foreach ($data as $key => $translations) {
			$texts[$key] = $translations[$langCode] ?? $translations['en'] ?? '';
		}

		$this->texts = $texts;
		$this->langLoaded = true;

    }


	/*
    * Lazily initializes HybridAuth instance
    */
    protected function InitializeHybridAuth ()
    {

        if ($this->hybridInstance !== null) return;

        if (empty($this->config['hybridauth_config'])) {
            throw new Exception('HybridAuth config missing.');
        }

        $this->hybridInstance = new \Hybridauth\Hybridauth($this->config['hybridauth_config']);

    }

    /*
    * Returns an adapter for a given provider (e.g. 'Google'), without authenticating
    * @param string $providerName
    * @return \Hybridauth\Adapter\AdapterInterface
    */
    public function GetHybridAdapter ($providerName)
    {

        $this->InitializeHybridAuth();

        if (empty($this->config['hybridauth_config']['providers'][$providerName]['enabled'])) {
            throw new Exception("Provider '$providerName' is not enabled.");
        }

        return $this->hybridInstance->getAdapter($providerName);

    }

    /*
    * Returns the currently logged-in user, or null if not logged in
    * Placeholder: always returns null for now
    * @return array|null
    */
    public function GetCurrentUser ()
    {

        return null;

    }


    public function HandleRequest () {

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        $isAjax = is_array($data);

        if (!$isAjax) $data = $_POST ?: $_GET;

        if (!isset($data['ah_action'])) return;

        $action = $data['ah_action'];
        unset($data['ah_action']);

        switch ($action) {

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

        if (!$password) $errors['password'] = 'missing_fields';

        if (!$email) $errors['email'] = 'missing_fields';

        if (empty($errors)) {

            $table     = $this->config['sql_config']['table'];
            $fieldset  = $this->config['sql_config']['fieldset'];

            $colEmail   = $fieldset['user_email'];
            $colHash    = $fieldset['user_password'];
            $colToken   = $fieldset['user_token'];
            $colRegkey  = $fieldset['user_regkey'];
            $colKey     = $fieldset['key'];

            $sql = "SELECT {$colKey}, {$colHash}, {$colToken}, {$colRegkey} FROM {$table} WHERE {$colEmail} = :email LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':email' => $email]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) $errors['email'] = 'cant_login';

            elseif (!password_verify($password, $row[$colHash])) $errors['password'] = 'cant_login';

            elseif (!empty($row[$colRegkey])) $errors['email'] = 'not_verified';

        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }

        $token = $row[$colToken] ?? null;

        return [
            'success' => true,
            'token'   => $token
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

        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $confirm  = $data['confirm'] ?? '';

        if (empty($email)) $errors['email'] = 'missing_fields';

        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'email_invalid';
        
        if (empty($password)) $errors['password'] = 'missing_fields';

        elseif (strlen($password) < 8) $errors['password'] = 'password_too_short';

        elseif (!isset($errors['password']) && !empty($this->config['password_pattern'])) {
            $pattern = '#'.$this->config['password_pattern'].'#';
            if (!preg_match($pattern, $password)) {
                $errors['password'] = 'password_invalid';
            }
        }

        elseif ($password !== $confirm) $errors['password'] = 'password_mismatch';

        if (!sizeof($errors) && $this->UserExistsByEmail($email)) $errors['email'] = 'email_exists';

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors
            ];
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $user = $this->InsertUser([
            'user_email'    => $email,
            'user_password' => $hash,
            'created_at'    => date('Y-m-d H:i:s')
        ]);

        if (!$user || empty($user['id'])) return ['success' => false, 'errors'  => ['email' => 'registration_failed']];

        $this->SendRegistrationEmail($email, $user['regkey']);

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
                $ok = $this->UpdateUserFieldsById($userId, [
                    'user_resetkey' => $code . '_'
                ]);
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
            $pattern = '#' . $this->config['password_pattern'] . '#';
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
        $hash   = password_hash($password, PASSWORD_DEFAULT);

        $ok = $this->UpdateUserFieldsById($userId, [
            'user_password' => $hash,
            'user_resetkey' => null
        ]);
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

        if (!$row || !isset($row['regkey'])) {
            return [
                'success' => false,
                'errors' => ['code' => 'verify_failed']
            ];
        }

        if ($row['regkey'] === null) {
            return ['success' => true];
        }

        if ($row['regkey'] !== $code) {
            return [
                'success' => false,
                'errors' => ['code' => 'verify_failed']
            ];
        }

        if ($doWrite) {
            $ok = $this->UpdateUserFieldsById((int)$row['id'], [
                'user_regkey' => null
            ]);
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
     * Renders a JS snippet that instantiates the AuthHandler class.
     *
     * Automatically sets autoInit = true if not explicitly disabled in config.
     * Skips null config entries to avoid unnecessary JSON noise.
     *
     * @return void
     */
    public function RenderAuthHandlerInjector () {

        $jsObject = $this->config['js_object'] ?? 'authHandler';
        $modulePath = $this->config['module_path'] ?? './AuthHandler';
        $autoInit = $this->config['auto_init'] ?? true;

        $this->FeedbackScriptInjector();

        $callbacks = [];

        $map = [
            'on_init'  => 'onInit',
            'on_login' => 'onLogin',
            'on_error' => 'onError'
        ];

        foreach ($map as $sourceKey => $targetKey) {
            $raw = $this->config[$sourceKey] ?? null;

            if (is_array($raw)) {
                foreach ($raw as $line) {
                    if (is_string($line) && trim($line)) {
                        $callbacks[$targetKey][] = trim($line);
                    }
                }
            } elseif (is_string($raw) && trim($raw)) {
                $callbacks[$targetKey][] = trim($raw);
            }
        }


        $enabledProviders = [];
        foreach ($this->config['hybridauth_config']['providers'] ?? [] as $name => $p) {
            if (!empty($p['enabled'])) $enabledProviders[] = $name;
        }

        $config = array_filter([
            'langCode' => $this->config['lang_code'] ?? 'en',
            'providers' => $enabledProviders,
            'allowRegistration' => $this->config['allow_registration'] ?? false,
            'providersOnRegistration' => $this->config['providers_on_registration'] ?? false,
            'mode' => $this->config['mode'] ?? 'modal',
            'buttonsTarget' => $this->config['buttons_target'] ?? null,
            'onInit'  => !empty($callbacks['onInit']) ? "_{$jsObject}OnInit" : null,
            'onLogin' => !empty($callbacks['onLogin']) ? "_{$jsObject}OnLogin" : null,
            'onError' => !empty($callbacks['onError']) ? "_{$jsObject}onError" : null,
            'siteUrl' => $this->siteUrl,
            'siteScript' => $this->siteScript
        ], function ($v) {
            return $v !== null;
        });

        foreach ($callbacks as $hook => $lines) {
            if (!count($lines)) continue;

            $fnName = "_{$jsObject}" . ucfirst(str_replace('_', '', $hook));
            $arg = $hook === 'on_login' ? 'token' : 'instance';

            echo "window.{$fnName} = function ({$arg}) {\n";
            foreach ($lines as $line) {
                echo "{$line}\n";
            }
            echo "};\n";
        }

        echo "window.{$jsObject} = new AuthHandler({\n";
        echo "modulePath: '" . $modulePath . "',\n";
        echo "config: " . json_encode($config, JSON_UNESCAPED_SLASHES) . ",\n";
        echo "autoInit: " . ($autoInit ? 'true' : 'false') . ",\n";
        echo "});\n";

    }


    public function FeedbackScriptInjector () {

        if (empty($this->lastActionResult) && empty($this->lastActionData)) {
            return;
        }

        if (!isset($this->config['on_init']) || !is_array($this->config['on_init'])) {
            $this->config['on_init'] = [];
        }

        if (!empty($this->lastActionData) && $this->lastActionData['type']) {

            switch ($this->lastActionData['type']) {

                case 'verify':
                    $this->config['on_init'][] = "instance._renderForm('login', ".json_encode($this->lastActionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).");";
                    break;

                case 'reset':
                    $this->config['on_init'][] = "instance._renderForm('reset3', ".json_encode($this->lastActionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).");";
                    break;

            }
        }

        elseif (!empty($this->lastActionResult)) $this->config['on_init'][] = "instance.showFeedback(".json_encode($this->lastActionResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).");";

    }


    protected function SendResetEmail (string $toEmail, string $regkey): bool
    {
        $link = $this->siteUrl . $this->siteScript . '?ah_action=remote_reset&email=' . urlencode($toEmail) . '&code=' . urlencode($regkey);

        return $this->SendTemplateEmail(
            $toEmail,
            __DIR__ . '/templates/email_reset_pasword.html',
            $this->texts['reset_subject'] ?? 'Reset password',
            [
                '{regkey}'     => $regkey,
                '{verify_url}' => $link
            ]
        );
    }


    protected function SendRegistrationEmail (string $toEmail, string $regkey): bool
    {
        $link = $this->siteUrl . $this->siteScript . '?ah_action=remote_verify&email=' . urlencode($toEmail) . '&code=' . urlencode($regkey);

        return $this->SendTemplateEmail(
            $toEmail,
            __DIR__ . '/templates/email_regcheck.html',
            $this->texts['verify_subject'] ?? 'Email verification',
            [
                '{regkey}'     => $regkey,
                '{verify_url}' => $link
            ]
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
    protected function SendTemplateEmail (string $toEmail, string $templateFile, string $subject, array $placeholders): bool
    {
        $cfg = $this->config['email_config'] ?? null;

        if (!$cfg || empty($cfg['email_command'])) {
            return false;
        }

        if (!file_exists($templateFile)) {
            return false;
        }

        $templateContent = file_get_contents($templateFile);
        if ($templateContent === false) {
            return false;
        }

        $placeholders['{email}']     = $toEmail;
        $placeholders['{site_name}'] = $this->config['site_name'] ?? '';

        $body = strtr($templateContent, $placeholders);

        $tmpFile = tempnam(sys_get_temp_dir(), 'mail_');
        file_put_contents($tmpFile, $body);

        $command = strtr($cfg['email_command'], [
            '{to}'       => $toEmail,
            '{subject}'  => addslashes($subject) . ' - ' . $this->config['site_name'],
            '{bodyfile}' => $tmpFile
        ]);

        exec($command, $output, $exitCode);

        unlink($tmpFile);

        return $exitCode === 0;
    }


    protected function GetSqlMeta (): array
    {
        $table    = $this->sqlConfig['table'] ?? null;
        $fieldset = $this->sqlConfig['fieldset'] ?? null;

        if (!$table || !is_array($fieldset)) {
            throw new Exception('SQL table or fieldset missing');
        }

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

            if ($needsInit) {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS {$this->sqlConfig['table']} (
                        {$this->sqlConfig['fieldset']['key']} INTEGER PRIMARY KEY AUTOINCREMENT,
                        {$this->sqlConfig['fieldset']['user_email']} TEXT UNIQUE,
                        {$this->sqlConfig['fieldset']['user_password']} TEXT,
                        {$this->sqlConfig['fieldset']['user_regkey']} TEXT,
                        {$this->sqlConfig['fieldset']['user_resetkey']} TEXT,
                        {$this->sqlConfig['fieldset']['user_token']} TEXT,
                        {$this->sqlConfig['fieldset']['created_at']} TEXT,
                        {$this->sqlConfig['fieldset']['last_login']} TEXT
                    );
                ");
            }

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


    protected function AssignNewResetkeyByEmail (string $email): ?string
    {

        list($table, $fs) = $this->GetSqlMeta();

        $sql = "SELECT {$fs['key']} FROM {$table} WHERE {$fs['user_email']} = :email LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || empty($user[$fs['key']])) {
            return null;
        }

        $userId = (int)$user[$fs['key']];
        $resetKey = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $ok = $this->UpdateUserFieldsById($userId, [
            'user_resetkey' => $resetKey
        ]);

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

        list($table, $fs) = $this->GetSqlMeta();

        if (!isset($fs['user_email'], $fs['user_regkey'], $fs['user_token'], $fs['key'])) {
            return null;
        }

        $sql = "SELECT {$fs['key']} AS id, {$fs['user_token']} AS token, {$fs['user_regkey']} AS regkey
                FROM {$table}
                WHERE {$fs['user_email']} = :email
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;

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

        if (isset($fs['user_regkey']) && !isset($fields['user_regkey'])) {
            $fields['user_regkey'] = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        }

        if (isset($fs['user_token']) && !isset($fields['user_token'])) {
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

        if (empty($columns)) return null;

        $sql = 'INSERT INTO ' . $table .
            ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';

        $stmt = $this->pdo->prepare($sql);
        if (!$stmt->execute($params)) return null;

        return [
            'id'     => (int)$this->pdo->lastInsertId(),
            'regkey' => $fields['user_regkey'] ?? null,
            'token'  => $fields['user_token'] ?? null
        ];

    }


    /**
     * Updates user fields by user ID using fieldset mapping.
     *
     * @param int $userId The user's ID
     * @param array $fields Associative array of logical field keys => new values
     * @return bool True on success, false on failure
     */
    protected function UpdateUserFieldsById (int $userId, array $fields): bool
    {
        list($table, $fs) = $this->GetSqlMeta();

        if (!isset($fs['key'])) {
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

        if (empty($assignments)) {
            return false;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $assignments) . " WHERE {$fs['key']} = :id";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    
}
