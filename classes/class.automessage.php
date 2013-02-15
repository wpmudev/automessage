<?php

class automessage {

	var $build = 5;

	// Our own link to the database class - using this means we can easily switch db libraries in just this class if required
	var $db;

	var $user_id;

	// Change this to increase or decrease the number of messages to process in any run
	var $processlimit = 250;

	function __construct() {

		global $wpdb, $blog_id;

		// Link to the database class
		$this->db =& $wpdb;

		// Installation functions
		$installed = get_automessage_option('automessage_installed', false);
		if($installed != $this->build) {
			$this->install();
		}

		add_action( 'plugins_loaded', array(&$this, 'load_textdomain'));

		add_action( 'init', array($this, 'initialise_plugin'));
		add_action(	'init', array(&$this,'process_user_automessage'));
		add_action(	'init', array(&$this,'process_blog_automessage'));

		add_action('admin_menu', array(&$this,'setup_menu'), 100);

		add_action('load-toplevel_page_automessage', array(&$this, 'add_admin_header_automessage_dash'));
		add_action('load-automessage_page_automessage_blogadmin', array(&$this, 'add_admin_header_automessage_blogadmin'));
		add_action('load-automessage_page_automessage_useradmin', array(&$this, 'add_admin_header_automessage_useradmin'));

		add_action( 'automessage_dashboard_left', array(&$this, 'dashboard_news') );

		if($blog_id == 1 || !is_multisite()) {
			// All the following actions we only want on the main blog
			// Rewrites
			add_action('generate_rewrite_rules', array(&$this, 'add_rewrite'));
			add_filter('query_vars', array(&$this, 'add_queryvars'));

			// Set up api object to enable processing by other plugins
			add_action('pre_get_posts', array(&$this, 'process_unsubscribe_action') );
		}

		if(defined('AUTOMESSAGE_POLL_USERS') && AUTOMESSAGE_POLL_USERS === true) {
			// We are going to circumvent any action calling issues by regularly checking for new users.
			add_action('init', array(&$this, 'poll_new_users'));
		} else {
			add_action('user_register', array(&$this,'add_user_message'), 10, 1);
		}

		if(function_exists('is_multisite') && is_multisite()) {
			if(defined('AUTOMESSAGE_POLL_BLOGS') && AUTOMESSAGE_POLL_BLOGS === true) {
				// We are going to circumvent any action calling issues by regularly checking for new users.
				add_action('init', array(&$this, 'poll_new_blogs'));
			} else {
				add_action('wpmu_new_blog',array(&$this,'add_blog_message'), 10, 2);
			}
		}

		//$actions = apply_filters( 'user_row_actions', $actions, $user_object );
		add_filter( 'user_row_actions', array( &$this, 'add_user_to_queue_action' ), 99, 2 );
		add_filter( 'ms_user_row_actions', array( &$this, 'add_msuser_to_queue_action' ), 99, 2 );
		//ms_user_row_actions

		//$actions = apply_filters( 'manage_sites_action_links', array_filter( $actions ), $blog['blog_id'], $blogname );
		add_filter( 'manage_sites_action_links', array( &$this, 'add_blog_to_queue_action' ), 99, 3 );

		add_action( 'load-users.php', array( &$this, 'process_add_user_to_queue_action' ) );
		add_action( 'load-sites.php', array( &$this, 'process_add_blog_to_queue_action' ) );
	}

	function __destruct() {
		return true;
	}

	function automessage() {
		$this->__construct();
	}

	function load_textdomain() {

		$locale = apply_filters( 'automessage_locale', get_locale() );
		$mofile = automessage_dir( "languages/automessage-$locale.mo" );

		if ( file_exists( $mofile ) )
			load_textdomain( 'automessage', $mofile );

	}

	function add_update_check() {
	}

	function initialise_plugin() {

		$role = get_role( 'administrator' );
		if( method_exists($role, 'has_cap') && !$role->has_cap( 'read_automessage' ) ) {
			// Administrator
			$role->add_cap( 'read_automessage' );
			$role->add_cap( 'edit_automessage' );
			$role->add_cap( 'delete_automessage' );
			$role->add_cap( 'publish_automessages' );
			$role->add_cap( 'edit_automessages' );
			$role->add_cap( 'edit_others_automessages' );
		}

		// Register the property post type
		register_post_type('automessage', array(	'singular_label' => __('Messages','automessage'),
													'label' => __('Messages', 'automessage'),
													'public' => false,
													'show_ui' => false,
													'publicly_queryable' => false,
													'exclude_from_search' => true,
													'hierarchical' => true,
													'capability_type' => 'automessage',
													'edit_cap' => 'edit_automessage',
													'edit_type_cap' => 'edit_automessages',
													'edit_others_cap' => 'edit_others_automessages',
													'publish_others_cap' => 'publish_automessages',
													'read_cap' => 'read_automessage',
													'delete_cap' => 'delete_automessage'
													)
												);

		$user = wp_get_current_user();
		$this->user_id = $user->ID;

		do_action('automessage_addlisteners');

	}

	function process_add_user_to_queue_action() {

		if( isset($_GET['action']) && $_GET['action'] == 'addtoautomessageuserqueue' ) {

			check_admin_referer( 'queueuser' );

			$user_id = (isset($_GET['user'])) ? (int) $_GET['user'] : false;
			if(!empty($user_id) && is_numeric($user_id)) {
				$this->add_user_message( $user_id );
			}

		}

	}

