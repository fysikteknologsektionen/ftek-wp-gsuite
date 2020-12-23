<?php

/**
* The file that defines the core plugin class
*
* @since      1.0.0
*
* @package    Ftek_GSuite
* @subpackage Ftek_GSuite/includes
*/

class Ftek_GSuite {
   
   public function __construct(  ) {
      require_once 'class-ftek-gsuite-updater.php';
      $this->define_hooks();
	}
   
   /**
   * Setup hooks.
   *
   * @since    0.1.0
   * @access   private
   */
	private function define_hooks() {
		// Add links to plugin page
      add_filter( 'plugin_action_links_' . PLUGIN_NAME, function( $links ){
         $links[] = '<a href="'. esc_url( get_admin_url(null, 'options-general.php?page=ftek_gsuite') ) .'">Settings</a>';
         return $links;
      });
      
      // Settings
      add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
      add_action( 'admin_init', array( $this, 'settings_init' ) );
      add_action('ftek_gsuite_fetch_updates', array($this, 'update_cache'));
      
      // Shortcode
      add_shortcode('ftek_gsuite_members', array($this, 'member_shortcode'));
      add_shortcode('ftek_gsuite_vacant', array($this, 'vacant_shortcode'));
   }
   
   public static function update_cache() {
      $updater = new Ftek_GSuite_Updater();
      $updater->update_cache();
   }
   
   public static function get_admin_email() {
      $options = get_option( 'ftek_gsuite_settings' );
      return $options['ftek_gsuite_impersonator_email'];
   }

   public static function get_library_path() {
      $options = get_option( 'ftek_gsuite_settings' );
      return $options['ftek_gsuite_library_path'];
   }
   
   public static function get_credentials_path() {
      $options = get_option( 'ftek_gsuite_settings' );
      return $options['ftek_gsuite_credentials_path'];
   }
   
   
   // Shortcodes
   public function member_shortcode( $atts, $content = null ) {
      extract( shortcode_atts( array(
         'group' => '',
         'exclude' => ''
      ), $atts ) );
      if ($group === '') {
         return '';
      }
      $groups = json_decode(get_option('ftek_gsuite_groups'));
      if (!property_exists($groups, $group)) {
         return '';
      }
      $members = $groups->$group;
      $exclude = explode(',', $exclude);
      $members = array_filter($members, function($m) { return ($m->show && !in_array($m->email, $exclude)); });
      if (!$members) {
         return '';
      }
      $html = '';
      foreach ($members as $member) {
         $user_id = get_user_by( 'email', $member->email );
         $user_id = $user_id->ID;
         $nickname = get_user_meta($user_id, 'nickname', true);
         if (substr($nickname, -8) === '@ftek.se' || $nickname === 'null') { $nickname = null; }
         if ($nickname) {
            $nickname = '&ldquo;'.$nickname.'&rdquo; '; 
         }
         $html .= '<div class="member">'
         . Ftek_GSuite_Updater::get_profile_pic($member->photo)
         . '<div class="member-info">'
         . '<div class="member-name">'
         . $member->givenName.' '.$nickname.$member->familyName
         . '</div>'
         . '<div class="member-meta">'
         . '<span class="member-position">'
         . $member->position
         . '</span>'
         . ' (<a href="mailto:'.$member->email.'" class="member-email" target="_blank" rel="noopener">'.$member->email.'</a>)'
         . '</div>'
         . '<div class="member-bio">'
         . get_user_meta($user_id, 'description', true)
         . '</div>'
         . '</div>'.'</div>';
      }
      return $html;
   }

   public function vacant_shortcode( $atts, $content = null ) {
      extract( shortcode_atts( array(
         'group' => '',
         'exclude' => ''
      ), $atts ) );
      if ($group === '') {
         return '';
      }
      $groups = json_decode(get_option('ftek_gsuite_groups'));
      if (!property_exists($groups, $group)) {
         return '';
      }
      $members = $groups->$group;
      $exclude = explode(',', $exclude);
      $members = array_filter($members, function($m) { return ( !in_array($m->email, $exclude)); }); #!$m->closed &&
      if (!$members) {
         return '';
      }
      $html = '';
      foreach ($members as $member) {
         $user_id = get_user_by( 'email', $member->email );
         $user_id = $user_id->ID;
         $nickname = get_user_meta($user_id, 'nickname', true);
         if (substr($nickname, -8) === '@ftek.se' || $nickname === 'null') { $nickname = null; }
         if ($nickname) {
            $nickname = '&ldquo;'.$nickname.'&rdquo; '; 
         }
         $html .= '<div class="member">'
         . Ftek_GSuite_Updater::get_profile_pic($member->photo)
         . '<div class="member-info">'. $member->vacant . "| " . $member->closed
         . '<div class="member-name">'
         . $member->givenName.' '.$nickname.$member->familyName
         . '</div>'
         . '<div class="member-meta">'
         . '<span class="member-position">'
         . $member->position
         . '</span>'
         . ' (<a href="mailto:'.$member->email.'" class="member-email" target="_blank" rel="noopener">'.$member->email.'</a>)'
         . '</div>'
         . '<div class="member-bio">'
         . get_user_meta($user_id, 'description', true)
         . '</div>'
         . '</div>'.'</div>';
      }
      return $html;
   }
   
