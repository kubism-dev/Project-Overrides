<?php
/**
 * Theme token reader.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides;

final class ThemeTokens {
	/**
	 * Request-local token cache.
	 *
	 * @var array<string, array<int, array{label:string, value:string}>>|null
	 */
	private ?array $cache = null;

	/**
	 * Read preset slugs from the resolved theme.json data.
	 *
	 * @return array<string, array<int, array{label:string, value:string}>>
	 */
	public function get(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$tokens = array(
			'colors'     => array(),
			'spacing'    => array(),
			'typography' => array(),
		);

		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			$this->cache = $tokens;
			return $this->cache;
		}

		$data     = \WP_Theme_JSON_Resolver::get_merged_data()->get_data();
		$settings = $data['settings'] ?? array();

		$this->append_presets( $tokens['colors'], $settings['color']['palette'] ?? array(), 'color' );
		$this->append_presets( $tokens['spacing'], $settings['spacing']['spacingSizes'] ?? array(), 'spacing' );
		$this->append_presets( $tokens['typography'], $settings['typography']['fontSizes'] ?? array(), 'font-size' );
		$this->append_presets( $tokens['typography'], $settings['typography']['fontFamilies'] ?? array(), 'font-family' );

		$this->cache = $tokens;
		return $this->cache;
	}

	/**
	 * @param array<int, array{label:string, value:string}> $target Destination.
	 * @param mixed                                         $presets Preset data.
	 */
	private function append_presets( array &$target, $presets, string $type ): void {
		if ( ! is_array( $presets ) ) {
			return;
		}

		foreach ( $presets as $preset ) {
			if ( empty( $preset['slug'] ) ) {
				continue;
			}

			$slug     = sanitize_title( (string) $preset['slug'] );
			$target[] = array(
				'label' => (string) ( $preset['name'] ?? $slug ),
				'value' => sprintf( 'var(--wp--preset--%s--%s)', $type, $slug ),
			);
		}
	}
}
