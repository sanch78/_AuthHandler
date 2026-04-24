# AuthHandler Git User Guide

## Purpose

This guide explains how to manage AuthHandler in Git without assuming any specific host, directory structure, or consumer project.

AuthHandler may be integrated in several ways:

- copied directly into an application repository
- kept as a separate repository and linked locally during development
- added as a Git submodule
- imported through subtree or vendor-sync workflows

The workflow depends on that choice.

## The most important rule

Always decide first where the source of truth lives.

That is the single most important Git rule for this module.

If AuthHandler is maintained as a standalone repository, changes to module files belong in that repository even if your application references the files through a link or mirrored directory.

## Typical integration layouts

### Layout 1: standalone module repository

Use this when AuthHandler is shared across multiple applications.

Practical consequence:

- module source changes are committed in the AuthHandler repository
- application wiring changes are committed in the consumer repository

### Layout 2: copied into one application repository

Use this when AuthHandler is project-local and not reused elsewhere.

Practical consequence:

- both module changes and application wiring changes are committed in the same repository

### Layout 3: submodule or subtree

Use this when you want clearer dependency boundaries but still keep reproducible revision control.

Practical consequence:

- module history remains distinct
- the application repository records which module revision it consumes

## What belongs to the module

Commit to the module source of truth when you change:

- `AuthHandler.js`
- `AuthHandler.css`
- `lang.json`
- `includes/AuthHandler.php`
- templates inside `includes/templates/`
- module documentation
- example integrations that describe the module itself

## What belongs to the consumer application

Commit to the consuming application when you change:

- application bootstrap code
- `auth_config` values
- application templates that trigger auth actions
- application callbacks such as `on_login` or `on_logout`
- application routing around protected workflows
- tenant or environment-specific provider credentials

## When a change belongs to both

Commit to both places when a change crosses the module boundary, for example:

- a new `additional_fields` key is exposed by the module and then consumed by the app
- a new callback behavior is added in the module and configured in the app
- a provider or auth flow change requires both module code and consumer configuration

## Recommended workflow

### Module-only change

Use this when the behavior change is fully internal to AuthHandler.

1. Open the module source of truth.
2. Make the code change there.
3. Validate the narrowest affected auth flow in at least one consuming application.
4. Commit in the module source of truth.

### Consumer-only change

Use this when AuthHandler already supports the needed behavior and only app wiring changes.

Examples:

- changing `buttons_target`
- changing callback strings
- adding or removing fields from `auth_config`
- moving login or logout triggers in templates

Workflow:

1. Edit the consumer application.
2. Validate the affected workflow there.
3. Commit in the consumer repository.

### Cross-boundary change

Use this when the module contract and application usage both change.

Workflow:

1. Change the module first.
2. Validate the module immediately against a real consumer.
3. Adjust consumer wiring only where required.
4. Commit the module change.
5. Commit the consumer change.
6. Reference the paired commit or branch in both change descriptions when useful.

## Day-to-day checks

Before starting work, answer these questions:

1. Am I changing module code, consumer code, or both?
2. Will this change affect `userData`, callbacks, routing, or request payloads?
3. Which repository should contain the final commit?

Before committing, inspect the relevant working tree directly instead of assuming a linked path will show up in the application repository.

## Common mistakes

### Mistake: assuming a linked module path is tracked by the app repository

It may not be. Symlinks, ignored vendor paths, and external working copies can hide where the real diff lives.

### Mistake: editing only the application after changing a module contract

If the module changes how `userData`, callbacks, redirects, or response payloads behave, you still need to validate the consumer.

### Mistake: reviewing only one repository

For cross-boundary auth work, review the module diff and the consumer diff separately.

### Mistake: keeping module docs in the wrong repository

If the documentation explains reusable module behavior, it usually belongs with the module.

## Suggested commit style

Good module commit subjects:

- `Normalize reset flow feedback handling`
- `Preserve redirect query during OAuth callback`
- `Document generic integration patterns and examples`

Good consumer commit subjects:

- `Wire logout callback to public landing route`
- `Expose profile completion fields through auth config`
- `Move auth buttons into header target`

## Review checklist

Use this checklist before merging auth-related work:

- The correct repository contains the code change.
- At least one real consumer was checked for integration impact.
- Login still works.
- Logout still works.
- Registration still works.
- Password reset still works.
- OAuth provider login still works if touched.
- Protected workflows still redirect correctly.
- `userData` consumers still receive the fields they expect.
- Documentation was updated if the module contract changed.

## When releases matter

If AuthHandler is consumed by more than one project, treat it as a reusable dependency rather than an informal shared folder.

That means:

- document breaking changes clearly
- prefer small, reviewable module commits
- and consider version tags or release notes when multiple consumers depend on the same repo

This is especially important for changes to:

- request actions
- JSON response structure
- `userData` shape
- callback semantics
- provider configuration requirements