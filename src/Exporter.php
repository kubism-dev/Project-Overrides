<?php
/**
 * CSS export formatter.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides;

final class Exporter {
	/**
	 * Build valid CSS while retaining page scope as migration metadata.
	 *
	 * @param string                                              $global_css Global CSS.
	 * @param array<int, array{id:int, title:string, css:string}> $pages Page sections.
	 */
	public function build( string $global_css, array $pages ): string {
		$sections = array( "/* GLOBAL */\n\n" . trim( $global_css ) );

		foreach ( $pages as $page ) {
			$title      = str_replace( '*/', '', $page['title'] );
			$sections[] = sprintf(
				"/* %s */\n/* Page scope: body.page-id-%d */\n\n%s",
				$title,
				$page['id'],
				trim( $page['css'] )
			);
		}

		return implode( "\n\n\n", $sections ) . "\n";
	}
}
