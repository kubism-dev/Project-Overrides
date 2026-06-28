<?php
/**
 * Front-end CSS output.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides;

final class Frontend {
	public function __construct( private Repository $repository ) {}

	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_css' ), 99 );
	}

	public function output_css(): void {
		$global  = 'migrated' === $this->repository->get_global_status() ? '' : trim( $this->repository->get_global_css() );
		$page_id = is_page() ? (int) get_queried_object_id() : 0;
		$page    = $page_id && 'migrated' !== $this->repository->get_page_status( $page_id ) ? trim( $this->repository->get_page_css( $page_id ) ) : '';

		if ( '' === $global && '' === $page ) {
			return;
		}

		echo "<style id=\"project-overrides-css\">\n";
		if ( '' !== $global ) {
			echo "/* Project Overrides: Global */\n" . $global . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS sanitized before storage.
		}
		if ( '' !== $page ) {
			echo "/* Project Overrides: Page */\n" . $page . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS sanitized before storage.
		}
		echo "</style>\n";
	}
}
