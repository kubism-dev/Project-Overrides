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
		$GLOBALS['project_overrides_test_options'] = array();
		$GLOBALS['project_overrides_test_meta']    = array();
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
			'FTP URL'        => array( '.x { background: url(ftp://files.example/image.png); }' ),
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
		self::assertSame( 'migrated', $this->repository->sanitize_status( 'migrated' ) );
	}

	public function test_saving_changed_global_override_creates_restorable_revision(): void {
		$this->repository->save_global( '.old { color: red; }', 'temporary', 0, 'Hotfix' );
		$modified = $this->repository->get_global_modified();
		$this->repository->save_global( '.new { color: blue; }', 'permanent', $modified, 'Final' );

		$revisions = $this->repository->get_global_revisions();
		self::assertCount( 1, $revisions );
		self::assertSame( '.old { color: red; }', $revisions[0]['css'] );
		self::assertSame( 'Hotfix', $revisions[0]['reason'] );

		$this->repository->rollback_global( (string) $revisions[0]['id'] );
		self::assertSame( '.old { color: red; }', $this->repository->get_global_css() );
		self::assertSame( 'Hotfix', $this->repository->get_global_reason() );
	}

	public function test_page_revision_history_is_bounded(): void {
		for ( $index = 0; $index < 23; ++$index ) {
			$this->repository->save_page( 42, ".item { order: {$index}; }", 'temporary', $this->repository->get_page_modified( 42 ) );
		}

		self::assertCount( 20, $this->repository->get_page_revisions( 42 ) );
	}

	public function test_saves_pattern_and_block_class_scopes(): void {
		self::assertTrue( $this->repository->save_scoped_override( 'pattern:42', '.hero { color: red; }', 'temporary', 'Landing hero' ) );
		self::assertTrue( $this->repository->save_scoped_override( 'class:c-card', '.c-card { padding: 1rem; }', 'permanent' ) );

		$overrides = $this->repository->get_scoped_overrides();
		self::assertSame( 'Landing hero', $overrides['pattern:42']['reason'] );
		self::assertSame( 'permanent', $overrides['class:c-card']['status'] );
	}

	public function test_scoped_override_revisions_are_bounded_and_restorable(): void {
		$this->repository->save_scoped_override( 'class:c-card', '.c-card { color: red; }', 'temporary', 'Original' );
		for ( $index = 0; $index < 23; ++$index ) {
			$this->repository->save_scoped_override( 'class:c-card', ".c-card { order: {$index}; }", 'temporary' );
		}

		$revisions = $this->repository->get_scoped_revisions( 'class:c-card' );
		self::assertCount( 20, $revisions );
		$revision = $revisions[ count( $revisions ) - 1 ];
		$this->repository->rollback_scoped( 'class:c-card', (string) $revision['id'] );
		self::assertSame( (string) $revision['css'], $this->repository->get_scoped_override( 'class:c-card' )['css'] );
	}

	public function test_rejects_invalid_scoped_override_keys(): void {
		self::assertInstanceOf(
			WP_Error::class,
			$this->repository->save_scoped_override( 'selector:body', 'body { color: red; }', 'temporary' )
		);
	}
}
