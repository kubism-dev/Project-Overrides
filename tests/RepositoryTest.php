<?php
/**
 * Repository unit tests.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides\Tests;

use PHPUnit\Framework\TestCase;
use ProjectOverrides\Repository;
use WP_Error;

final class RepositoryTest extends TestCase {
	private Repository $repository;

	protected function setUp(): void {
		$this->repository = new Repository();
	}

	public function test_normalizes_line_endings_without_mutating_css(): void {
		$css = ".c-card {\r\n\tcolor: red;\r}\n";

		self::assertSame( ".c-card {\n\tcolor: red;\n}\n", $this->repository->sanitize_css( $css ) );
	}

	/**
	 * @dataProvider unsafe_css_provider
	 */
	public function test_rejects_executable_or_context_breaking_input( string $css ): void {
		self::assertInstanceOf( WP_Error::class, $this->repository->validate_css( $css ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafe_css_provider(): array {
		return array(
			'style breakout' => array( 'body { color:red; }</style>' ),
			'script tag'     => array( '<script>alert(1)</script>' ),
			'javascript URL' => array( 'a { background: url(javascript:alert(1)); }' ),
			'old expression' => array( 'p { width: expression(alert(1)); }' ),
			'old behavior'   => array( '.x { behavior: url(test.htc); }' ),
			'php tag'        => array( '<?php echo "no"; ?>' ),
			'remote URL'     => array( '.x { background: url("https://tracker.example/pixel"); }' ),
			'protocol URL'   => array( '.x { background: url(//tracker.example/pixel); }' ),
			'remote import'  => array( '@import "https://tracker.example/styles.css";' ),
		);
	}

	public function test_accepts_normal_css_including_at_rules_and_custom_properties(): void {
		$css = '@media (min-width: 48rem) { .c-card { color: var(--wp--preset--color--primary); } }';

		self::assertTrue( $this->repository->validate_css( $css ) );
	}

	public function test_rejects_unbalanced_css(): void {
		self::assertInstanceOf( WP_Error::class, $this->repository->validate_css( '.c-card { color: red;' ) );
		self::assertInstanceOf( WP_Error::class, $this->repository->validate_css( '/* unfinished' ) );
	}

	public function test_status_is_restricted_to_known_values(): void {
		self::assertSame( 'temporary', $this->repository->sanitize_status( 'unexpected' ) );
		self::assertSame( 'permanent', $this->repository->sanitize_status( 'permanent' ) );
	}
}
