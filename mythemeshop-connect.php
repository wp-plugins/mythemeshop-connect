<?php
/*
 * Plugin Name: MyThemeShop Connect
 * Plugin URI: http://www.mythemeshop.com
 * Description: Update MyThemeShop themes & plugins, get news & exclusive offers right from your WordPress dashboard
 * Version: 1.0
 * Author: MyThemeShop
 * Author URI: http://www.mythemeshop.com
 * License: GPLv2
 */
 
defined('ABSPATH') or die;

class mts_connection {
    
    private $api_url = "https://www.mythemeshop.com/api/v3/";
    
    private $settings_option = "mts_connect_settings";
    private $data_option = "mts_connect_data";
    private $notices_option = "mts_connect_notices";
    private $dismissed_meta = "mts_connect_dismissed_notices";
    
    protected $connect_data = array();
    protected $notices = array();
    protected $sticky_notices = array();
    protected $settings = array();
    protected $default_settings = array('network_notices' => '1');
    protected $notice_defaults = array();
    protected $notice_tags = array();
    
    function __construct() {
        
        $this->connect_data = $this->get_data();
        $this->sticky_notices = $this->get_notices();
        $this->settings = $this->get_settings();
        
        // Notices default options
        $this->notice_defaults = array(
            'content' => '', 
            'class' => 'updated', 
            'priority' => 10, 
            'sticky' => false, 
            'date' => time(), 
            'expire' => time() + 7 * DAY_IN_SECONDS,
            'context' => array()
        );
        
        add_action( 'admin_init', array(&$this, 'admin_init'));
        //add_action( 'admin_print_scripts', array(&$this, 'admin_inline_js'));        
        
        add_action( 'load-themes.php', array( &$this, 'force_check' ));
        add_action( 'load-plugins.php', array( &$this, 'force_check' ));
        
        // show notices
        if (is_multisite())
            add_action( 'network_admin_notices', array(&$this, 'show_notices'));
        else
            add_action( 'admin_notices', array(&$this, 'show_notices'));
        
        // user has dismissed a notice?
        add_action( 'admin_init', array(&$this, 'dismiss_notices'));
        // add menu item
        if (is_multisite())
            add_action( 'network_admin_menu', array(&$this, 'admin_menu'));
        else
            add_action( 'admin_menu', array(&$this, 'admin_menu'));
        // remove old notifications
        add_action( 'after_setup_theme', array($this, 'after_theme') );
        
        add_action('wp_ajax_mts_connect',array(&$this,'ajax_mts_connect'));
        add_action('wp_ajax_mts_connect_dismiss_notice',array(&$this,'ajax_mts_connect_dismiss_notices'));
        add_action('wp_ajax_mts_connect_check_themes',array(&$this,'ajax_mts_connect_check_themes'));
        add_action('wp_ajax_mts_connect_check_plugins',array(&$this,'ajax_mts_connect_check_plugins'));
        
        add_filter( 'pre_set_site_transient_update_themes',  array( &$this,'check_theme_updates' ));
        add_filter( 'pre_set_site_transient_update_plugins',  array( &$this,'check_plugin_updates' ));
        
        register_activation_hook( __FILE__, array($this, 'plugin_activated' ));
        register_deactivation_hook( __FILE__, array($this, 'plugin_deactivated' ));
        
        // localization
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        
    }

    public function plugin_activated(){
         $this->update_themes_now();
         $this->update_plugins_now();
    }

    public function plugin_deactivated(){
         $this->reset_notices(); // todo: reset for all admins
         $this->disconnect();
    }
    
    function load_textdomain() {
        load_plugin_textdomain( 'mts-connect', false, dirname( plugin_basename( __FILE__ ) ) . '/language/' ); 
    }
    
