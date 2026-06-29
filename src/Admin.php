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
	private const SCOPE_META = '_project_overrides_editor_scope';

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
		add_action( 'admin_post_project_overrides_rollback', array( $this, 'rollback_override' ) );
		add_action( 'admin_post_project_overrides_migrate', array( $this, 'migration_action' ) );
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
		$post_id        = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_page_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& ( 'page' === get_current_screen()?->post_type || ( $post_id && 'page' === get_post_type( $post_id ) ) || isset( $_GET['meta-box-loader'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $is_plugin_page && ! $is_page_editor ) {
			return;
		}

		$class_names = array_values( array_unique( array_merge( $this->class_names->get(), $this->get_page_block_classes( $post_id ) ) ) );
		$editor      = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
		wp_enqueue_style( 'project-overrides-admin', PROJECT_OVERRIDES_URL . 'assets/admin.css', array(), PROJECT_OVERRIDES_VERSION );
		wp_enqueue_script( 'project-overrides-admin', PROJECT_OVERRIDES_URL . 'assets/admin.js', array( 'jquery', 'code-editor' ), PROJECT_OVERRIDES_VERSION, true );
		wp_localize_script(
			'project-overrides-admin',
			'projectOverrides',
			array(
				'editorSettings' => false === $editor ? new \stdClass() : $editor,
				'classNames'     => $class_names,
				'copied'         => __( 'Copied', 'project-overrides' ),
				'copy'           => __( 'Copy to clipboard', 'project-overrides' ),
				'unsaved'        => __( 'You have unsaved CSS changes. Leave this page?', 'project-overrides' ),
				'confirmDelete'  => __( 'Delete this override? This cannot be undone.', 'project-overrides' ),
				'saveCss'        => __( 'Save CSS', 'project-overrides' ),
				'openCss'        => __( 'CSS', 'project-overrides' ),
				'saving'         => __( 'Saving CSS…', 'project-overrides' ),
				'saved'          => __( 'CSS saved', 'project-overrides' ),
				'saveError'      => __( 'CSS save failed', 'project-overrides' ),
				'scopeData'      => $is_page_editor ? $this->get_editor_scope_data( $post_id ) : new \stdClass(),
			)
		);
	}

	/**
	 * @return array<string, array{css:string,status:string,reason:string}>
	 */
	private function get_editor_scope_data( int $post_id ): array {
		$data = array(
			'global' => array(
				'css'    => $this->repository->get_global_css(),
				'status' => $this->repository->get_global_status(),
				'reason' => $this->repository->get_global_reason(),
			),
			'page'   => array(
				'css'    => $post_id ? $this->repository->get_page_css( $post_id ) : '',
				'status' => $post_id ? $this->repository->get_page_status( $post_id ) : 'temporary',
				'reason' => $post_id ? $this->repository->get_page_reason( $post_id ) : '',
			),
		);
		foreach ( $this->repository->get_scoped_overrides() as $scope => $override ) {
			$data[ $scope ] = array(
				'css'    => (string) ( $override['css'] ?? '' ),
				'status' => $this->repository->sanitize_status( (string) ( $override['status'] ?? 'temporary' ) ),
				'reason' => (string) ( $override['reason'] ?? '' ),
			);
		}
		return $data;
	}

	public function render_css_page(): void {
		$this->guard();

		$selected_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected    = $selected_id ? get_post( $selected_id ) : null;
		if ( ! $selected instanceof WP_Post || 'page' !== $selected->post_type || ! current_user_can( 'edit_post', $selected_id ) ) {
			$selected_id = 0;
			$selected    = null;
		}
		$selected_scope = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope_options  = $this->get_general_scope_options();
		if ( ! isset( $scope_options[ $selected_scope ] ) ) {
			$selected_scope = '';
		}
		$scoped_override = $selected_scope ? $this->repository->get_scoped_override( $selected_scope ) : null;

		$temporary_count = $this->repository->count_temporary();
		$draft           = $this->get_css_draft();
		$global_css      = isset( $draft['global_css'] ) ? (string) $draft['global_css'] : $this->repository->get_global_css();
		$global_status   = isset( $draft['global_status'] ) ? (string) $draft['global_status'] : $this->repository->get_global_status();
		$page_css        = $selected && isset( $draft['page_css'] ) ? (string) $draft['page_css'] : ( $selected ? $this->repository->get_page_css( $selected_id ) : '' );
		$page_status     = $selected && isset( $draft['page_status'] ) ? (string) $draft['page_status'] : ( $selected ? $this->repository->get_page_status( $selected_id ) : 'temporary' );
		$global_reason   = isset( $draft['global_reason'] ) ? (string) $draft['global_reason'] : $this->repository->get_global_reason();
		$page_reason     = $selected && isset( $draft['page_reason'] ) ? (string) $draft['page_reason'] : ( $selected ? $this->repository->get_page_reason( $selected_id ) : '' );
		if ( $scoped_override && ( $draft['scoped_key'] ?? '' ) === $selected_scope ) {
			$scoped_override['css']    = (string) ( $draft['scoped_css'] ?? $scoped_override['css'] );
			$scoped_override['status'] = (string) ( $draft['scoped_status'] ?? $scoped_override['status'] );
			$scoped_override['reason'] = (string) ( $draft['scoped_reason'] ?? $scoped_override['reason'] );
		}
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
							<?php $this->render_metadata_fields( 'global', $global_reason ); ?>
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
								<?php $this->render_metadata_fields( 'page', $page_reason ); ?>
							<?php else : ?>
								<div class="project-overrides__empty"><?php esc_html_e( 'Select a page to edit its override.', 'project-overrides' ); ?></div>
							<?php endif; ?>
						</section>

						<section class="project-overrides__panel">
							<div class="project-overrides__heading">
								<div>
									<h2><?php esc_html_e( 'Pattern & Block Class CSS', 'project-overrides' ); ?></h2>
									<p><?php esc_html_e( 'General overrides shared wherever the selected pattern or class is used.', 'project-overrides' ); ?></p>
								</div>
								<?php if ( $scoped_override ) : ?>
									<?php $this->render_status( 'scoped_status', $scoped_override['status'] ); ?>
								<?php endif; ?>
							</div>

							<label for="project-overrides-general-scope"><strong><?php esc_html_e( 'Scope', 'project-overrides' ); ?></strong></label>
							<select id="project-overrides-general-scope" class="project-overrides-general-scope-select" data-base-url="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
								<option value=""><?php esc_html_e( 'Select a pattern or block class…', 'project-overrides' ); ?></option>
								<?php foreach ( $scope_options as $scope => $label ) : ?>
									<option value="<?php echo esc_attr( $scope ); ?>" <?php selected( $selected_scope, $scope ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>

							<?php if ( $scoped_override ) : ?>
								<input type="hidden" name="scoped_key" value="<?php echo esc_attr( $selected_scope ); ?>">
								<div class="project-overrides-scope-indicator"><?php echo esc_html( 'Editing: ' . ( $scope_options[ $selected_scope ] ?? $selected_scope ) ); ?></div>
								<textarea id="project-overrides-scoped-css" class="project-overrides-editor" name="scoped_css" rows="16"><?php echo esc_textarea( $scoped_override['css'] ); ?></textarea>
								<?php $this->render_metadata_fields( 'scoped', $scoped_override['reason'] ); ?>
								<?php $this->render_scope_diagnostics( $selected_scope, 0, $scoped_override['status'] ); ?>
							<?php else : ?>
								<div class="project-overrides__empty"><?php esc_html_e( 'Select a general scope to edit its override.', 'project-overrides' ); ?></div>
							<?php endif; ?>
						</section>
					</main>
					<?php $this->render_token_sidebar(); ?>
				</div>

				<?php submit_button( __( 'Save CSS', 'project-overrides' ) ); ?>
			</form>

			<div class="project-overrides__history-grid">
				<?php $this->render_revisions( 'global', 0, $this->repository->get_global_revisions() ); ?>
				<?php if ( $selected ) : ?>
					<?php $this->render_revisions( 'page', $selected_id, $this->repository->get_page_revisions( $selected_id ) ); ?>
				<?php endif; ?>
				<?php if ( $selected_scope ) : ?>
					<?php $this->render_revisions( 'scoped', 0, $this->repository->get_scoped_revisions( $selected_scope ), $selected_scope ); ?>
				<?php endif; ?>
			</div>
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
				<option value="migrated" <?php selected( $value, 'migrated' ); ?>><?php esc_html_e( 'Migrated (inactive)', 'project-overrides' ); ?></option>
			</select>
		</label>
		<?php
	}

	private function render_metadata_fields( string $prefix, string $reason ): void {
		?>
		<div class="project-overrides__metadata">
			<p>
				<label for="project-overrides-<?php echo esc_attr( $prefix ); ?>-reason"><strong><?php esc_html_e( 'Reason / handoff note', 'project-overrides' ); ?></strong></label>
				<input id="project-overrides-<?php echo esc_attr( $prefix ); ?>-reason" type="text" class="widefat" name="<?php echo esc_attr( $prefix ); ?>_reason" value="<?php echo esc_attr( $reason ); ?>" maxlength="500">
			</p>
		</div>
		<?php
	}

	/**
	 * @param string                           $type      Override type.
	 * @param int                              $post_id   Page ID for page revisions.
	 * @param array<int, array<string, mixed>> $revisions Revisions.
	 * @param string                           $scope     Pattern or class scope key.
	 */
	private function render_revisions( string $type, int $post_id, array $revisions, string $scope = '' ): void {
		if ( ! $revisions ) {
			return;
		}
		?>
		<details class="project-overrides__revisions">
			<summary>
				<?php
				/* translators: %d: Number of stored revisions. */
				printf( esc_html__( 'Revision history (%d)', 'project-overrides' ), count( $revisions ) );
				?>
			</summary>
			<ul>
				<?php foreach ( $revisions as $revision ) : ?>
					<li>
						<span>
							<?php
							$modified = isset( $revision['modified'] ) ? (int) $revision['modified'] : 0;
							echo esc_html( $modified ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $modified ) : __( 'Unknown date', 'project-overrides' ) );
							?>
							&middot;
							<?php echo esc_html( (string) ( $revision['status'] ?? 'temporary' ) ); ?>
							&middot;
							<?php
							$author = isset( $revision['author'] ) ? get_userdata( (int) $revision['author'] ) : false;
							echo esc_html( $author ? $author->display_name : __( 'Unknown author', 'project-overrides' ) );
							?>
							&middot;
							<?php
							/* translators: %d: Number of lines in a CSS revision. */
							echo esc_html( sprintf( __( '%d lines', 'project-overrides' ), $this->repository->line_count( (string) ( $revision['css'] ?? '' ) ) ) );
							?>
						</span>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="project_overrides_rollback">
							<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
							<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
							<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
							<input type="hidden" name="revision_id" value="<?php echo esc_attr( (string) ( $revision['id'] ?? '' ) ); ?>">
							<?php wp_nonce_field( 'project_overrides_rollback_' . $type . '_' . (string) ( $revision['id'] ?? '' ) ); ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Restore', 'project-overrides' ); ?></button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
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
		$scope_labels = $this->get_general_scope_options();
		$scoped       = array_filter(
			$this->repository->get_scoped_overrides(),
			function ( array $override, string $scope ) use ( $scope_labels, $status_filter, $search ): bool {
				$label = $scope_labels[ $scope ] ?? $scope;
				return '' !== trim( (string) ( $override['css'] ?? '' ) )
					&& ( '' === $status_filter || $status_filter === $this->repository->sanitize_status( (string) ( $override['status'] ?? '' ) ) )
					&& ( '' === $search || false !== stripos( $label, $search ) );
			},
			ARRAY_FILTER_USE_BOTH
		);
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
					<option value="migrated" <?php selected( $status_filter, 'migrated' ); ?>><?php esc_html_e( 'Migrated only', 'project-overrides' ); ?></option>
				</select>
				<button class="button" type="submit"><?php esc_html_e( 'Filter', 'project-overrides' ); ?></button>
				<?php if ( '' !== $search || '' !== $status_filter ) : ?>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Clear', 'project-overrides' ); ?></a>
				<?php endif; ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Scope', 'project-overrides' ); ?></th><th><?php esc_html_e( 'CSS lines', 'project-overrides' ); ?></th><th><?php esc_html_e( 'Last modified', 'project-overrides' ); ?></th><th><?php esc_html_e( 'Status', 'project-overrides' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $global_visible ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><strong><?php esc_html_e( 'Global CSS', 'project-overrides' ); ?></strong></a>
							<?php $this->render_metadata_summary( $this->repository->get_global_reason() ); ?>
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
							<?php $this->render_metadata_summary( $this->repository->get_page_reason( (int) $page->ID ) ); ?>
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
				<?php foreach ( $scoped as $scope => $override ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'scope' => $scope ), admin_url( 'admin.php' ) ) ); ?>"><strong><?php echo esc_html( $scope_labels[ $scope ] ?? $scope ); ?></strong></a>
							<?php $this->render_metadata_summary( (string) ( $override['reason'] ?? '' ) ); ?>
							<div class="row-actions">
								<span class="delete">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="project_overrides_delete">
										<input type="hidden" name="type" value="scoped">
										<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
										<?php wp_nonce_field( 'project_overrides_delete_scoped_' . $scope ); ?>
										<button type="submit" class="button-link-delete project-overrides-delete"><?php esc_html_e( 'Delete override', 'project-overrides' ); ?></button>
									</form>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( (string) $this->repository->line_count( (string) ( $override['css'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( ! empty( $override['modified'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $override['modified'] ) : '—' ); ?></td>
						<td><?php $this->render_status_badge( $this->repository->sanitize_status( (string) ( $override['status'] ?? '' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $pages && ! $global_visible && ! $scoped ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No matching overrides.', 'project-overrides' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private function render_status_badge( string $status ): void {
		$labels = array(
			'temporary' => __( 'Temporary', 'project-overrides' ),
			'permanent' => __( 'Permanent', 'project-overrides' ),
			'migrated'  => __( 'Migrated', 'project-overrides' ),
		);
		$label  = $labels[ $status ] ?? $labels['temporary'];
		printf( '<span class="project-overrides__badge project-overrides__badge--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $label ) );
	}

	private function render_metadata_summary( string $reason ): void {
		if ( '' === $reason ) {
			return;
		}
		?>
		<div class="description">
			<?php echo esc_html( $reason ); ?>
		</div>
		<?php
	}

	public function save_css_page(): void {
		$this->guard();
		check_admin_referer( 'project_overrides_save_css' );

		$global_css    = isset( $_POST['global_css'] ) ? wp_unslash( $_POST['global_css'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSS must retain its syntax.
		$global_status = isset( $_POST['global_status'] ) ? sanitize_key( wp_unslash( $_POST['global_status'] ) ) : 'temporary';
		$global_reason = isset( $_POST['global_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['global_reason'] ) ) : '';
		$post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$page_css      = '';
		$page_status   = 'temporary';
		$page_reason   = '';
		$scoped_key    = isset( $_POST['scoped_key'] ) ? sanitize_text_field( wp_unslash( $_POST['scoped_key'] ) ) : '';
		$scoped_css    = isset( $_POST['scoped_css'] ) ? wp_unslash( $_POST['scoped_css'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSS must retain its syntax.
		$scoped_status = isset( $_POST['scoped_status'] ) ? sanitize_key( wp_unslash( $_POST['scoped_status'] ) ) : 'temporary';
		$scoped_reason = isset( $_POST['scoped_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['scoped_reason'] ) ) : '';
		if ( $post_id && 'page' === get_post_type( $post_id ) ) {
			$this->guard_page( $post_id );
			$page_css    = isset( $_POST['page_css'] ) ? wp_unslash( $_POST['page_css'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSS must retain its syntax.
			$page_status = isset( $_POST['page_status'] ) ? sanitize_key( wp_unslash( $_POST['page_status'] ) ) : 'temporary';
			$page_reason = isset( $_POST['page_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['page_reason'] ) ) : '';
		}

		$global_modified = isset( $_POST['global_modified'] ) ? absint( $_POST['global_modified'] ) : 0;
		$page_modified   = isset( $_POST['page_modified'] ) ? absint( $_POST['page_modified'] ) : 0;
		$error           = $this->preflight_save( (string) $global_css, $global_modified, $post_id, (string) $page_css, $page_modified );
		if ( ! is_wp_error( $error ) && $scoped_key ) {
			$error = isset( $this->get_general_scope_options()[ $scoped_key ] )
				? $this->repository->validate_css( (string) $scoped_css )
				: new \WP_Error( 'invalid_scope', __( 'The selected override scope is invalid.', 'project-overrides' ) );
		}

		if ( is_wp_error( $error ) ) {
			$this->store_css_draft(
				array(
					'error'         => $error->get_error_message(),
					'global_css'    => (string) $global_css,
					'global_status' => $global_status,
					'page_css'      => (string) $page_css,
					'page_status'   => $page_status,
					'global_reason' => $global_reason,
						'page_reason'   => $page_reason,
						'scoped_key'    => $scoped_key,
						'scoped_css'    => (string) $scoped_css,
						'scoped_status' => $scoped_status,
						'scoped_reason' => $scoped_reason,
					)
				);
				$this->redirect_to_css_page( $post_id, $scoped_key );
		}

		$this->repository->save_global( (string) $global_css, $global_status, $global_modified, $global_reason );
		if ( $post_id && 'page' === get_post_type( $post_id ) ) {
			$this->repository->save_page( $post_id, (string) $page_css, $page_status, $page_modified, $page_reason );
		}
		if ( $scoped_key ) {
			$this->repository->save_scoped_override( $scoped_key, (string) $scoped_css, $scoped_status, $scoped_reason );
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
		if ( $scoped_key ) {
			$url = add_query_arg( 'scope', $scoped_key, $url );
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

	private function redirect_to_css_page( int $post_id = 0, string $scope = '' ): void {
		$url = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		if ( $post_id ) {
			$url = add_query_arg( 'post_id', $post_id, $url );
		}
		if ( $scope ) {
			$url = add_query_arg( 'scope', $scope, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public function render_export_page(): void {
		$this->guard();
		$export = $this->build_export();
		$pages  = array_filter(
			$this->repository->get_pages_with_overrides(),
			static fn( WP_Post $page ): bool => current_user_can( 'edit_post', (int) $page->ID )
		);
		$scope_labels = $this->get_general_scope_options();
		$scoped       = $this->repository->get_scoped_overrides();
		?>
		<div class="wrap project-overrides">
			<h1><?php esc_html_e( 'Export CSS', 'project-overrides' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Migration state updated.', 'project-overrides' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Select overrides for migration. Migrated overrides remain stored and revisioned, but are no longer emitted on the front end.', 'project-overrides' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="project_overrides_migrate">
				<?php wp_nonce_field( 'project_overrides_migrate' ); ?>
				<fieldset class="project-overrides__migration-list">
					<legend class="screen-reader-text"><?php esc_html_e( 'Overrides to migrate', 'project-overrides' ); ?></legend>
					<?php if ( '' !== trim( $this->repository->get_global_css() ) ) : ?>
						<label><input type="checkbox" name="selected[]" value="global"> <?php esc_html_e( 'Global CSS', 'project-overrides' ); ?></label>
					<?php endif; ?>
					<?php foreach ( $pages as $page ) : ?>
						<?php $page_title = get_the_title( $page ); ?>
						<label><input type="checkbox" name="selected[]" value="page:<?php echo esc_attr( (string) $page->ID ); ?>"> <?php echo esc_html( $page_title ? $page_title : __( '(no title)', 'project-overrides' ) ); ?></label>
					<?php endforeach; ?>
					<?php foreach ( $scoped as $scope => $override ) : ?>
						<?php if ( '' !== trim( (string) ( $override['css'] ?? '' ) ) ) : ?>
							<label><input type="checkbox" name="selected[]" value="scope:<?php echo esc_attr( $scope ); ?>"> <?php echo esc_html( $scope_labels[ $scope ] ?? $scope ); ?></label>
						<?php endif; ?>
					<?php endforeach; ?>
				</fieldset>
				<div class="project-overrides__export-actions">
					<button type="button" class="button project-overrides-copy" data-target="project-overrides-export"><?php esc_html_e( 'Copy complete export', 'project-overrides' ); ?></button>
					<button type="submit" class="button button-primary" name="operation" value="download"><?php esc_html_e( 'Download selected', 'project-overrides' ); ?></button>
					<button type="submit" class="button" name="operation" value="mark"><?php esc_html_e( 'Mark selected migrated', 'project-overrides' ); ?></button>
					<button type="submit" class="button button-link-delete project-overrides-delete" name="operation" value="delete"><?php esc_html_e( 'Delete selected', 'project-overrides' ); ?></button>
				</div>
			</form>
			<textarea id="project-overrides-export" class="project-overrides-editor project-overrides-editor--readonly" rows="30" readonly><?php echo esc_textarea( $export ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * @param string[]|null $selected Selection keys, or null for all.
	 */
	private function build_export( ?array $selected = null ): string {
		$pages = array();
		$scopes = array();
		foreach ( $this->repository->get_pages_with_overrides() as $page ) {
			if ( ! current_user_can( 'edit_post', (int) $page->ID ) ) {
				continue;
			}
			if ( null !== $selected && ! in_array( 'page:' . $page->ID, $selected, true ) ) {
				continue;
			}
			$pages[] = array(
				'id'    => (int) $page->ID,
				'title' => get_the_title( $page ) ? get_the_title( $page ) : __( 'Untitled', 'project-overrides' ),
				'css'   => $this->repository->get_page_css( (int) $page->ID ),
			);
		}

		$global = null === $selected || in_array( 'global', $selected, true ) ? $this->repository->get_global_css() : '';
		$scope_labels = $this->get_general_scope_options();
		foreach ( $this->repository->get_scoped_overrides() as $scope => $override ) {
			if ( null !== $selected && ! in_array( 'scope:' . $scope, $selected, true ) ) {
				continue;
			}
			$scopes[] = array(
				'label' => $scope_labels[ $scope ] ?? $scope,
				'css'   => (string) ( $override['css'] ?? '' ),
			);
		}
		return $this->exporter->build( $global, $pages, $scopes );
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

	public function migration_action(): void {
		$this->guard();
		check_admin_referer( 'project_overrides_migrate' );

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$selected  = array();
		$submitted = isset( $_POST['selected'] ) ? wp_unslash( $_POST['selected'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each scalar value is sanitized below.
		if ( is_array( $submitted ) ) {
			foreach ( $submitted as $selection ) {
				if ( is_string( $selection ) ) {
					$selected[] = sanitize_text_field( $selection );
				}
			}
			$selected = array_values( array_filter( $selected ) );
		}

		if ( ! $selected ) {
			wp_die( esc_html__( 'Select at least one override.', 'project-overrides' ), '', array( 'response' => 400 ) );
		}

		if ( 'download' === $operation ) {
			nocache_headers();
			header( 'Content-Type: text/css; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="project-overrides-selected-' . gmdate( 'Y-m-d' ) . '.css"' );
			echo $this->build_export( $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS download, sanitized on storage.
			exit;
		}

		if ( ! in_array( $operation, array( 'mark', 'delete' ), true ) ) {
			wp_die( esc_html__( 'Invalid migration action.', 'project-overrides' ), '', array( 'response' => 400 ) );
		}

		$scoped_overrides = $this->repository->get_scoped_overrides();
		foreach ( $selected as $key ) {
			if ( preg_match( '/^page:(\d+)$/', $key, $matches ) ) {
				$post_id = (int) $matches[1];
				if ( 'page' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
					wp_die( esc_html__( 'You are not allowed to migrate one of the selected overrides.', 'project-overrides' ), '', array( 'response' => 403 ) );
				}
			}
			if ( preg_match( '/^scope:(pattern:\d+|class:[A-Za-z0-9_-]+)$/', $key, $matches )
				&& ! isset( $scoped_overrides[ $matches[1] ] ) ) {
				wp_die( esc_html__( 'One selected scoped override no longer exists.', 'project-overrides' ), '', array( 'response' => 400 ) );
			}
		}

		foreach ( $selected as $key ) {
			if ( 'global' === $key ) {
				$this->repository->save_global(
					'delete' === $operation ? '' : $this->repository->get_global_css(),
					'delete' === $operation ? 'temporary' : 'migrated',
					$this->repository->get_global_modified(),
					$this->repository->get_global_reason()
				);
				continue;
			}
			if ( preg_match( '/^scope:(pattern:\d+|class:[A-Za-z0-9_-]+)$/', $key, $matches ) ) {
				$scope    = $matches[1];
				$override = $this->repository->get_scoped_override( $scope );
				$this->repository->save_scoped_override(
					$scope,
					'delete' === $operation ? '' : $override['css'],
					'delete' === $operation ? 'temporary' : 'migrated',
					$override['reason']
				);
				continue;
			}

			if ( ! preg_match( '/^page:(\d+)$/', $key, $matches ) ) {
				continue;
			}
			$post_id = (int) $matches[1];
			if ( 'page' !== get_post_type( $post_id ) ) {
				continue;
			}
			$this->guard_page( $post_id );
			$this->repository->save_page(
				$post_id,
				'delete' === $operation ? '' : $this->repository->get_page_css( $post_id ),
				'delete' === $operation ? 'temporary' : 'migrated',
				$this->repository->get_page_modified( $post_id ),
				$this->repository->get_page_reason( $post_id )
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'project-overrides-export',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function rollback_override(): void {
		$this->guard();
		$type        = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$revision_id = isset( $_POST['revision_id'] ) ? sanitize_text_field( wp_unslash( $_POST['revision_id'] ) ) : '';
		$post_id     = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$scope       = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : '';
		check_admin_referer( 'project_overrides_rollback_' . $type . '_' . $revision_id );

		if ( 'global' === $type ) {
			$result = $this->repository->rollback_global( $revision_id );
		} elseif ( 'page' === $type && $post_id && 'page' === get_post_type( $post_id ) ) {
			$this->guard_page( $post_id );
			$result = $this->repository->rollback_page( $post_id, $revision_id );
		} elseif ( 'scoped' === $type && $scope ) {
			$result = $this->repository->rollback_scoped( $scope, $revision_id );
		} else {
			wp_die( esc_html__( 'Invalid revision.', 'project-overrides' ), '', array( 'response' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}
		$this->redirect_to_css_page( $post_id, $scope );
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
		} elseif ( 'scoped' === $type ) {
			$scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : '';
			check_admin_referer( 'project_overrides_delete_scoped_' . $scope );
			$result = $this->repository->save_scoped_override( $scope, '', 'temporary' );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
			}
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
		$scope          = (string) get_post_meta( (int) $post->ID, self::SCOPE_META, true );
		$pattern_scopes = $this->get_pattern_scopes();
		$class_names    = array_values( array_unique( array_merge( $this->class_names->get(), $this->get_page_block_classes( (int) $post->ID ) ) ) );
		$valid_scopes   = array_merge(
			array( 'global', 'page' ),
			array_map( static fn( int $id ): string => 'pattern:' . $id, array_keys( $pattern_scopes ) ),
			array_map( static fn( string $name ): string => 'class:' . $name, $class_names )
		);
		$scope          = in_array( $scope, $valid_scopes, true ) ? $scope : 'page';
		$css            = $this->repository->get_page_css( (int) $post->ID );
		$status         = $this->repository->get_page_status( (int) $post->ID );
		$reason         = $this->repository->get_page_reason( (int) $post->ID );
		if ( 'global' === $scope ) {
			$css    = $this->repository->get_global_css();
			$status = $this->repository->get_global_status();
			$reason = $this->repository->get_global_reason();
		} elseif ( str_starts_with( $scope, 'pattern:' ) || str_starts_with( $scope, 'class:' ) ) {
			$override = $this->repository->get_scoped_override( $scope );
			$css      = $override['css'];
			$status   = $override['status'];
			$reason   = $override['reason'];
		}

		$this->render_status( 'project_overrides_status', $status );
		?>
		<p>
			<label for="project-overrides-scope"><strong><?php esc_html_e( 'Override scope', 'project-overrides' ); ?></strong></label>
			<select id="project-overrides-scope" name="project_overrides_scope">
				<option value="global" <?php selected( $scope, 'global' ); ?>><?php esc_html_e( 'Global', 'project-overrides' ); ?></option>
				<option value="page" <?php selected( $scope, 'page' ); ?>><?php esc_html_e( 'Page', 'project-overrides' ); ?></option>
				<?php foreach ( $pattern_scopes as $pattern_id => $label ) : ?>
					<option value="pattern:<?php echo esc_attr( (string) $pattern_id ); ?>" <?php selected( $scope, 'pattern:' . $pattern_id ); ?>><?php echo esc_html( 'Pattern: ' . $label ); ?></option>
				<?php endforeach; ?>
				<?php foreach ( $class_names as $class_name ) : ?>
					<option value="class:<?php echo esc_attr( $class_name ); ?>" <?php selected( $scope, 'class:' . $class_name ); ?>><?php echo esc_html( 'Block class: ' . $class_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description"><?php esc_html_e( 'Global CSS loads everywhere. Page CSS loads here. Pattern scopes require a synced pattern; block-class CSS is limited by its selector.', 'project-overrides' ); ?></p>
		<div class="project-overrides-scope-indicator" data-scope-indicator><?php echo esc_html( 'Editing: ' . $this->scope_label( $scope, (int) $post->ID ) ); ?></div>
		<textarea id="project-overrides-meta-css" class="project-overrides-editor" name="project_overrides_css" rows="12"><?php echo esc_textarea( $css ); ?></textarea>
		<p>
			<label for="project-overrides-meta-reason"><strong><?php esc_html_e( 'Reason / handoff note', 'project-overrides' ); ?></strong></label>
			<input id="project-overrides-meta-reason" class="widefat" type="text" name="project_overrides_reason" maxlength="500" value="<?php echo esc_attr( $reason ); ?>">
		</p>
		<?php $this->render_scope_diagnostics( $scope, (int) $post->ID, $status ); ?>
		<?php
	}

	public function save_meta_box( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['project_overrides_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['project_overrides_meta_nonce'] ) ), 'project_overrides_save_meta' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( self::CAPABILITY ) || ! current_user_can( 'edit_post', $post_id ) || 'page' !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['project_overrides_css'] ) ) {
			return;
		}

		$scope    = isset( $_POST['project_overrides_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['project_overrides_scope'] ) ) : 'page';
		$css      = (string) wp_unslash( $_POST['project_overrides_css'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CSS must retain its syntax.
		$status   = isset( $_POST['project_overrides_status'] ) ? sanitize_key( wp_unslash( $_POST['project_overrides_status'] ) ) : 'temporary';
		$reason   = isset( $_POST['project_overrides_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['project_overrides_reason'] ) ) : '';
		if ( 'global' === $scope ) {
			$result = $this->repository->save_global( $css, $status, null, $reason );
		} elseif ( 'page' === $scope ) {
			$result = $this->repository->save_page( $post_id, $css, $status, null, $reason );
		} else {
			$result = $this->repository->save_scoped_override( $scope, $css, $status, $reason );
		}
		if ( is_wp_error( $result ) ) {
			set_transient( 'project_overrides_notice_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		} else {
			update_post_meta( $post_id, self::SCOPE_META, $scope );
		}
	}

	/**
	 * @return array<int, string>
	 */
	private function get_pattern_scopes(): array {
		$patterns = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$scopes   = array();
		foreach ( $patterns as $pattern ) {
			$scopes[ (int) $pattern->ID ] = $pattern->post_title ? $pattern->post_title : __( '(no title)', 'project-overrides' );
		}
		return $scopes;
	}

	/**
	 * @return array<string, string>
	 */
	private function get_general_scope_options(): array {
		$options = array();
		foreach ( $this->get_pattern_scopes() as $pattern_id => $label ) {
			$options[ 'pattern:' . $pattern_id ] = 'Pattern: ' . $label;
		}
		foreach ( $this->class_names->get() as $class_name ) {
			$options[ 'class:' . $class_name ] = 'Block class: ' . $class_name;
		}
		foreach ( array_keys( $this->repository->get_scoped_overrides() ) as $scope ) {
			if ( ! isset( $options[ $scope ] ) ) {
				$options[ $scope ] = str_starts_with( $scope, 'class:' )
					? 'Block class: ' . substr( $scope, 6 )
					: $scope;
			}
		}
		return $options;
	}

	private function scope_label( string $scope, int $post_id = 0 ): string {
		if ( 'global' === $scope ) {
			return __( 'Global', 'project-overrides' );
		}
		if ( 'page' === $scope ) {
			$title = $post_id ? get_the_title( $post_id ) : '';
			return $title ? sprintf( 'Page: %s', $title ) : __( 'Page', 'project-overrides' );
		}
		return $this->get_general_scope_options()[ $scope ] ?? $scope;
	}

	/**
	 * @return string[]
	 */
	private function get_page_block_classes( int $post_id ): array {
		if ( ! $post_id || ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		$classes = array();
		$walk    = static function ( array $blocks ) use ( &$walk, &$classes ): void {
			foreach ( $blocks as $block ) {
				$class_name = (string) ( $block['attrs']['className'] ?? '' );
				foreach ( preg_split( '/\s+/', $class_name ) ?: array() as $class ) {
					if ( preg_match( '/^[co]-[A-Za-z0-9_-]+$/', $class ) ) {
						$classes[] = $class;
					}
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) );
		return array_values( array_unique( $classes ) );
	}

	/**
	 * @return WP_Post[]
	 */
	private function get_pages_using_pattern( int $pattern_id ): array {
		$pages = array();
		foreach ( $this->repository->get_all_pages() as $page ) {
			if ( preg_match( '/<!--\s+wp:block\s+{[^}]*"ref"\s*:\s*' . $pattern_id . '\b/', (string) $page->post_content ) ) {
				$pages[] = $page;
			}
		}
		return $pages;
	}

	private function render_scope_diagnostics( string $scope, int $post_id, string $status ): void {
		$is_active = 'migrated' !== $status;
		?>
		<div class="project-overrides-diagnostics">
			<strong><?php esc_html_e( 'Diagnostics', 'project-overrides' ); ?></strong>
			<span><?php echo esc_html( $is_active ? __( 'Active', 'project-overrides' ) : __( 'Inactive: migrated', 'project-overrides' ) ); ?></span>
			<?php if ( 'global' === $scope ) : ?>
				<span><?php esc_html_e( 'Emitted on every front-end request.', 'project-overrides' ); ?></span>
			<?php elseif ( 'page' === $scope ) : ?>
				<span><?php esc_html_e( 'Emitted only on this page.', 'project-overrides' ); ?></span>
				<?php if ( $post_id && 'publish' === get_post_status( $post_id ) ) : ?>
					<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View page', 'project-overrides' ); ?></a>
				<?php endif; ?>
			<?php elseif ( preg_match( '/^pattern:(\d+)$/', $scope, $matches ) ) : ?>
				<?php $pages = $this->get_pages_using_pattern( (int) $matches[1] ); ?>
				<span>
					<?php
					printf(
						/* translators: %d: Number of matching pages. */
						esc_html( _n( 'Matched on %d page.', 'Matched on %d pages.', count( $pages ), 'project-overrides' ) ),
						(int) count( $pages )
					);
					?>
				</span>
				<?php foreach ( array_slice( $pages, 0, 5 ) as $page ) : ?>
					<a href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $page ) ); ?></a>
				<?php endforeach; ?>
			<?php else : ?>
				<span><?php esc_html_e( 'Emitted globally; the CSS selector controls matching elements.', 'project-overrides' ); ?></span>
			<?php endif; ?>
		</div>
		<?php
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
