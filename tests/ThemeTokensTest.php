<?php
/**
 * Theme token reader tests.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		final class WP_Theme_JSON_Resolver {
			/**
			 * @var array<string, mixed>
			 */
			public static array $data = array();

			public static function get_merged_data(): object {
				return new class() {
					/**
					 * @return array<string, mixed>
					 */
					public function get_data(): array {
						return WP_Theme_JSON_Resolver::$data;
					}
				};
			}
		}
	}
}

namespace ProjectOverrides\Tests {
	use PHPUnit\Framework\TestCase;
	use ProjectOverrides\ThemeTokens;

	final class ThemeTokensTest extends TestCase {
		protected function setUp(): void {
			\WP_Theme_JSON_Resolver::$data = array(
				'settings' => array(
					'color'      => array(
						'palette' => array(
							array(
								'name'  => 'Brand Blue',
								'slug'  => 'brand-blue',
								'color' => '#006eff',
							),
						),
					),
					'spacing'    => array(
						'spacingSizes' => array(
							array(
								'name' => 'Large',
								'slug' => 'large',
								'size' => '2rem',
							),
						),
					),
					'typography' => array(
						'fontSizes'    => array(
							array(
								'name' => 'Heading',
								'slug' => 'heading',
								'size' => '2rem',
							),
						),
						'fontFamilies' => array(
							array(
								'name'       => 'Display',
								'slug'       => 'display',
								'fontFamily' => 'sans-serif',
							),
						),
					),
				),
			);
		}

		public function test_reads_flattened_presets_from_resolved_theme_json_data(): void {
			$tokens = ( new ThemeTokens() )->get();

			self::assertSame(
				array(
					array(
						'label' => 'Brand Blue',
						'value' => 'var(--wp--preset--color--brand-blue)',
					),
				),
				$tokens['colors']
			);
			self::assertSame( 'var(--wp--preset--spacing--large)', $tokens['spacing'][0]['value'] );
			self::assertSame( 'var(--wp--preset--font-size--heading)', $tokens['typography'][0]['value'] );
			self::assertSame( 'var(--wp--preset--font-family--display)', $tokens['typography'][1]['value'] );
		}
	}
}
