# AuthHandler Integration Guide

## Scope

This document explains how to wire AuthHandler into a PHP application in a reusable way.

It is intentionally generic. It does not assume a specific host, project name, or deployment layout.

## Integration overview

At a high level, a consumer application needs to do four things:

1. make the module files available under a public and server-readable path
2. build an `auth_config` array
3. instantiate `AuthHandler` and handle auth requests early
4. inject the frontend assets and bootstrap payload into the rendered page

## Suggested directory layout

One simple layout is:

```text
<project-root>/
  public/
    lib/
      _AuthHandler/
  src/
    bootstrap/
    templates/
```

This is only an example. AuthHandler does not require these exact folder names.

The important part is that:

- PHP can require `includes/AuthHandler.php`
- the browser can load `AuthHandler.js`, `AuthHandler.css`, and `lang.json`
- `module_path` points to the public URL path of the module assets

## Backend bootstrap pattern

The backend bootstrap usually looks like this:

1. create or load database connection
2. build `auth_config`
3. require `includes/AuthHandler.php`
4. instantiate `new AuthHandler($auth_config)`
5. optionally call `SetEmailProcedure(...)`
6. call `HandleRequest()` before rendering the page body
7. read `isLoggedIn()` and `userData` where needed

Important rule:

If your application needs strict control over boot order, disable constructor-level auto handling and call `HandleRequest()` explicitly.

## Frontend bootstrap pattern

During page render, inject:

- `AssetsInjector()` into the page head or asset region
- `Injector()` after the module and application JavaScript dependencies are available

This creates the browser-side auth object and passes configuration, language data, token state, and current `userData` into the frontend.

## Minimum configuration areas

Your `auth_config` should normally define:

- site identity values such as `site_name`, `site_url`, and `site_script`
- `module_path`
- session behavior
- registration and provider flags
- `provider_only_auth` if only OAuth/provider sign-in should be allowed
- password policy
- SQL mapping in `sql_config`
- email sending in `email_config`
- provider settings in `hybridauth_config` if OAuth is enabled
- optional provider avatar storage in `provider_profile_image` if local profile-image saving is desired
- reCAPTCHA settings in `recaptcha_config` if bot protection is enabled

## SQL mapping pattern

AuthHandler uses logical field names rather than fixed database column names.

That means your integration must map logical names such as:

- `user_email`
- `user_password`
- `user_token`
- `user_regkey`
- `user_resetkey`
- `provider_name`
- `provider_id`

to your actual user table columns.

If the frontend needs extra user profile fields, add them to `sql_config.additional_fields`.

If you want AuthHandler to persist provider profile images locally, expose a destination field such as `avatar_path` through `sql_config.additional_fields`, then configure `provider_profile_image.enabled` and `provider_profile_image.directory`.

AuthHandler only stores the filesystem path. Rendering that image for a user is intentionally left to the consuming project, which can map the saved path to a public URL, image controller, CDN path, or any other project-specific delivery mechanism.

If you enable `provider_only_auth`, make sure at least one provider is enabled in `hybridauth_config.providers`, because the built-in email/password flows will be unavailable.

## Email integration pattern

There are two common ways to integrate email sending:

### Direct command-based sending

Use `email_config` when the module can send auth emails directly.

### Project-owned email procedure

Use `SetEmailProcedure()` when the application needs to control branding, templating, or delivery.

This is often the better option in real projects.

## Frontend usage pattern

Once bootstrapped, the browser-side object typically supports patterns such as:

- `authHandler.login()`
- `authHandler.registration()`
- `authHandler.logout()`
- `authHandler.resetPassword()`
- `authHandler.changePassword()`
- `authHandler.setProviderRedirectGetQuery(...)`

Applications typically use callback hooks such as:

- `on_ready`
- `on_login`
- `on_logout`
- `on_timeout`

to update navigation, reroute users, or refresh protected UI.

## Protected workflow pattern

If an application has routes that require login, the usual flow is:

1. detect guest access on the backend or frontend
2. redirect to a public route or open login
3. optionally remember the intended destination
4. continue to the protected workflow after successful auth

If OAuth is enabled, `setProviderRedirectGetQuery(...)` is the usual mechanism for preserving intent across provider redirects.

## Session timeout pattern

Session timeout is primarily a backend concern, but the JavaScript class also supports timeout UX.

Typical behavior:

- server-side session expires or is considered inactive
- frontend timeout flow resets local auth state
- application callback decides whether to reopen login or redirect elsewhere

## Integration checklist

Use this checklist when integrating the module into a new project:

- Module files are available to PHP and to the browser.
- `module_path` points to the public asset path.
- The database mapping matches the real user table.
- `HandleRequest()` runs early enough.
- `AssetsInjector()` and `Injector()` are rendered on pages that need auth UI.
- Provider callback URLs point back to a compatible entrypoint.
- `additional_fields` includes all frontend-required user fields.
- Login, logout, registration, verification, and reset were tested.

## Common integration mistakes

### Mistake: wrong `module_path`

If the module assets are reachable by PHP but not by the browser, the backend may work while the frontend bootstrap fails.

### Mistake: incomplete field mapping

If required user fields are missing from `fieldset` or `additional_fields`, the app may appear logged in while parts of the UI still break.

### Mistake: calling `HandleRequest()` too late

Auth request routing should happen before normal page rendering starts.

### Mistake: forgetting callback dependencies

If `on_login`, `on_logout`, or `on_timeout` call app-specific functions, those functions must exist before the callback runs.

## Examples

See the `examples/` directory for a minimal generic integration layout with example config, bootstrap, and page render files.