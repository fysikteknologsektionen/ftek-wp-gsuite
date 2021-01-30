<?php
/**
* Handler for data caching
*
* @since      1.0.0
*
* @package    Ftek_GSuite
* @subpackage Ftek_GSuite/includes
*/

class Ftek_GSuite_Updater {
    
    protected $gsuite_raw_client;
    protected $gsuite_client;
    
    public function __construct(  ) {
        $this->load_dependencies();
        $this->gsuite_raw_client = $this->set_gsuite_raw_client();
        $this->gsuite_client = $this->set_gsuite_client( $this->gsuite_raw_client );
    }
    
    /**
    * Load the required dependencies for this plugin.
    *
    * @since    0.1.0
    * @access   private
    */
    
	private function load_dependencies() {
        // Include Google's PHP library.
        require_once(self::get_library_path());
    }
    
    private function set_gsuite_raw_client() {
        if (!$this->is_setup_functional()) {
            return null;
        }
        $client = new Google_Client();
        $client->setAuthConfig(Ftek_GSuite::get_credentials_path());
        $client->setApplicationName("Ftek GSuite Plugin");
        $client->setScopes([
            'https://www.googleapis.com/auth/admin.directory.group.readonly',
            'https://www.googleapis.com/auth/admin.directory.user.readonly',
            'https://www.googleapis.com/auth/admin.directory.userschema.readonly']
        );
        $client->setSubject(self::get_admin_email());
        return $client;
    }
    
    private function set_gsuite_client( $client ) {
        if (!$this->is_setup_functional()) {
            return null;
        }
        $service_client = new Google_Service_Directory($client);
        return $service_client;
    }

    private static function get_admin_email() {
        $options = get_option( 'ftek_gsuite_settings' );
        return $options['ftek_gsuite_impersonator_email'];
    }

    private static function get_library_path() {
        $options = get_option( 'ftek_gsuite_settings' );
        return $options['ftek_gsuite_library_path'];
    }
     
    private static function get_credentials_path() {
        $options = get_option( 'ftek_gsuite_settings' );
        return $options['ftek_gsuite_credentials_path'];
    }
    
