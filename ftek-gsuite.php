<?php
/*
Plugin Name: Ftek G Suite
Description: Provides functionality for fetching G Suite user and group data 
Author: Johan Winther (johwin)
Version: 0.1.0
Text Domain: ftek_gsuite
Domain Path: /languages
GitHub Plugin URI: Fysikteknologsektionen/ftek-gsuite
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define('PLUGIN_NAME', plugin_basename(__FILE__));
// Includes
require( plugin_dir_path( __FILE__ ) . 'includes/class-ftek-gsuite.php');

function ftek_gsuite_run() {
	$plugin = new Ftek_GSuite();
}

ftek_gsuite_run();