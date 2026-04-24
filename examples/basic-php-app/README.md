# Basic PHP App Example

This example shows a minimal, generic AuthHandler integration for a PHP application.

Files in this example:

- `auth_config.php`: builds a sample `auth_config` array
- `bootstrap.php`: creates the `AuthHandler` instance and handles requests
- `index.php`: renders a page and injects AuthHandler assets

Assumptions:

- the module is available under `public/lib/_AuthHandler/`
- the application has a working PDO connection
- the application provides real provider credentials, mail delivery, and reCAPTCHA values where needed

Replace all placeholder values before using this pattern in a real project.