    function ajax_mts_connect_dismiss_notices() {
        if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
            foreach ($_POST['ids'] as $id) {
                $this->dismiss_notice($id);
            }
        }
        exit;
    }
    function ajax_mts_connect_check_themes() {
        $this->update_themes_now();
        $transient = get_site_transient( 'mts_update_themes' );
        if (is_object($transient) && isset($transient->response)) {
            echo count($transient->response);
        } else {
            echo '0';
        }
            
        exit;
    }
    function ajax_mts_connect_check_plugins() {
        $this->update_plugins_now();
        $transient = get_site_transient( 'mts_update_plugins' );
        if (is_object($transient) && isset($transient->response)) {
            echo count($transient->response);
        } else {
            echo '0';
        }
            
        exit;
    }
    function ajax_mts_connect() {
        header("Content-type: application/json");
        $username = isset($_POST['username']) ? $_POST['username'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        
        $response = wp_remote_post( 
            $this->api_url . '?action=connect', 
            array( 'body' => array( 'username' => $username, 'password' => $password ), 'timeout' => 10 ) 
        );
        
        if ( is_wp_error( $response ) ) {
           $error_message = $response->get_error_message();
           echo json_encode( array( 'status' => 'fail', 'errors' => array($error_message) ) );
        } else {
            echo $response['body']; // should be JSON already
           
            $data = json_decode($response['body'], true);
            if (isset($data['status']) && $data['status'] == 'success') {
                $this->reset_notices();
                $this->connect_data['username'] = $username;
                $this->connect_data['api_key'] = $data['api_key'];
                $this->connect_data['connected'] = true;
                $this->update_data();
            }
            // notices
            if (isset($data['notices']) && is_array($data['notices'])) {
                foreach($data['notices'] as $notice) {
                    if (!empty($notice['network_notice'])) {
                        $this->add_network_notice((array) $notice);
                    } else {
                        $this->add_sticky_notice((array) $notice);
                    }
                }
            }
        }
        exit;
    }
        
    function disconnect() {
        $this->connect_data['username'] = '';
        $this->connect_data['api_key'] = '';
        $this->connect_data['connected'] = false;
        $this->update_data();
        
        // remove theme updates for mts themes in transient by searching through 'packages' properties for 'mythemeshop'
        $transient = get_site_transient( 'update_themes' );
        delete_site_transient( 'mts_update_themes' );
        if ( $transient && !empty($transient->response) ) {
            foreach ($transient->response as $theme => $data) {
                if (strstr($data['package'], 'mythemeshop') !== false) {
                    unset($transient->response[$theme]);
                }
            }
            set_site_transient('update_themes', $transient);
		}
        $transient = get_site_transient( 'update_plugins' );
        delete_site_transient( 'mts_update_plugins' );
        if ( $transient && !empty($transient->response) ) {
            foreach ($transient->response as $plugin => $data) {
                if (strstr($data->package, 'mythemeshop') !== false) {
                    unset($transient->response[$plugin]);
                }
            }
            set_site_transient('update_plugins', $transient);
		}
        $this->reset_notices();
    }
    
    function reset_notices() {
        $notices = $this->notices + $this->sticky_notices;
        foreach ($notices as $id => $notice) {
            $this->remove_notice( $id );
            $this->undismiss_notice( $id );
        }
    }
    
    function admin_menu() {
        // Add the new admin menu and page and save the returned hook suffix
    	$this->menu_hook_suffix = add_menu_page('MyThemeShop Connect', 'MyThemeShop', 'manage_options', 'mts-connect', array( &$this, 'show_ui' ), 'dashicons-update', 66 );
    	// Use the hook suffix to compose the hook and register an action executed when plugin's options page is loaded
    	add_action( 'load-' . $this->menu_hook_suffix , array( &$this, 'ui_onload' ) );
    }
    function admin_init() {
        wp_register_script( 'mts-connect', plugins_url('/js/admin.js', __FILE__), array('jquery') );
        wp_register_script( 'mts-connect-form', plugins_url('/js/connect.js', __FILE__), array('jquery') );
        wp_register_style( 'mts-connect', plugins_url('/css/admin.css', __FILE__) );
        
        wp_localize_script('mts-connect', 'mtsconnect', array(
            'pluginurl' => network_admin_url('admin.php?page=mts-connect'),
            'connected_class_attr' => (!empty($this->connect_data['connected']) && empty($_GET['disconnect']) ? 'connected' : 'disconnected'),
            'check_themes_url' => network_admin_url('themes.php?force-check=1'),
            'check_plugins_url' => network_admin_url('plugins.php?force-check=1'),
            'l10n_ajax_login_success' => __('<p>Login successful! Checking for theme updates...</p>', 'mts-connect'),
            'l10n_ajax_theme_check_done' => __('<p>Theme check done. Checking plugins...</p>', 'mts-connect'),
            'l10n_ajax_plugin_check_done' => __('<p>Plugin check done. Refreshing page...</p>', 'mts-connect'),
            'l10n_check_themes_button' => __('Check for updates now', 'mts-connect'),
            'l10n_check_plugins_button' => __('Check for updates now', 'mts-connect'),
        ) );
        
        wp_enqueue_script( 'mts-connect' );
        wp_enqueue_style( 'mts-connect' );
        
        $current_user = wp_get_current_user();
        // Tags to use in notifications
        $this->notice_tags = array(
            '[logo_url' => plugins_url( 'images/wordpress.png' , __FILE__ ),
            '[plugin_url]' => network_admin_url('admin.php?page=mts-connect'),
            '[themes_url]' => network_admin_url('themes.php'),
            '[plugins_url]' => network_admin_url('plugins.php'),
            '[updates_url]' => network_admin_url('update-core.php'),
            '[site_url]' => site_url(),
            '[user_firstname]' => $current_user->first_name
        );
        
    }
    
    function force_check() {
        $screen = get_current_screen();
        if (isset($_GET['force-check']) && $_GET['force-check'] == 1) {
            switch ($screen->id) {
                case 'themes':
                case 'themes-network':
                    $this->update_themes_now();
                break;
                
                case 'plugins':
                case 'plugins-network':
                    $this->update_plugins_now();
                break;
                
                case 'update-core':
                case 'network-update-core':
                    $this->update_themes_now();
                    $this->update_plugins_now();
                break;
                
            }
        }
    }
    
    function update_themes_now() {
		if ( $transient = get_site_transient( 'update_themes' ) ) {
            delete_site_transient( 'mts_update_themes' );
            set_site_transient('update_themes', $transient);
		}
    }
    function update_plugins_now() {
		if ( $transient = get_site_transient( 'update_plugins' ) ) {
            delete_site_transient( 'mts_update_plugins' );
            set_site_transient('update_plugins', $transient);
		}
    }
    
    function plugin_get_version() {
        $plugin_data = get_plugin_data( __FILE__ );
        $plugin_version = $plugin_data['Version'];
        return $plugin_version;
    }
    
    function check_theme_updates( $update_transient ){
		global $wp_version;
        
        if ( !isset($update_transient->checked) )
            return $update_transient;
        else
            $themes = $update_transient->checked;
        
        $mts_updates = get_site_transient('mts_update_themes');
        if (empty($_GET['disconnect'])) {
    		$send_to_api = array(
    			'installed_themes' => $themes,
				'php_version'      => phpversion(),
				'siteurl'          => home_url(),
				'wp_version'       => $wp_version,  
                'plugin_version'   => $this->plugin_get_version(),
                'connected'        => 0
    		);
            // is connected
            if ($this->connected()) {
                $send_to_api['connected'] = 1;
                $send_to_api['username'] = $this->connect_data['username'];
                $send_to_api['api_key'] = $this->connect_data['api_key'];
            }
    
    		$options = array(
    			'timeout' => ( ( defined('DOING_CRON') && DOING_CRON ) ? 30 : 10),
    			'body'			=> $send_to_api,
    			'user-agent'	=> 'WordPress/' . $wp_version . '; ' . home_url()
    		);
    
    		$last_update = new stdClass();
    
    		$theme_request = wp_remote_post( $this->api_url.'?action=check_themes', $options );
            
    		if ( ! is_wp_error( $theme_request ) && wp_remote_retrieve_response_code( $theme_request ) == 200 ){
 			    $theme_response = json_decode( wp_remote_retrieve_body( $theme_request ), true );
    
    			if ( ! empty( $theme_response ) ) {
                    if ( ! empty( $theme_response['themes'] )) {
                        if ( empty( $update_transient->response ) ) $update_transient->response = array();
                        $update_transient->response = array_merge( (array) $update_transient->response, (array) $theme_response['themes'] );
                    }
    				$last_update->checked = $themes;
                    
                    if (!empty($theme_response['themes'])) {
                        $last_update->response = $theme_response['themes'];
                    } else {
                        $last_update->response = array();
                    }
                    
                    if (!empty($theme_response['notices'])) {
                        foreach ($theme_response['notices'] as $notice) {
                            if (!empty($notice['network_notice'])) {
                                $this->add_network_notice((array) $notice);
                            } else {
                                $this->add_sticky_notice((array) $notice);
                            }
                        }
                    }
                    
                    if (!empty($theme_response['disconnect'])) $this->disconnect();
    			}
    		}
    
    		$last_update->last_checked = time();
    		set_site_transient( 'mts_update_themes', $last_update );
        }

		return $update_transient;
	}
    
    function check_plugin_updates( $update_transient ){
		global $wp_version;
        
        if ( !isset($update_transient->checked) )
            return $update_transient;
        else
            $plugins = $update_transient->checked;
        
        $mts_updates = get_site_transient('mts_update_plugins');
        if (empty($_GET['disconnect'])) {
    		$send_to_api = array(
    			'installed_plugins' => $plugins,
				'php_version'      => phpversion(),
				'siteurl'          => home_url(),
				'wp_version'       => $wp_version,  
                'plugin_version'   => $this->plugin_get_version(),
                'connected'        => 0
    		);
            // is connected
            if ($this->connected()) {
                $send_to_api['connected'] = 1;
                $send_to_api['username'] = $this->connect_data['username'];
                $send_to_api['api_key'] = $this->connect_data['api_key'];
            }
    
    		$options = array(
    			'timeout' => ( ( defined('DOING_CRON') && DOING_CRON ) ? 30 : 10),
    			'body'			=> $send_to_api,
    			'user-agent'	=> 'WordPress/' . $wp_version . '; ' . home_url()
    		);
    
    		$last_update = new stdClass();
    
    		$plugin_request = wp_remote_post( $this->api_url.'?action=check_plugins', $options );
            
    		if ( ! is_wp_error( $plugin_request ) && wp_remote_retrieve_response_code( $plugin_request ) == 200 ){
 			    $plugin_response = json_decode( wp_remote_retrieve_body( $plugin_request ), true );
    
    			if ( ! empty( $plugin_response ) ) {
                    if ( ! empty( $plugin_response['plugins'] )) {
                        if ( empty( $update_transient->response ) ) $update_transient->response = array();
                        
                        // array to object
                        $new_arr = array();
                        foreach ($plugin_response['plugins'] as $pluginname => $plugindata) {
                            $object = new stdClass();
                            foreach ($plugindata as $k => $v) {
                                $object->$k = $v;
                            }
                            $new_arr[$pluginname] = $object;
                        }
                        $plugin_response['plugins'] = $new_arr;
                        
                        $update_transient->response = array_merge( (array) $update_transient->response, (array) $plugin_response['plugins'] );
                    }
    				$last_update->checked = $plugins;
                    
                    if (!empty($plugin_response['plugins'])) {
                        $last_update->response = $plugin_response['plugins'];
                    } else {
                        $last_update->response = array();
                    }
                    
                    if (!empty($plugin_response['notices'])) {
                        foreach ($plugin_response['notices'] as $notice) {
                            if (!empty($notice['network_notice'])) {
                                $this->add_network_notice((array) $notice);
                            } else {
                                $this->add_sticky_notice((array) $notice);
                            }
                        }
                    }
                    
                    if (!empty($plugin_response['disconnect'])) $this->disconnect();
    			}
    		}
    
    		$last_update->last_checked = time();
    		set_site_transient( 'mts_update_plugins', $last_update );
        }

		return $update_transient;
	}
    
    function ui_onload() {
        if (isset($_GET['disconnect']) && $_GET['disconnect'] == 1) {
            $this->disconnect();
            $this->add_notice(array('content' => __('Disconnected.', 'mts-connect'), 'class' => 'error'));
        }
        if (isset($_GET['reset_notices']) && $_GET['reset_notices'] == 1)  {
            $this->reset_notices();
            $this->add_notice(array('content' => __('Notices reset.', 'mts-connect')));
        }
        
    }
    
    public function show_ui() {
        wp_enqueue_script('mts-connect-form');
        echo '<div class="mts_connect_ui">';
        echo '<h2>'.__('MyThemeShop Connect', 'mts-connect').'</h2>';
        echo '<div class="mts_connect_ui_content">';
        if ( $this->connected() ) {
            
            echo '<p>'.__('Connected!', 'mts-connect').'</br>';
            echo __('MyThemeShop username:', 'mts-connect').' <strong>'.$this->connect_data['username'].'</strong></p>';
            echo '<a href="'.add_query_arg('disconnect', '1').'">'.__('Disconnect', 'mts-connect').'</a>';
            
        } else {
            // connect form
            $form = '<form action="'.admin_url('admin-ajax.php').'" method="post" id="mts_connect_form">';
            $form .= '<input type="hidden" name="action" value="mts_connect" />';
            $form .= '<p>'.__('Enter your MyThemeShop Username and Password to get free Theme/Plugin updates.', 'mts-connect').'</p>';
            $form .= '<label>'.__('Username', 'mts-connect').'</label>';
            $form .= '<input type="text" val="" name="username" id="mts_username" />';
            $form .= '<label>'.__('Password', 'mts-connect').'</label>';
            $form .= '<input type="password" val="" name="password" id="mts_password" />';
            
            $form .= '<input type="submit" class="button button-primary" value="'.__('Connect', 'mts-connect').'" />';
            
            $form .= '</form>';
            
            echo $form;
            
        }
        echo '</div>'; 
        echo '</div>';
    }
    
    function after_theme() {
        add_action('admin_menu', array(&$this, 'remove_themeupdates_page'));
    }
    function remove_themeupdates_page() {
        remove_submenu_page( 'index.php', 'mythemeshop-updates' );
    }
    
    function connected() {
        return ( ! empty( $this->connect_data['connected'] ) );
    }
    
    function get_data() {
        $options = get_option( $this->data_option );
        if (empty($options)) $options = array();
        return $options;
    }
    function get_settings() {
        $settings = get_option( $this->settings_option );
        if (empty($settings)) {
            $settings = $this->default_settings;
            update_option( $this->settings_option, $settings );
        }
        return $settings;
    }
    function get_notices() {
        $notices = get_option( $this->notices_option );
        if (empty($notices)) $notices = array();
        return $notices;
    }
    
    /**
     * add_notice() 
     * $args:
     * - content: notice content text or HTML
     * - class: notice element class attribute, possible values are 'updated' (default), 'error', 'update-nag', 'mts-network-notice'
     * - priority:  default 10
     * - date: date of notice as UNIX timestamp
     * - expire: expiry date as UNIX timestamp. Notice is removed and "undissmissed" ater expiring
     * - (array) context: 
     *      - screen: admin page id where the notice should appear
     *      - (bool) connected: check if plugin have this setting
     * 
     * @return
     */
    public function add_notice( $args ) {
        if (empty($args)) return;
        
        if (is_string( $args ) && ! strstr($args, 'content=')) $args = array('content' => $args); // $this->add_notice('instant content!');
        
        $args = wp_parse_args( $args, $this->notice_defaults );
        
        if (empty($args['content'])) return;
        
        $id = ( empty( $args['id'] ) ? md5($args['content']) : $args['id'] );
        unset( $args['id'] );
        
        if ($args['sticky']) {
            if (empty($args['overwrite']) && empty($this->sticky_notices[$id])) {
                $this->sticky_notices[$id] = $args;
                $this->update_notices();
            }
        } else {
            $this->notices[$id] = $args;
        }
    }
    
    
    public function add_sticky_notice( $args ) {
        $args = wp_parse_args( $args, array() );
        $args['sticky'] = 1;
        $this->add_notice( $args );
    }
    
    // Network notices are additional API messages (news and offers) that can be switched off with an option
    public function add_network_notice( $args ) {
        if (!empty($this->settings['network_notices'])) {
            $args['network_notice'] = 1;
            $this->add_sticky_notice( $args );
        }
    }
    
    public function error_message( $msg ) {
        $this->add_notice( array('content' => $msg, 'class' => 'error') );
    }
    
    public function remove_notice( $id ) {
        unset( $this->notices[$id], $this->sticky_notices[$id] );
        $this->update_notices();
    }
     
    protected function update_data() {
        update_option( $this->data_option, $this->connect_data );
    }
    protected function update_settings() {
        update_option( $this->settings_option, $this->settings );
    }
    protected function update_notices() {
        update_option( $this->notices_option, $this->sticky_notices );
    }
    public function show_notices() {
        global $current_user;
        $user_id = $current_user->ID;
        $notices = $this->notices + $this->sticky_notices;
        uasort($notices, array($this, 'sort_by_priority'));
        $multiple_notices = false;
        $thickbox = 0;
        foreach ($notices as $id => $notice) {
            // expired
            if ( $notice['expire'] < time() ) {
                $this->remove_notice( $id );
                $this->undismiss_notice( $id );
                continue;
            }
            
            
            // sticky & dismissed
            if ( $notice['sticky'] ) {
                $dismissed = get_user_meta( $user_id, $this->dismissed_meta, true );
                if ( empty( $dismissed ) ) $dismissed = array();
                if (in_array( $id, $dismissed ))
                    continue;
            }
            
            // network notice and disabled
            if ( !empty($notice['network_notice']) && empty($this->settings['network_notices'])) {
                continue;
            }
            
            // context: connected
            if (isset($notice['context']['connected'])) {
                if ( ( ! $notice['context']['connected'] && $this->connect_data['connected'] )
                    || ( $notice['context']['connected'] && ! $this->connect_data['connected'] ) ) {
                    continue; // skip this
                }
            }
            
            // context: screen
            if (isset($notice['context']['screen'])) {
                if (!is_array($notice['context']['screen'])) {
                    $notice['context']['screen'] = array($notice['context']['screen']);
                }
                $is_targeted_page = false;
                $screen = get_current_screen();
                foreach ($notice['context']['screen'] as $page) {
                    if ($screen->id == $page) $is_targeted_page = true;
                }
                if (!$is_targeted_page) continue; // skip if not targeted
            }
            
            if ( ! $thickbox ) { add_thickbox(); $thickbox = 1; }
            
            // wrap plaintext content in <p>
            // assumes text if first char != '<'
            if (substr(trim($notice['content']), 0 , 1) != '<') $notice['content'] = '<p>'.$notice['content'].'</p>';   
            
            // insert notice tags
            foreach ($this->notice_tags as $tag => $value) {
                $notice['content'] = str_replace($tag, $value, $notice['content']);
            }
            
            echo '<div class="'.$notice['class'].($notice['sticky'] ? ' mts-connect-sticky' : '').' mts-connect-notice" id="notice_'.$id.'">';
            echo $notice['content'];
            echo '<a href="' . add_query_arg( 'mts_dismiss_notice', $id ) . '" class="dashicons dashicons-dismiss mts-notice-dismiss-icon" title="'.__('Dissmiss Notice').'"></a>';
            echo '<a href="' . add_query_arg( 'mts_dismiss_notice', 'dismiss_all' ) . '" class="dashicons dashicons-dismiss mts-notice-dismiss-all-icon" title="'.__('Dissmiss All Notices').'"></a>';
            echo '</div>';
            $multiple_notices = true;
        }
        
    }
    
    public function dismiss_notices() {
        if ( !empty($_GET['mts_dismiss_notice']) && is_string( $_GET['mts_dismiss_notice'] ) ) {
            if ( $_GET['mts_dismiss_notice'] == 'dismiss_all' ) {
                foreach ( $this->sticky_notices as $id => $notice ) {
                    $this->dismiss_notice( $id );
                }
            } else {
                $this->dismiss_notice( $_GET['mts_dismiss_notice'] );
            }
            
    	}
    }
    private function dismiss_notice( $id ) {
        global $current_user;
        $user_id = $current_user->ID;
        $dismissed = get_user_meta($user_id, $this->dismissed_meta, true );
        if (is_string($dismissed)) $dismissed = array($dismissed);
        if ( ! in_array( $id, $dismissed ) ) {
            $dismissed[] = $id;
            update_user_meta($user_id, $this->dismissed_meta, $dismissed);
        }
    }
    
    private function undismiss_notice( $id ) {
        global $current_user;
        $user_id = $current_user->ID;
        $dismissed = get_user_meta($user_id, $this->dismissed_meta, true );
        if (is_string($dismissed)) $dismissed = array($dismissed);
        if ( $key = array_search( $id, $dismissed ) ) {
            unset( $dismissed[$key] );
            update_user_meta($user_id, $this->dismissed_meta, $dismissed);
        }
    }
    
    public function sort_by_priority($a, $b) {
        if ($a['priority'] == $b['priority']) return 1;
        return $a['priority'] - $b['priority'];
    }
    
}

$mts_connection = new mts_connection();

?>