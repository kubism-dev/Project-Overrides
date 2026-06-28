<?php
/**
 * BEM class provider tests.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides\Tests;

use PHPUnit\Framework\TestCase;
use ProjectOverrides\ClassNames;

final class ClassNamesTest extends TestCase {
	private string $base_directory;
	private string $theme_directory;

	protected function setUp(): void {
		$this->base_directory  = sys_get_temp_dir() . '/project-overrides-' . bin2hex( random_bytes( 6 ) );
		$this->theme_directory = $this->base_directory . '/theme';
		mkdir( $this->theme_directory, 0777, true );
		$GLOBALS['project_overrides_test_theme_dir'] = $this->theme_directory;
		$GLOBALS['project_overrides_test_options']   = array();
	}

	protected function tearDown(): void {
		$files = glob( $this->base_directory . '/*' ) ?: array();
		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				$nested = glob( $file . '/*' ) ?: array();
				foreach ( $nested as $nested_file ) {
					unlink( $nested_file );
				}
				rmdir( $file );
			} else {
				unlink( $file );
			}
		}
		rmdir( $this->base_directory );
	}

	public function test_reads_and_filters_a_theme_json_file(): void {
		file_put_contents( $this->theme_directory . '/classes.json', '["c-card",".o-grid","utility","c-card"]' );
		$GLOBALS['project_overrides_test_options'][ ClassNames::OPTION ] = 'classes.json';

		self::assertSame( array( 'c-card', 'o-grid' ), ( new ClassNames() )->get() );
	}

	public function test_refuses_directory_traversal_outside_the_theme(): void {
		file_put_contents( $this->base_directory . '/classes.json', '["c-private"]' );
		$GLOBALS['project_overrides_test_options'][ ClassNames::OPTION ] = '../classes.json';

		self::assertSame( array(), ( new ClassNames() )->get() );
	}
}
