<?php
/*
Plugin Name: Ftek G Suite
Description: Provides functionality for fetching G Suite user and group data 
Author: Johan Winther (johwin)
Version: 1.0.2
Text Domain: ftek_gsuite
Domain Path: /languages
GitHub Plugin URI: Fysikteknologsektionen/ftek-gsuite
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

function ftek_gsuite_required_plugin_activated() {
	if ( is_admin() && current_user_can( 'activate_plugins' ) &&  !is_plugin_active( 'sign-in-with-google/sign-in-with-google.php' ) ) {
		add_action( 'admin_notices', function(){
			?><div class="error"><p>Sorry, but the Ftek G Suite plugin requires the <a href="https://wordpress.org/plugins/sign-in-with-google/" rel="noreferrer">Sign In With Google plugin</a> to be installed and active.</p></div><?php
		});
		deactivate_plugins( plugin_basename( __FILE__ ) ); 
		
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}
}

/**
* The code that runs during plugin activation.
* This action is documented in includes/class-sign-in-with-google-activator.php
*/
function ftek_gsuite_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-ftek-gsuite-activator.php';
	Ftek_GSuite_Activator::activate();
}
/**
* The code that runs during plugin deactivation.
* This action is documented in includes/class-sign-in-with-google-deactivator.php
*/
function ftek_gsuite_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-ftek-gsuite-activator.php';
	Ftek_GSuite_Activator::deactivate();
}
register_activation_hook( __FILE__, 'ftek_gsuite_activate' );
register_deactivation_hook( __FILE__, 'ftek_gsuite_deactivate' );


define('PLUGIN_NAME', plugin_basename(__FILE__));
// Includes
require_once( plugin_dir_path( __FILE__ ) . 'includes/class-ftek-gsuite.php');

function ftek_gsuite_run() {
	$plugin = new Ftek_GSuite();
}

ftek_gsuite_run();