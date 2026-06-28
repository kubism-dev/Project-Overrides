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
	public const GLOBAL_REASON        = 'project_overrides_global_reason';
	public const GLOBAL_TICKET        = 'project_overrides_global_ticket';
	public const GLOBAL_REVISIONS     = 'project_overrides_global_revisions';
	public const CSS_META             = '_project_overrides_css';
	public const STATUS_META          = '_project_overrides_status';
	public const MODIFIED_META        = '_project_overrides_modified';
	public const REASON_META          = '_project_overrides_reason';
	public const TICKET_META          = '_project_overrides_ticket';
	public const REVISIONS_META       = '_project_overrides_revisions';
	private const REVISION_LIMIT      = 20;

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
			'#url\s*\(\s*([\'"]?\s*)?(?:(?:https?|ftp):|//)#i',
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
		return in_array( $status, array( 'temporary', 'permanent', 'migrated' ), true ) ? $status : 'temporary';
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

	public function get_global_reason(): string {
		return (string) get_option( self::GLOBAL_REASON, '' );
	}

	public function get_global_ticket(): string {
		return (string) get_option( self::GLOBAL_TICKET, '' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_global_revisions(): array {
		$revisions = get_option( self::GLOBAL_REVISIONS, array() );
		return is_array( $revisions ) ? $revisions : array();
	}

	/**
	 * @return true|WP_Error
	 */
	public function save_global( string $css, string $status, ?int $expected_modified = null, string $reason = '', string $ticket = '' ) {
		$validation = $this->validate_css( $css );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( null !== $expected_modified && $expected_modified !== $this->get_global_modified() ) {
			return new WP_Error( 'stale_override', __( 'The global override changed after you opened it. Reload the page and merge your changes.', 'project-overrides' ) );
		}

		$css    = $this->sanitize_css( $css );
		$status = $this->sanitize_status( $status );
		$reason = sanitize_text_field( $reason );
		$ticket = esc_url_raw( $ticket );

		if ( $this->global_changed( $css, $status, $reason, $ticket ) ) {
			$this->store_global_revision();
		}
		if ( '' === trim( $css ) ) {
			$status = 'temporary';
			$reason = '';
			$ticket = '';
		}

		update_option( self::GLOBAL_CSS_OPTION, $css, false );
		update_option( self::GLOBAL_STATUS_OPTION, $status, false );
		update_option( self::GLOBAL_REASON, $reason, false );
		update_option( self::GLOBAL_TICKET, $ticket, false );
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

	public function get_page_reason( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::REASON_META, true );
	}

	public function get_page_ticket( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::TICKET_META, true );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_page_revisions( int $post_id ): array {
		$revisions = get_post_meta( $post_id, self::REVISIONS_META, true );
		return is_array( $revisions ) ? $revisions : array();
	}

	/**
	 * @return true|WP_Error
	 */
	public function save_page( int $post_id, string $css, string $status, ?int $expected_modified = null, string $reason = '', string $ticket = '' ) {
		$validation = $this->validate_css( $css );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( null !== $expected_modified && $expected_modified !== $this->get_page_modified( $post_id ) ) {
			return new WP_Error( 'stale_override', __( 'This page override changed after you opened it. Reload the page and merge your changes.', 'project-overrides' ) );
		}

		$css    = $this->sanitize_css( $css );
		$status = $this->sanitize_status( $status );
		$reason = sanitize_text_field( $reason );
		$ticket = esc_url_raw( $ticket );

		if ( $this->page_changed( $post_id, $css, $status, $reason, $ticket ) ) {
			$this->store_page_revision( $post_id );
		}

		if ( '' === trim( $css ) ) {
			delete_post_meta( $post_id, self::CSS_META );
			delete_post_meta( $post_id, self::STATUS_META );
			delete_post_meta( $post_id, self::MODIFIED_META );
			delete_post_meta( $post_id, self::REASON_META );
			delete_post_meta( $post_id, self::TICKET_META );
			return true;
		}

		update_post_meta( $post_id, self::CSS_META, $css );
		update_post_meta( $post_id, self::STATUS_META, $status );
		update_post_meta( $post_id, self::MODIFIED_META, time() );
		update_post_meta( $post_id, self::REASON_META, $reason );
		update_post_meta( $post_id, self::TICKET_META, $ticket );
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public function rollback_global( string $revision_id ) {
		foreach ( $this->get_global_revisions() as $revision ) {
			if ( isset( $revision['id'] ) && hash_equals( (string) $revision['id'], $revision_id ) ) {
				return $this->save_global(
					(string) ( $revision['css'] ?? '' ),
					(string) ( $revision['status'] ?? 'temporary' ),
					$this->get_global_modified(),
					(string) ( $revision['reason'] ?? '' ),
					(string) ( $revision['ticket'] ?? '' )
				);
			}
		}

		return new WP_Error( 'invalid_revision', __( 'The selected revision no longer exists.', 'project-overrides' ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public function rollback_page( int $post_id, string $revision_id ) {
		foreach ( $this->get_page_revisions( $post_id ) as $revision ) {
			if ( isset( $revision['id'] ) && hash_equals( (string) $revision['id'], $revision_id ) ) {
				return $this->save_page(
					$post_id,
					(string) ( $revision['css'] ?? '' ),
					(string) ( $revision['status'] ?? 'temporary' ),
					$this->get_page_modified( $post_id ),
					(string) ( $revision['reason'] ?? '' ),
					(string) ( $revision['ticket'] ?? '' )
				);
			}
		}

		return new WP_Error( 'invalid_revision', __( 'The selected revision no longer exists.', 'project-overrides' ) );
	}

	private function global_changed( string $css, string $status, string $reason, string $ticket ): bool {
		return $css !== $this->get_global_css()
			|| $status !== $this->get_global_status()
			|| $reason !== $this->get_global_reason()
			|| $ticket !== $this->get_global_ticket();
	}

	private function page_changed( int $post_id, string $css, string $status, string $reason, string $ticket ): bool {
		return $css !== $this->get_page_css( $post_id )
			|| $status !== $this->get_page_status( $post_id )
			|| $reason !== $this->get_page_reason( $post_id )
			|| $ticket !== $this->get_page_ticket( $post_id );
	}

	private function store_global_revision(): void {
		if ( '' === trim( $this->get_global_css() ) ) {
			return;
		}

		$revisions = $this->prepend_revision(
			$this->get_global_revisions(),
			$this->revision_snapshot(
				$this->get_global_css(),
				$this->get_global_status(),
				$this->get_global_reason(),
				$this->get_global_ticket(),
				$this->get_global_modified()
			)
		);
		update_option( self::GLOBAL_REVISIONS, $revisions, false );
	}

	private function store_page_revision( int $post_id ): void {
		if ( '' === trim( $this->get_page_css( $post_id ) ) ) {
			return;
		}

		$revisions = $this->prepend_revision(
			$this->get_page_revisions( $post_id ),
			$this->revision_snapshot(
				$this->get_page_css( $post_id ),
				$this->get_page_status( $post_id ),
				$this->get_page_reason( $post_id ),
				$this->get_page_ticket( $post_id ),
				$this->get_page_modified( $post_id )
			)
		);
		update_post_meta( $post_id, self::REVISIONS_META, $revisions );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function revision_snapshot( string $css, string $status, string $reason, string $ticket, int $modified ): array {
		return array(
			'id'       => wp_generate_uuid4(),
			'css'      => $css,
			'status'   => $status,
			'reason'   => $reason,
			'ticket'   => $ticket,
			'modified' => $modified,
			'author'   => get_current_user_id(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $revisions Existing revisions.
	 * @param array<string, mixed>             $revision  New revision.
	 * @return array<int, array<string, mixed>>
	 */
	private function prepend_revision( array $revisions, array $revision ): array {
		array_unshift( $revisions, $revision );
		return array_slice( $revisions, 0, self::REVISION_LIMIT );
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
