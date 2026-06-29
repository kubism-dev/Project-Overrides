<?php
/**
 * CSS export tests.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides\Tests;

use PHPUnit\Framework\TestCase;
use ProjectOverrides\Exporter;

final class ExporterTest extends TestCase {
	public function test_preserves_authored_css_and_adds_scope_metadata(): void {
		$export = ( new Exporter() )->build(
			':root { --gap: 1rem; }',
			array(
				array(
					'id'       => 18,
					'title'    => 'Contact',
					'css'      => "@media (min-width: 40rem) {\n\t.c-form { display: grid; }\n}",
					'status'   => 'temporary',
					'reason'   => 'Marketing landing page tweak',
					'modified' => 1767225600,
				),
			),
			array(
				array(
					'label'  => 'Pattern: Hero',
					'scope'  => 'pattern:42',
					'status' => 'permanent',
					'css'    => '.c-hero { min-height: 80vh; }',
				),
			),
			array(
				'status' => 'temporary',
				'reason' => 'Global stopgap',
			)
		);

		self::assertStringContainsString( 'Project override: Global', $export );
		self::assertStringContainsString( 'Scope: Page: body.page-id-18', $export );
		self::assertStringContainsString( 'Reason: Marketing landing page tweak', $export );
		self::assertStringContainsString( '@media (min-width: 40rem)', $export );
		self::assertStringNotContainsString( ".page-id-18 {\n@media", $export );
		self::assertStringContainsString( 'Project override: Pattern: Hero', $export );
		self::assertStringContainsString( 'Scope: pattern:42', $export );
		self::assertStringContainsString( '.c-hero { min-height: 80vh; }', $export );
	}

	public function test_neutralizes_comment_termination_in_page_titles(): void {
		$export = ( new Exporter() )->build(
			'',
			array(
				array(
					'id'    => 5,
					'title' => 'Home */ malicious',
					'css'   => '.home { color: red; }',
				),
			)
		);

		self::assertStringNotContainsString( 'Home */ malicious', $export );
	}
}
