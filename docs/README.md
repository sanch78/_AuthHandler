# AuthHandler Documentation Set

This directory contains the English documentation set for the AuthHandler module.

These documents are split by task:

- `integration-guide.md`: a generic guide for wiring the module into a PHP application.
- `module-usage-reference.md`: detailed reference for the module architecture, flows, configuration, and extension points.
- `git-user-guide.md`: how to work with the module in Git across common integration layouts.
- `codex-project-usage.md`: how to use this module safely during Codex, Copilot, or similar AI-assisted implementation work.

## Recommended reading order

If you are new to the module:

1. Read `integration-guide.md` first.
2. Read `module-usage-reference.md` second.
3. Read `git-user-guide.md` before making changes.
4. Read `codex-project-usage.md` before asking an AI agent to edit code.
5. Inspect the `examples/` directory for a minimal working structure.

## Coverage goals

This set is designed to cover the documentation goals that matter in practice:

- Git-oriented user guidance.
- Safe use during Codex-style project work.
- Detailed explanation of how the module works.
- A reusable integration guide that is not tied to one host or one consumer project.
- Concrete example files that show a minimal setup.

## Existing documentation

The top-level `README.md` in this repository is a Hungarian deep-dive. The files in this `docs/` directory are the English working set intended for general engineering use.