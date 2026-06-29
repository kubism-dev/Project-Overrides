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
		$scoped  = $this->get_applicable_scoped_css( $page_id );

		if ( '' === $global && '' === $page && ! $scoped ) {
			return;
		}

		echo "<style id=\"project-overrides-css\">\n";
		if ( '' !== $global ) {
			echo "/* Project Overrides: Global */\n" . $global . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS sanitized before storage.
		}
		if ( '' !== $page ) {
			echo "/* Project Overrides: Page */\n" . $page . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS sanitized before storage.
		}
		foreach ( $scoped as $scope => $css ) {
			echo '/* Project Overrides: ' . esc_html( $scope ) . " */\n" . $css . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS sanitized before storage.
		}
		echo "</style>\n";
	}

	/**
	 * @return array<string, string>
	 */
	private function get_applicable_scoped_css( int $page_id ): array {
		$applicable = array();
		$content    = $page_id ? (string) get_post_field( 'post_content', $page_id ) : '';
		foreach ( $this->repository->get_scoped_overrides() as $scope => $override ) {
			$css = trim( (string) ( $override['css'] ?? '' ) );
			if ( '' === $css || 'migrated' === ( $override['status'] ?? '' ) ) {
				continue;
			}
			if ( str_starts_with( $scope, 'class:' ) ) {
				$applicable[ $scope ] = $css;
				continue;
			}
			if ( preg_match( '/^pattern:(\d+)$/', $scope, $matches )
				&& preg_match( '/<!--\\s+wp:block\\s+{[^}]*"ref"\\s*:\\s*' . (int) $matches[1] . '\\b/', $content ) ) {
				$applicable[ $scope ] = $css;
			}
		}
		return $applicable;
	}
}
