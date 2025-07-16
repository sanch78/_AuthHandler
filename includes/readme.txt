# Default Configuration Reference (AuthHandler)

site_name                # The name of the site (used in branding or email subjects)
site_url                 # Public URL of the site; automatically normalized to end with '/'
site_script              # Entry script used for email verification links (e.g. 'index.php'); if null, auto-detected
module_path              # Path to the AuthHandler module directory (JS, lang files, etc.), e.g. './AuthHandler/'

allow_registration       # Whether user registration is allowed (true/false)
providers_on_registration# Whether to show OAuth provider buttons during registration (true/false)
mode                     # UI mode: 'modal' (default) or 'inline'
buttons_target           # CSS selector where login/logout buttons are injected (e.g. 'body', '#nav')

on_init                  # JS callback function executed after initialization
on_login                 # JS callback function executed after successful login
js_object                # Name of the global JS object exposed (default: 'authHandler')
lang_code                # Language code for translations (e.g. 'en', 'hu')
auto_init                # Whether to auto-initialize the JS module on load
auto_request_handler     # Whether to automatically process incoming HTTP requests in PHP constructor

password_pattern         # Regex pattern for password validation (e.g. requires 8+ chars, uppercase, lowercase, number)

[sql_config]
driver                   # PDO driver name, e.g. 'sqlite', 'mysql'
host                     # Optional database connection parameters (if not using SQLite)
port                     # Optional database connection parameters (if not using SQLite)
user                     # Optional database connection parameters (if not using SQLite)
pw                       # Optional database connection parameters (if not using SQLite)
dbname                   # Path to SQLite file or name of the SQL database
pdo_object               # Optionally, a pre-existing PDO object can be injected
fieldset                 # Field mapping for table columns (email, password, token, regkey, etc.)
table                    # Name of the users table (e.g. 'users')

[email_config]
email_command            # External command-line email sender with placeholders (e.g. {to}, {subject}, {bodyfile})

[hybridauth_config]
callback                 # Full URL to the HybridAuth callback handler
providers                # List of enabled social login providers (e.g. Google, Facebook), with credentials and scopes
