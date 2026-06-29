=== Project Overrides ===
Contributors: projectoverrides
Tags: css, developer, gutenberg, theme, overrides
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused developer utility for temporary global and page-specific CSS overrides.

== Description ==

Project Overrides gives WordPress agencies a controlled place for project CSS
that is not ready to migrate into a custom theme. It provides:

* Global and per-page CSS using the native WordPress CodeMirror editor.
* Temporary and permanent status tracking.
* A dashboard reminder for temporary overrides.
* A combined copyable and downloadable CSS export.
* Click-to-insert theme.json preset variables.
* Optional BEM class autocomplete from a theme JSON file or PHP filter.
* Revision history with rollback.
* Reason and handoff notes.
* Selective migration export and inactive migrated status.

Only administrators can edit overrides. The plugin does not execute PHP or
JavaScript. Context-breaking markup and legacy executable CSS constructs are
rejected with an error while preserving the submitted draft.

Existing overrides keep their latest 20 revisions. A restored revision also
preserves the version it replaces. Migrated overrides remain available for
review and rollback but are not output on the front end.

== BEM autocomplete ==

In Settings, enter a JSON file path relative to the active theme, such as
`assets/classes.json`. The file should be a flat array:

`["c-card", "c-card__title", "o-grid"]`

Classes can alternatively be supplied in PHP:

`add_filter( 'project_overrides_class_names', fn() => array( 'c-card', 'o-grid' ) );`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate Project Overrides.
3. Open Project Overrides in the WordPress administration menu.

== Development ==

Run `composer lint` and `composer test` before packaging. On Windows,
`tools/build.ps1` creates `dist/project-overrides.zip` with production files
only.

== Changelog ==

= 1.2.0 =
* Added global, page, synced-pattern, and block-class editor scopes.
* Added a dedicated Gutenberg Save CSS action.
* Removed ticket URL fields.

= 1.1.2 =
* Added a dark CodeMirror theme and improved editor spacing.

= 1.1.1 =
* Fixed per-page CSS persistence in the block editor.
* Fixed loading presets from resolved theme.json data.

= 1.1.0 =
* Added revision history, rollback, change metadata, and migration workflow.
* Hardened permissions, destructive actions, and remote CSS validation.

= 1.0.0 =
* Initial release.
