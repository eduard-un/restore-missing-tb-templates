<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RMTBT_Admin {

	private $page_hook;

	const TEMPLATE_PT = 'et_template';
	const HEADER_PT   = 'et_header_layout';
	const BODY_PT     = 'et_body_layout';
	const FOOTER_PT   = 'et_footer_layout';
	const TB_ROOT_PT  = 'et_theme_builder';
	const UNUSED_META = '_et_theme_builder_marked_as_unused';
	const TRASH_DAYS  = 7;

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_post_rmtbt_restore_template', array( $this, 'handle_restore_template' ) );
		add_action( 'admin_post_rmtbt_export_part', array( $this, 'handle_export_part' ) );
		add_action( 'admin_post_rmtbt_restore_all', array( $this, 'handle_restore_all' ) );
		add_action( 'admin_post_rmtbt_restore_revision', array( $this, 'handle_restore_revision' ) );
	}

	public function register_menu() {
		$this->page_hook = add_submenu_page(
			'et_divi_options',
			'Restore TB Templates',
			'Restore TB Templates',
			'manage_options',
			'rmtbt',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'rmtbt-admin',
			RMTBT_URL . 'includes/admin.css',
			array(),
			RMTBT_VERSION
		);

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		if ( $tab === 'revisions' && isset( $_GET['part_id'] ) ) {
			wp_enqueue_style( 'revisions' );
		}
	}

	// -------------------------
	// DATA METHODS
	// -------------------------

	private function get_deleted_templates() {
		return get_posts( array(
			'post_type'      => self::TEMPLATE_PT,
			'post_status'    => array( 'publish', 'trash' ),
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => self::UNUSED_META,
					'compare' => 'EXISTS',
				),
			),
		) );
	}

	private function get_deleted_parts() {
		return get_posts( array(
			'post_type'      => array( self::HEADER_PT, self::BODY_PT, self::FOOTER_PT ),
			'post_status'    => array( 'publish', 'trash' ),
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => self::UNUSED_META,
					'compare' => 'EXISTS',
				),
			),
		) );
	}

	/**
	 * Returns all template parts (active + deleted) that have at least one revision,
	 * along with their revision posts.
	 */
	private function get_parts_with_revisions() {
		$all_parts = get_posts( array(
			'post_type'      => array( self::HEADER_PT, self::BODY_PT, self::FOOTER_PT ),
			'post_status'    => array( 'publish', 'trash' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$result = array();

		foreach ( $all_parts as $part ) {
			$revisions = wp_get_post_revisions( $part->ID, array( 'order' => 'DESC' ) );
			if ( ! empty( $revisions ) ) {
				$result[] = array(
					'part'      => $part,
					'revisions' => array_values( $revisions ),
				);
			}
		}

		return $result;
	}

	/**
	 * Find the parent template that references this part via _et_*_layout_id meta.
	 */
	private function get_parent_template_for_part( $part_id, $post_type ) {
		$meta_key = '_' . $post_type . '_id';

		$results = get_posts( array(
			'post_type'      => self::TEMPLATE_PT,
			'post_status'    => array( 'publish', 'trash' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => $meta_key,
					'value'   => $part_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		) );

		return ! empty( $results ) ? get_post( $results[0] ) : null;
	}

	private function get_root_post() {
		$posts = get_posts( array(
			'post_type'      => self::TB_ROOT_PT,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		) );
		return $posts ? $posts[0] : null;
	}

	private function get_part_meta_key( $post_type ) {
		return '_' . $post_type . '_id';
	}

	private function get_template_parts_from_meta( $template_id ) {
		$parts    = array();
		$part_map = array(
			'header' => '_et_header_layout_id',
			'body'   => '_et_body_layout_id',
			'footer' => '_et_footer_layout_id',
		);

		foreach ( $part_map as $type => $meta_key ) {
			$part_id = (int) get_post_meta( $template_id, $meta_key, true );
			if ( $part_id > 0 ) {
				$post = get_post( $part_id );
				if ( $post ) {
					$parts[ $type ] = $post;
				}
			}
		}

		return $parts;
	}

	private function days_until_trash( $marked_date ) {
		$marked_ts = strtotime( $marked_date );
		$trash_ts  = $marked_ts + ( self::TRASH_DAYS * DAY_IN_SECONDS );
		$remaining = $trash_ts - time();
		return max( 0, (int) ceil( $remaining / DAY_IN_SECONDS ) );
	}

	private function format_use_on( $raw ) {
		if ( empty( $raw ) ) {
			return '&mdash;';
		}

		$shortcuts = array(
			'all'                     => 'Entire Website',
			'singular'                => 'All Singular Pages',
			'archive'                 => 'All Archives',
			'home'                    => 'Home Page',
			'search'                  => 'Search Results',
			'404'                     => '404 Page',
			'singular:post_type:page' => 'All Pages',
			'singular:post_type:post' => 'All Posts',
		);

		if ( isset( $shortcuts[ $raw ] ) ) {
			return $shortcuts[ $raw ];
		}

		$parts = explode( ':', $raw );

		if ( count( $parts ) >= 5 && $parts[3] === 'id' ) {
			$post = get_post( (int) $parts[4] );
			$name = $post ? '"' . esc_html( $post->post_title ) . '"' : '#' . (int) $parts[4];
			return esc_html( ucfirst( $parts[0] ) ) . ': ' . esc_html( $parts[2] ) . ' &rarr; ' . $name;
		}

		if ( count( $parts ) >= 3 && $parts[1] === 'post_type' ) {
			return esc_html( ucfirst( $parts[0] ) ) . ': All ' . esc_html( ucfirst( $parts[2] ) ) . 's';
		}

		return esc_html( $raw );
	}

	private function is_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	private function get_divi_version() {
		$theme = wp_get_theme();
		if ( $theme->get( 'Name' ) === 'Divi' || $theme->get( 'Template' ) === 'Divi' ) {
			return version_compare( $theme->get( 'Version' ), '5.0', '>=' ) ? 5 : 4;
		}
		return null;
	}

	// -------------------------
	// RESTORE METHODS
	// -------------------------

	private function restore_template( $template_id ) {
		$template_id = (int) $template_id;

		if ( get_post_status( $template_id ) === 'trash' ) {
			wp_untrash_post( $template_id );
		}

		delete_post_meta( $template_id, self::UNUSED_META );
		update_post_meta( $template_id, '_et_enabled', '1' );

		$root = $this->get_root_post();
		if ( $root ) {
			$existing = get_post_meta( $root->ID, '_et_template', false );
			if ( ! in_array( (string) $template_id, array_map( 'strval', (array) $existing ), true ) ) {
				add_post_meta( $root->ID, '_et_template', $template_id );
			}
		}

		foreach ( $this->get_template_parts_from_meta( $template_id ) as $part ) {
			$this->restore_part_only( $part->ID );
		}
	}

	private function restore_part_only( $part_id ) {
		$part_id = (int) $part_id;

		if ( get_post_status( $part_id ) === 'trash' ) {
			wp_untrash_post( $part_id );
		}

		delete_post_meta( $part_id, self::UNUSED_META );
	}

	// -------------------------
	// ACTION HANDLERS
	// -------------------------

	public function handle_restore_template() {
		check_admin_referer( 'rmtbt_restore_template' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( $id ) {
			$this->restore_template( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rmtbt&tab=templates&restored=template' ) );
		exit;
	}

	public function handle_export_part() {
		check_admin_referer( 'rmtbt_export_part' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$part_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $part_id ) {
			wp_die( 'Invalid part ID' );
		}

		$part = get_post( $part_id );
		if ( ! $part || ! in_array( $part->post_type, array( self::HEADER_PT, self::BODY_PT, self::FOOTER_PT ), true ) ) {
			wp_die( 'Template part not found' );
		}

		// --- Presets ---
		// Collect text from preset attrs so we can scan it for gcid- references too.
		$presets       = null;
		$preset_source = '';

		preg_match_all( '/"modulePreset":\[([^\]]+)\]/', $part->post_content, $mp_matches );
		$preset_ids = array();
		foreach ( $mp_matches[1] as $match ) {
			preg_match_all( '/"([a-z0-9]+)"/', $match, $id_matches );
			foreach ( $id_matches[1] as $id ) {
				$preset_ids[] = $id;
			}
		}
		$preset_ids = array_unique( $preset_ids );

		if ( ! empty( $preset_ids ) ) {
			$all_presets = et_get_option( 'builder_global_presets_d5', array(), '', true, false, '', '', true );
			$used_presets = array();

			if ( is_array( $all_presets ) ) {
				foreach ( array( 'module', 'group' ) as $preset_type ) {
					if ( ! isset( $all_presets[ $preset_type ] ) ) {
						continue;
					}
					foreach ( $all_presets[ $preset_type ] as $module_name => $module_data ) {
						if ( ! isset( $module_data['items'] ) ) {
							continue;
						}
						foreach ( $preset_ids as $preset_id ) {
							if ( ! isset( $module_data['items'][ $preset_id ] ) ) {
								continue;
							}
							if ( ! isset( $used_presets[ $preset_type ][ $module_name ] ) ) {
								$used_presets[ $preset_type ][ $module_name ] = array(
									'default' => isset( $module_data['default'] ) ? $module_data['default'] : '',
									'items'   => array(),
								);
							}
							$item = $module_data['items'][ $preset_id ];
							unset( $item['renderAttrs'] );
							$used_presets[ $preset_type ][ $module_name ]['items'][ $preset_id ] = $item;
							$preset_source .= wp_json_encode( $item );
						}
					}
				}
			}

			if ( ! empty( $used_presets ) ) {
				$presets = $used_presets;
			}
		}

		// --- Global Colors ---
		// Scan both post content and preset attrs for gcid- references.
		preg_match_all( '/gcid-[a-z0-9]+/', $part->post_content . $preset_source, $color_matches );
		$referenced_ids = array_unique( $color_matches[0] );

		$global_colors = array();
		if ( ! empty( $referenced_ids ) ) {
			$global_data = maybe_unserialize( et_get_option( 'et_global_data' ) );
			$all_colors  = isset( $global_data['global_colors'] ) ? $global_data['global_colors'] : array();

			foreach ( $referenced_ids as $color_id ) {
				if ( isset( $all_colors[ $color_id ] ) ) {
					$raw           = $all_colors[ $color_id ];
					$global_colors[] = array(
						$color_id,
						array(
							'color'  => isset( $raw['color'] ) ? $raw['color'] : '',
							'status' => isset( $raw['status'] ) ? $raw['status'] : 'active',
							'label'  => isset( $raw['label'] ) ? $raw['label'] : '',
						),
					);
				}
			}
		}

		// --- Build export ---
		$custom_css = get_post_meta( $part_id, '_et_pb_custom_css', false );

		$export = array(
			'context'            => 'et_builder',
			'data'               => array( $part_id => $part->post_content ),
			'presets'            => $presets,
			'global_colors'      => $global_colors,
			'global_variables'   => array(),
			'page_settings_meta' => array(
				'_et_pb_custom_css' => $custom_css ?: array( '' ),
			),
			'canvases'           => array(
				'local'  => array(),
				'global' => array(),
			),
			'images'             => array(),
			'thumbnails'         => array(),
		);

		$filename = sanitize_file_name( $part->post_title ) . '.json';

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function handle_restore_all() {
		check_admin_referer( 'rmtbt_restore_all' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		foreach ( $this->get_deleted_templates() as $template ) {
			$this->restore_template( $template->ID );
		}

		foreach ( $this->get_deleted_parts() as $part ) {
			$this->restore_part_only( $part->ID );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rmtbt&restored=all' ) );
		exit;
	}

	public function handle_restore_revision() {
		check_admin_referer( 'rmtbt_restore_revision' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$revision_id = isset( $_POST['revision_id'] ) ? (int) $_POST['revision_id'] : 0;
		$part_id     = isset( $_POST['part_id'] ) ? (int) $_POST['part_id'] : 0;

		if ( $revision_id ) {
			// Restore the revision content to the parent part post
			wp_restore_post_revision( $revision_id );

			// Also un-mark the part as unused if it was marked
			if ( $part_id ) {
				$this->restore_part_only( $part_id );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rmtbt&tab=revisions&part_id=' . $part_id . '&restored=revision' ) );
		exit;
	}

	// -------------------------
	// RENDER
	// -------------------------

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab        = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'templates';
		$deleted_templates = $this->get_deleted_templates();
		$deleted_parts     = $this->get_deleted_parts();
		$divi_version      = $this->get_divi_version();

		?>
		<div class="wrap rmtbt-wrap">
			<h1>Restore Divi Theme Builder Templates</h1>

			<?php $this->render_notices( $divi_version, $deleted_templates, $deleted_parts ); ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rmtbt&tab=templates' ) ); ?>" class="nav-tab <?php echo $active_tab === 'templates' ? 'nav-tab-active' : ''; ?>">
					Templates
					<?php if ( ! empty( $deleted_templates ) ) : ?>
						<span class="rmtbt-count"><?php echo count( $deleted_templates ); ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rmtbt&tab=parts' ) ); ?>" class="nav-tab <?php echo $active_tab === 'parts' ? 'nav-tab-active' : ''; ?>">
					Template Parts
					<?php if ( ! empty( $deleted_parts ) ) : ?>
						<span class="rmtbt-count"><?php echo count( $deleted_parts ); ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rmtbt&tab=revisions' ) ); ?>" class="nav-tab <?php echo $active_tab === 'revisions' ? 'nav-tab-active' : ''; ?>">
					Revisions
				</a>
			</nav>

			<div class="rmtbt-tab-content">
				<?php if ( $active_tab === 'templates' ) : ?>
					<?php $this->render_templates_tab( $deleted_templates ); ?>
				<?php elseif ( $active_tab === 'parts' ) : ?>
					<?php $this->render_parts_tab( $deleted_parts ); ?>
				<?php else : ?>
					<?php $this->render_revisions_tab(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_notices( $divi_version, $deleted_templates, $deleted_parts ) {
		$restored = isset( $_GET['restored'] ) ? sanitize_key( $_GET['restored'] ) : '';

		if ( $restored === 'template' ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Done.</strong> Template and its linked parts have been restored. Open the Divi Theme Builder to verify.</p></div>';
		} elseif ( $restored === 'all' ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Done.</strong> All deleted templates restored. Orphaned parts have been un-marked — assign them to their templates in the Divi Theme Builder.</p></div>';
		} elseif ( $restored === 'revision' ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Done.</strong> Revision restored. The template part now contains the content from that revision.</p></div>';
		}

		if ( $this->is_wpml_active() ) {
			echo '<div class="notice notice-warning"><p><strong>WPML is active.</strong> For best results, deactivate WPML before restoring, then reactivate afterwards.</p></div>';
		}

		if ( $divi_version === 5 ) {
			echo '<div class="notice notice-info"><p><strong>Divi 5 detected.</strong> After restoring, open the Divi Theme Builder to confirm templates are correctly linked to their parts.</p></div>';
		}

		if ( empty( $deleted_templates ) && empty( $deleted_parts ) && ( ! isset( $_GET['tab'] ) || $_GET['tab'] === 'templates' ) ) {
			echo '<div class="notice notice-success"><p><strong>No deleted or unused templates found.</strong> Your Theme Builder templates appear to be intact.</p></div>';
		}
	}

	// -------------------------
	// TEMPLATES TAB
	// -------------------------

	private function render_templates_tab( $templates ) {
		if ( empty( $templates ) ) {
			echo '<p class="rmtbt-empty">No deleted or unused templates found.</p>';
			return;
		}
		?>
		<div class="rmtbt-toolbar">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rmtbt_restore_all' ); ?>
				<input type="hidden" name="action" value="rmtbt_restore_all">
				<button type="submit" class="button button-primary"
					onclick="return confirm('Restore all <?php echo count( $templates ); ?> template(s) and their parts?')">
					Restore All
				</button>
				<span class="rmtbt-toolbar-note">Restores all deleted templates and their linked parts.</span>
			</form>
		</div>

		<table class="wp-list-table widefat fixed striped rmtbt-table">
			<thead>
				<tr>
					<th class="col-title">Template</th>
					<th class="col-assigned">Assigned To</th>
					<th class="col-parts">Linked Parts</th>
					<th class="col-date">Marked Unused</th>
					<th class="col-status">Status</th>
					<th class="col-actions">Actions</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $templates as $template ) :
				$marked_date = get_post_meta( $template->ID, self::UNUSED_META, true );
				$days_left   = $this->days_until_trash( $marked_date );
				$use_on      = get_post_meta( $template->ID, '_et_use_on', false );
				$is_default  = get_post_meta( $template->ID, '_et_default', true ) === '1';
				$meta_parts  = $this->get_template_parts_from_meta( $template->ID );
				$is_trashed  = $template->post_status === 'trash';
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( $template->post_title ); ?></strong>
						<?php if ( $is_default ) : ?>
							<span class="rmtbt-pill rmtbt-pill-blue">Default</span>
						<?php endif; ?>
						<br><small class="rmtbt-muted">ID: <?php echo $template->ID; ?></small>
					</td>
					<td>
						<?php if ( ! empty( $use_on ) ) : ?>
							<?php foreach ( (array) $use_on as $condition ) : ?>
								<span class="rmtbt-tag"><?php echo $this->format_use_on( $condition ); ?></span>
							<?php endforeach; ?>
						<?php else : ?>
							<span class="rmtbt-muted">&mdash;</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( empty( $meta_parts ) ) : ?>
							<span class="rmtbt-muted">None found in DB</span>
						<?php else : ?>
							<?php foreach ( $meta_parts as $type => $part ) :
								$part_deleted = (bool) get_post_meta( $part->ID, self::UNUSED_META, true );
							?>
								<span class="rmtbt-part-row <?php echo $part_deleted ? 'rmtbt-part-deleted' : 'rmtbt-part-ok'; ?>">
									<strong><?php echo ucfirst( $type ); ?>:</strong>
									<?php echo esc_html( $part->post_title ); ?>
									<?php echo $part_deleted ? ' <em>(deleted)</em>' : ' <em>(active)</em>'; ?>
								</span>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
					<td>
						<span class="rmtbt-date"><?php echo esc_html( $marked_date ); ?></span>
						<?php $this->render_days_chip( $is_trashed, $days_left ); ?>
					</td>
					<td><?php $this->render_status_pill( $is_trashed ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'rmtbt_restore_template' ); ?>
							<input type="hidden" name="action" value="rmtbt_restore_template">
							<input type="hidden" name="post_id" value="<?php echo $template->ID; ?>">
							<button type="submit" class="button button-primary button-small">Restore</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------
	// PARTS TAB
	// -------------------------

	private function render_parts_tab( $parts ) {
		if ( empty( $parts ) ) {
			echo '<p class="rmtbt-empty">No deleted or unused template parts found.</p>';
			return;
		}

		$type_labels      = array(
			self::HEADER_PT => 'Headers',
			self::BODY_PT   => 'Bodies',
			self::FOOTER_PT => 'Footers',
		);

		// Group parts by post type in the fixed Header → Body → Footer order.
		$grouped = array();
		foreach ( array_keys( $type_labels ) as $type ) {
			$grouped[ $type ] = array();
		}
		foreach ( $parts as $part ) {
			if ( isset( $grouped[ $part->post_type ] ) ) {
				$grouped[ $part->post_type ][] = $part;
			}
		}
		?>
		<div class="rmtbt-info-box">
			<strong>Export a template part as JSON</strong> to save its content. You can then re-import it via Divi Theme Builder &rarr; Import.
		</div>

		<?php foreach ( $grouped as $post_type => $group_parts ) :
			if ( empty( $group_parts ) ) continue;
			$section_label = $type_labels[ $post_type ];
			$type_class    = str_replace( '_', '-', $post_type );
		?>
		<h3 class="rmtbt-section-heading">
			<span class="rmtbt-type-pill rmtbt-type-<?php echo esc_attr( $type_class ); ?>">
				<?php echo esc_html( $section_label ); ?>
			</span>
			<span class="rmtbt-section-count"><?php echo count( $group_parts ); ?> item<?php echo count( $group_parts ) !== 1 ? 's' : ''; ?></span>
		</h3>

		<table class="wp-list-table widefat fixed striped rmtbt-table">
			<thead>
				<tr>
					<th class="col-title">Part Title</th>
					<th class="col-date">Marked Unused</th>
					<th class="col-status">Status</th>
					<th class="col-actions">Actions</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $group_parts as $part ) :
				$marked_date = get_post_meta( $part->ID, self::UNUSED_META, true );
				$days_left   = $this->days_until_trash( $marked_date );
				$is_trashed  = $part->post_status === 'trash';
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( $part->post_title ); ?></strong>
						<br><small class="rmtbt-muted">ID: <?php echo $part->ID; ?></small>
					</td>
					<td>
						<span class="rmtbt-date"><?php echo esc_html( $marked_date ); ?></span>
						<?php $this->render_days_chip( $is_trashed, $days_left ); ?>
					</td>
					<td><?php $this->render_status_pill( $is_trashed ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'rmtbt_export_part' ); ?>
							<input type="hidden" name="action" value="rmtbt_export_part">
							<input type="hidden" name="post_id" value="<?php echo $part->ID; ?>">
							<button type="submit" class="button button-secondary button-small">Export JSON</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endforeach; ?>
		<?php
	}

	// -------------------------
	// REVISIONS TAB
	// -------------------------

	private function render_revisions_tab() {
		if ( isset( $_GET['part_id'], $_GET['revision_id'] ) ) {
			$this->render_revision_diff( (int) $_GET['part_id'], (int) $_GET['revision_id'] );
			return;
		}

		$this->render_revisions_overview();
	}

	private function render_revisions_overview() {
		$parts_with_revisions = $this->get_parts_with_revisions();

		if ( empty( $parts_with_revisions ) ) {
			echo '<p class="rmtbt-empty">No template parts with revisions found.</p>';
			return;
		}

		$type_labels = array(
			self::HEADER_PT => 'Header',
			self::BODY_PT   => 'Body',
			self::FOOTER_PT => 'Footer',
		);
		?>
		<table class="wp-list-table widefat fixed striped rmtbt-table">
			<thead>
				<tr>
					<th class="col-title">Part Title</th>
					<th class="col-type">Type</th>
					<th class="col-parent">Parent Template</th>
					<th class="col-revcount">Revisions</th>
					<th class="col-revdate">Latest Revision</th>
					<th class="col-status">Part Status</th>
					<th class="col-actions">Actions</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $parts_with_revisions as $entry ) :
				$part         = $entry['part'];
				$revisions    = $entry['revisions'];
				$latest       = $revisions[0];
				$type_label   = isset( $type_labels[ $part->post_type ] ) ? $type_labels[ $part->post_type ] : $part->post_type;
				$type_class   = str_replace( '_', '-', $part->post_type );
				$is_deleted   = (bool) get_post_meta( $part->ID, self::UNUSED_META, true );
				$is_trashed   = $part->post_status === 'trash';
				$parent_tpl   = $this->get_parent_template_for_part( $part->ID, $part->post_type );
				$rev_url      = admin_url( 'admin.php?page=rmtbt&tab=revisions&part_id=' . $part->ID . '&revision_id=' . $latest->ID );
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( $part->post_title ); ?></strong>
						<br><small class="rmtbt-muted">ID: <?php echo $part->ID; ?></small>
					</td>
					<td>
						<span class="rmtbt-type-pill rmtbt-type-<?php echo esc_attr( $type_class ); ?>">
							<?php echo esc_html( $type_label ); ?>
						</span>
					</td>
					<td>
						<?php if ( $parent_tpl ) : ?>
							<span class="rmtbt-muted"><?php echo esc_html( $parent_tpl->post_title ); ?></span>
						<?php else : ?>
							<span class="rmtbt-muted">&mdash;</span>
						<?php endif; ?>
					</td>
					<td>
						<strong><?php echo count( $revisions ); ?></strong>
					</td>
					<td>
						<span class="rmtbt-date">
							<?php echo esc_html( get_the_date( 'd M Y @ H:i', $latest ) ); ?>
						</span>
						<br><small class="rmtbt-muted">by <?php echo esc_html( get_the_author_meta( 'display_name', $latest->post_author ) ); ?></small>
					</td>
					<td>
						<?php if ( $is_trashed ) : ?>
							<span class="rmtbt-pill rmtbt-pill-red">Trashed</span>
						<?php elseif ( $is_deleted ) : ?>
							<span class="rmtbt-pill rmtbt-pill-orange">Unused</span>
						<?php else : ?>
							<span class="rmtbt-pill rmtbt-pill-green">Active</span>
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( $rev_url ); ?>" class="button button-secondary button-small">
							View Revisions
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_revision_diff( $part_id, $revision_id ) {
		$part     = get_post( $part_id );
		$revision = get_post( $revision_id );

		if ( ! $part || ! $revision || (int) $revision->post_parent !== $part_id ) {
			echo '<p class="rmtbt-empty">Invalid revision or part.</p>';
			return;
		}

		// Load all revisions for navigation (DESC = newest first, index 0 = newest).
		$all_revisions = wp_get_post_revisions( $part_id, array( 'order' => 'DESC' ) );
		$all_revisions = array_values( $all_revisions );
		$total         = count( $all_revisions );

		// Find the position of the current revision in the list.
		$current_idx = null;
		foreach ( $all_revisions as $i => $rev ) {
			if ( (int) $rev->ID === $revision_id ) {
				$current_idx = $i;
				break;
			}
		}

		if ( $current_idx === null ) {
			echo '<p class="rmtbt-empty">Revision not found.</p>';
			return;
		}

		// Human-readable position: 1 = oldest, $total = newest.
		$position = $total - $current_idx;

		// Prev/next IDs for navigation (prev = older, next = newer).
		$prev_id = ( $current_idx < $total - 1 ) ? $all_revisions[ $current_idx + 1 ]->ID : null;
		$next_id = ( $current_idx > 0 )           ? $all_revisions[ $current_idx - 1 ]->ID : null;

		// Comparison model: left (from) = previous older state, right (to) = this revision.
		// This ensures the newest revision always shows a meaningful diff against the one before it,
		// rather than comparing against the current post (which is always identical to the newest revision).
		// For the oldest revision there is no prior state, so we fall back to the current post.
		if ( $current_idx < $total - 1 ) {
			// There is an older revision to compare against.
			$compare_from_post = $all_revisions[ $current_idx + 1 ]; // next older
			$left_label        = 'Previous Revision';
		} else {
			// Oldest revision: compare against the current post to show any drift.
			$compare_from_post = $part;
			$left_label        = 'Current Version';
		}

		$back_url = admin_url( 'admin.php?page=rmtbt&tab=revisions' );
		$base_url = admin_url( 'admin.php?page=rmtbt&tab=revisions&part_id=' . $part_id );
		$author   = get_the_author_meta( 'display_name', $revision->post_author );

		// wp_get_revision_ui_diff() is in a file not loaded by default.
		require_once ABSPATH . 'wp-admin/includes/revision.php';

		// Override the column labels inside the diff tables.
		$label_filter = function( $args ) use ( $left_label ) {
			$args['title_left']  = $left_label;
			$args['title_right'] = 'This Revision';
			return $args;
		};
		add_filter( 'revision_text_diff_options', $label_filter );

		// Left (from) = previous older state; right (to) = this revision.
		$fields = wp_get_revision_ui_diff( $part, $compare_from_post, $revision );

		remove_filter( 'revision_text_diff_options', $label_filter );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		// wp_get_revision_ui_diff() silently omits post_content when both sides are identical.
		$has_content_field = false;
		foreach ( $fields as $f ) {
			if ( isset( $f['name'] ) && $f['name'] === __( 'Content' ) ) {
				$has_content_field = true;
				break;
			}
		}

		if ( ! $has_content_field ) {
			$content_diff = wp_text_diff(
				$compare_from_post->post_content,
				$revision->post_content,
				array(
					'show_split_view' => true,
					'title_left'      => $left_label,
					'title_right'     => 'This Revision',
				)
			);

			$fields[] = array(
				'id'   => 'wp-revision-field-post_content',
				'name' => __( 'Content' ),
				'diff' => $content_diff
					? $content_diff
					: '<tr><td colspan="3" style="padding:10px;color:#646970;font-style:italic;">Content is identical in both versions.</td></tr>',
			);
		}

		// Build a position → revision_id map for the slider (position 1 = oldest, $total = newest).
		$rev_ids_by_position = array();
		foreach ( array_reverse( $all_revisions ) as $idx => $rev ) {
			$rev_ids_by_position[ $idx + 1 ] = $rev->ID;
		}
		?>
		<div class="rmtbt-breadcrumb">
			<a href="<?php echo esc_url( $back_url ); ?>">&larr; Back to Revisions</a>
		</div>

		<div class="rmtbt-rev-nav">
			<?php if ( $prev_id ) : ?>
				<a href="<?php echo esc_url( $base_url . '&revision_id=' . $prev_id ); ?>" class="button button-secondary">&larr; Previous</a>
			<?php else : ?>
				<button class="button button-secondary" disabled>&larr; Previous</button>
			<?php endif; ?>

			<div class="rmtbt-rev-nav-slider-wrap">
				<input
					type="range"
					id="rmtbt-rev-slider"
					class="rmtbt-rev-slider"
					min="1"
					max="<?php echo $total; ?>"
					value="<?php echo $position; ?>"
					step="1"
				>
				<div class="rmtbt-rev-nav-info">
					<span class="rmtbt-rev-nav-position">Revision <?php echo $position; ?> of <?php echo $total; ?></span>
					<span class="rmtbt-muted">
						<?php echo esc_html( get_the_date( 'd M Y @ H:i', $revision ) ); ?>
						&bull; <?php echo esc_html( $author ); ?>
					</span>
				</div>
			</div>

			<?php if ( $next_id ) : ?>
				<a href="<?php echo esc_url( $base_url . '&revision_id=' . $next_id ); ?>" class="button button-secondary">Next &rarr;</a>
			<?php else : ?>
				<button class="button button-secondary" disabled>Next &rarr;</button>
			<?php endif; ?>
		</div>

		<script>
		(function() {
			var slider  = document.getElementById( 'rmtbt-rev-slider' );
			var revIds  = <?php echo wp_json_encode( $rev_ids_by_position ); ?>;
			var baseUrl = <?php echo wp_json_encode( $base_url ); ?>;

			slider.addEventListener( 'change', function() {
				var pos = parseInt( this.value, 10 );
				if ( revIds[ pos ] ) {
					window.location.href = baseUrl + '&revision_id=' + revIds[ pos ];
				}
			} );
		})();
		</script>

		<div class="rmtbt-diff-header">
			<div class="rmtbt-diff-meta">
				<div class="rmtbt-diff-avatar">
					<?php echo get_avatar( $revision->post_author, 40 ); ?>
				</div>
				<div>
					<strong><?php echo esc_html( $part->post_title ); ?></strong>
					<br>
					<span class="rmtbt-muted">
						Comparing against <strong><?php echo esc_html( $left_label ); ?></strong>
					</span>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rmtbt_restore_revision' ); ?>
				<input type="hidden" name="action" value="rmtbt_restore_revision">
				<input type="hidden" name="revision_id" value="<?php echo $revision->ID; ?>">
				<input type="hidden" name="part_id" value="<?php echo $part_id; ?>">
				<button type="submit" class="button button-primary"
					onclick="return confirm('Restore this revision? The current content of the template part will be overwritten.')">
					Restore This Revision
				</button>
			</form>
		</div>

		<div class="rmtbt-diff-legend">
			<span class="rmtbt-legend-removed">&#9632; <?php echo esc_html( $left_label ); ?></span>
			<span class="rmtbt-legend-added">&#9632; This Revision</span>
		</div>

		<?php if ( empty( $fields ) ) : ?>
			<p class="rmtbt-empty">No differences found — this revision has the same content as the <?php echo esc_html( strtolower( $left_label ) ); ?>.</p>
		<?php else : ?>
			<div class="rmtbt-diff-wrap">
				<?php foreach ( $fields as $field ) : ?>
					<div class="rmtbt-diff-field">
						<h3 class="rmtbt-diff-field-name"><?php echo esc_html( $field['name'] ); ?></h3>
						<table class="diff rmtbt-diff-table">
							<colgroup>
								<col class="content diffsplit left">
								<col class="content diffsplit middle">
								<col class="content diffsplit right">
							</colgroup>
							<tbody>
								<?php echo $field['diff']; // phpcs:ignore -- HTML from WP core diff function ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	// -------------------------
	// SHARED RENDER HELPERS
	// -------------------------

	private function render_days_chip( $is_trashed, $days_left ) {
		if ( $is_trashed ) {
			echo '<br><span class="rmtbt-chip rmtbt-chip-red">Already trashed</span>';
		} elseif ( $days_left === 0 ) {
			echo '<br><span class="rmtbt-chip rmtbt-chip-red">Auto-trash imminent</span>';
		} elseif ( $days_left <= 2 ) {
			echo '<br><span class="rmtbt-chip rmtbt-chip-orange">' . $days_left . ' day' . ( $days_left !== 1 ? 's' : '' ) . ' left</span>';
		} else {
			echo '<br><span class="rmtbt-chip rmtbt-chip-green">' . $days_left . ' days left</span>';
		}
	}

	private function render_status_pill( $is_trashed ) {
		if ( $is_trashed ) {
			echo '<span class="rmtbt-pill rmtbt-pill-red">Trashed</span>';
		} else {
			echo '<span class="rmtbt-pill rmtbt-pill-green">Published</span>';
		}
	}

}
