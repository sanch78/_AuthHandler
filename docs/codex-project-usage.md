# Using AuthHandler During Codex Projects

## Purpose

This guide is for AI-assisted implementation work, including Codex, Copilot, and similar coding agents.

The goal is simple: avoid shallow edits that only patch the visible symptom while missing the actual integration boundary.

## Mental model

AuthHandler is not just a login helper.

In a typical consumer application it acts as:

- backend auth request router,
- session bootstrapper,
- SQL adapter to the user table,
- OAuth integration point,
- frontend auth state bootstrap,
- modal-based auth UI controller,
- and workflow gate for user-only features.

Any AI task that touches login state, user profile bootstrap, auth buttons, protected workflows, or provider login should assume AuthHandler is involved until proven otherwise.

## Start from the right files

When investigating auth behavior in a consumer project, start from the consumer entrypoint and then move into the module.

A good generic order is:

1. the application's bootstrap file that builds `auth_config`
2. the file that instantiates `new AuthHandler(...)`
3. the file that calls `HandleRequest()`
4. the layout or template that injects assets and the frontend bootstrap
5. the consumer-side JavaScript that reacts to auth state
6. `includes/AuthHandler.php`
7. `AuthHandler.js`

This order matters because it follows the actual control path from bootstrap to UI.

## What to inspect for common task types

### Login, logout, or timeout behavior

Inspect:

- `auth_config` callback strings in the consumer bootstrap
- explicit `HandleRequest()` call in the backend flow
- frontend callback consumers in the application JavaScript
- template buttons that call `authHandler.login()` or `authHandler.logout()`

### Registration, verification, or password reset

Inspect:

- `recaptcha_config`
- `email_config`
- `SetEmailProcedure()` usage
- `ApiRegister`, `ApiVerify`, `ApiReset1`, `ApiReset2`, `ApiReset3`
- mail templates in `includes/templates/`

### OAuth provider issues

Inspect:

- `hybridauth_config` in the consumer project
- provider callback URLs
- `HandleOAuthLogin()` in the PHP module
- any use of `setProviderRedirectGetQuery()` in the frontend

### Missing frontend user fields

Inspect:

- `sql_config.additional_fields`
- the module's user-loading and serialization logic
- consumer code that reads `authHandler.userData`
- any project workflow gating that depends on those fields

### Auth-gated workflows

Inspect:

- the main routed entrypoint or controller
- project workflows that require authenticated users
- `data-auth` markers in templates
- the frontend menu visibility logic

## Rules for safe AI edits

### Rule 1: do not stop at the template

If a button opens login or registration, that button is not the behavior. The behavior usually lives in callback config, request routing, or module methods.

### Rule 2: treat `auth_config` as executable integration code

In this setup, callback strings are part of the application logic, not just configuration metadata.

### Rule 3: verify the data contract whenever `userData` is involved

If a project screen expects a field in `authHandler.userData`, the corresponding field must usually be present in `sql_config.additional_fields`.

### Rule 4: distinguish module changes from consumer changes

Before editing, decide whether the fix belongs to:

- the reusable module,
- the consumer project,
- or both.

### Rule 5: validate the narrowest possible auth flow immediately after the first real edit

Good validation targets are:

- login flow,
- logout flow,
- password reset flow,
- provider login redirect,
- protected workflow access,
- profile completion redirect.

## Prompt patterns that work well

### Good prompt: integration-aware

`Trace why logged-out users do not return to the intended protected workflow after Google login. Inspect the AuthHandler bootstrap, callback wiring, template calls to setProviderRedirectGetQuery(), and the module's OAuth redirect handling. Fix at the root cause and verify the redirect.`

### Good prompt: contract-aware

`Expose a new user profile field to the frontend through AuthHandler. Update the whitelist, confirm where userData is populated, and wire the consuming screen in the application.`

### Bad prompt: surface-only

`Make the login button work.`

That prompt is too vague and encourages shallow fixes.

## What an AI task should return

A good auth-related Codex task should report:

- which repository was changed,
- which control path was traced,
- which request or callback was responsible,
- what validation was run,
- and any remaining integration risks.

## Manual validation checklist for AI-generated auth changes

Run this checklist even if the code change looks small:

- Open the page while logged out.
- Trigger login from the target UI element.
- Confirm the expected modal or redirect appears.
- Complete the flow or inspect the request payload.
- Confirm post-login UI updates happen.
- Confirm protected workflow access behaves correctly.
- Confirm logout returns the UI to the logged-out state.

If the task touches registration or reset:

- verify reCAPTCHA still renders,
- verify email flow still starts,
- and verify step-to-step transitions still work.

If the task touches OAuth:

- verify callback URLs,
- verify redirect query persistence,
- and verify the final landing workflow.

## When to open the module docs immediately

Read `module-usage-reference.md` before editing if the task touches:

- request actions,
- callback execution,
- `additional_fields`,
- email hooks,
- provider login,
- session timeout,
- or injected assets.

Read `integration-guide.md` before editing if the task touches:

- menu visibility,
- profile workflows,
- protected workflow routing,
- route-aware redirects,
- or app-specific auth callbacks.