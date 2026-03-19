<?php
/**
 * Plugin Name: Divi Restore Theme Builder Templates
 * Plugin URI:  https://github.com/eduard-un/restore-missing-tb-templates
 * Description: Restore deleted or missing Divi Theme Builder templates and template parts. Compatible with Divi 4 and Divi 5.
 * Version:     1.1
 * Author:      Eduard Ungureanu
 * Author URI:  https://github.com/eduard-ungureanu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

register_activation_hook( __FILE__, 'rmtbt_activation_check' );
function rmtbt_activation_check() {
	$theme = wp_get_theme();
	if ( $theme->get( 'Name' ) !== 'Divi' && $theme->get( 'Template' ) !== 'Divi' ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'<strong>Restore Missing TB Templates</strong> requires the Divi theme (or a Divi child theme) to be active. Please activate Divi first.',
			'Plugin Activation Error',
			array( 'back_link' => true )
		);
	}
}

define( 'RMTBT_VERSION', '1.1' );
define( 'RMTBT_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMTBT_URL', plugin_dir_url( __FILE__ ) );

require_once RMTBT_DIR . 'includes/class-rmtbt-admin.php';

add_action( 'plugins_loaded', function () {
	if ( is_admin() ) {
		( new RMTBT_Admin() )->init();
	}
} );
