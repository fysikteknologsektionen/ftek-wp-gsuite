<?php
/**
* Fired during plugin activation
*
* @since      0.1.0
*
* @package    Ftek_GSuite
* @subpackage Ftek_GSuite/includes
*/

class Ftek_GSuite_Activator {
    
	public static function activate() {
        if( !wp_next_scheduled( 'ftek_gsuite_fetch_updates' ) ) {
            wp_schedule_event( time(), 'daily', 'ftek_gsuite_fetch_updates' );
        }
    }
    
    public static function deactivate() {
        wp_clear_scheduled_hook('ftek_gsuite_fetch_updates');
	}
}

?>