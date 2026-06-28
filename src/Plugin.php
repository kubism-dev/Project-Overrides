<?php
/**
 * Main plugin orchestrator.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides;

final class Plugin {
	private static ?Plugin $instance = null;

	private function __construct() {}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		$repository = new Repository();

		( new Admin( $repository, new ThemeTokens(), new ClassNames(), new Exporter() ) )->register();
		( new Frontend( $repository ) )->register();
	}
}
