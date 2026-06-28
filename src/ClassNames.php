<?php
/**
 * BEM class name provider.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides;

final class ClassNames {
	public const OPTION = 'project_overrides_class_file';

	/**
	 * @return string[]
	 */
	public function get(): array {
		$classes = array();
		$path    = (string) get_option( self::OPTION, '' );

		if ( '' !== $path ) {
			$theme_dir = realpath( get_stylesheet_directory() );
			$file      = realpath( trailingslashit( get_stylesheet_directory() ) . ltrim( $path, '/\\' ) );

			if ( false !== $theme_dir && false !== $file && str_starts_with( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $theme_dir ) ) ) && is_readable( $file ) && 'json' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
				$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validated local JSON file.
				if ( is_array( $decoded ) ) {
					$classes = $decoded;
				}
			}
		}

		/**
		 * Filter autocomplete class names.
		 *
		 * @param array $classes Configured class names.
		 */
		$classes = apply_filters( 'project_overrides_class_names', $classes );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $class_name ): string {
							return ltrim( sanitize_html_class( (string) $class_name ), '.' );
						},
						is_array( $classes ) ? $classes : array()
					),
					static fn( string $class_name ): bool => str_starts_with( $class_name, 'c-' ) || str_starts_with( $class_name, 'o-' )
				)
			)
		);
	}
}
