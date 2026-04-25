# AuthHandler Module Usage Reference

## What the module is

AuthHandler is a two-part authentication module:

- a server-side PHP class in `includes/AuthHandler.php`
- a client-side JavaScript class in `AuthHandler.js`

Together they provide:

- email and password login
- registration with email verification
- password reset in multiple steps
- password change for logged-in users
- OAuth login through HybridAuth providers
- session bootstrap and timeout handling
- frontend auth UI rendering
- consumer callbacks for auth lifecycle events

It should be treated as an auth orchestrator, not as a simple helper.

## High-level architecture

Backend responsibilities:

- accept and normalize configuration
- initialize session state
- map application schema to logical user fields
- handle auth requests
- read and update user records
- integrate provider login
- build frontend bootstrap payloads
- dispatch auth-related email flows

Frontend responsibilities:

- represent current auth state in the browser
- render auth forms and modals
- submit auth requests to the backend
- render provider login buttons
- manage timeout UX
- trigger project callbacks after auth events

## Core request model

The PHP class exposes a multi-action request router through `HandleRequest()`.

Important actions:

- `login`
- `logout`
- `register`
- `verify`
- `remote_verify`
- `reset1`
- `reset2`
- `reset3`
- `remote_reset`
- `change_password`
- `provider`

Requests may come from:

- JSON request bodies sent by the JavaScript client
- traditional `GET` or `POST` parameters

Responses are typically JSON for AJAX flows and redirect or deferred feedback for link-driven flows.

## Minimal backend integration pattern

Typical consumer-side pattern:

1. build an `auth_config` array
2. instantiate `new AuthHandler($auth_config)`
3. optionally attach a custom email procedure
4. call `HandleRequest()` early in the request lifecycle
5. inject assets with `AssetsInjector()`
6. inject frontend bootstrap with `Injector()`
7. read auth state through `isLoggedIn()` and `userData`

## Configuration reference

### General runtime settings

- `debug`: cache-busting and debug-friendly behavior
- `js_object`: global browser object name
- `site_name`: branding and email subject source
- `site_url`: public site base URL
- `site_script`: request entry script path
- `module_path`: public path to the module assets
- `lang_code`: active language code

### Session settings

- `auto_session`: auto-start session behavior
- `session_name`: session cookie name
- `session_cookie_lifetime_days`: persistent session lifetime
- `session_expiry_timeout_secs`: inactivity timeout
- `session_cookie_path`: cookie path restriction
- `allow_persistent_login`: enables the keep-me-logged-in option

### UI and lifecycle settings

- `allow_registration`: allow or disable registration
- `provider_only_auth`: disable password-based login, registration, verification, reset, and change-password flows while keeping provider login enabled
- `providers_on_registration`: show providers on the registration screen
- `auto_request_handler`: automatically call `HandleRequest()` in the constructor
- `auto_init`: automatically initialize the frontend class
- `buttons_target`: CSS selector for injected auth buttons
- `mode`: frontend display mode, usually `modal` or `inline`
- `inject_reset_button`: whether reset action is auto-injected with buttons
- `password_pattern`: server-side password validation regex

### Callback settings

- `on_ready`
- `on_login`
- `on_logout`
- `on_timeout`

These callbacks are a major extension point. In many integrations they are provided as JavaScript strings that are evaluated on the client.

### SQL settings

`sql_config` controls database integration.

Expected areas:

- connection source such as `pdo_object` or explicit connection parameters
- `table`
- `fieldset`
- `additional_fields`

This lets the module adapt to an existing user table instead of forcing a fixed schema.

### Email settings

`email_config` defines how auth emails are sent.

The default model is command-driven, but consumers can override sending behavior through `SetEmailProcedure()`.

### Provider settings

`hybridauth_config` enables OAuth providers and supplies:

- provider enable flags
- application keys
- requested scopes
- callback URLs

If `provider_only_auth` is enabled, provider login remains active while built-in password-based flows are blocked in both the backend request handler and the bundled frontend UI.

Optional `provider_profile_image` controls whether AuthHandler also downloads and stores the provider avatar locally during OAuth login.

Supported keys:

- `enabled`: opt-in switch for avatar download and persistence
- `directory`: server filesystem directory where images are stored
- `field`: logical user field to update, default `avatar_path`
- `timeout`: download timeout in seconds

### reCAPTCHA settings

`recaptcha_config` controls registration and reset flow bot protection.

## Database contract

The module works with logical fields rather than hard-coded column names.

Common logical fields:

- `key`
- `user_email`
- `user_password`
- `user_token`
- `user_regkey`
- `user_resetkey`
- `provider_name`
- `provider_id`
- `created_at`
- `last_login`
- `last_update`
- `last_ip`

`additional_fields` acts as both:

- a read contract for `userData`
- and a write whitelist for profile-like updates

That is one of the module's most important safety boundaries.

## Session model

The module uses server-side session state as the main auth store.

Typical session contents include:

- auth token
- user identifier
- activity timestamp
- provider-related storage for HybridAuth
- temporary registration or reset flow values

Persistent login does not mean a token is stored directly in a durable cookie. In the current design it primarily extends session cookie lifetime behavior.

