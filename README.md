# Project Overrides

A small WordPress plugin for temporary project CSS.

## What it does

- Edit CSS with WordPress CodeMirror.
- Scope overrides globally, to one page, to a synced pattern, or to a block
  class.
- Insert presets from `theme.json`.
- Track temporary, permanent, and migrated overrides.
- Keep revision history and rollback for every scope.
- Export CSS when it is ready to move into the theme.
- Preview matches, inspect activation diagnostics, and save with `Cmd/Ctrl+S`.

Pattern overrides apply everywhere the selected synced pattern is used.
Block-class overrides are loaded globally, so author them against the selected
class.

## Requirements

- WordPress 6.4+
- PHP 8.0+
- An administrator account

## Install

Copy the plugin to `wp-content/plugins/project-overrides`, activate it, then
open **Project Overrides** in wp-admin.

## BEM autocomplete

In **Project Overrides → Settings**, enter a JSON file relative to the active
theme:

```text
assets/classes.json
```

The file should contain a flat list:

```json
["c-card", "c-card__title", "o-grid"]
```

Classes can also be supplied with the `project_overrides_class_names` filter.

## Development

```bash
composer install
composer lint
composer test
node --check assets/admin.js
```

Build the release ZIP on Windows with:

```powershell
.\tools\build.ps1
```

Plugin data is retained on uninstall.
