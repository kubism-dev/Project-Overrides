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
	 * @param string                                                     $global_css  Global CSS.
	 * @param array<int, array{id:int, title:string, css:string}>        $pages       Page sections.
	 * @param array<int, array{label:string,css:string}>                 $scopes      Pattern and class sections.
	 * @param array{status?:string,reason?:string,modified?:int}|array{} $global_meta Global metadata.
	 */
	public function build( string $global_css, array $pages, array $scopes = array(), array $global_meta = array() ): string {
		$sections = array( $this->section( 'Global', trim( $global_css ), array_merge( array( 'scope' => 'Global' ), $global_meta ) ) );

		foreach ( $pages as $page ) {
			$sections[] = $this->section(
				(string) $page['title'],
				trim( $page['css'] ),
				array(
					'scope'    => 'Page: body.page-id-' . (int) $page['id'],
					'status'   => (string) ( $page['status'] ?? '' ),
					'reason'   => (string) ( $page['reason'] ?? '' ),
					'modified' => (int) ( $page['modified'] ?? 0 ),
				)
			);
		}
		foreach ( $scopes as $scope ) {
			$sections[] = $this->section(
				(string) $scope['label'],
				trim( $scope['css'] ),
				array(
					'scope'    => (string) ( $scope['scope'] ?? $scope['label'] ),
					'status'   => (string) ( $scope['status'] ?? '' ),
					'reason'   => (string) ( $scope['reason'] ?? '' ),
					'modified' => (int) ( $scope['modified'] ?? 0 ),
				)
			);
		}

		return implode( "\n\n\n", $sections ) . "\n";
	}

	/**
	 * @param string                                                           $label Section label.
	 * @param string                                                           $css   Section CSS.
	 * @param array{scope?:string,status?:string,reason?:string,modified?:int} $meta Section metadata.
	 */
	private function section( string $label, string $css, array $meta ): string {
		$lines    = array( 'Project override: ' . $this->comment_value( $label ) );
		$scope    = (string) ( $meta['scope'] ?? '' );
		$status   = (string) ( $meta['status'] ?? '' );
		$reason   = (string) ( $meta['reason'] ?? '' );
		$modified = (int) ( $meta['modified'] ?? 0 );

		if ( '' !== $scope ) {
			$lines[] = 'Scope: ' . $this->comment_value( $scope );
		}
		if ( '' !== $status ) {
			$lines[] = 'Status: ' . $this->comment_value( $status );
		}
		if ( '' !== $reason ) {
			$lines[] = 'Reason: ' . $this->comment_value( $reason );
		}
		if ( $modified ) {
			$lines[] = 'Last modified: ' . gmdate( 'Y-m-d H:i:s', $modified ) . ' UTC';
		}

		return "/**\n * " . implode( "\n * ", $lines ) . "\n */\n\n" . $css;
	}

	private function comment_value( string $value ): string {
		return str_replace( '*/', '', $value );
	}
}