    private function is_setup_functional() {
        // Check path
        $lib_path = self::get_library_path();
        if (!$lib_path) {
            return false;
        }
        // Check file contents
        $contents = file_get_contents($lib_path);
        if (!$contents) {
            return false;
        }
        // Check path
        $cred_path = self::get_credentials_path();
        if (!$cred_path || !self::get_admin_email()) {
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
    public function get_test_data() {
        //$this->update_cache();
        $response = $this->gsuite_client->users->get(self::get_admin_email(), array('projection'=>'full'));
        $user = array(
            'name'   =>$response->name,
            'email'  =>$response->primaryEmail,
            'title'  =>$response->organizations[0]['title'],
            'role'   =>$response->organizations[0]['description'],
            'CID'    =>$response->customSchemas['Sektion']['CID']
        );
        return $user;
    }
    
    public static function profile_pic_src($photo) {
        return 'data:image/jpeg;charset=utf-8;base64, ' .strtr($photo,'-_','+/');
    }
    
    public static function get_profile_pic( $photo ) {
        if ($photo) {
            return '<img src="'.self::profile_pic_src($photo).'" class="avatar avatar-75 photo" alt="Profile picture" />';
        } else {
            $url = "https://www.gravatar.com/avatar/?s=75&d=mm&f=y";
            return '<img src="'.$url.'" class="avatar avatar-75 photo" alt="Profile picture" />';
        }
    }
    
    private function get_group_members( $email, $include = false ) {
        try {
            
            $members = array();
            $response = new stdClass();
            $response->nextPageToken = null;
            do {
                $opts = array(
                    'includeDerivedMembership' => $include,
                    'pageToken' => $response->nextPageToken,
                );
                $response = $this->gsuite_client->members->listMembers($email, $opts);
                $response_members = array_filter($response->members, function($member) {
                    return $member->type==='USER';
                });
                
                $this->gsuite_raw_client->setUseBatch(true);
                $batch = new Google_Http_Batch($this->gsuite_raw_client, false, null, 'batch/admin/v1');
                array_walk($response_members, function($member, $key, $batch) {
                    $user = $this->gsuite_client->users->get($member->email, array('projection'=>'full'));
                    $batch->add($user, $member->email);
                }, $batch); 
                $batch_results = $batch->execute();
                $this->gsuite_raw_client->setUseBatch(false);
                $response_members = array_map(function($member) use ($batch_results) {
                    $user = $batch_results['response-'.$member->email];
                    $m = new stdClass();
                    $m->email = $member->email;
                    $m->givenName = isset( $user->name->givenName ) ? $user->name->givenName : '';
                    $m->familyName = isset( $user->name->familyName ) ? $user->name->familyName : '';
                    $m->position = '';
                    $m->type = '';
                    if (isset( $user->organizations )) {
                        if ( isset( $user->organizations[0]['title'] ) ) {
                            $m->position = $user->organizations[0]['title'];
                        };
                        if ( isset( $user->organizations[0]['description'] )) {
                            $m->type = $user->organizations[0]['description'];
                        }
                    }
                    try {
                        $photo = $this->gsuite_client->users_photos->get($m->email);
                        $m->photo = $photo->photoData;
                    } catch(Exception $e) {
                        $m->photo = null;
                    }
                    if ( !empty($user->customSchemas) && array_key_exists('Sektion', $user->customSchemas) && array_key_exists('vacantPost', $user->customSchemas['Sektion']) ) {
                        $m->vacant = $user->customSchemas['Sektion']['vacantPost'];
                        $m->show = false;
                
                        $m->closed = (boolval($m->vacant) ? 0 : 1);

                    } else {
                        $m->vacant = false;
                        $m->show = true;
                    }
                    return $m;
                }, $response_members);
                
                $members = array_merge($members, $response_members);
            } while ($response->nextPageToken);
            
        } catch(Exception $e) {
            return null;
        }
        
        usort($members, function($a, $b) {
            $role_order = ['Ordförande', 'Vice ordförande', 'Kassör', 'Ledamot'];
            preg_match_all("/(.*?) ?(\d+)?$/", $a->type, $m_a);
            preg_match_all("/(.*?) ?(\d+)?$/", $b->type, $m_b);
            if (!in_array($m_a[1][0], $role_order)) { $m_a[1][0] = 'Ledamot'; $m_a[2][0] = 999; }
            if (!in_array($m_b[1][0], $role_order)) { $m_b[1][0] = 'Ledamot'; $m_b[2][0] = 999; }
            if (!strcmp($m_a[1][0], $m_b[1][0])) {
                if (!$m_a[2][0]) { $m_a[2][0] = 999; };
                if (!$m_b[2][0]) { $m_b[2][0] = 999; };
                return (intval($m_a[2][0])-intval($m_b[2][0])); 
            }
            return array_search($m_a[1][0], $role_order) - array_search($m_b[1][0], $role_order);
        });
        
        return $members;
    }
    
    public function update_cache() {
        if (function_exists('wp_get_terms_meta')) { 
            $groups = get_categories(array("hide_empty" => 0));
            $groups = array_values(array_filter($groups, function($group){
                return wp_get_terms_meta($group->cat_ID, 'email' ,true);
            }));
            $groups_data = array();
            foreach ($groups as $group) {
                $group_email = wp_get_terms_meta($group->cat_ID, 'email' ,true);
                $group_data = $this->get_group_members($group_email);
                if ($group_data) {
                    $groups_data[$group_email] = $group_data;
                }
            }
            update_option( 'ftek_gsuite_groups', json_encode($groups_data) );
        }
    }
    
}

?>
