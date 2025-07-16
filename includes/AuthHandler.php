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

        foreach (['on_init', 'on_login'] as $key) {
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

        if (!isset($data['authHandlerAction'])) return;

        $action = $data['authHandlerAction'];
        unset($data['authHandlerAction']);

        switch ($action) {

            case 'register':
                $result = $this->ApiRegister($data);
                break;

            case 'verify':
                $result = $this->ApiVerify($data);
                break;

            case 'login':
                $result = $this->ApiLogin($data);
                break;

            case 'logout':
                $result = $this->ApiLogout();
                break;

            default:
                $result = [
                    'success' => false,
                    'message' => 'Unknown action: ' . $action
                ];

        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }

        $this->lastActionResult = $result;

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

        if (empty($email)) $errors['email'] = 'email_required';
        
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'email_invalid';
    
        if (empty($password)) $errors['password'] = 'password_required';

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

        if (!$user || empty($user['id'])) {
            return [
                'success' => false,
                'errors'  => ['email' => 'registration_failed']
            ];
        }

        $this->SendRegistrationEmail($email, $user['regkey'] ?? '');

        return [
            'success' => true
        ];
    }


    /**
     * Verifies the email confirmation code and activates the user.
     *
     * @param array $data Input data with 'email' and 'code'
     * @return array ['success' => bool, 'token' => string|null]
     */
    public function ApiVerify (array $data): array
    {

        $email = trim($data['email'] ?? '');
        $code  = trim($data['code'] ?? '');

        if (!$email || !$code) {
            return ['success' => false, 'errors' => ['code' => 'verify_failed']];
        }

        $table     = $this->config['sql_config']['table'];
        $fieldset  = $this->config['sql_config']['fieldset'];

        $colEmail  = $fieldset['user_email'];
        $colRegkey = $fieldset['user_regkey'];
        $colToken  = $fieldset['user_token'];
        $colKey    = $fieldset['key'];

        $checkSql = "SELECT {$colKey}, {$colToken}, {$colRegkey} FROM {$table} WHERE {$colEmail} = :email LIMIT 1";
        $checkStmt = $this->pdo->prepare($checkSql);
        $checkStmt->execute([':email' => $email]);

        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['success' => false, 'errors' => ['code' => 'verify_failed']];
        }

        if (empty($row[$colRegkey])) {
            return ['success' => false, 'errors' => ['code' => 'already_verified']];
        }

        if ($row[$colRegkey] !== $code) {
            return ['success' => false, 'errors' => ['code' => 'verify_failed']];
        }

        $userId = (int)$row[$colKey];
        $token  = $row[$colToken] ?? null;

        $updateSql = "UPDATE {$table} SET {$colRegkey} = NULL WHERE {$colKey} = :id";
        $updateStmt = $this->pdo->prepare($updateSql);
        $updateStmt->execute([':id' => $userId]);

        return [
            'success' => true,
            'message' => 'verify_success'
        ];

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
            'on_login' => 'onLogin'
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

        if (empty($this->lastActionResult)) {
            return;
        }

        $payload = json_encode($this->lastActionResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!isset($this->config['on_init']) || !is_array($this->config['on_init'])) {
            $this->config['on_init'] = [];
        }

        $this->config['on_init'][] = "instance.showFeedback({$payload});";
    }



    /**
     * Sends a registration verification email using configured HTML template and CLI command.
     *
     * @param string $toEmail Recipient's email address
     * @param string $regkey  Verification code to include
     * @return bool True if mail sent successfully, false otherwise
     */
    protected function SendRegistrationEmail (string $toEmail, string $regkey): bool
    {

        $cfg = $this->config['email_config'] ?? null;

        if (!$cfg || empty($cfg['email_command'])) {
            return false;
        }

        $templateFile = __DIR__ . '/templates/email_regcheck.html';

        if (!file_exists($templateFile)) {
            return false;
        }

        $templateContent = file_get_contents($templateFile);

        if ($templateContent === false) return false;

        $link = $this->siteUrl . $this->siteScript . '?authHandlerAction=verify&email=' . urlencode($toEmail) . '&code=' . urlencode($regkey);

        $body = strtr($templateContent, [
            '{email}'      => $toEmail,
            '{regkey}'     => $regkey,
            '{site_name}'  => $this->config['site_name'],
            '{verify_url}' => $link
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'mail_');
        file_put_contents($tmpFile, $body);

        $command = strtr($cfg['email_command'], [
            '{to}'       => $toEmail,
            '{subject}'  => addslashes($this->texts['verify_subject'] ?? 'Email megerősítés'),
            '{bodyfile}' => $tmpFile
        ]);

        exec($command, $output, $exitCode);

        unlink($tmpFile);

        return $exitCode === 0;

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

        $config = $this->config['sql_config'];
        $table  = $config['table'];
        $field  = $config['fieldset']['user_email'];

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$field} = :email";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);

        return (int)$stmt->fetchColumn() > 0;
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
        $config    = $this->config['sql_config'];
        $table     = $config['table'];
        $fieldset  = $config['fieldset'];

        $generatedRegkey = null;
        $generatedToken  = null;

        if (isset($fieldset['user_regkey']) && !isset($fields['user_regkey'])) {
            $generatedRegkey = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $fields['user_regkey'] = $generatedRegkey;
        }

        if (isset($fieldset['user_token']) && !isset($fields['user_token'])) {
            $generatedToken = $this->GenerateUniqueToken();
            $fields['user_token'] = $generatedToken;
        }

        $columns = [];
        $values  = [];
        $params  = [];

        foreach ($fields as $key => $value) {
            if (!isset($fieldset[$key])) continue;

            $col = $fieldset[$key];
            $columns[] = $col;
            $values[]  = ':' . $col;
            $params[':' . $col] = $value;
        }

        if (empty($columns)) return null;

        $sql = 'INSERT INTO ' . $table .
            ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';

        $stmt = $this->pdo->prepare($sql);

        if (!$stmt->execute($params)) {
            return null;
        }

        return [
            'id'     => (int)$this->pdo->lastInsertId(),
            'regkey' => $generatedRegkey,
            'token'  => $generatedToken
        ];
    }


    /**
     * Generates a unique user token that does not already exist in the users table.
     *
     * @return string Unique token
     */
    protected function GenerateUniqueToken (): string
    {

        $table    = $this->config['sql_config']['table'];
        $column   = $this->config['sql_config']['fieldset']['user_token'];

        do {
            $token = bin2hex(random_bytes(16));

            $sql  = "SELECT COUNT(*) FROM {$table} WHERE {$column} = :token";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':token' => $token]);

            $count = (int)$stmt->fetchColumn();

        } while ($count > 0);

        return $token;
    }
    
}
