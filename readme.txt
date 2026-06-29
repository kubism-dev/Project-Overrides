=== Project Overrides ===
Contributors: projectoverrides
Tags: css, developer, gutenberg, theme, overrides
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused developer utility for temporary global and page-specific CSS overrides.

== Description ==

Project Overrides is a small place for CSS that is not ready to move into the
theme:

* Global, page, synced-pattern, and block-class scopes.
* Native WordPress CodeMirror editing.
* theme.json presets and optional BEM class autocomplete.
* Status tracking, revisions, and CSS export.

Only administrators can edit overrides.

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

= 1.3.0 =
* Completed revision, inventory, export, migration, and deletion support for every scope.
* Added Gutenberg focus/save tools, previews, class discovery, and diagnostics.

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