   // Settings Page
   public function add_admin_menu(  ) { 
      add_options_page( 'Ftek G Suite', 'Ftek G Suite', 'manage_options', 'ftek_gsuite', array($this, 'options_page' ));
   }
   
   public function settings_init(  ) { 
      register_setting( 'pluginPage', 'ftek_gsuite_settings' );
      // Instructions
      add_settings_section(
         'ftek_gsuite_pluginPage_instructions', 
         __( 'Instructions', 'ftek_gsuite' ), 
         array($this, 'settings_instructions_callback'), 
         'pluginPage'
      );
      // Settings Fields
      add_settings_section(
         'ftek_gsuite_pluginPage_setup', 
         __( 'Settings', 'ftek_gsuite' ), 
         array($this, 'settings_setup_callback'), 
         'pluginPage'
      );
      add_settings_field( 
         'ftek_gsuite_library_path', 
         __( 'Absolute path to Google API client autoload.php', 'ftek_gsuite' ), 
         array($this, 'library_path_render'), 
         'pluginPage', 
         'ftek_gsuite_pluginPage_setup' 
      );
      add_settings_field( 
         'ftek_gsuite_credentials_path', 
         __( 'Absolute path to Google credentials file', 'ftek_gsuite' ), 
         array($this, 'credentials_path_render'), 
         'pluginPage', 
         'ftek_gsuite_pluginPage_setup' 
      );
      add_settings_field( 
         'ftek_gsuite_impersonator_email', 
         __( 'Email of a G Suite admin', 'ftek_gsuite' ), 
         array($this, 'impersonator_email_render'), 
         'pluginPage', 
         'ftek_gsuite_pluginPage_setup' 
      );
      // API Test Call
      add_settings_section(
         'ftek_gsuite_pluginPage_test', 
         __( 'Connection test', 'ftek_gsuite' ), 
         array($this, 'settings_test_callback'), 
         'pluginPage'
      );
   }

   public function library_path_render(  ) { 
      $options = get_option( 'ftek_gsuite_settings' );
      ?>
      <input type='text' name='ftek_gsuite_settings[ftek_gsuite_library_path]' value='<?php echo $options['ftek_gsuite_library_path']; ?>' placeholder='/path/to/autoload.php'>
      <?php
   }
   
   public function credentials_path_render(  ) { 
      $options = get_option( 'ftek_gsuite_settings' );
      ?>
      <input type='text' name='ftek_gsuite_settings[ftek_gsuite_credentials_path]' value='<?php echo $options['ftek_gsuite_credentials_path']; ?>' placeholder='/path/to/credentials.json'>
      <?php
   }
   
   public function impersonator_email_render(  ) { 
      $options = get_option( 'ftek_gsuite_settings' );
      ?>
      <input type='email' name='ftek_gsuite_settings[ftek_gsuite_impersonator_email]' value='<?php echo $options['ftek_gsuite_impersonator_email']; ?>'>
      <?php
   }
   
   public function settings_setup_callback(  ) { 
      echo __( 'These fields are required for the G Suite integration.', 'ftek_gsuite' );
   }
   
   public function settings_instructions_callback(  ) { 
      echo __( 'Follow these instructions:', 'ftek_gsuite' );
   }
   
   public function settings_test_callback(  ) {
      
      // Check path
      $lib_path = self::get_library_path();
      if (!$lib_path) {
         echo __('No autoload file set.', 'ftek_gsuite');
         return;
      }
      // Check path
      $cred_path = self::get_credentials_path();
      if (!$cred_path) {
         echo __('No credentials file set.', 'ftek_gsuite');
         return;
      }
      // Check email
      $email = self::get_admin_email();
      if (!$email) {
         echo __('No admin email set.', 'ftek_gsuite');
         return;
      }
      // Check file contents
      $contents = file_get_contents($lib_path);
      if (!$contents) {
         echo __('Could not read autoload file. ', 'ftek_gsuite');
         echo __('Make sure the server has read permissions on all files of the library.', 'ftek_gsuite');
         return;
      }
      // Check file contents
      $contents = file_get_contents($cred_path);
      if (!$contents) {
         echo __('Could not read credential file. ', 'ftek_gsuite');
         echo __('Make sure the server has read permissions.', 'ftek_gsuite');
         return;
      }
      $cred_file = json_decode($contents, true);
      if ( $cred_file['type']!=='service_account' ) {
         echo __('Error in credential file. Make sure it is the credentials file for a service account!', 'ftek_gsuite');
         return;
      }
      // Make a test call
      try {
         $updater = new Ftek_GSuite_Updater();
         $user = $updater->get_test_data();
         echo '<p>'. __( 'Test call successful! Here are the results:', 'ftek_gsuite' ). '</p>';
         echo '<pre>' . json_encode($user, JSON_PRETTY_PRINT) . '</pre>';
      } catch (Google_Service_Exception $e) {
         echo '<p>'. __( 'Test call failed! Here is the error message:', 'ftek_gsuite' ). '</p>';
         echo '<pre>' . json_encode(json_decode($e->getMessage()), JSON_PRETTY_PRINT) . '</pre>';
      }
   }
   
   public function options_page(  ) {
      ?>
      <form action='options.php' method='post'>
      <h2>G Suite Integration</h2>
      <?php
      settings_fields( 'pluginPage' );
      do_settings_sections( 'pluginPage' );
      submit_button();
      ?>
      </form>
      <?php
   }
}