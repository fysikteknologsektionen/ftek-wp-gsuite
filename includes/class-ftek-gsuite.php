<?php

/**
* The file that defines the core plugin class
*
* @since      0.1.0
*
* @package    Ftek_GSuite
* @subpackage Ftek_GSuite/includes
*/

class Ftek_GSuite {

   protected $gsuite_raw_client;
   protected $gsuite_client;

   public function __construct(  ) {
      $this->load_dependencies();
      $this->define_hooks();
      $this->gsuite_raw_client = $this->set_gsuite_raw_client();
      $this->gsuite_client = $this->set_gsuite_client( $this->gsuite_client );
	}
   
   /**
   * Load the required dependencies for this plugin.
   *
   * @since    0.1.0
   * @access   private
   */
   
	private function load_dependencies() {
      // Include Google's PHP library.
		require_once plugin_dir_path( __DIR__ ) . 'vendor/google-api-php-client/vendor/autoload.php';
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

      add_shortcode('ftek_gsuite_members', array($this, 'member_shortcode'));
   }
   
   private function set_gsuite_raw_client() {
      if (!$this->is_setup_functional()) {
         return null;
      }
      $client = new Google_Client();
      $client->setAuthConfig($this->get_credentials_path());
      $client->setApplicationName("Ftek GSuite Plugin");
      $client->setScopes([
         'https://www.googleapis.com/auth/admin.directory.group.readonly',
         'https://www.googleapis.com/auth/admin.directory.user.readonly',
         'https://www.googleapis.com/auth/admin.directory.userschema.readonly'
      ]);
      $client->setSubject($this->get_admin_email());
      return $client;
   }

   private function set_gsuite_client( $client ) {
      if (!$this->is_setup_functional()) {
         return null;
      }
      $service_client = new Google_Service_Directory($client);
      return $service_client;
   }
   
   /**
   * Getter for the admin email
   *
   * @since    0.1.0
   * @access   private
   */
   private function get_admin_email() {
      $options = get_option( 'ftek_gsuite_settings' );
      return $options['ftek_gsuite_impersonator_email'];
   }

   /**
   * Getter for the credentials file path.
   *
   * @since    0.1.0
   * @access   private
   */   
   private function get_credentials_path() {
      $options = get_option( 'ftek_gsuite_settings' );
      return $options['ftek_gsuite_credentials_path'];
   }
   
   private function is_setup_functional() {  
      // Check path
      $cred_path = $this->get_credentials_path();
      if (!$cred_path || !$this->get_admin_email()) {
         return false;
      }
      // Check file contents
      $contents = file_get_contents($cred_path);
      if (!$contents) {
         return false;
      }
      $cred_file = json_decode($contents, true);
      if ( $cred_file['type']!=='service_account' ) {
         return false;
      }
      return true;
   }

   /**
   * Test call data.
   *
   * @since    0.1.0
   * @access   private
   */
   private function get_test_data() {
      $response = $this->gsuite_client->users->get($this->get_admin_email(), array('projection'=>'full'));
      $user = array(
         'name'   =>$response->name,
         'email'  =>$response->primaryEmail,
         'title'  =>$response->organizations[0]['title'],
         'role'   =>$response->organizations[0]['description'],
         'CID'    =>$response->customSchemas['Sektion']['CID']
      );
      return $user;
   }
   


   private function profile_pic_src($photo) {
      return 'data:'.$photo->mimeType. ';charset=utf-8;base64, ' .strtr($photo->photoData,'-_','+/');
   }

   private function get_profile_pic( $email ) {
      try {
         $photo = $this->gsuite_client->users_photos->get($email);
         return '<img src="'.$this->profile_pic_src($photo).'" />';
      } catch (Exception $e) {
         $email_hash = md5( strtolower( trim( $email ) ) );
         $url = "https://www.gravatar.com/avatar/".$email_hash."?s=96&d=mm&f=y";
         return '<img src="'.$url.'" />';
      }
   }
   
   private function get_group_members( $email ) {
      try {
         $members = array();
         $response = new stdClass();
         $response->nextPageToken = null;
         do {
            $opts = array(
               'includeDerivedMembership' => true,
               'pageToken' => $response->nextPageToken
            );
            $response = $this->gsuite_client->members->listMembers($email, $opts);

            $response_members = array_filter($response->members, function($member) {
               return $member->type==='USER';
            });

            $this->gsuite_raw_client->setUseBatch(true);
            $batch = new Google_Http_Batch($client);
            $response_members = array_walk($response_members, function($member) {
               $user = $this->gsuite_client->users->get($member->email, array('projection'=>'full'));
               $batch->add($user, $member->email);
            });
            $batch_results = $batch->execute();
            $response_members = array_map(function($member) {
               $user = $batch_results['response-'.$member->email];
               $m = new stdClass();
               $m->email = $member->email;
               $m->name = $user->name['fullName'];
               $m->name = $user->name['fullName'];
               return $m;
            }, $response_members);
            $this->gsuite_raw_client->setUseBatch(false);


            $members = array_merge($members, $response_members);
         } while ($response->nextPageToken);
      } catch(Exception $e) {
         return null;
      }
      usort($members, function($a, $b) {
         $role_order = ['Ordförande', 'Vice ordförande', 'Kassör', 'Ledamot'];
         if (!in_array($a->type, $role_order)) { $a->type = 'Ledamot'; }
         if (!in_array($b->type, $role_order)) { $b->type = 'Ledamot'; }
         return array_search($a->type, $role_order) - array_search($b->type, $role_order);
      });
      return $members;
   }

   // Shortcodes
   public function member_shortcode( $atts, $content = null ) {
      extract( shortcode_atts( array(
         'group' => ''
      ), $atts ) );
      if ($group === '') {
         return '';
      }

      $members = $this->get_group_members( $group );
      if (!$members) {
         return '';
      }
      return '<pre>'.print_r($members, true).'</pre>';
      $html = '';
      foreach ($members as $member) {
         $user_id = get_user_by( 'email', $member->email );
         $user_id = $user_id->ID;
         $html .= '<div class="member">'
         . get_profile_pic( $member->email )
           . '<div class="member-info">'
               . '<div class="member-name">'
                   . $member->name
               . '</div>'
               . '<div class="member-meta">'
                   . '<span class="member-position">'
                       . $member->position
                   . '</span>'
                   . '(<a href="mailto:'.$member->email.'" class="member-email" target="_blank" rel="noopener">'.$member->email.'</a>)'
               . '</div>'
               . '<div class="member-bio">'
                   . get_user_meta($user_id, 'description', true)
               . '</div>'
        . '</div>'
        . '</div>';
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
      $cred_path = $this->get_credentials_path();
      if (!$cred_path) {
         echo __('No credentials file set.', 'ftek_gsuite');
         return;
      }
      // Check email
      $email = $this->get_admin_email();
      if (!$email) {
         echo __('No admin email set.', 'ftek_gsuite');
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
         $user = $this->get_test_data();
         echo '<p>'. __( 'Test call successful! Here are the results:', 'ftek_gsuite' ). '</p>';
         echo '<pre>' . json_encode($user, JSON_PRETTY_PRINT) . '</pre>';
         echo $this->get_profile_pic($user['email']);
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