## Public backend methods worth knowing

### Core lifecycle methods

- `__construct($config)`
- `HandleRequest()`
- `AssetsInjector()`
- `Injector()`
- `SetEmailProcedure()`
- `LoadLang()`
- `SetLangData()`

### Auth state methods

- `IsLoggedIn()` or `isLoggedIn()` depending on the public method style used by the integration
- `LoginUser()`
- `LogoutUser()`

### User data methods

- `FindUserByFields()`
- `FindUserByOAuthProvider()`
- `UpdateUserData()`
- `UpdateUserFieldsById()`
- `DeleteUser()`
- `LinkOAuthProvider()`

## Public frontend methods worth knowing

- `init()`
- `setEvent()`
- `setLang()`
- `getText()`
- `setLangData()`
- `sessionTimeout()`
- `sessionExpired()`
- `injectButtons()`
- `login()`
- `logout()`
- `registration()`
- `resetPassword()`
- `changePassword()`
- `setProviderRedirectGetQuery()`
- `showFeedback()`
- `showFormFeedback()`

## Main flows

### Email and password login

Typical backend sequence:

1. validate input
2. find user by email
3. reject disabled, invalid, or unverified states
4. verify password
5. ensure token exists
6. update login metadata
7. log the user into session state
8. return token and user payload

Typical frontend sequence:

1. open login modal
2. submit JSON payload with `ah_action=login`
3. receive success payload
4. update frontend auth state
5. run `on_login`

### Registration and verification

Typical sequence:

1. validate email and password
2. verify reCAPTCHA if configured
3. find or prepare the user row
4. create a registration key
5. send a verification email
6. verify the code through `verify`
7. clear the registration key

Important behavior:

The module can upgrade an existing user record that has no password yet instead of always inserting a brand new row.

### Password reset

The reset flow is split into three actions:

- `reset1`: send the code
- `reset2`: validate the code
- `reset3`: save the new password

The current implementation uses a consumed-code convention in the reset key state rather than a separate state table.

### Password change

This is for authenticated users and requires the current password.

### OAuth login

Typical sequence:

1. validate provider name
2. store optional redirect intent
3. authenticate through HybridAuth
4. fetch provider profile
5. find or create the local user
6. link provider identity
7. log the user in
8. redirect back into the application

Important behavior:

Provider login is a full-page redirect flow, not an AJAX popup flow.

If local avatar storage is enabled, AuthHandler uses the provider profile `photoURL` and writes the saved filesystem path into the configured user field. Provider-side permission to expose that image is still controlled by each provider's configured scopes in `hybridauth_config`.

AuthHandler does not generate a browser-facing URL for the saved file. The consuming project is responsible for deciding how a stored path is exposed or transformed for rendering.

## Frontend UI model

The module builds auth UI dynamically in JavaScript.

Common rendered forms:

- login
- registration
- verification
- reset step 1
- reset step 2
- reset step 3
- change password

In modal mode the class creates Bootstrap-style dialogs at runtime.

## Callback model

The frontend event model centers on:

- `onReady`
- `onLogin`
- `onLogout`
- `onTimeout`

In current usage these are often generated server-side and injected into the browser bootstrap.

This is flexible, but it also means callback behavior is partly defined in project configuration rather than only in source code.

## Extension points

The most important extension points are:

- `SetEmailProcedure()` for branded or custom email sending
- `sql_config.fieldset` for schema adaptation
- `sql_config.additional_fields` for frontend-visible user data
- lifecycle callbacks for project-specific side effects
- `lang_data` or language loading for text customization
- `hybridauth_config` for provider enablement and credentials

## Behaviors that deserve extra caution

### Callback execution model

If callbacks are supplied as strings, the frontend may evaluate them dynamically. Treat that as executable integration logic, not as inert configuration.

### Shared auth and profile payload

`userData` can contain much more than minimal auth state. Consumer apps may rely on it for profile completion, ownership checks, and UI branching.

### Entry-point coupling

The module assumes auth requests will return to a compatible application entrypoint. If the consumer routing model changes, auth handling can break without any direct edit to the module.

### Provider fallback identity data

If an OAuth provider does not return an email, the module may synthesize a fallback email-like value. That is technically useful but business-sensitive.

## Good implementation patterns

- put post-auth UI effects into lifecycle callbacks
- expose needed profile fields through `additional_fields`
- keep project-specific branding in a custom email procedure
- use `setProviderRedirectGetQuery()` for guest-to-protected-workflow flows
- keep auth request handling early in the backend bootstrap

## Anti-patterns

- duplicating auth state in localStorage as a second source of truth
- editing consumer templates without checking callback and routing logic
- exposing arbitrary writable fields without the whitelist boundary
- rewriting provider login as AJAX without redesigning the backend flow
- treating the module as frontend-only or backend-only

## Final summary

AuthHandler is best understood as a reusable auth subsystem with a strong consumer integration surface.

If you are changing behavior, always ask two questions first:

1. Is this change inside the reusable module, the consuming project, or both?
2. Does it affect the auth contract, the frontend state contract, or the workflow contract?

Those two questions usually determine the correct implementation path.

For a minimal generic setup, see the `examples/` directory.