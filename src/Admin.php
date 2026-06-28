<?php
/**
 * WordPress administration integration.
 *
 * @package ProjectOverrides
 */

declare(strict_types=1);

namespace ProjectOverrides;

use WP_Post;

final class Admin {
	private const CAPABILITY = 'manage_options';
	private const MENU_SLUG  = 'project-overrides';

	public function __construct(
		private Repository $repository,
		private ThemeTokens $tokens,
		private ClassNames $class_names,
		private Exporter $exporter
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_project_overrides_save_css', array( $this, 'save_css_page' ) );
		add_action( 'admin_post_project_overrides_download', array( $this, 'download_export' ) );
		add_action( 'admin_post_project_overrides_delete', array( $this, 'delete_override' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes_page', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_page', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Project Overrides', 'project-overrides' ),
			__( 'Project Overrides', 'project-overrides' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_css_page' ),
			'dashicons-editor-code',
			81
		);

		add_submenu_page( self::MENU_SLUG, __( 'CSS', 'project-overrides' ), __( 'CSS', 'project-overrides' ), self::CAPABILITY, self::MENU_SLUG, array( $this, 'render_css_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Export', 'project-overrides' ), __( 'Export', 'project-overrides' ), self::CAPABILITY, 'project-overrides-export', array( $this, 'render_export_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Settings', 'project-overrides' ), __( 'Settings', 'project-overrides' ), self::CAPABILITY, 'project-overrides-settings', array( $this, 'render_settings_page' ) );
	}

	public function enqueue_assets( string $hook ): void {
		$is_plugin_page = str_contains( $hook, 'project-overrides' );
		$is_page_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'page' === get_current_screen()?->post_type;

		if ( ! $is_plugin_page && ! $is_page_editor ) {
			return;
		}

		$editor = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
		wp_enqueue_style( 'project-overrides-admin', PROJECT_OVERRIDES_URL . 'assets/admin.css', array(), PROJECT_OVERRIDES_VERSION );
		wp_enqueue_script( 'project-overrides-admin', PROJECT_OVERRIDES_URL . 'assets/admin.js', array( 'jquery', 'code-editor' ), PROJECT_OVERRIDES_VERSION, true );
		wp_localize_script(
			'project-overrides-admin',
			'projectOverrides',
			array(
				'editorSettings' => false === $editor ? new \stdClass() : $editor,
				'classNames'     => $this->class_names->get(),
				'copied'         => __( 'Copied', 'project-overrides' ),
				'copy'           => __( 'Copy to clipboard', 'project-overrides' ),
				'unsaved'        => __( 'You have unsaved CSS changes. Leave this page?', 'project-overrides' ),
				'confirmDelete'  => __( 'Delete this override? This cannot be undone.', 'project-overrides' ),
			)
		);
	}

	public function render_css_page(): void {
		$this->guard();

		$selected_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected    = $selected_id ? get_post( $selected_id ) : null;
		if ( ! $selected instanceof WP_Post || 'page' !== $selected->post_type || ! current_user_can( 'edit_post', $selected_id ) ) {
			$selected_id = 0;
			$selected    = null;
		}

		$temporary_count = $this->repository->count_temporary();
		$draft           = $this->get_css_draft();
		$global_css      = isset( $draft['global_css'] ) ? (string) $draft['global_css'] : $this->repository->get_global_css();
		$global_status   = isset( $draft['global_status'] ) ? (string) $draft['global_status'] : $this->repository->get_global_status();
		$page_css        = $selected && isset( $draft['page_css'] ) ? (string) $draft['page_css'] : ( $selected ? $this->repository->get_page_css( $selected_id ) : '' );
		$page_status     = $selected && isset( $draft['page_status'] ) ? (string) $draft['page_status'] : ( $selected ? $this->repository->get_page_status( $selected_id ) : 'temporary' );
		?>
		<div class="wrap project-overrides">
			<h1><?php esc_html_e( 'Project Overrides', 'project-overrides' ); ?></h1>
			<p class="project-overrides__summary">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php
				printf(
					/* translators: %s: Number of temporary overrides. */
					esc_html( _n( '%s temporary override', '%s temporary overrides', $temporary_count, 'project-overrides' ) ),
					esc_html( number_format_i18n( $temporary_count ) )
				);
				?>
			</p>

			<?php if ( ! empty( $draft['error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( (string) $draft['error'] ); ?></p></div>
			<?php elseif ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Overrides saved.', 'project-overrides' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="project_overrides_save_css">
				<input type="hidden" name="global_modified" value="<?php echo esc_attr( (string) $this->repository->get_global_modified() ); ?>">
				<?php wp_nonce_field( 'project_overrides_save_css' ); ?>

				<div class="project-overrides__layout">
					<main>
						<section class="project-overrides__panel">
							<div class="project-overrides__heading">
								<div>
									<h2><?php esc_html_e( 'Global CSS', 'project-overrides' ); ?></h2>
									<p><?php esc_html_e( 'Loaded on every public page inside the document head.', 'project-overrides' ); ?></p>
								</div>
								<?php $this->render_status( 'global_status', $global_status ); ?>
							</div>
							<textarea id="project-overrides-global" class="project-overrides-editor" name="global_css" rows="16"><?php echo esc_textarea( $global_css ); ?></textarea>
						</section>

						<section class="project-overrides__panel">
							<div class="project-overrides__heading">
								<div>
									<h2><?php esc_html_e( 'Current Page CSS', 'project-overrides' ); ?></h2>
									<p><?php esc_html_e( 'Scoped to the selected page by WordPress at load time.', 'project-overrides' ); ?></p>
								</div>
								<?php if ( $selected ) : ?>
									<?php $this->render_status( 'page_status', $page_status ); ?>
								<?php endif; ?>
							</div>

							<label for="project-overrides-page"><strong><?php esc_html_e( 'Page', 'project-overrides' ); ?></strong></label>
							<select id="project-overrides-page" class="project-overrides-page-select" data-base-url="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
								<option value=""><?php esc_html_e( 'Select a page…', 'project-overrides' ); ?></option>
								<?php foreach ( $this->get_editable_pages() as $page ) : ?>
									<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( $selected_id, $page->ID ); ?>><?php echo esc_html( $page->post_title ? $page->post_title : __( '(no title)', 'project-overrides' ) ); ?></option>
								<?php endforeach; ?>
							</select>

							<?php if ( $selected ) : ?>
								<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $selected_id ); ?>">
								<input type="hidden" name="page_modified" value="<?php echo esc_attr( (string) $this->repository->get_page_modified( $selected_id ) ); ?>">
								<textarea id="project-overrides-page-css" class="project-overrides-editor" name="page_css" rows="16"><?php echo esc_textarea( $page_css ); ?></textarea>
							<?php else : ?>
								<div class="project-overrides__empty"><?php esc_html_e( 'Select a page to edit its override.', 'project-overrides' ); ?></div>
							<?php endif; ?>
						</section>
					</main>
					<?php $this->render_token_sidebar(); ?>
				</div>

				<?php submit_button( __( 'Save CSS', 'project-overrides' ) ); ?>
			</form>

			<?php $this->render_page_table(); ?>
		</div>
		<?php
	}

	private function render_status( string $name, string $value ): void {
		?>
		<label class="project-overrides__status">
			<span class="screen-reader-text"><?php esc_html_e( 'Override status', 'project-overrides' ); ?></span>
			<select name="<?php echo esc_attr( $name ); ?>">
				<option value="temporary" <?php selected( $value, 'temporary' ); ?>><?php esc_html_e( 'Temporary', 'project-overrides' ); ?></option>
				<option value="permanent" <?php selected( $value, 'permanent' ); ?>><?php esc_html_e( 'Permanent', 'project-overrides' ); ?></option>
			</select>
		</label>
		<?php
	}

	private function render_token_sidebar(): void {
		?>
		<aside class="project-overrides__tokens">
			<h2><?php esc_html_e( 'Theme tokens', 'project-overrides' ); ?></h2>
			<p><?php esc_html_e( 'Insert a theme.json custom property into the active editor.', 'project-overrides' ); ?></p>
			<?php
			$groups = array(
				'colors'     => __( 'Colors', 'project-overrides' ),
				'spacing'    => __( 'Spacing', 'project-overrides' ),
				'typography' => __( 'Typography', 'project-overrides' ),
			);
			foreach ( $groups as $key => $label ) :
				$items = $this->tokens->get()[ $key ];
				?>
				<h3><?php echo esc_html( $label ); ?></h3>
				<?php if ( $items ) : ?>
					<div class="project-overrides__token-list">
						<?php foreach ( $items as $item ) : ?>
							<button type="button" class="button project-overrides-token" data-token="<?php echo esc_attr( $item['value'] ); ?>" title="<?php echo esc_attr( $item['value'] ); ?>"><?php echo esc_html( $item['label'] ); ?></button>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No theme presets found.', 'project-overrides' ); ?></p>
				<?php endif; ?>
			<?php endforeach; ?>
		</aside>
		<?php
	}

	private function render_page_table(): void {
		$pages          = array_values(
			array_filter(
				$this->repository->get_pages_with_overrides(),
				static fn( WP_Post $page ): bool => current_user_can( 'edit_post', (int) $page->ID )
			)
		);
		$status_filter  = isset( $_GET['override_status'] ) ? sanitize_key( wp_unslash( $_GET['override_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search         = isset( $_GET['override_search'] ) ? sanitize_text_field( wp_unslash( $_GET['override_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pages          = array_values(
			array_filter(
				$pages,
				function ( WP_Post $page ) use ( $status_filter, $search ): bool {
					$status_matches = '' === $status_filter || $status_filter === $this->repository->get_page_status( (int) $page->ID );
					$search_matches = '' === $search || false !== stripos( get_the_title( $page ), $search );
					return $status_matches && $search_matches;
				}
			)
		);
		$global_visible = '' !== trim( $this->repository->get_global_css() )
			&& ( '' === $status_filter || $status_filter === $this->repository->get_global_status() )
			&& ( '' === $search || false !== stripos( __( 'Global CSS', 'project-overrides' ), $search ) );
		?>
		<section class="project-overrides__panel project-overrides__all">
			<h2><?php esc_html_e( 'All Overrides', 'project-overrides' ); ?></h2>
			<form class="project-overrides__filters" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<label class="screen-reader-text" for="project-overrides-search"><?php esc_html_e( 'Search overrides', 'project-overrides' ); ?></label>
				<input id="project-overrides-search" type="search" name="override_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search pages…', 'project-overrides' ); ?>">
				<label class="screen-reader-text" for="project-overrides-status-filter"><?php esc_html_e( 'Filter by status', 'project-overrides' ); ?></label>
				<select id="project-overrides-status-filter" name="override_status">
					<option value=""><?php esc_html_e( 'All statuses', 'project-overrides' ); ?></option>
					<option value="temporary" <?php selected( $status_filter, 'temporary' ); ?>><?php esc_html_e( 'Temporary only', 'project-overrides' ); ?></option>
					<option value="permanent" <?php selected( $status_filter, 'permanent' ); ?>><?php esc_html_e( 'Permanent only', 'project-overrides' ); ?></option>
				</select>
				<button class="button" type="submit"><?php esc_html_e( 'Filter', 'project-overrides' ); ?></button>
				<?php if ( '' !== $search || '' !== $status_filter ) : ?>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Clear', 'project-overrides' ); ?></a>
				<?php endif; ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Page', 'project-overrides' ); ?></th><th><?php esc_html_e( 'CSS lines', 'project-overrides' ); ?></th><th><?php esc_html_e( 'Last modified', 'project-overrides' ); ?></th><th><?php esc_html_e( 'Status', 'project-overrides' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $global_visible ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><strong><?php esc_html_e( 'Global CSS', 'project-overrides' ); ?></strong></a>
							<div class="row-actions">
								<span class="delete">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="project_overrides_delete">
										<input type="hidden" name="type" value="global">
										<?php wp_nonce_field( 'project_overrides_delete_global' ); ?>
										<button type="submit" class="button-link-delete project-overrides-delete"><?php esc_html_e( 'Delete', 'project-overrides' ); ?></button>
									</form>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( (string) $this->repository->line_count( $this->repository->get_global_css() ) ); ?></td>
						<td><?php echo esc_html( $this->repository->get_global_modified() ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $this->repository->get_global_modified() ) : '—' ); ?></td>
						<td><?php $this->render_status_badge( $this->repository->get_global_status() ); ?></td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $pages as $page ) : ?>
					<?php $status = $this->repository->get_page_status( (int) $page->ID ); ?>
					<?php $modified = (int) get_post_meta( $page->ID, Repository::MODIFIED_META, true ); ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&post_id=' . $page->ID ) ); ?>">
							<?php
							$page_title = get_the_title( $page );
							echo esc_html( $page_title ? $page_title : __( '(no title)', 'project-overrides' ) );
							?>
							</a>
							<div class="row-actions">
								<span><a href="<?php echo esc_url( get_edit_post_link( $page->ID ) ); ?>"><?php esc_html_e( 'Edit page', 'project-overrides' ); ?></a> | </span>
								<?php
								if ( 'publish' === $page->post_status ) :
									?>
									<span><a href="<?php echo esc_url( get_permalink( $page ) ); ?>"><?php esc_html_e( 'View', 'project-overrides' ); ?></a> | </span><?php endif; ?>
								<span class="delete">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="project_overrides_delete">
										<input type="hidden" name="type" value="page">
										<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $page->ID ); ?>">
										<?php wp_nonce_field( 'project_overrides_delete_' . $page->ID ); ?>
										<button type="submit" class="button-link-delete project-overrides-delete"><?php esc_html_e( 'Delete override', 'project-overrides' ); ?></button>
									</form>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( (string) $this->repository->line_count( $this->repository->get_page_css( (int) $page->ID ) ) ); ?></td>
						<td><?php echo esc_html( $modified ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $modified ) : get_the_modified_date( '', $page ) ); ?></td>
						<td><?php $this->render_status_badge( $status ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $pages && ! $global_visible ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No matching overrides.', 'project-overrides' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private function render_status_badge( string $status ): void {
		$label = 'permanent' === $status ? __( 'Permanent', 'project-overrides' ) : __( 'Temporary', 'project-overrides' );
		printf( '<span class="project-overrides__badge project-overrides__badge--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $label ) );
	}

	public function save_css_page(): void {
		$this->guard();
		check_admin_referer( 'project_overrides_save_css' );

		$global_css    = isset( $_POST['global_css'] ) ? wp_unslash( $_POST['global_css'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSS must retain its syntax.
		$global_status = isset( $_POST['global_status'] ) ? sanitize_key( wp_unslash( $_POST['global_status'] ) ) : 'temporary';
		$post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$page_css      = '';
		$page_status   = 'temporary';
		if ( $post_id && 'page' === get_post_type( $post_id ) ) {
			$this->guard_page( $post_id );
			$page_css    = isset( $_POST['page_css'] ) ? wp_unslash( $_POST['page_css'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSS must retain its syntax.
			$page_status = isset( $_POST['page_status'] ) ? sanitize_key( wp_unslash( $_POST['page_status'] ) ) : 'temporary';
		}

		$global_modified = isset( $_POST['global_modified'] ) ? absint( $_POST['global_modified'] ) : 0;
		$page_modified   = isset( $_POST['page_modified'] ) ? absint( $_POST['page_modified'] ) : 0;
		$error           = $this->preflight_save( (string) $global_css, $global_modified, $post_id, (string) $page_css, $page_modified );

		if ( is_wp_error( $error ) ) {
			$this->store_css_draft(
				array(
					'error'         => $error->get_error_message(),
					'global_css'    => (string) $global_css,
					'global_status' => $global_status,
					'page_css'      => (string) $page_css,
					'page_status'   => $page_status,
				)
			);
			$this->redirect_to_css_page( $post_id );
		}

		$this->repository->save_global( (string) $global_css, $global_status, $global_modified );
		if ( $post_id && 'page' === get_post_type( $post_id ) ) {
			$this->repository->save_page( $post_id, (string) $page_css, $page_status, $page_modified );
		}

		$url = add_query_arg(
			array(
				'page'    => self::MENU_SLUG,
				'updated' => '1',
			),
			admin_url( 'admin.php' )
		);
		if ( $post_id ) {
			$url = add_query_arg( 'post_id', $post_id, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Validate all submitted values before making either write.
	 *
	 * @return true|\WP_Error
	 */
	private function preflight_save( string $global_css, int $global_modified, int $post_id, string $page_css, int $page_modified ) {
		$validation = $this->repository->validate_css( $global_css );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( $global_modified !== $this->repository->get_global_modified() ) {
			return new \WP_Error( 'stale_override', __( 'The global override changed after you opened it. Reload the page and merge your changes.', 'project-overrides' ) );
		}
		if ( $post_id ) {
			$validation = $this->repository->validate_css( $page_css );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			if ( $page_modified !== $this->repository->get_page_modified( $post_id ) ) {
				return new \WP_Error( 'stale_override', __( 'This page override changed after you opened it. Reload the page and merge your changes.', 'project-overrides' ) );
			}
		}

		return true;
	}

	/**
	 * Preserve rejected input across the redirect.
	 *
	 * @param array<string, string> $draft Draft fields.
	 */
	private function store_css_draft( array $draft ): void {
		set_transient( 'project_overrides_draft_' . get_current_user_id(), $draft, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return array<string, string>
	 */
	private function get_css_draft(): array {
		$key   = 'project_overrides_draft_' . get_current_user_id();
		$draft = get_transient( $key );
		delete_transient( $key );
		return is_array( $draft ) ? $draft : array();
	}

	private function redirect_to_css_page( int $post_id = 0 ): void {
		$url = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		if ( $post_id ) {
			$url = add_query_arg( 'post_id', $post_id, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public function render_export_page(): void {
		$this->guard();
		$export = $this->build_export();
		?>
		<div class="wrap project-overrides">
			<h1><?php esc_html_e( 'Export CSS', 'project-overrides' ); ?></h1>
			<p><?php esc_html_e( 'A migration-ready bundle of the global and page-specific overrides.', 'project-overrides' ); ?></p>
			<div class="project-overrides__export-actions">
				<button type="button" class="button button-primary project-overrides-copy" data-target="project-overrides-export"><?php esc_html_e( 'Copy to clipboard', 'project-overrides' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=project_overrides_download' ), 'project_overrides_download' ) ); ?>"><?php esc_html_e( 'Download CSS', 'project-overrides' ); ?></a>
			</div>
			<textarea id="project-overrides-export" class="project-overrides-editor project-overrides-editor--readonly" rows="30" readonly><?php echo esc_textarea( $export ); ?></textarea>
		</div>
		<?php
	}

	private function build_export(): string {
		$pages = array();
		foreach ( $this->repository->get_pages_with_overrides() as $page ) {
			if ( ! current_user_can( 'edit_post', (int) $page->ID ) ) {
				continue;
			}
			$pages[] = array(
				'id'    => (int) $page->ID,
				'title' => get_the_title( $page ) ? get_the_title( $page ) : __( 'Untitled', 'project-overrides' ),
				'css'   => $this->repository->get_page_css( (int) $page->ID ),
			);
		}

		return $this->exporter->build( $this->repository->get_global_css(), $pages );
	}

	public function download_export(): void {
		$this->guard();
		check_admin_referer( 'project_overrides_download' );
		nocache_headers();
		header( 'Content-Type: text/css; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="project-overrides-' . gmdate( 'Y-m-d' ) . '.css"' );
		echo $this->build_export(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS download, sanitized on storage.
		exit;
	}

	public function delete_override(): void {
		$this->guard();
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		if ( 'global' === $type ) {
			check_admin_referer( 'project_overrides_delete_global' );
			$this->repository->save_global( '', 'temporary' );
		} elseif ( 'page' === $type ) {
			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			check_admin_referer( 'project_overrides_delete_' . $post_id );
			if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
				wp_die( esc_html__( 'Invalid page override.', 'project-overrides' ) );
			}
			$this->guard_page( $post_id );
			$this->repository->save_page( $post_id, '', 'temporary' );
		} else {
			wp_die( esc_html__( 'Invalid override type.', 'project-overrides' ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function register_settings(): void {
		register_setting(
			'project_overrides_settings',
			ClassNames::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $value ): string => ltrim( sanitize_text_field( (string) $value ), '/\\' ),
				'default'           => '',
			)
		);
	}

	public function render_settings_page(): void {
		$this->guard();
		?>
		<div class="wrap project-overrides">
			<h1><?php esc_html_e( 'Project Overrides Settings', 'project-overrides' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'project_overrides_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="project-overrides-class-file"><?php esc_html_e( 'BEM class JSON file', 'project-overrides' ); ?></label></th>
						<td>
							<input id="project-overrides-class-file" class="regular-text" type="text" name="<?php echo esc_attr( ClassNames::OPTION ); ?>" value="<?php echo esc_attr( (string) get_option( ClassNames::OPTION, '' ) ); ?>" placeholder="assets/classes.json">
							<p class="description"><?php esc_html_e( 'Path relative to the active theme. The file must contain a JSON array of c- or o- class names. Class names can also be supplied with the project_overrides_class_names filter.', 'project-overrides' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function add_meta_box(): void {
		if ( current_user_can( self::CAPABILITY ) ) {
			add_meta_box( 'project-overrides-css', __( 'Project Overrides CSS', 'project-overrides' ), array( $this, 'render_meta_box' ), 'page', 'normal', 'default' );
		}
	}

	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'project_overrides_save_meta', 'project_overrides_meta_nonce' );
		printf( '<input type="hidden" name="project_overrides_modified" value="%s">', esc_attr( (string) $this->repository->get_page_modified( (int) $post->ID ) ) );
		$this->render_status( 'project_overrides_status', $this->repository->get_page_status( (int) $post->ID ) );
		?>
		<p class="description"><?php esc_html_e( 'Loaded only on this page. Use CSS only; script and style tags are stripped.', 'project-overrides' ); ?></p>
		<textarea id="project-overrides-meta-css" class="project-overrides-editor" name="project_overrides_css" rows="12"><?php echo esc_textarea( $this->repository->get_page_css( (int) $post->ID ) ); ?></textarea>
		<?php
	}

	public function save_meta_box( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['project_overrides_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['project_overrides_meta_nonce'] ) ), 'project_overrides_save_meta' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( self::CAPABILITY ) || ! current_user_can( 'edit_post', $post_id ) || 'page' !== $post->post_type ) {
			return;
		}

		$css      = isset( $_POST['project_overrides_css'] ) ? (string) wp_unslash( $_POST['project_overrides_css'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSS must retain its syntax.
		$status   = isset( $_POST['project_overrides_status'] ) ? sanitize_key( wp_unslash( $_POST['project_overrides_status'] ) ) : 'temporary';
		$modified = isset( $_POST['project_overrides_modified'] ) ? absint( $_POST['project_overrides_modified'] ) : 0;
		$result   = $this->repository->save_page( $post_id, $css, $status, $modified );
		if ( is_wp_error( $result ) ) {
			set_transient( 'project_overrides_notice_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		}
	}

	public function render_admin_notice(): void {
		$key     = 'project_overrides_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}

	public function add_dashboard_widget(): void {
		if ( current_user_can( self::CAPABILITY ) ) {
			wp_add_dashboard_widget( 'project_overrides_dashboard', __( 'Project Overrides', 'project-overrides' ), array( $this, 'render_dashboard_widget' ) );
		}
	}

	public function render_dashboard_widget(): void {
		$count = $this->repository->count_temporary();
		printf(
			'<p class="project-overrides-dashboard-count"><strong>%s</strong></p>',
			sprintf(
				/* translators: %s: Number of temporary overrides. */
				esc_html( _n( '%s temporary override', '%s temporary overrides', $count, 'project-overrides' ) ),
				esc_html( number_format_i18n( $count ) )
			)
		);
		printf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ), esc_html__( 'Review overrides', 'project-overrides' ) );
	}

	private function guard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage project overrides.', 'project-overrides' ) );
		}
	}

	/**
	 * @return WP_Post[]
	 */
	private function get_editable_pages(): array {
		return array_values(
			array_filter(
				$this->repository->get_all_pages(),
				static fn( WP_Post $page ): bool => current_user_can( 'edit_post', (int) $page->ID )
			)
		);
	}

	private function guard_page( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this page override.', 'project-overrides' ), '', array( 'response' => 403 ) );
		}
	}
}
