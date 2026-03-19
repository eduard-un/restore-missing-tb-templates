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
		add_action( 'admin_post_rmtbt_restore_part', array( $this, 'handle_restore_part' ) );
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
		if ( $tab === 'revisions' && isset( $_GET['revision_id'] ) ) {
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

	private function get_active_templates() {
		return get_posts( array(
			'post_type'      => self::TEMPLATE_PT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => self::UNUSED_META,
					'compare' => 'NOT EXISTS',
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

	private function restore_part_with_link( $part_id, $template_id ) {
		$part_id     = (int) $part_id;
		$template_id = (int) $template_id;
		$post_type   = get_post_type( $part_id );

		if ( get_post_status( $part_id ) === 'trash' ) {
			wp_untrash_post( $part_id );
		}

		delete_post_meta( $part_id, self::UNUSED_META );

		if ( $template_id > 0 && $post_type ) {
			update_post_meta( $template_id, $this->get_part_meta_key( $post_type ), $part_id );
		}
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

	public function handle_restore_part() {
		check_admin_referer( 'rmtbt_restore_part' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$part_id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$template_id = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;

		if ( $part_id ) {
			$this->restore_part_with_link( $part_id, $template_id );
		}

		$status = $template_id > 0 ? 'part_linked' : 'part_only';
		wp_safe_redirect( admin_url( 'admin.php?page=rmtbt&tab=parts&restored=' . $status ) );
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
		} elseif ( $restored === 'part_linked' ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Done.</strong> Template part restored and re-linked to its template.</p></div>';
		} elseif ( $restored === 'part_only' ) {
			echo '<div class="notice notice-warning is-dismissible"><p><strong>Part restored without re-linking.</strong> Assign it to a template in the Divi Theme Builder, or restore again with a template selected.</p></div>';
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

		$active_templates = $this->get_active_templates();
		$type_labels      = array(
			self::HEADER_PT => 'Header',
			self::BODY_PT   => 'Body',
			self::FOOTER_PT => 'Footer',
		);
		?>
		<div class="rmtbt-info-box">
			<strong>How to restore a template part:</strong> Select the template it belongs to from the dropdown, then click <em>Restore &amp; Re-link</em>.
			Divi clears the part-to-template link when a part is deleted, so you must specify the template manually.
			The part title usually matches the template name.
		</div>

		<table class="wp-list-table widefat fixed striped rmtbt-table">
			<thead>
				<tr>
					<th class="col-title">Part Title</th>
					<th class="col-type">Type</th>
					<th class="col-date">Marked Unused</th>
					<th class="col-status">Status</th>
					<th class="col-actions-wide">Restore &amp; Re-link</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $parts as $part ) :
				$marked_date = get_post_meta( $part->ID, self::UNUSED_META, true );
				$days_left   = $this->days_until_trash( $marked_date );
				$is_trashed  = $part->post_status === 'trash';
				$type_label  = isset( $type_labels[ $part->post_type ] ) ? $type_labels[ $part->post_type ] : $part->post_type;
				$type_class  = str_replace( '_', '-', $part->post_type );
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( $part->post_title ); ?></strong>
						<br><small class="rmtbt-muted">ID: <?php echo $part->ID; ?> &bull; <?php echo esc_html( $part->post_type ); ?></small>
					</td>
					<td>
						<span class="rmtbt-type-pill rmtbt-type-<?php echo esc_attr( $type_class ); ?>">
							<?php echo esc_html( $type_label ); ?>
						</span>
					</td>
					<td>
						<span class="rmtbt-date"><?php echo esc_html( $marked_date ); ?></span>
						<?php $this->render_days_chip( $is_trashed, $days_left ); ?>
					</td>
					<td><?php $this->render_status_pill( $is_trashed ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rmtbt-link-form">
							<?php wp_nonce_field( 'rmtbt_restore_part' ); ?>
							<input type="hidden" name="action" value="rmtbt_restore_part">
							<input type="hidden" name="post_id" value="<?php echo $part->ID; ?>">
							<?php if ( ! empty( $active_templates ) ) : ?>
								<select name="template_id" class="rmtbt-select">
									<option value="0">— Select template (optional) —</option>
									<?php foreach ( $active_templates as $tpl ) :
										$use_on     = get_post_meta( $tpl->ID, '_et_use_on', false );
										$use_on_str = ! empty( $use_on ) ? ' (' . $this->format_use_on( reset( $use_on ) ) . ')' : '';
									?>
										<option value="<?php echo $tpl->ID; ?>">
											<?php echo esc_html( $tpl->post_title . $use_on_str ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<em class="rmtbt-muted">No active templates found</em>
								<input type="hidden" name="template_id" value="0">
							<?php endif; ?>
							<button type="submit" class="button button-primary button-small">Restore &amp; Re-link</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------
	// REVISIONS TAB
	// -------------------------

	private function render_revisions_tab() {
		// State: diff view
		if ( isset( $_GET['part_id'], $_GET['revision_id'] ) ) {
			$compare_to_id = isset( $_GET['compare_to'] ) ? (int) $_GET['compare_to'] : 0;
			$this->render_revision_diff(
				(int) $_GET['part_id'],
				(int) $_GET['revision_id'],
				$compare_to_id
			);
			return;
		}

		// State: revision list for a specific part
		if ( isset( $_GET['part_id'] ) ) {
			$this->render_revision_list( (int) $_GET['part_id'] );
			return;
		}

		// State: overview — all parts with revisions
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
				$rev_url      = admin_url( 'admin.php?page=rmtbt&tab=revisions&part_id=' . $part->ID );
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

	private function render_revision_list( $part_id ) {
		$part = get_post( $part_id );

		if ( ! $part || ! in_array( $part->post_type, array( self::HEADER_PT, self::BODY_PT, self::FOOTER_PT ), true ) ) {
			echo '<p class="rmtbt-empty">Template part not found.</p>';
			return;
		}

		$revisions     = wp_get_post_revisions( $part_id, array( 'order' => 'DESC' ) );
		$revisions     = array_values( $revisions );
		$newest_rev_id = ! empty( $revisions ) ? $revisions[0]->ID : 0;
		if (
			! empty( $revisions ) &&
			$revisions[0]->post_content === $part->post_content &&
			$revisions[0]->post_title === $part->post_title
		) {
			array_shift( $revisions );
		}
		$back_url   = admin_url( 'admin.php?page=rmtbt&tab=revisions' );
		$type_labels = array(
			self::HEADER_PT => 'Header',
			self::BODY_PT   => 'Body',
			self::FOOTER_PT => 'Footer',
		);
		$type_label = isset( $type_labels[ $part->post_type ] ) ? $type_labels[ $part->post_type ] : $part->post_type;
		?>
		<div class="rmtbt-breadcrumb">
			<a href="<?php echo esc_url( $back_url ); ?>">&larr; Back to Revisions</a>
		</div>

		<div class="rmtbt-revision-header">
			<h2>
				Revisions for: <em><?php echo esc_html( $part->post_title ); ?></em>
				<span class="rmtbt-type-pill rmtbt-type-<?php echo esc_attr( str_replace( '_', '-', $part->post_type ) ); ?>">
					<?php echo esc_html( $type_label ); ?>
				</span>
			</h2>
		</div>

		<?php if ( empty( $revisions ) ) : ?>
			<p class="rmtbt-empty">No revisions found for this template part.</p>
			<?php return; ?>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped rmtbt-table">
			<thead>
				<tr>
					<th>Date</th>
					<th>Author</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php
				// First row = current version (not a revision)
				$current_author = get_the_author_meta( 'display_name', $part->post_author );
				?>
				<tr class="rmtbt-current-row">
					<td>
						<strong>Current version</strong>
						<br><small class="rmtbt-muted"><?php echo esc_html( mysql2date( 'd M Y @ H:i', $part->post_modified ) ); ?></small>
					</td>
					<td><?php echo esc_html( $current_author ); ?></td>
					<td><span class="rmtbt-pill rmtbt-pill-green">Active</span></td>
				</tr>

				<?php
				foreach ( $revisions as $revision ) :
					$author   = get_the_author_meta( 'display_name', $revision->post_author );
					$diff_url = admin_url(
						'admin.php?page=rmtbt&tab=revisions&part_id=' . $part_id
						. '&revision_id=' . $revision->ID
						. ( $newest_rev_id ? '&compare_to=' . $newest_rev_id : '' )
					);
				?>
					<tr>
						<td>
							<?php echo esc_html( get_the_date( 'd M Y @ H:i', $revision ) ); ?>
						</td>
						<td><?php echo esc_html( $author ); ?></td>
						<td class="rmtbt-rev-actions">
							<a href="<?php echo esc_url( $diff_url ); ?>" class="button button-secondary button-small">
								Compare with current
							</a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'rmtbt_restore_revision' ); ?>
								<input type="hidden" name="action" value="rmtbt_restore_revision">
								<input type="hidden" name="revision_id" value="<?php echo $revision->ID; ?>">
								<input type="hidden" name="part_id" value="<?php echo $part_id; ?>">
								<button type="submit" class="button button-primary button-small"
									onclick="return confirm('Restore this revision? The current content of the template part will be overwritten.')">
									Restore
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_revision_diff( $part_id, $revision_id, $compare_to_id = 0 ) {
		$part     = get_post( $part_id );
		$revision = get_post( $revision_id );

		// Validate: revision must belong to this part
		if ( ! $part || ! $revision || (int) $revision->post_parent !== $part_id ) {
			echo '<p class="rmtbt-empty">Invalid revision or part.</p>';
			return;
		}

		$back_url = admin_url( 'admin.php?page=rmtbt&tab=revisions&part_id=' . $part_id );
		$author   = get_the_author_meta( 'display_name', $revision->post_author );

		// Determine the "current" state to compare against.
		// When compare_to_id is provided we compare two revisions against each other
		// (avoids relying on $part->post_content which Divi 5 may not always update).
		$compare_from_post = null;
		if ( $compare_to_id > 0 ) {
			$compare_from_post = get_post( $compare_to_id );
			if ( ! $compare_from_post || (int) $compare_from_post->post_parent !== $part_id ) {
				$compare_from_post = null;
			}
		}
		// Fall back to the main post if no valid compare_to revision was found.
		if ( ! $compare_from_post ) {
			$compare_from_post = $part;
		}

		// wp_get_revision_ui_diff() is in a file not loaded by default
		require_once ABSPATH . 'wp-admin/includes/revision.php';

		// Override the "Removed"/"Added" column labels inside the diff tables.
		$label_filter = function( $args ) {
			$args['title_left']  = 'This Revision';
			$args['title_right'] = 'Current Version';
			return $args;
		};
		add_filter( 'revision_text_diff_options', $label_filter );

		// Left (from) = current state; right (to) = the revision being reviewed.
		$fields = wp_get_revision_ui_diff( $part, $compare_from_post, $revision );

		remove_filter( 'revision_text_diff_options', $label_filter );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		// wp_get_revision_ui_diff() silently omits post_content when both sides are identical.
		// Check by name (more reliable across WP versions than checking the 'id' key).
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
					'title_left'      => 'This Revision',
					'title_right'     => 'Current Version',
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
		?>
		<div class="rmtbt-breadcrumb">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rmtbt&tab=revisions' ) ); ?>">&larr; Back to Revisions</a>
			&nbsp;&bull;&nbsp;
			<a href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( $part->post_title ); ?></a>
		</div>

		<div class="rmtbt-diff-header">
			<div class="rmtbt-diff-meta">
				<div class="rmtbt-diff-avatar">
					<?php echo get_avatar( $revision->post_author, 40 ); ?>
				</div>
				<div>
					<strong><?php echo esc_html( $author ); ?></strong>
					<br>
					<span class="rmtbt-muted"><?php echo esc_html( get_the_date( 'd M Y @ H:i', $revision ) ); ?></span>
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
			<span class="rmtbt-legend-removed">&#9632; This Revision</span>
			<span class="rmtbt-legend-added">&#9632; Current Version</span>
		</div>

		<?php if ( empty( $fields ) ) : ?>
			<p class="rmtbt-empty">No differences found — this revision has the same content as the current version.</p>
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