	function process_add_blog_to_queue_action() {

		if( isset($_GET['action']) && $_GET['action'] == 'addtoautomessageblogqueue' ) {

			check_admin_referer( 'queueblog' );

			$blog_id = (isset($_GET['id'])) ? (int) $_GET['id'] : false;
			if(!empty($blog_id) && is_numeric($blog_id)) {
				// Get the user_id of the person we think created the blog
				$user_id = $this->db->get_var( $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = '" . $this->db->base_prefix . $blog_id . "_capabilities' AND meta_value = %s", 'a:1:{s:13:"administrator";b:1;}') );
				if(!empty($user_id)) {
					$this->add_blog_message( $blog_id, $user_id );
				}
			}

		}

	}

	function add_user_to_queue_action( $actions, $user_object ) {

		$url = 'users.php?';

		$user = new Auto_User($user_object->ID);

		if(!$user->on_action()) {
			$actions['automessage'] = "<a class='submitautomessage' href='" . wp_nonce_url( $url."action=addtoautomessageuserqueue&amp;user=$user_object->ID", 'queueuser' ) . "' title='" . __('Add user to Automessage queue', 'automessage') . "'>" . __( 'Queue', 'automessage' ) . "</a>";
		}

		return $actions;
	}

	function add_msuser_to_queue_action( $actions, $user_object ) {

		$url = 'users.php?';

		$user = new Auto_User($user_object->ID);

		if(!$user->on_action()) {
			$actions['automessage'] = '<a href="' . $delete = esc_url( network_admin_url( add_query_arg( '_wp_http_referer', urlencode( stripslashes( $_SERVER['REQUEST_URI'] ) ), wp_nonce_url( 'users.php', 'queueuser' ) . '&amp;action=addtoautomessageuserqueue&amp;id=' . $user_object->ID ) ) ) . '" class="submitautomessage" title="' . __('Add user to Automessage queue', 'automessage') . '">' . __( 'Queue', 'automessage' ) . '</a>';
		}

		return $actions;
	}

	function add_blog_to_queue_action( $actions, $blog_id, $blog_name ) {

		$url = 'users.php?';

		$user_id = $this->find_user_id_for_blog( $blog_id );
		if($user_id !== false) {
			$user = new Auto_User( $user_id );
			if(!$user->on_action( 'blog' )) {
				$actions['automessage'] = '<a href="' . $delete = esc_url( network_admin_url( add_query_arg( '_wp_http_referer', urlencode( stripslashes( $_SERVER['REQUEST_URI'] ) ), wp_nonce_url( 'sites.php', 'queueblog' ) . '&amp;action=addtoautomessageblogqueue&amp;id=' . $blog_id ) ) ) . '" class="submitautomessage" title="' . __('Add blog to Automessage queue', 'automessage') . '">' . __( 'Queue', 'automessage' ) . '</a>';
			}
		}

		return $actions;
	}

	function find_user_id_for_blog( $blog_id ) {

		//_automessage_on_blog
		$sql = $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = %s AND meta_value = %s", '_automessage_on_blog', $blog_id );

		$user_id = $this->db->get_var( $sql );

		if(!empty($user_id)) {
			return $user_id;
		} else {
			return false;
		}

	}

	function setup_menu() {

		global $menu, $admin_page_hooks;

		add_menu_page(__('Automessage','automessage'), __('Automessage','automessage'), 'edit_automessage',  'automessage', array(&$this,'handle_dash_panel'));

		// Fix WP translation hook issue
		if(isset($admin_page_hooks['automessages'])) {
			$admin_page_hooks['automessages'] = 'automessages';
		}

		// Add the sub menu
		if(function_exists('is_super_admin') && is_super_admin()) {
			add_submenu_page('automessage', __('Edit Blog Messages','automessage'), __('Blog Level Messages','automessage'), 'edit_automessage', "automessage_blogadmin", array(&$this,'handle_blogmessageadmin_panel'));
		}

		add_submenu_page('automessage', __('Edit User Messages','automessage'), __('User Level Messages','automessage'), 'edit_automessage', "automessage_useradmin", array(&$this,'handle_usermessageadmin_panel'));

	}

	function install($install = false) {

		if($install == false) {
			$this->flush_rewrite();
		}

		update_automessage_option('automessage_installed', $this->build);

	}

	function uninstall() {

	}

	function add_admin_header_automessage_core() {

		global $action, $page;

		wp_reset_vars( array('action', 'page') );

		$this->add_update_check();

		wp_enqueue_style( 'automessageadmincss', automessage_url('css/automessage.css'), array(), $this->build );
	}

	function add_admin_header_automessage_dash() {

		global $action, $page;

		$this->add_admin_header_automessage_core();
	}

	function add_admin_header_automessage_blogadmin() {

		global $action, $page;

		$this->add_admin_header_automessage_core();

		$this->process_admin_updates();

	}

	function add_admin_header_automessage_useradmin() {

		global $action, $page;

		$this->add_admin_header_automessage_core();

		$this->process_admin_updates();
	}

	function process_admin_updates() {
		global $action, $page;

		switch($action) {

			case 'addaction':
						check_admin_referer('add-action');
						if($this->add_action()) {
							wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 1, wp_get_original_referer() )) );
						} else {
							wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 2, wp_get_original_referer() )) );
						}
						break;
			case 'pauseaction':
						$id = addslashes($_GET['id']);
						$this->set_pause($id, true);
						wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 3, wp_get_original_referer() )) );
						break;
			case 'unpauseaction':
						$id = addslashes($_GET['id']);
						$this->set_pause($id, false);
						wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 4, wp_get_original_referer() )) );
						break;
			case 'allmessages':
						check_admin_referer($_POST['actioncheck']);
						if(isset($_POST['allaction_delete'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->delete_action($as);
								}
								wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 12, wp_get_original_referer() )) );
							} else {
								wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 5, wp_get_original_referer() )) );
							}
						}
						if(isset($_POST['allaction_pause'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->set_pause($as, true);
								}
								wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 6, wp_get_original_referer() )) );
							} else {
								wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 7, wp_get_original_referer() )) );
							}
						}
						if(isset($_POST['allaction_unpause'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->set_pause($as, false);
								}
								wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 8, wp_get_original_referer() )) );
							} else {
								wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 9, wp_get_original_referer() )) );
							}
						}
						if(isset($_POST['allaction_process'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->force_process($as);
								}
								wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 10, wp_get_original_referer() )) );
							} else {
								wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 11, wp_get_original_referer() )) );
							}
						}
						$this->handle_messageadmin_panel();
						break;
			case 'deleteaction':
						$id = addslashes($_GET['id']);
						$this->delete_action($id);
						wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 12, wp_get_original_referer() )) );
						break;
			case 'updateaction':
						check_admin_referer('update-action');
						$this->update_action();
						wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 13, wp_get_original_referer() )) );
						break;
			case 'processuseraction':
						$id = addslashes($_GET['id']);
						$this->force_process_user($id);
						wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 14, wp_get_original_referer() )) );
						break;
			case 'processblogaction':
						$id = addslashes($_GET['id']);
						$this->force_process_blog($id);
						wp_safe_redirect( remove_query_arg(array('action', 'id'), add_query_arg( 'msg', 14, wp_get_original_referer() )) );
						break;

			default:	// do nothing and carry on
						break;

		}
	}

	function show_admin_messages() {

		global $action, $page, $msg;

		$this->add_admin_header_automessage_core();

		if(isset($_GET['msg'])) {

			$msg = (int) $_GET['msg'];

			switch($msg) {
				case 1:		echo '<div id="message" class="updated fade"><p>' . __('Your action has been added to the schedule.', 'automessage') . '</p></div>';
							break;

				case 2:		echo '<div id="message" class="updated fade"><p>' . __('Your action could not be added.', 'automessage') . '</p></div>';
							break;

				case 3:		echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been paused', 'automessage') . '</p></div>';
							break;

				case 4:		echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been unpaused', 'automessage') . '</p></div>';
							break;

				case 5:		echo '<div id="message" class="updated fade"><p>' . __('Please select an action to delete', 'automessage') . '</p></div>';
							break;

				case 6:		echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been paused', 'automessage') . '</p></div>';
							break;

				case 7:		echo '<div id="message" class="updated fade"><p>' . __('Please select an action to pause', 'automessage') . '</p></div>';
							break;

				case 8:		echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been unpaused', 'automessage') . '</p></div>';
							break;

				case 9:		echo '<div id="message" class="updated fade"><p>' . __('Please select an action to unpause', 'automessage') . '</p></div>';
							break;

				case 10:	echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been processed', 'automessage') . '</p></div>';
							break;

				case 11:	echo '<div id="message" class="updated fade"><p>' . __('Please select an action to process', 'automessage') . '</p></div>';
							break;

				case 12:	echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been deleted', 'automessage') . '</p></div>';
							break;

				case 13:	echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been updated', 'automessage') . '</p></div>';
							break;

				case 14:	echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been processed', 'automessage') . '</p></div>';
							break;

			}

			$_SERVER['REQUEST_URI'] = remove_query_arg(array('msg'), $_SERVER['REQUEST_URI']);
		}
	}

	function add_blog_message($blog_id, $user_id) {
		// This function will add a scheduled item to the blog actions
		if(is_numeric($user_id)) {

			$action = $this->get_first_action( 'blog' );

			$theuser = new Auto_User( $user_id );
			$theuser->set_blog_id( $blog_id );
			$onaction = $theuser->on_action( 'blog' );

			if(!empty($action) && $onaction === false ) {

				// Remove any user level messages first as we only want blog level messages to be sent
				if( defined('AUTOMESSAGE_SINGLE_PATH') && AUTOMESSAGE_SINGLE_PATH == true ) {
					$theuser->clear_subscriptions( 'user' );
				}

				if($action->menu_order == 0) {
					// Immediate response
					$theuser->send_message( $action->post_title, $action->post_content );

					// The get the next one
					$next = $this->get_action_after( $action->ID, 'blog' );
					if(!empty($next)) {
						$theuser->schedule_message( $next->ID, strtotime('+' . $next->menu_order . ' days'), 'blog' );
					} else {
						$theuser->clear_subscriptions( 'blog' );
					}
				} else {
					// Schedule response
					$theuser = new Auto_User( $user_id );
					$theuser->schedule_message( $action->ID, strtotime('+' . $action->menu_order . ' days'), 'blog' );
				}
			}

		}
	}

	function add_user_message($user_id) {
		// This function will add a scheduled item to the user actions
		global $blog_id;

		if(!empty($user_id)) {

			$action = $this->get_first_action( 'user' );

			$theuser = new Auto_User( $user_id );
			$theuser->set_blog_id( $blog_id );
			$onaction = $theuser->on_action( 'user' );

			if(!empty($action) && $onaction === false ) {
				if($action->menu_order == 0) {
					// Immediate response - we no longer want to send immediately, rather wait for 15 minutes in case the user also creates a blog
					$theuser->schedule_message( $action->ID, strtotime('+5 minutes'), 'user' );

					// Commented out for now as moved to a 15 minute wait for first message
					/*
					$theuser->send_message( $action->post_title, $action->post_content );

					// The get the next one
					$next = $this->get_action_after( $action->ID, 'user' );
					if(!empty($next)) {
						$theuser->schedule_message( $next->ID, strtotime('+' . $next->menu_order . ' days'), 'user' );
					} else {
						$theuser->clear_subscriptions( 'user' );
					}
					*/
				} else {
					// Schedule response
					$theuser->schedule_message( $action->ID, strtotime('+' . $action->menu_order . ' days'), 'user' );
				}
			}

		}
	}

	function poll_new_users() {

		$lastmax = get_automessage_option('automessage_max_ID', false);

		if(empty($lastmax) || $lastmax === false || $lastmax < 1) {
			// first run - set it to the current maximum
			$maxID = $this->db->get_var( "SELECT MAX(ID) FROM {$this->db->users}" );
			update_automessage_option('automessage_max_ID', $maxID);
		} else {
			// later runs, check the maximum user ID and process if needed.
			$users = $this->db->get_col( $this->db->prepare( "SELECT ID FROM {$this->db->users} WHERE ID > %d", $lastmax) );
			if(!empty($users)) {
				update_automessage_option('automessage_max_ID', max($users) );
				foreach($users as $user_ID) {
					$this->add_user_message( $user_ID );
				}
			}
		}

	}

	function poll_new_blogs() {

		$lastmax = get_automessage_option('automessage_max_blog_ID', false);

		if(empty($lastmax) || $lastmax === false || $lastmax < 1) {
			// first run - set it to the current maximum
			$maxID = $this->db->get_var( "SELECT MAX(blog_id) FROM {$this->db->blogs}" );
			update_automessage_option('automessage_max_blog_ID', $maxID);
		} else {
			// later runs, check the maximum user ID and process if needed.
			$blogs = $this->db->get_col( $this->db->prepare( "SELECT blog_id FROM {$this->db->blogs} WHERE blog_id > %d", $lastmax) );
			if(!empty($blogs)) {
				foreach($blogs as $blog_ID) {
					// Get the user_id of the person we think created the blog
					$user_id = $this->db->get_var( $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = '" . $this->db->base_prefix . $blog_ID . "_capabilities' AND meta_value = %s", 'a:1:{s:13:"administrator";b:1;}') );
					if(!empty($user_id)) {
						$this->add_blog_message( $blog_ID, $user_id );
					}
				}
				update_automessage_option('automessage_max_blog_ID', max($blogs) );
			}
		}

	}

	function get_queued_for_message($id) {

		$sql = $this->db->prepare( "SELECT count(*) FROM {$this->db->usermeta} WHERE meta_key LIKE %s AND meta_value = %s", '_automessage_on_%_action', $id );

		return $this->db->get_var( $sql );

	}


	function get_bloglevel_schedule() {

		$args = array(
			'posts_per_page' => 250,
			'offset' => 0,
			'post_type' => 'automessage',
			'post_status' => 'private, draft',
			'meta_key' => '_automessage_level',
			'orderby' => 'menu_order',
			'order' => 'ASC',
			'meta_value' => 'blog'
		);

		$get_actions = new WP_Query;
		$actions = $get_actions->query($args);

		return $actions;

	}

	function get_userlevel_schedule() {

		$args = array(
			'posts_per_page' => 250,
			'offset' => 0,
			'post_type' => 'automessage',
			'post_status' => 'private, draft',
			'meta_key' => '_automessage_level',
			'orderby' => 'menu_order',
			'order' => 'ASC',
			'meta_value' => 'user'
		);

		$get_actions = new WP_Query;
		$actions = $get_actions->query($args);

		return $actions;

	}

	function get_available_actions($level) {

		if(empty($level)) {
			return false;
		}

		$args = array(
			'posts_per_page' => 250,
			'offset' => 0,
			'post_type' => 'automessage',
			'post_status' => 'private',
			'meta_key' => '_automessage_level',
			'orderby' => 'menu_order',
			'order' => 'ASC',
			'meta_value' => $level
		);

		$get_actions = new WP_Query;
		$actions = $get_actions->query($args);

		return $actions;

	}

	function get_first_action($level) {

		if(empty($level)) {
			return false;
		}

		$args = array(
			'posts_per_page' => 250,
			'offset' => 0,
			'post_type' => 'automessage',
			'post_status' => 'private',
			'meta_key' => '_automessage_level',
			'orderby' => 'menu_order',
			'order' => 'ASC',
			'meta_value' => $level
		);

		$get_actions = new WP_Query;
		$actions = $get_actions->query($args);

		if(!empty($actions)) {
			return array_shift($actions);
		} else {
			return false;
		}
	}

	function get_action_after( $previous_id, $level ) {

		if(empty($level)) {
			return false;
		}

		$args = array(
			'posts_per_page' => 250,
			'offset' => 0,
			'post_type' => 'automessage',
			'post_status' => 'private',
			'meta_key' => '_automessage_level',
			'orderby' => 'menu_order',
			'order' => 'ASC',
			'meta_value' => $level
		);

		$get_actions = new WP_Query;
		$actions = $get_actions->query($args);

		if(!empty($actions)) {
			$wantnext = false;
			foreach($actions as $action) {
				if($action->ID == $previous_id) {
					$wantnext = true;
				} else {
					if($wantnext) {
						return $action;
					}
				}

			}
		}

		return false;

	}

	function get_action($id = false) {

		if(!$id) {
			return false;
		}

		$result = &get_post($id);

		if( !empty($result) ) {
			return $result;
		} else {
			return false;
		}

	}

	function add_action() {

		$hook = $_POST['hook'];
		$subject = $_POST['subject'];
		$message = $_POST['message'];

		$period = $_POST['period'] . ' ' . $_POST['timeperiod'];

		$type = $_POST['type'];

		$post = array(
		'post_title' => $subject,
		'post_content' => $message,
		'post_name' => sanitize_title($subject),
		'post_status' => 'private', // You can also make this pending, or whatever you want, really.
		'post_author' => $this->user_id,
		'post_category' => array(get_option('default_category')),
		'post_type' => 'automessage',
		'comment_status' => 'closed',
		'menu_order' => $_POST['period']
		);

		// update the post
		$message_id = wp_insert_post($post);

		if(!is_wp_error($message_id)) {
			update_metadata('post', $message_id, '_automessage_hook', $hook);
			update_metadata('post', $message_id, '_automessage_level', $type);
			update_metadata('post', $message_id, '_automessage_period', $period);
		}

		return $message_id;

	}

	function delete_action($scheduleid) {


		if($scheduleid) {
			wp_delete_post( $scheduleid, true );
		}

	}

	function update_action() {

		$id = $_POST['ID'];
		$hook = $_POST['hook'];
		$subject = $_POST['subject'];
		$message = $_POST['message'];

		$period = $_POST['period'] . ' ' . $_POST['timeperiod'];

		$type = $_POST['type'];

		$post = array(
		'post_title' => $subject,
		'post_content' => $message,
		'post_name' => sanitize_title($subject),
		'post_status' => 'private', // You can also make this pending, or whatever you want, really.
		'post_category' => array(get_option('default_category')),
		'post_type' => 'automessage',
		'comment_status' => 'closed',
		'menu_order' => $_POST['period'],
		'ID' => $id
		);

		// update the post
		$message_id = wp_update_post($post);

		if(!is_wp_error($message_id)) {
			update_metadata('post', $message_id, '_automessage_hook', $hook);
			update_metadata('post', $message_id, '_automessage_level', $type);
			update_metadata('post', $message_id, '_automessage_period', $period);
		}

		return $message_id;

	}

	function set_pause($scheduleid, $pause = true) {

		if($pause) {

			$post = array(
			'post_status' => 'draft',
			'ID' => $scheduleid
			);

			// update the post
			$message_id = wp_update_post($post);

		} else {

			$post = array(
			'post_status' => 'private',
			'ID' => $scheduleid
			);

			// update the post
			$message_id = wp_update_post($post);


		}


	}

	function edit_action_form($id, $type) {

		global $page;

		$editing = $this->get_action($id);

		if(!$editing) {
			$this->add_action_form($type);
			return;
		}

		if(!empty($editing->ID)) {
			$metadata = get_post_custom($editing->ID);
		} else {
			$metadata = array();
		}

		echo "<div class='wrap'>";
		echo "<h2>" . __('Edit Action', 'automessage') . "</h2>";

		echo '<div id="poststuff" class="metabox-holder">';
		?>
		<div class="postbox">
			<h3 class="hndle" style='cursor:auto;'><span><?php _e('Edit Action','automessage'); ?></span></h3>
			<div class="inside">
		<?php

		echo '<form method="post" action="?page=' . $page . '">';
		echo '<input type="hidden" name="ID" value="' . $editing->ID . '" />';
		echo "<input type='hidden' name='type' value='" . $type . "' />";
		echo "<input type='hidden' name='action' value='updateaction' />";
		wp_nonce_field('update-action');
		echo '<table class="form-table">';
		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Action','automessage') . '</th>';
		echo '<td valign="top">';

		echo '<select name="hook" style="width: 40%;">';
			switch($type) {
				case 'blog':	echo '<option value="wpmu_new_blog"';
								echo '>';
								echo __('Create new blog','automessage');
								echo '</option>';
								break;

				case 'user':	echo '<option value="wpmu_new_user"';
								echo '>';
								echo __('Create new user','automessage');
								echo '</option>';
								break;
			}
		echo '</select>';

		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message delay','automessage') . '</th>';
		echo '<td valign="top">';

		echo '<select name="period" style="width: 40%;">';
		for($n = 0; $n <= AUTOMESSAGE_POLL_MAX_DELAY; $n++) {
			echo "<option value='$n'";
			if($editing->menu_order == $n)  echo ' selected="selected" ';
			echo ">";
			switch($n) {
				case 0: 	echo __("Send immediately", 'automessage');
							break;
				case 1: 	echo __("1 day", 'automessage');
							break;
				default:	echo sprintf(__('%d days','automessage'),$n);
			}
			echo "</option>";
		}
		echo '</select>';
		echo '<input type="hidden" name="timeperiod" value="day" />';
		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message Subject','automessage') . '</th>';
		echo '<td valign="top"><input name="subject" type="text" size="50" title="' . __('Message subject') . '" style="width: 50%;" value="' . htmlentities(stripslashes($editing->post_title),ENT_QUOTES, 'UTF-8') . '" /></td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message','automessage') . '</th>';
		echo '<td valign="top"><textarea name="message" style="width: 50%; float: left;" rows="15" cols="40">' . htmlentities(stripslashes($editing->post_content),ENT_QUOTES, 'UTF-8') . '</textarea>';
		// Display some instructions for the message.
		echo '<div class="instructions" style="float: left; width: 40%; margin-left: 10px;">';
		echo __('You can use the following constants within the message body to embed database information.','automessage');
		echo '<br /><br />';
		echo '%blogname%<br />';
		echo '%blogurl%<br />';
		echo '%username%<br />';
		echo '%usernicename%<br/>';
		echo '%sitename%<br/>';
		echo "%siteurl%<br/>";

		echo '</div>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		echo '<p class="submit">';
		echo '<input class="button-primary" type="submit" name="go" value="' . __('Update action', 'automessage') . '" /></p>';
		echo '</form>';

		echo "</div>";
		echo "</div>";

		echo "</div>";
	}

	function add_action_form($type) {

		global $page;

		echo "<div class='wrap'>";

		echo "<h2>" . __('Add Action', 'automessage') . "</h2>";

		echo "<a name='form-add-action' ></a>\n";

		echo '<div id="poststuff" class="metabox-holder">';
		?>
		<div class="postbox">
			<h3 class="hndle" style='cursor:auto;'><span><?php _e('Add Action','automessage'); ?></span></h3>
			<div class="inside">
		<?php

		echo '<form method="post" action="?page=' . $page . '">';
		echo "<input type='hidden' name='action' value='addaction' />";
		echo "<input type='hidden' name='type' value='" . $type . "' />";
		wp_nonce_field('add-action');
		echo '<table class="form-table">';
		echo '<tr class="form-field form-required" valign="top">';
		echo '<th style="" scope="row" valign="top">' . __('Action','automessage') . '</th>';
		echo '<td>';

		echo '<select name="hook" style="width: 40%;">';
			switch($type) {
				case 'blog':	echo '<option value="wpmu_new_blog">';
								echo __('Create new blog','automessage');
								echo '</option>';
								break;

				case 'user':	echo '<option value="wpmu_new_user">';
								echo __('Create new user','automessage');
								echo '</option>';
								break;
			}
		echo '</select>';

		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message delay','automessage') . '</th>';
		echo '<td valign="top">';

		echo '<select name="period" style="width: 40%;">';
		for($n = 0; $n <= AUTOMESSAGE_POLL_MAX_DELAY; $n++) {
			echo "<option value='$n'>";
			switch($n) {
				case 0: 	echo __("Send immediately", 'automessage');
							break;
				case 1: 	echo __("1 day", 'automessage');
							break;
				default:	echo sprintf(__('%d days','automessage'),$n);
			}
			echo "</option>";
		}
		echo '</select>';
		echo '<input type="hidden" name="timeperiod" value="day" />';
		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message Subject','automessage') . '</th>';
		echo '<td valign="top"><input name="subject" type="text" size="50" title="' . __('Message subject') . '" style="width: 50%;" /></td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message','automessage') . '</th>';
		echo '<td valign="top"><textarea name="message" style="width: 50%; float: left;" rows="15" cols="40"></textarea>';
		// Display some instructions for the message.
		echo '<div class="instructions" style="float: left; width: 40%; margin-left: 10px;">';
		echo __('You can use the following constants within the message body to embed database information.','automessage');
		echo '<br /><br />';
		echo '%blogname%<br />';
		echo '%blogurl%<br />';
		echo '%username%<br />';
		echo '%usernicename%<br/>';
		echo '%sitename%<br/>';
		echo "%siteurl%<br/>";

		echo '</div>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		echo '<p class="submit">';
		echo '<input class="button-primary" type="submit" name="go" value="' . __('Add action', 'automessage') . '" /></p>';
		echo '</form>';

		echo "</div>";
		echo "</div>";

		echo "</div>";

	}

	function show_actions_list($results = false, $type = 'user') {

		global $page;

		echo '<table width="100%" cellpadding="3" cellspacing="3" class="widefat">';
		echo '<thead>';
		echo '<tr>';
		echo '<th scope="col" class="check-column"></th>';

		echo '<th scope="col">';
		echo __('Action','automessage');
		echo '</th>';

		echo '<th scope="col">';
		echo __('Time delay','automessage');
		echo '</th>';

		echo '<th scope="col">';
		echo __('Subject','automessage');
		echo '</th>';

		echo '<th scope="col">';
		echo __('Queued','automessage');
		echo '</th>';

		echo '</tr>';
		echo '</thead>';

		echo '<tbody id="the-list">';

		if($results) {
			$bgcolor = $class = '';
			$lasthook = '';

			foreach($results as $result) {

				if(!empty($result->ID)) {
					$metadata = get_post_custom($result->ID);
				} else {
					$metadata = array();
				}

				//print_r($metadata);

				if(array_key_exists('_automessage_hook', $metadata) && is_array($metadata['_automessage_hook'])) {
					$hook = array_shift($metadata['_automessage_hook']);
				} else {
					$hook = '';
				}

				$class = ('alternate' == $class) ? '' : 'alternate';
				if($lasthook != $hook) {
					switch($hook) {
						case 'wpmu_new_blog':	$title = __('Create new blog','automessage');
												break;

						case 'wpmu_new_user':	$title = __('Create new user','automessage');
												break;
					}

					$lasthook = $hook;
				} else {
					$title = '&nbsp;';
				}
				echo '<tr>';
				echo '<th scope="row" class="check-column" >';
				echo '<input type="checkbox" id="schedule_' . $result->ID . '" name="allschedules[]" value="' . $result->ID .'" />';
				echo '</th>';

				echo '<td scope="row">';
				if($result->post_status == 'draft') {
					echo __('[Paused] ','automessage');
				}
				echo $title;

				$actions = array();

				$actions[] = '<a href="?page=' . $page . '&amp;action=editaction&amp;id=' . $result->ID . '" title="' . __('Edit this message','automessage') . '">' . __('Edit','automessage') . '</a>';
				if($result->post_status == 'private') {
					$actions[] = '<a href="?page=' . $page . '&amp;action=pauseaction&amp;id=' . $result->ID . '" title="' . __('Pause this message','automessage') . '">' . __('Pause','automessage') . '</a>';
				} else {
					$actions[] = '<a href="?page=' . $page . '&amp;action=unpauseaction&amp;id=' . $result->ID . '" title="' . __('Unpause this message','automessage') . '">' . __('Unpause','automessage') . '</a>';
				}
				$actions[] = '<a href="?page=' . $page . '&amp;action=process' . $type . 'action&amp;id=' . $result->ID . '" title="' . __('Process this message','automessage') . '">' . __('Process','automessage') . '</a>';
				$actions[] = '<a href="?page=' . $page . '&amp;action=deleteaction&amp;id=' . $result->ID . '" title="' . __('Delete this message','automessage') . '">' . __('Delete','automessage') . '</a>';

				echo '<div class="row-actions">';
				echo implode(' | ', $actions);
				echo '</div>';

				echo '</td>';

				echo '<td scope="row" valign="top">';

				if($result->menu_order == 0) {
					echo __('Immediate','automessage');
				} elseif($result->period == 1) {
					echo sprintf(__('%d %s','automessage'), $result->menu_order, 'day');
				} else {
					echo sprintf(__('%d %ss','automessage'), $result->menu_order, 'day');
				}

				echo '</td>';

				echo '<td scope="row" valign="top">';
				echo stripslashes($result->post_title);
				echo '</td>';

				echo '<td scope="row" valign="top">';
				echo intval($this->get_queued_for_message( $result->ID) );
				echo '</td>';

				echo '</tr>' . "\n";

			}
		} else {
			echo '<tr>';
			echo '<td colspan="5">' . __('No actions set for this level.') . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

	}

	function dashboard_news() {
		global $page, $action;

		$plugin = get_plugin_data(automessage_dir('automessage.php'));

		$debug = get_automessage_option('automessage_debug', false);

		?>
		<div class="postbox ">
			<h3 class="hndle"><span><?php _e('Automessage','automessage'); ?></span></h3>
			<div class="inside">
				<?php
				echo "<p>";
				echo __('You are running Automessage version ','automessage') . "<strong>" . $plugin['Version'] . '</strong>';
				echo "</p>";

				echo "<p>";
				echo __('Debug mode is ','automessage') . "<strong>";
				if($debug) {
					echo __('Enabled','automessage');
				} else {
					echo __('Disabled','automessage');
				}
				echo '</strong>';
				echo "</p>";
				?>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	function handle_dash_panel() {
		?>
		<div class='wrap nosubsub'>
			<div class="icon32" id="icon-index"><br></div>
			<h2><?php _e('Automessage dashboard','automessage'); ?></h2>

			<div id="dashboard-widgets-wrap">

			<div class="metabox-holder" id="dashboard-widgets">
				<div style="width: 49%;" class="postbox-container">
					<div class="meta-box-sortables ui-sortable" id="normal-sortables">
						<?php
						do_action( 'automessage_dashboard_left' );
						?>
					</div>
				</div>

				<div style="width: 49%;" class="postbox-container">
					<div class="meta-box-sortables ui-sortable" id="side-sortables">
						<?php
						do_action( 'automessage_dashboard_right' );
						?>
					</div>
				</div>

				<div style="display: none; width: 49%;" class="postbox-container">
					<div class="meta-box-sortables ui-sortable" id="column3-sortables" style="">
					</div>
				</div>

				<div style="display: none; width: 49%;" class="postbox-container">
					<div class="meta-box-sortables ui-sortable" id="column4-sortables" style="">
					</div>
				</div>
			</div>

			<div class="clear"></div>
			</div>

		</div> <!-- wrap -->
		<?php
	}

	function handle_blogmessageadmin_panel() {

		global $action, $page;

		wp_reset_vars( array('action', 'page') );

		if(!empty($action) && ($action == 'editaction' || $action == 'newaction') ) {
			if(isset($_GET['id'])) {
				$id = addslashes($_GET['id']);
			} else {
				$id = false;
			}
			$this->edit_action_form($id, 'blog');
			return;
		}

		echo "<div class='wrap'  style='position:relative;'>";
		echo '<div class="icon32" id="icon-edit-pages"><br></div>';
		echo "<h2>" . __('Blog Level Messages','automessage');
		echo '<a class="add-new-h2" href="' . remove_query_arg('msg', add_query_arg(array('action' => 'newaction'))) . '">' . __('Add New','automessage') . '</a>';
		echo "</h2>";

		$this->show_admin_messages();

		echo '<br clear="all" />';

		$results = $this->get_bloglevel_schedule();

		echo '<form id="form-site-list" action="?page=' . $page . '&amp;action=allmessages" method="post">';
		echo '<input type="hidden" name="page" value="' . $page . '" />';
		echo '<input type="hidden" name="actioncheck" value="allsiteactions" />';
		echo '<div class="tablenav">';
		echo '<div class="alignleft">';

		echo '<input type="submit" value="' . __('Delete','automessage') . '" name="allaction_delete" class="button-secondary delete" style="margin-right: 10px;" />';
		echo '<input type="submit" value="' . __('Pause','automessage') . '" name="allaction_pause" class="button-secondary" style="margin-right: 10px;" />';
		echo '<input type="submit" value="' . __('Unpause','automessage') . '" name="allaction_unpause" class="button-secondary" />';
		//echo '&nbsp;&nbsp;<input type="submit" value="' . __('Process now') . '" name="allaction_process" class="button-secondary" />';
		wp_nonce_field( 'allsiteactions' );
		echo '<br class="clear" />';
		echo '</div>';
		echo '</div>';

		$this->show_actions_list($results ,'blog');

		echo "</form>";

		echo "</div>";

	}

	function handle_usermessageadmin_panel() {

		global $action, $page;

		wp_reset_vars( array('action', 'page') );

		if(!empty($action) && ($action == 'editaction' || $action == 'newaction') ) {
			if(isset($_GET['id'])) {
				$id = addslashes($_GET['id']);
			} else {
				$id = false;
			}

			$this->edit_action_form($id, 'user');
			return;
		}

		echo "<div class='wrap'  style='position:relative;'>";
		echo '<div class="icon32" id="icon-edit-pages"><br></div>';
		echo "<h2>" . __('User Level Messages','automessage');
		echo '<a class="add-new-h2" href="' . remove_query_arg('msg', add_query_arg(array('action' => 'newaction'))) . '">' . __('Add New','automessage') . '</a>';
		echo "</h2>";

		echo '<br clear="all" />';

		$this->show_admin_messages();

		$results = $this->get_userlevel_schedule();

		echo '<form id="form-site-list" action="?page=' . $page . '&amp;action=allmessages" method="post">';
		echo '<input type="hidden" name="page" value="' . $page . '" />';
		echo '<input type="hidden" name="actioncheck" value="allblogactions" />';
		echo '<div class="tablenav">';
		echo '<div class="alignleft">';

		echo '<input type="submit" value="' . __('Delete','automessage') . '" name="allaction_delete" class="button-secondary delete" style="margin-right: 10px;" />';
		echo '<input type="submit" value="' . __('Pause','automessage') . '" name="allaction_pause" class="button-secondary" style="margin-right: 10px;" />';
		echo '<input type="submit" value="' . __('Unpause','automessage') . '" name="allaction_unpause" class="button-secondary" />';
		//echo '&nbsp;&nbsp;<input type="submit" value="' . __('Process now') . '" name="allaction_process" class="button-secondary" />';
		wp_nonce_field( 'allblogactions' );
		echo '<br class="clear" />';
		echo '</div>';
		echo '</div>';

		$this->show_actions_list($results);

		echo "</form>";

		echo "</div>";

	}

	function get_automessage_users_to_process( $time = false, $type = 'user' ) {

		if(!$time) {
			return;
		}

		//update_usermeta($this->ID, '_automessage_run_action', (int) $timestamp);
		$sql = $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = %s AND meta_value <= %s", '_automessage_run_' . $type . '_action', (int) $time );

		$users = $this->db->get_col( $sql );

		return $users;

	}

	function get_forced_automessage_users_to_process( $schedule_id = false, $type = 'user' ) {

		if(!$schedule_id) {
			return;
		}

		//update_usermeta($this->ID, '_automessage_run_action', (int) $timestamp);
		$sql = $this->db->prepare( "SELECT user_id FROM {$this->db->usermeta} WHERE meta_key = %s AND meta_value = %s", '_automessage_on_' . $type . '_action', (int) $schedule_id );

		$users = $this->db->get_col( $sql );

		return $users;

	}

	function process_user_automessage() {

		// Our starting time
		$timestart = time();

		// grab the users
		$users = $this->get_automessage_users_to_process( $timestart );

		//Or processing limit
		$timelimit = 5; // max seconds for processing

		$lastprocessing = get_automessage_option('automessage_processing', strtotime('-1 week'));
		if($lastprocessing == 'yes' || $lastprocessing == 'no' || $lastprocessing == 'np') {
			$lastprocessing = strtotime('-30 minutes');
			update_automessage_option('automessage_processing', $lastprocessing);
		}

		if(!empty($users) && $lastprocessing <= strtotime('-30 minutes')) {
			update_automessage_option('automessage_processing', time());

			foreach( (array) $users as $user_id) {

				if(time() > $timestart + $timelimit) {
					if($this->debug) {
						// time out
						$this->errors[] = sprintf(__('Notice: Processing stopped due to %d second timeout.','automessage'), $timelimit);
					}
					break;
				}

				// Create the user - get the message they are on and then process it
				$theuser = new Auto_User( $user_id );
				$action = $this->get_action( (int) $theuser->current_action() );

				if(!empty($action)) {
					$theuser->send_message( $action->post_title, $action->post_content );
					if(get_metadata('post', $action->ID, '_automessage_level', true) == 'user') {
						$next = $this->get_action_after( $action->ID, 'user' );
					}

					if(!empty($next)) {
						$days = (int) $next->menu_order - (int) $action->menu_order;
						$theuser->schedule_message( $next->ID, strtotime('+' . $days . ' days') );
					} else {
						$theuser->clear_subscriptions( 'user' );
					}
				}

			}
		} else {
			if(isset($this->debug) && $this->debug) {
				// empty list or not processing
			}
		}

		if(!empty($this->errors)) {
			//$this->record_error();
		}

	}

	function process_blog_automessage() {

		// Our starting time
		$timestart = time();

		// grab the users
		$users = $this->get_automessage_users_to_process( $timestart, 'blog' );

		//Or processing limit
		$timelimit = 5; // max seconds for processing

		$lastprocessing = get_automessage_option('automessage_processing', strtotime('-1 week'));
		if($lastprocessing == 'yes' || $lastprocessing == 'no' || $lastprocessing == 'np') {
			$lastprocessing = strtotime('-30 minutes');
			update_automessage_option('automessage_processing', $lastprocessing);
		}

		if(!empty($users) && $lastprocessing <= strtotime('-30 minutes')) {
			update_automessage_option('automessage_processing', time());

			foreach( (array) $users as $user_id) {

				if(time() > $timestart + $timelimit) {
					if($this->debug) {
						// time out
						$this->errors[] = sprintf(__('Notice: Processing stopped due to %d second timeout.','automessage'), $timelimit);
					}
					break;
				}

				// Create the user - get the message they are on and then process it
				$theuser = new Auto_User( $user_id );
				$action = $this->get_action( (int) $theuser->current_action( 'blog' ) );

				if(!empty($action)) {
					$theuser->send_message( $action->post_title, $action->post_content );
					if(get_metadata('post', $action->ID, '_automessage_level', true) == 'blog') {
						$next = $this->get_action_after( $action->ID, 'blog' );
					}

					if(!empty($next)) {
						$days = (int) $next->menu_order - (int) $action->menu_order;
						$theuser->schedule_message( $next->ID, strtotime('+' . $days . ' days'), 'blog' );
					} else {
						$theuser->clear_subscriptions( 'blog' );
					}
				}

			}
		} else {
			if(isset($this->debug) && $this->debug) {
				// empty list or not processing
			}
		}

		if(!empty($this->errors)) {
			//$this->record_error();
		}

	}



	function force_process_user($schedule_id) {

		// Our starting time
		$timestart = time();

		// grab the users
		$users = $this->get_forced_automessage_users_to_process( $schedule_id, 'user' );

		//Or processing limit
		$timelimit = 3; // max seconds for processing

		if(!empty($users)) {

			update_automessage_option('automessage_processing', time());

			foreach( (array) $users as $user_id) {

				if(time() > $timestart + $timelimit) {
					if($this->debug) {
						// time out
						$this->errors[] = sprintf(__('Notice: Processing stopped due to %d second timeout.','automessage'), $timelimit);
					}
					break;
				}

				// Create the user - get the message they are on and then process it
				$theuser = new Auto_User( $user_id );
				$action = $this->get_action( (int) $theuser->current_action( 'user' ) );

				if(!empty($action)) {

					$theuser->send_message( $action->post_title, $action->post_content );
					if(get_metadata('post', $action->ID, '_automessage_level', true) == 'user') {
						$next = $this->get_action_after( $action->ID, 'user' );
					}

					if(!empty($next)) {
						$days = (int) $next->menu_order - (int) $action->menu_order;
						$theuser->schedule_message( $next->ID, strtotime('+' . $days . ' days'), 'user' );
					} else {
						$theuser->clear_subscriptions( 'user' );
					}
				}

			}
		} else {
			if(isset($this->debug) && $this->debug) {
				// empty list or not processing
			}
		}

		if(!empty($this->errors)) {
			//$this->record_error();
		}

	}

	function force_process_blog($schedule_id) {

		// Our starting time
		$timestart = time();

		// grab the users
		$users = $this->get_forced_automessage_users_to_process( $schedule_id, 'blog' );

		//Or processing limit
		$timelimit = 3; // max seconds for processing

		if(!empty($users)) {

			update_automessage_option('automessage_processing', time());

			foreach( (array) $users as $user_id) {

				if(time() > $timestart + $timelimit) {
					if($this->debug) {
						// time out
						$this->errors[] = sprintf(__('Notice: Processing stopped due to %d second timeout.','automessage'), $timelimit);
					}
					break;
				}

				// Create the user - get the message they are on and then process it
				$theuser = new Auto_User( $user_id );
				$action = $this->get_action( (int) $theuser->current_action( 'blog' ) );

				if(!empty($action)) {
					$theuser->send_message( $action->post_title, $action->post_content );
					if(get_metadata('post', $action->ID, '_automessage_level', true) == 'blog') {
						$next = $this->get_action_after( $action->ID, 'blog' );
					}

					if(!empty($next)) {
						$days = (int) $next->menu_order - (int) $action->menu_order;
						$theuser->schedule_message( $next->ID, strtotime('+' . $days . ' days'), 'blog' );
					} else {
						$theuser->clear_subscriptions( 'blog' );
					}
				}

			}
		} else {
			if(isset($this->debug) && $this->debug) {
				// empty list or not processing
			}
		}

		if(!empty($this->errors)) {
			//$this->record_error();
		}

	}

	// Unsubscribe actions
	function flush_rewrite() {
		// This function clears the rewrite rules and forces them to be regenerated

		global $wp_rewrite;

		//$wp_rewrite->flush_rules();

	}

	function add_queryvars($vars) {
		// This function add the namespace (if it hasn't already been added) and the
		// eventperiod queryvars to the list that WordPress is looking for.
		// Note: Namespace provides a means to do a quick check to see if we should be doing anything

		if(!in_array('namespace',$vars)) $vars[] = 'namespace';
		$vars[] = 'unsubscribe';

		return $vars;
	}

	function add_rewrite($wp_rewrite ) {

		$new_rules = array(
							'unsubscribe' . '/(.+)$' => 'index.php?namespace=automessage&unsubscribe=' . $wp_rewrite->preg_index(1)
							);

		$wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
	}

	function process_unsubscribe_action() {
		global $wpdb, $wp_query;

		if(isset($wp_query->query_vars['namespace']) && $wp_query->query_vars['namespace'] == 'automessage') {

			// Set up the property query variables
			if(isset($wp_query->query_vars['unsubscribe'])) $unsub = $wp_query->query_vars['unsubscribe'];

			// Handle unsubscribe functionality
			if(!empty($unsub)) {
				$sql = $this->db->prepare( "SELECT ID from {$this->db->users} WHERE MD5(CONCAT(ID,'16224')) = %s", $unsub);
				$user_id = $this->db->get_var( $sql );

				if(!empty($user_id)) {
					$theuser = new Auto_User( $user_id );
					$theuser->clear_subscriptions( 'user' );
					$theuser->clear_subscriptions( 'blog' );
					$theuser->send_unsubscribe();
				}

			}

		}
	}

	function output_unsubscribe_message() {
		global $wp_query;

		if (file_exists(TEMPLATEPATH . '/' . 'page.php')) {

			/**
			 * What we are going to do here, is create a fake post.  A post
			 * that doesn't actually exist. We're gonna fill it up with
			 * whatever values you want.  The content of the post will be
			 * the output from your plugin.  The questions and answers.
			 */
			/**
			 * Clear out any posts already stored in the $wp_query->posts array.
			 */
			$wp_query->posts = array();
			$wp_query->post_count = 0;

			/**
			 * Create a fake post.
			 */
			$post = new stdClass;
			$post->post_author = 1;
			$post->post_name = 'unsubscribe';

			add_filter('the_permalink',create_function('$permalink', 'return "' . get_option('home') . '";'));
			$post->guid = get_bloginfo('wpurl') . '/' . 'unsubscribe';
			$post->post_title = 'Unsubscription request';
			$post->post_content = '<p>Your unsubscription request has been processed successfully.</p>';
			$post->post_excerpt = 'Your unsubscription request has been processed successfully.';
			$post->ID = -1;
			$post->post_status = 'publish';
			$post->post_type = 'page';
			$post->comment_status = 'closed';
			$post->ping_status = 'closed';
			$post->comment_count = 0;
			$post->post_date = current_time('mysql');
			$post->post_date_gmt = current_time('mysql', 1);

			$wp_query->posts[] = $post;
			$wp_query->post_count = 1;
			$wp_query->is_home = false;

			ob_start();

			load_template(TEMPLATEPATH . '/' . 'page.php');

			ob_end_flush();

			/**
			 * YOU MUST DIE AT THE END.  BAD THINGS HAPPEN IF YOU DONT
			 */
			die();

		}

		return $post;
	}

	function use_template($template) {

			$trequestedtemplate = 'page.php';

			if ( file_exists(STYLESHEETPATH . '/' . $trequestedtemplate)) {
				$template = STYLESHEETPATH . '/' . $requestedtemplate;
			} else if ( file_exists(TEMPLATEPATH . '/' . $requestedtemplate) ) {
				$template = TEMPLATEPATH . '/' . $requestedtemplate;
			}


		return $template;

	}

}

?>