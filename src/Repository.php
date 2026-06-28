<?php
/**
 * CSS override persistence.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides;

use WP_Error;
use WP_Post;

final class Repository {
	public const GLOBAL_CSS_OPTION    = 'project_overrides_global_css';
	public const GLOBAL_STATUS_OPTION = 'project_overrides_global_status';
	public const GLOBAL_MODIFIED      = 'project_overrides_global_modified';
	public const CSS_META             = '_project_overrides_css';
	public const STATUS_META          = '_project_overrides_status';
	public const MODIFIED_META        = '_project_overrides_modified';

	/**
	 * Normalize CSS before storage.
	 */
	public function sanitize_css( string $css ): string {
		$css = wp_check_invalid_utf8( $css );
		return str_replace( array( "\r\n", "\r" ), "\n", $css );
	}

	/**
	 * Reject input that can break out of a CSS context or invoke legacy script.
	 *
	 * @return true|WP_Error
	 */
	public function validate_css( string $css ) {
		$unsafe_patterns = array(
			'#</?style\b#i',
			'#<script\b#i',
			'#<\?php#i',
			'#javascript\s*:#i',
			'#expression\s*\(#i',
			'#(?:^|[;{])\s*behavior\s*:#i',
			'#-moz-binding\s*:#i',
			'#@import\b#i',
			'#url\s*\(\s*([\'"]?\s*)?(?:https?:)?//#i',
		);

		foreach ( $unsafe_patterns as $pattern ) {
			if ( preg_match( $pattern, $css ) ) {
				return new WP_Error(
					'unsafe_css',
					__( 'The CSS contains markup or an executable construct that Project Overrides does not allow.', 'project-overrides' )
				);
			}
		}

		if ( ! $this->has_balanced_blocks( $css ) ) {
			return new WP_Error(
				'invalid_css_structure',
				__( 'The CSS has an unclosed string, comment, or block.', 'project-overrides' )
			);
		}

		return true;
	}

	private function has_balanced_blocks( string $css ): bool {
		$depth      = 0;
		$quote      = '';
		$in_comment = false;
		$escaped    = false;
		$length     = strlen( $css );

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $css[ $index ];
			$next      = $index + 1 < $length ? $css[ $index + 1 ] : '';

			if ( $in_comment ) {
				if ( '*' === $character && '/' === $next ) {
					$in_comment = false;
					++$index;
				}
				continue;
			}

			if ( '' !== $quote ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $character ) {
					$escaped = true;
				} elseif ( $quote === $character ) {
					$quote = '';
				}
				continue;
			}

			if ( '/' === $character && '*' === $next ) {
				$in_comment = true;
				++$index;
			} elseif ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '{' === $character ) {
				++$depth;
			} elseif ( '}' === $character ) {
				--$depth;
				if ( $depth < 0 ) {
					return false;
				}
			}
		}

		return 0 === $depth && '' === $quote && ! $in_comment;
	}

	public function sanitize_status( string $status ): string {
		return in_array( $status, array( 'temporary', 'permanent' ), true ) ? $status : 'temporary';
	}

	public function get_global_css(): string {
		return (string) get_option( self::GLOBAL_CSS_OPTION, '' );
	}

	public function get_global_status(): string {
		return $this->sanitize_status( (string) get_option( self::GLOBAL_STATUS_OPTION, 'temporary' ) );
	}

	public function get_global_modified(): int {
		return (int) get_option( self::GLOBAL_MODIFIED, 0 );
	}

	/**
	 * @return true|WP_Error
	 */
	public function save_global( string $css, string $status, ?int $expected_modified = null ) {
		$validation = $this->validate_css( $css );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( null !== $expected_modified && $expected_modified !== $this->get_global_modified() ) {
			return new WP_Error( 'stale_override', __( 'The global override changed after you opened it. Reload the page and merge your changes.', 'project-overrides' ) );
		}

		update_option( self::GLOBAL_CSS_OPTION, $this->sanitize_css( $css ), false );
		update_option( self::GLOBAL_STATUS_OPTION, $this->sanitize_status( $status ), false );
		update_option( self::GLOBAL_MODIFIED, time(), false );
		return true;
	}

	public function get_page_css( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::CSS_META, true );
	}

	public function get_page_status( int $post_id ): string {
		return $this->sanitize_status( (string) get_post_meta( $post_id, self::STATUS_META, true ) );
	}

	public function get_page_modified( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::MODIFIED_META, true );
	}

	/**
	 * @return true|WP_Error
	 */
	public function save_page( int $post_id, string $css, string $status, ?int $expected_modified = null ) {
		$validation = $this->validate_css( $css );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( null !== $expected_modified && $expected_modified !== $this->get_page_modified( $post_id ) ) {
			return new WP_Error( 'stale_override', __( 'This page override changed after you opened it. Reload the page and merge your changes.', 'project-overrides' ) );
		}

		$css = $this->sanitize_css( $css );

		if ( '' === trim( $css ) ) {
			delete_post_meta( $post_id, self::CSS_META );
			delete_post_meta( $post_id, self::STATUS_META );
			delete_post_meta( $post_id, self::MODIFIED_META );
			return true;
		}

		update_post_meta( $post_id, self::CSS_META, $css );
		update_post_meta( $post_id, self::STATUS_META, $this->sanitize_status( $status ) );
		update_post_meta( $post_id, self::MODIFIED_META, time() );
		return true;
	}

	/**
	 * Get all pages containing CSS overrides.
	 *
	 * @return WP_Post[]
	 */
	public function get_pages_with_overrides(): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'meta_key'               => self::CSS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The override meta key defines this query.
				'meta_compare'           => 'EXISTS',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		return $query->posts;
	}

	/**
	 * Get editable pages for the page selector.
	 *
	 * @return WP_Post[]
	 */
	public function get_all_pages(): array {
		return get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	public function count_temporary(): int {
		$count = 0;

		if ( '' !== trim( $this->get_global_css() ) && 'temporary' === $this->get_global_status() ) {
			++$count;
		}

		foreach ( $this->get_pages_with_overrides() as $page ) {
			if ( 'temporary' === $this->get_page_status( (int) $page->ID ) ) {
				++$count;
			}
		}

		return $count;
	}

	public function line_count( string $css ): int {
		$trimmed = trim( $css );
		return '' === $trimmed ? 0 : substr_count( $trimmed, "\n" ) + 1;
	}
}
