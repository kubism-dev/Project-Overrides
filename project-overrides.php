<?php
/**
 * Plugin Name:       Project Overrides
 * Description:       Developer-focused, temporary CSS overrides for custom Gutenberg themes.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Project Overrides
 * License:           GPL-2.0-or-later
 * Text Domain:       project-overrides
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PROJECT_OVERRIDES_VERSION', '1.1.0' );
define( 'PROJECT_OVERRIDES_FILE', __FILE__ );
define( 'PROJECT_OVERRIDES_PATH', plugin_dir_path( __FILE__ ) );
define( 'PROJECT_OVERRIDES_URL', plugin_dir_url( __FILE__ ) );

require_once PROJECT_OVERRIDES_PATH . 'src/Plugin.php';
require_once PROJECT_OVERRIDES_PATH . 'src/Repository.php';
require_once PROJECT_OVERRIDES_PATH . 'src/ThemeTokens.php';
require_once PROJECT_OVERRIDES_PATH . 'src/ClassNames.php';
require_once PROJECT_OVERRIDES_PATH . 'src/Exporter.php';
require_once PROJECT_OVERRIDES_PATH . 'src/Admin.php';
require_once PROJECT_OVERRIDES_PATH . 'src/Frontend.php';

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
