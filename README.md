# Project Overrides

A lightweight WordPress developer utility for managing temporary,
project-specific CSS overrides.

Project Overrides fills the gap between rebuilding a local SCSS/BEM theme and
putting difficult-to-track CSS inside Gutenberg HTML blocks. It is intended for
agencies and developers working with custom Gutenberg themes.

It is not a page builder or visual CSS editor.

> [!IMPORTANT]
> Project Overrides is currently a proof of concept. It demonstrates the
> intended workflow and architecture, but should be thoroughly tested in a
> staging environment before use on a production site.

## Features

- Global CSS loaded in the document `<head>`.
- Page-specific CSS loaded only on the relevant page.
- Native WordPress CodeMirror editing.
- Temporary and permanent override statuses.
- Dashboard reminder for temporary overrides.
- Searchable and filterable override inventory.
- Combined CSS export with copy and download actions.
- Click-to-insert presets from the active theme's `theme.json`.
- Optional `c-` and `o-` BEM class autocomplete.
- Stale-edit protection when multiple administrators edit an override.
- Administrator-only access with nonce-protected writes.

## Requirements

- WordPress 6.4 or newer
- PHP 8.0 or newer
- An administrator account

No ACF, page builder, or third-party runtime dependency is required.

## Installation

### Packaged plugin

1. Download `project-overrides.zip` from a release.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload and activate the ZIP.
4. Open **Project Overrides** in the administration menu.

### From source

Copy or clone the repository to:

```text
wp-content/plugins/project-overrides
```

Then activate **Project Overrides** from the WordPress Plugins screen.

## Usage

### Global CSS

Open **Project Overrides → CSS** and enter CSS in the Global CSS editor. Global
CSS is stored in the `project_overrides_global_css` option and loaded on every
public front-end page.

### Page CSS

Select a page on the CSS screen or use the **Project Overrides CSS** meta box
when editing a page. Page CSS is stored as post metadata and is emitted only
when that page is requested.

Emptying a page override removes its associated CSS, status, and modification
metadata.

### Statuses

Every non-empty override is either:

- **Temporary** — expected to be migrated back into the theme.
- **Permanent** — intentionally retained by the plugin.

The WordPress dashboard displays the number of temporary overrides that still
need attention.

### Export

**Project Overrides → Export** creates one migration-ready CSS document.
Page-specific sections retain their authored CSS and include a comment such as:

```css
/* Contact */
/* Page scope: body.page-id-18 */

.c-contact {
	display: grid;
}
```

Scope is represented as metadata rather than wrapping arbitrary CSS. This keeps
existing selectors and at-rules syntactically valid.

## Theme tokens

The sidebar reads color, spacing, font-size, and font-family presets from the
resolved `theme.json` data.

Clicking a token inserts its WordPress custom property into the active editor:

```css
var(--wp--preset--color--primary)
```

## BEM autocomplete

Autocomplete is suggestion-only; it never generates or changes class names.

In **Project Overrides → Settings**, configure a JSON file relative to the
active theme:

```text
assets/classes.json
```

The file must contain a flat array of `c-` or `o-` class names:

```json
[
	"c-card",
	"c-card__title",
	"c-card--featured",
	"o-grid"
]
```

Class names can alternatively be supplied from PHP:

```php
add_filter(
	'project_overrides_class_names',
	static function ( array $classes ): array {
		return array_merge(
			$classes,
			array(
				'c-card',
				'c-card__title',
				'o-grid',
			)
		);
	}
);
```

Configured JSON paths are resolved inside the active theme directory.
Directory traversal outside that directory is rejected.

## Security

- Editing requires the `manage_options` capability.
- Form submissions and destructive actions use WordPress nonces.
- Output is escaped according to its context.
- CSS is normalized and validated before storage.
- HTML context breakouts, PHP tags, JavaScript URLs, and legacy executable CSS
  constructs are rejected.
- Unclosed strings, comments, and CSS blocks are rejected.
- No PHP or JavaScript override execution is supported.
- Rejected CSS is preserved temporarily so it can be corrected.

Administrators can still write CSS capable of making external network requests,
such as `url()` and `@import`. This is intentional for a developer CSS tool.

## Data storage

| Data | WordPress location |
| --- | --- |
| Global CSS | `project_overrides_global_css` option |
| Global status | `project_overrides_global_status` option |
| Page CSS | `_project_overrides_css` post meta |
| Page status | `_project_overrides_status` post meta |
| Modification timestamps | Option or post meta |
| BEM JSON path | `project_overrides_class_file` option |

Plugin data is retained during uninstall to avoid destroying project CSS.
Delete individual overrides from the inventory if they are no longer needed.

## Architecture

```text
project-overrides.php   Plugin bootstrap
src/
  Admin.php             Admin screens, actions, meta box, and dashboard widget
  ClassNames.php        BEM JSON and filter provider
  Exporter.php          CSS export formatting
  Frontend.php          Conditional front-end output
  Plugin.php            Service composition
  Repository.php        Validation and WordPress persistence
  ThemeTokens.php       Resolved theme.json preset reader
assets/
  admin.css             Admin interface styles
  admin.js              CodeMirror and interface behavior
tests/                  Isolated PHPUnit tests
tools/build.ps1         Production ZIP builder
```

The persistence, export, token, and interface responsibilities are separated so
future features can be added without placing them in the MVP. JavaScript
overrides, revision history, JSON import/export, pattern-specific overrides,
Monaco, and theme synchronization are intentionally not implemented.

## Development

Install development dependencies:

```bash
composer install
```

Run WordPress Coding Standards:

```bash
composer lint
```

Run the unit tests:

```bash
composer test
```

Check the administration JavaScript:

```bash
node --check assets/admin.js
```

GitHub Actions runs PHPCS, PHPUnit, and the JavaScript syntax check against PHP
8.0 and PHP 8.3.

## Building a release

From PowerShell:

```powershell
.\tools\build.ps1
```

The production archive is written to:

```text
dist/project-overrides.zip
```

Development dependencies, tests, CI configuration, and local tooling are not
included in the ZIP.

## License

Project Overrides is licensed under the
[GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
