<?php
/*
Plugin Name: Automessage
Plugin URI:
Description: This plugin allows emails to be scheduled and sent to new users.
Author: Barry at clearskys.net (Incsub)
Version: 1.0.4
Author URI:
Plugin Update URI:
*/

/*
Copyright 2007-2009 Incsub (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// Un comment and set the value to the blog_id you want to run the wp-cron on
// If you are having issues with server load, try this first.
//define('AUTOMESSAGE_RUNCRONON', '1');

class automessageadmin {

	var $build = 5;

	// Our own link to the database class - using this means we can easily switch db libraries in just this class if required
	var $db;

	// The tables used by this plugin
	var $tables = array('am_actions', 'am_schedule', 'am_queue');

	// Table links
	var $am_actions;
	var $am_schedule;
	var $am_queue;

	// Change this to increase or decrease the number of messages to process in any run
	var $processlimit = 250;

	function __construct() {

		global $wpdb, $blog_id;

		// Link to the database class
		$this->db =& $wpdb;

		// Set up the table variables
		foreach($this->tables as $table) {
			$this->$table = $this->db->base_prefix . $table;
		}

		// Installation functions
		register_activation_hook(__FILE__, array(&$this, 'install'));
		register_deactivation_hook(__FILE__, array(&$this, 'uninstall'));

		add_action('admin_menu', array(&$this,'setup_menu'), 100);

		add_action('init', array(&$this,'setup_listeners'));

		// Cron filters - only add a global cron functionality IF we aren't specifying the main cron site
		if(!defined('AUTOMESSAGE_RUNCRONON')) {
			add_filter('option_cron', array(&$this, 'append_global_cronjobs'));
			add_filter('pre_update_option_cron', array(&$this,'remove_global_cronjobs'), 10, 2);
		}

		// Cron actions
		add_filter( 'cron_schedules', array(&$this, 'add_schedules') );
		// Cron actions
		add_action('process_automessage_hook', 'process_automessage');

		// UnSubscribe functions
		// Rewrites
		add_action('generate_rewrite_rules', array(&$this, 'add_rewrite'));
		add_filter('query_vars', array(&$this, 'add_queryvars'));

		// Set up api object to enable processing by other plugins
		add_action('pre_get_posts', array(&$this, 'process_unsubscribe_action') );

	}

	function __destruct() {
		return true;
	}

	function automessageadmin() {
		$this->__construct();
	}

	function install() {

		$table_name = $this->am_actions;
		if($this->db->get_var("show tables like '$table_name'") != $table_name)
   		{
			// Create or update the tables
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

			$sql = "CREATE TABLE $this->am_actions (
			  id bigint(20) NOT NULL auto_increment,
			  level varchar(10) default NULL,
			  action varchar(150) default NULL,
			  title varchar(250) default NULL,
			  description text,
			  PRIMARY KEY  (id),
			  KEY level (level),
			  KEY action (action)
			)";
			dbDelta($sql);

			$sql = "CREATE TABLE $this->am_schedule (
			  id bigint(20) NOT NULL auto_increment,
			  system_id bigint(20) NOT NULL default '0',
			  site_id bigint(20) NOT NULL default '0',
			  blog_id bigint(20) NOT NULL default '0',
			  action_id bigint(20) default NULL,
			  subject varchar(250) default NULL,
			  message text,
			  period int(11) default '0',
			  timeperiod varchar(50) default 'day',
			  pause tinyint(4) default '0',
			  PRIMARY KEY  (id),
			  KEY system_id (system_id),
			  KEY site_id (site_id),
			  KEY blog_id (blog_id),
			  KEY action_id (action_id)
			)";
			dbDelta($sql);

			$sql = "CREATE TABLE $this->am_queue (
			  id bigint(20) NOT NULL auto_increment,
			  schedule_id bigint(20) default NULL,
			  runon bigint(20) default NULL,
			  sendtoemail varchar(150) default NULL,
			  user_id bigint(20) NOT NULL default '0',
			  blog_id bigint(20) default '0',
			  site_id bigint(20) default '0',
			  PRIMARY KEY  (id),
			  KEY action_id (schedule_id),
			  KEY runon (runon),
			  KEY user_id (user_id),
			  KEY blog_id (blog_id),
			  KEY site_id (site_id)
			)";
			dbDelta($sql);

			$this->db->insert($this->am_actions, array("level" => "site", "action" => "wpmu_new_blog", "title" => "Create new blog"));
			$this->db->insert($this->am_actions, array("level" => "blog", "action" => "wpmu_new_user", "title" => "Create new user"));

			update_site_option('automessage_installed', 'yes');
   		}

		$this->flush_rewrite();


	}

	function uninstall() {

	}

	function setup_listeners() {

		global $blog_id;

		// Check we are installed
		if(get_site_option('automessage_installed', 'no') != 'yes') {
			$this->install();
		}

		// This function will add all of the actions that are setup
		// At the moment we'll hard code them - in future versions we'll use the database table we have set up
		add_action('wpmu_new_blog',array(&$this,'add_blog_message'), 10, 2);
		add_action('wpmu_new_user',array(&$this,'add_user_message'), 10, 1);

		do_action('automessage_addlisteners');

		// Cron action
		if(!defined('AUTOMESSAGE_RUNCRONON') || (defined('AUTOMESSAGE_RUNCRONON') && $blog_id == AUTOMESSAGE_RUNCRONON)) {
			// Only shedule the events IF we want a global cron, or we are on the specified blog
			if ( !wp_next_scheduled('process_automessage_hook')) {
				wp_schedule_event(time(), 'fourdaily', 'process_automessage_hook');
			}
		}


		$this->flush_rewrite();

	}

	function send_message($message, $user, $blog_id = 0, $site_id = 0) {

		if(!empty($user->user_email) && validate_email($user->user_email, false)) {

			$replacements = array(	"/%blogname%/" 	=> 	get_blog_option($blog_id, 'blogname'),
									"/%blogurl%/"	=>	get_blog_option($blog_id, 'home'),
									"/%username%/"	=>	$user->user_login,
									"/%usernicename%/"	=>	$user->user_nicename
								);

			if(function_exists('get_site_details')) {
				$site = get_site_details($site_id);
				$replacements['/%sitename%/'] = $site->sitename;
				$replacements['/%siteurl%/'] = 'http://' . $site->domain . $site->path;
			} else {
				$site = $this->db->get_row( $this->db->prepare("SELECT * FROM {$this->db->site} WHERE id = %d", $site_id));
				$replacements['/%sitename%/'] = $this->db->get_var( $this->db->prepare("SELECT meta_value FROM {$this->db->sitemeta} WHERE meta_key = 'site_name' AND site_id = %d", $site_id) );
				$replacements['/%siteurl%/'] = 'http://' . $site->domain . $site->path;
			}

			$replacements = apply_filters('automessage_replacements', $replacements);

			if(!empty($message->message)) {
				$subject = stripslashes($message->subject);
				$msg = stripslashes($message->message);

				// Add in the unsubscribe text at the bottom of the message
				$msg .= "\n\n"; // Two blank lines
				$msg .= "-----\n"; // Footer marker
				$msg .= __('To stop receiving messages from %sitename% click on the following link: %siteurl%unsubscribe/','automessage');
				// Add in the user id
				$msg .= md5($message->user_id . '16224');

				$find = array_keys($replacements);
				$replace = array_values($replacements);

				$msg = preg_replace($find, $replace, $msg);
				$subject = preg_replace($find, $replace, $subject);

				// Set up the from address
				$header = 'From: "' . $replacements['/%sitename%/'] . '" <noreply@' . $site->domain . '>';
				$res = @wp_mail( $user->user_email, $subject, $msg, $header );

			}

		}

	}

	function schedule_message($action, $user_id, $blog_id = 0, $site_id = 0) {

		// Get the lowest day scheduled action for add site
		$sql = "select s.* from {$this->am_schedule} as s, {$this->am_actions} as a WHERE
		s.action_id = a.id AND a.action = %s AND s.pause = 0 ORDER BY period, timeperiod ASC
		LIMIT 0,2";

		$sched = $this->db->get_results( $this->db->prepare($sql, $action), OBJECT );

		if($sched) {
			$user = get_userdata($user_id);

			foreach($sched as $s) {
				if($s->period == 0) {
					// If the timeperiod is 0 - then we need to send this immediately and
					// get the next one for the schedule
					$this->send_message($s, $user, $blog_id, $site_id);
				} else {
					// Otherwise we add the person to the schedule for later processing
					$runon = strtotime("+ $s->period $s->timeperiod");
					$this->db->insert($this->am_queue, array("schedule_id" => $s->id, "runon" => $runon, "user_id" => $user->ID, "site_id" => $site_id, "blog_id" => $blog_id, "sendtoemail" => $user->user_email));
					break;
				}
			}
		}

	}

	function add_blog_message($blog_id, $user_id) {
		// This function will add a scheduled item to the blog actions
		global $current_site;

		if(is_numeric($user_id)) {
			$action = 'wpmu_new_blog';

			$this->schedule_message($action, $user_id, $blog_id, $current_site->id);
		}
	}

	function add_user_message($user_id) {
		// This function will add a scheduled item to the user actions
		global $current_blog;

		if(is_numeric($user_id)) {
			$action = 'wpmu_new_user';
			//print_r($current_blog);
			//die('hello new user - ' . $user_id);
			$this->schedule_message($action, $user_id, $current_blog->blog_id, $current_blog->site_id);
		}
	}

	function handle_message_panel() {

		$action = addslashes($_GET['action']);


		switch($action) {

			case 'addaction':
						check_admin_referer('add-action');
						if($this->add_action()) {
							echo '<div id="message" class="updated fade"><p>' . __('Your action has been added to the schedule.', 'automessage') . '</p></div>';
						} else {
							echo '<div id="message" class="updated fade"><p>' . __('Your action could not be added.', 'automessage') . '</p></div>';
						}
						$this->handle_messageadmin_panel();
						break;
			case 'pauseaction':
						$id = addslashes($_GET['id']);
						$this->set_pause($id, true);
						echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been paused', 'automessage') . '</p></div>';
						$this->handle_messageadmin_panel();
						break;
			case 'unpauseaction':
						$id = addslashes($_GET['id']);
						$this->set_pause($id, false);
						echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been unpaused', 'automessage') . '</p></div>';
						$this->handle_messageadmin_panel();
						break;
			case 'allmessages':
						check_admin_referer($_POST['actioncheck']);
						if(isset($_POST['allaction_delete'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->delete_action($as);
								}
							} else {
								echo '<div id="message" class="updated fade"><p>' . __('Please select an action to delete', 'automessage') . '</p></div>';
							}
						}
						if(isset($_POST['allaction_pause'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->set_pause($as, true);
								}
								echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been paused', 'automessage') . '</p></div>';
							} else {
								echo '<div id="message" class="updated fade"><p>' . __('Please select an action to pause', 'automessage') . '</p></div>';
							}
						}
						if(isset($_POST['allaction_unpause'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->set_pause($as, false);
								}
								echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been unpaused', 'automessage') . '</p></div>';
							} else {
								echo '<div id="message" class="updated fade"><p>' . __('Please select an action to unpause', 'automessage') . '</p></div>';
							}
						}
						if(isset($_POST['allaction_process'])) {
							if(isset($_POST['allschedules'])) {
								$allsscheds = $_POST['allschedules'];
								foreach ($allsscheds as $as) {
									$this->force_process($as);
								}
								echo '<div id="message" class="updated fade"><p>' . __('The scheduled actions have been processed', 'automessage') . '</p></div>';
							} else {
								echo '<div id="message" class="updated fade"><p>' . __('Please select an action to process', 'automessage') . '</p></div>';
							}
						}
						$this->handle_messageadmin_panel();
						break;
			case 'deleteaction':
						$id = addslashes($_GET['id']);
						$this->delete_action($id);
						echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been deleted', 'automessage') . '</p></div>';
						$this->handle_messageadmin_panel();
						break;
			case 'editaction':
						$id = addslashes($_GET['id']);
						$this->edit_action_form($id);
						break;
			case 'updateaction':
						check_admin_referer('update-action');
						$this->update_action();
						echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been updated', 'automessage') . '</p></div>';
						$this->handle_messageadmin_panel();
						break;
			case 'processaction':
						$id = addslashes($_GET['id']);
						$this->force_process($id);
						echo '<div id="message" class="updated fade"><p>' . __('The scheduled action has been processed', 'automessage') . '</p></div>';
						$this->handle_messageadmin_panel();
						break;

			default: 	$this->handle_messageadmin_panel();
						break;
		}

	}

	function setup_menu() {

		add_submenu_page('wpmu-admin.php', __('Messages','automessage'), __('Messages','automessage'), 10, "siteadmin_messages", array(&$this,'handle_message_panel'));

	}

	function get_sitelevel_schedule() {

		global $current_site;

		$sql = $this->db->prepare("SELECT s.*, a.title FROM {$this->am_schedule} AS s, {$this->am_actions} AS a WHERE s.action_id = a.id AND a.level = %s AND s.site_id = %d ORDER BY action_id, timeperiod, period", 'site', $current_site->id);

		$results = $this->db->get_results($sql, OBJECT);

		if($results) {

			foreach($results as $key => $value) {
				$results[$key]->queued = $this->db->get_var( $this->db->prepare("SELECT count(*) FROM {$this->am_queue} as q WHERE q.schedule_id = %d", $value->id) );
			}

			return $results;
		} else {
			return false;
		}

	}

	function get_bloglevel_schedule() {

		global $current_site;

		$sql = $this->db->prepare("SELECT s.*, a.title FROM {$this->am_schedule} AS s, {$this->am_actions} AS a WHERE s.action_id = a.id AND a.level = %s AND s.blog_id = %d ORDER BY action_id, timeperiod, period", 'blog', $current_site->blog_id);

		$results = $this->db->get_results($sql, OBJECT);

		if($results) {

			foreach($results as $key => $value) {
				$results[$key]->queued = $this->db->get_var( $this->db->prepare("SELECT count(*) FROM {$this->am_queue} as q WHERE q.schedule_id = %d", $value->id) );
			}

			return $results;
		} else {
			return false;
		}

	}

	function get_available_actions($levels = array('site', 'blog')) {

		if(!is_array($levels)) {
			return false;
		}

		$sql = $this->db->prepare("SELECT * FROM {$this->am_actions} WHERE level IN ('" . implode("','", $levels) . "')");

		$actions = $this->db->get_results($sql, OBJECT);

		if($actions) {
			return $actions;
		} else {
			return false;
		}

	}

	function get_action($id) {

		$sql = $this->db->prepare("SELECT * FROM {$this->am_schedule} WHERE id = %d", $id);

		$results = $this->db->get_row($sql);

		if($results) {
			return $results;
		} else {
			return false;
		}

	}

	function add_action() {

		global $current_site;

		$system_id = apply_filters('get_system_id', 1);
		$site_id = $current_site->id;
		$blog_id = $current_site->blog_id;


		$action = $_POST['action'];
		$subject = $_POST['subject'];
		$message = $_POST['message'];

		$period = $_POST['period'];
		$timeperiod = $_POST['timeperiod'];

		$this->db->insert($this->am_schedule, array("system_id" => $system_id, "site_id" => $site_id, "blog_id" => $blog_id, "action_id" => $action, "subject" => $subject, "message" => $message, "period" => $period, "timeperiod" => $timeperiod));

		return $this->db->insert_id;
	}

	function delete_action($scheduleid) {

		if($scheduleid) {
			$this->db->query( $this->db->prepare("DELETE FROM {$this->am_schedule} WHERE id = %d", $scheduleid));
		}

	}

	function update_action() {

		$id = $_POST['id'];

		$system_id = $_POST['system_id'];
		$site_id = $_POST['site_id'];
		$blog_id = $_POST['blog_id'];


		$action = $_POST['action'];
		$subject = $_POST['subject'];
		$message = $_POST['message'];

		$period = $_POST['period'];
		$timeperiod = $_POST['timeperiod'];

		$this->db->update($this->am_schedule, array("system_id" => $system_id, "site_id" => $site_id, "blog_id" => $blog_id, "action_id" => $action, "subject" => $subject, "message" => $message, "period" => $period, "timeperiod" => $timeperiod), array("id" => $id));

	}

	function set_pause($scheduleid, $pause = true) {

		if($pause) {
			$this->db->update($this->am_schedule, array("pause" => 1), array("id" => $scheduleid));
		} else {
			$this->db->update($this->am_schedule, array("pause" => 0), array("id" => $scheduleid));
		}


	}

	function edit_action_form($id) {

		$page = addslashes($_GET['page']);

		$editing = $this->get_action($id);

		if(!$editing) {
			echo __('Could not find the action, please check the available message list.','automessage');
		}

		echo "<div class='wrap'>";
		echo "<h2>" . __('Edit Action', 'automessage') . "</h2>";

		echo '<form method="post" action="?page=' . $page . '&amp;action=updateaction">';
		echo '<input type="hidden" name="id" value="' . $editing->id . '" />';

		echo '<input type="hidden" name="system_id" value="' . $editing->system_id . '" />';
		echo '<input type="hidden" name="site_id" value="' . $editing->site_id . '" />';
		echo '<input type="hidden" name="blog_id" value="' . $editing->blog_id . '" />';

		wp_nonce_field('update-action');
		echo '<table class="form-table">';
		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Action','automessage') . '</th>';
		echo '<td valign="top">';

		$filter = array();
		if(function_exists('is_site_admin') && is_site_admin()) {
			$filter[] = 'site';
		}
		$filter[] = 'blog';

		$actions = $this->get_available_actions($filter);

		if($actions) {

			echo '<select name="action" style="width: 40%;">';

			$lastlevel = "";

			foreach($actions as $action) {
				if($lastlevel != $action->level) {
					if($lastlevel != "") {
						echo '</optgroup>';
					}
					$lastlevel = $action->level;
					echo '<optgroup label="';
					switch($lastlevel) {
						case "site": 	echo "Site level actions";
										break;
						case "blog": 	echo "Blog level actions";
										break;
					}
					echo '">';
				}
				echo '<option value="' . $action->id . '"';
				if($editing->action_id == $action->id) echo ' selected="selected" ';
				echo '>';
				echo wp_specialchars($action->title);
				echo '</option>';
			}

			echo '</select>';

		}

		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message delay','automessage') . '</th>';
		echo '<td valign="top">';

		echo '<select name="period" style="width: 40%;">';
		for($n = 0; $n <= 31; $n++) {
			echo "<option value='$n'";
			if($editing->period == $n)  echo ' selected="selected" ';
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
		echo '<input type="hidden" name="timeperiod" value="' . $editing->timeperiod . '" />';
		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message Subject','automessage') . '</th>';
		echo '<td valign="top"><input name="subject" type="text" size="50" title="' . __('Message subject') . '" style="width: 50%;" value="' . htmlentities(stripslashes($editing->subject),ENT_QUOTES, 'UTF-8') . '" /></td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message','automessage') . '</th>';
		echo '<td valign="top"><textarea name="message" style="width: 50%; float: left;" rows="15" cols="40">' . htmlentities(stripslashes($editing->message),ENT_QUOTES, 'UTF-8') . '</textarea>';
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
		echo '<input class="button" type="submit" name="go" value="' . __('Update action', 'automessage') . '" /></p>';
		echo '</form>';

		echo "</div>";
	}

	function add_action_form() {

		$page = addslashes($_GET['page']);

		echo "<div class='wrap'>";

		echo "<h2>" . __('Add Action', 'automessage') . "</h2>";

		echo "<a name='form-add-action' ></a>\n";

		echo '<form method="post" action="?page=' . $page . '&amp;action=addaction">';
		wp_nonce_field('add-action');
		echo '<table class="form-table">';
		echo '<tr class="form-field form-required" valign="top">';
		echo '<th style="" scope="row" valign="top">' . __('Action','automessage') . '</th>';
		echo '<td>';

		$filter = array();
		if(function_exists('is_site_admin') && is_site_admin()) {
			$filter[] = 'site';
		}
		$filter[] = 'blog';

		$actions = $this->get_available_actions($filter);

		if($actions) {

			echo '<select name="action" style="width: 40%;">';

			$lastlevel = "";

			foreach($actions as $action) {
				if($lastlevel != $action->level) {
					if($lastlevel != "") {
						echo '</optgroup>';
					}
					$lastlevel = $action->level;
					echo '<optgroup label="';
					switch($lastlevel) {
						case "site": 	echo "Site level actions";
										break;
						case "blog": 	echo "Blog level actions";
										break;
					}
					echo '">';
				}
				echo '<option value="' . $action->id . '">';
				echo wp_specialchars($action->title);
				echo '</option>';
			}
			echo '</select>';
		}

		echo '</td>';
		echo '</tr>';

		echo '<tr class="form-field form-required">';
		echo '<th style="" scope="row" valign="top">' . __('Message delay','automessage') . '</th>';
		echo '<td valign="top">';

		echo '<select name="period" style="width: 40%;">';
		for($n = 0; $n <= 31; $n++) {
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
		echo '<input class="button" type="submit" name="go" value="' . __('Add action', 'automessage') . '" /></p>';
		echo '</form>';



		echo "</div>";

	}

	function show_actions_list($results = false) {

		$page = addslashes($_GET['page']);

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
			$action = '';

			foreach($results as $result) {
				$class = ('alternate' == $class) ? '' : 'alternate';
				if($action != $result->action_id) {
					$title = stripslashes($result->title);
					$action = $result->action_id;
				} else {
					$title = '&nbsp;';
				}
				echo '<tr>';
				echo '<th scope="row" class="check-column">';
				echo '<input type="checkbox" id="schedule_' . $result->id . '" name="allschedules[]" value="' . $result->id .'" />';
				echo '</th>';

				echo '<th scope="row">';
				if($result->pause != 0) {
					echo __('[Paused] ','automessage');
				}
				echo $title;

				$actions = array();

				$actions[] = '<a href="?page=' . $page . '&amp;action=editaction&amp;id=' . $result->id . '" title="' . __('Edit this message','automessage') . '">' . __('Edit','automessage') . '</a>';
				if($result->pause == 0) {
					$actions[] = '<a href="?page=' . $page . '&amp;action=pauseaction&amp;id=' . $result->id . '" title="' . __('Pause this message','automessage') . '">' . __('Pause','automessage') . '</a>';
				} else {
					$actions[] = '<a href="?page=' . $page . '&amp;action=unpauseaction&amp;id=' . $result->id . '" title="' . __('Unpause this message','automessage') . '">' . __('Unpause','automessage') . '</a>';
				}
				$actions[] = '<a href="?page=' . $page . '&amp;action=processaction&amp;id=' . $result->id . '" title="' . __('Process this message','automessage') . '">' . __('Process','automessage') . '</a>';
				$actions[] = '<a href="?page=' . $page . '&amp;action=deleteaction&amp;id=' . $result->id . '" title="' . __('Delete this message','automessage') . '">' . __('Delete','automessage') . '</a>';

				echo '<div class="row-actions">';
				echo implode(' | ', $actions);
				echo '</div>';

				echo '</th>';

				echo '<th scope="row">';

				if($result->period == 0) {
					echo __('Immediate','automessage');
				} elseif($result->period == 1) {
					echo sprintf(__('%d %s','automessage'), $result->period, $result->timeperiod);
				} else {
					echo sprintf(__('%d %ss','automessage'), $result->period, $result->timeperiod);
				}

				echo '</th>';

				echo '<th scope="row">';
				echo stripslashes($result->subject);
				echo '</th>';

				echo '<th scope="row">';
				echo intval($result->queued);
				echo '</th>';

				echo '</tr>' . "\n";

			}
		} else {
			echo '<tr style="background-color: ' . $bgcolor . '">';
			echo '<td colspan="5">' . __('No actions set for this level.') . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

	}

	function handle_messageadmin_panel() {

		$page = addslashes($_GET['page']);

		echo "<div class='wrap'  style='position:relative;'>";
		echo "<h2>" . __('Message responses','automessage') . "</h2>";

		echo '<ul class="subsubsub">';
		echo '<li><a href="#form-add-action" class="rbutton"><strong>' . __('Add a new action', 'automessage') . '</strong></a></li>';
		echo '</ul>';
		echo '<br clear="all" />';

		// Site level messages - if we are at a site level
		if(function_exists('is_site_admin') && is_site_admin()) {

			echo "<h3>" . __('Site level actions','automessage') . "</h3>";

			$results = $this->get_sitelevel_schedule();


			echo '<form id="form-site-list" action="?page=' . $page . '&amp;action=allmessages" method="post">';
			echo '<input type="hidden" name="page" value="' . $page . '" />';
			echo '<input type="hidden" name="actioncheck" value="allsiteactions" />';
			echo '<div class="tablenav">';
			echo '<div class="alignleft">';

			echo '<input type="submit" value="' . __('Delete') . '" name="allaction_delete" class="button-secondary delete" />';
			echo '<input type="submit" value="' . __('Pause') . '" name="allaction_pause" class="button-secondary" />';
			echo '<input type="submit" value="' . __('Unpause') . '" name="allaction_unpause" class="button-secondary" />';
			echo '&nbsp;&nbsp;<input type="submit" value="' . __('Process now') . '" name="allaction_process" class="button-secondary" />';
			wp_nonce_field( 'allsiteactions' );
			echo '<br class="clear" />';
			echo '</div>';
			echo '</div>';


			$this->show_actions_list($results);

			echo "</form>";
		}

		// Blog level messages
		echo "<h3>" . __('Blog level actions','automessage') . "</h3>";

		$results = $this->get_bloglevel_schedule();

		echo '<form id="form-site-list" action="?page=' . $page . '&amp;action=allmessages" method="post">';
		echo '<input type="hidden" name="page" value="' . $page . '" />';
		echo '<input type="hidden" name="actioncheck" value="allblogactions" />';
		echo '<div class="tablenav">';
		echo '<div class="alignleft">';

		echo '<input type="submit" value="' . __('Delete') . '" name="allaction_delete" class="button-secondary delete" />';
		echo '<input type="submit" value="' . __('Pause') . '" name="allaction_pause" class="button-secondary" />';
		echo '<input type="submit" value="' . __('Unpause') . '" name="allaction_unpause" class="button-secondary" />';
		echo '&nbsp;&nbsp;<input type="submit" value="' . __('Process now') . '" name="allaction_process" class="button-secondary" />';
		wp_nonce_field( 'allblogactions' );
		echo '<br class="clear" />';
		echo '</div>';
		echo '</div>';

		if(apply_filters('automessage_add_action', true))
			$this->show_actions_list($results);

		echo "</form>";

		echo "</div>";

		$this->add_action_form();

	}

	// Cron functions
	function append_global_cronjobs($cron) {
		// This function gets the global cron jobs and apends them
		// to the blogs cron jobs


		$globalcron = get_site_option('automessage_cron', false);

		if(is_array($globalcron)) {

			foreach($globalcron as $key => $value) {
				if(array_key_exists($key, $cron)) {
					// There is something already scheduled for this timestamp to add ours
					$cron[$key] += $value;
				} else {
					$cron[$key] = $value;
				}
			}
		}

		return $cron;
	}

	function remove_global_cronjobs($newcron, $oldcron) {
		// This function gets the cronjobs to be updated and removes the global ones
		// before storing them and return the remainder for blog level storage
		if(is_array($newcron)) {

			foreach($newcron as $key => $cron) {
				if(is_array($cron)) {
					foreach($cron as $k => $value) {
						if($k == 'process_automessage_hook') {
							// This is our action so we want to grab it
							$global[$key][$k] = $value;
							// and then remove it
							unset($newcron[$key][$k]);
							if(empty($newcron[$key])) {
								// That was the only entry
								unset($newcron[$key]);
							}

							update_site_option( 'automessage_cron', $global);

						} // If
					} // Foreach
				} // If
			} // Foreach

		} // If

		// return only newcron
		return $newcron;
	}

	function queue_next_message($q) {

		$sql = "select s.* from {$this->am_schedule} as s, {$this->am_actions} as a WHERE
		s.action_id = a.id AND s.action_id = %d AND s.period > %d AND s.pause = 0 ORDER BY period, timeperiod ASC
		LIMIT 0,1";

		$sched = $this->db->get_row( $this->db->prepare($sql, $q->action_id, $q->period), OBJECT );

		if($sched) {
			$gapperiod = intval($sched->period - $q->period);

			$runon = strtotime("+ $gapperiod $sched->timeperiod");
			$this->db->insert($this->am_queue, array("schedule_id" => $sched->id, "runon" => $runon, "user_id" => $q->user_id, "site_id" => $q->site_id, "blog_id" => $q->blog_id, "sendtoemail" => $q->sendtoemail));
		}

	}

	function add_schedules($scheds) {

		if(!is_array($scheds)) {
			$scheds = array();
		}

		$scheds['fourdaily'] = array( 'interval' => 21600, 'display' => __('Four times daily') );

		return $scheds;
	}

	function process_schedule() {
		global $wpdb;

		$tstamp = time();

		$lastrun = get_site_option('automessage_lastrunon', 1);

		// Get the queued items that should have been processed by now
		$sql = $this->db->prepare( "SELECT q.*, s.subject, s.message, s.period, s.timeperiod, s.action_id  FROM {$this->am_queue} AS q, {$this->am_schedule} AS s, {$this->am_actions} AS a
		WHERE q.schedule_id = s.id AND a.id = s.action_id
		AND s.pause = 0 AND runon <= $tstamp AND runon >= $lastrun
		ORDER BY runon LIMIT 0, " . $this->processlimit );

		$queue = $this->db->get_results($sql, OBJECT);

		if($queue) {
			// We have items to process

			// Set last processed
			foreach($queue as $key => $q) {
				// Store the timestamp
				$lastrun = $q->runon;

				// Send the email
				$user = get_userdata($q->user_id);
				$this->send_message($q, $user, $q->blog_id, $q->site_id);

				// Find if there is another message to schedule and add it to the queue
				$this->queue_next_message($q);

				// delete the now processed item
				$this->db->query($this->db->prepare("DELETE FROM {$this->am_queue} WHERE id = %d", $q->id));
			}
			update_site_option('automessage_lastrunon', $lastrun);
		}
	}

	function force_process($schedule_id) {

		$lastrun = get_site_option('automessage_lastrunon', 1);

		$sql = $this->db->prepare( "SELECT q.*, s.subject, s.message, s.period, s.timeperiod, s.action_id  FROM {$this->am_queue} AS q, {$this->am_schedule} AS s, {$this->am_actions} AS a
		WHERE q.schedule_id = s.id AND a.id = s.action_id
		AND s.pause = 0 AND q.schedule_id <= %d AND runon >= $lastrun
		ORDER BY runon LIMIT 0, " . $this->processlimit, $schedule_id );

		$queue = $this->db->get_results($sql, OBJECT);

		if($queue) {
			// We have items to process
			foreach($queue as $key => $q) {
				// Store the timestamp
				$lastrun = $q->runon;

				// Send the email
				$user = get_userdata($q->user_id);
				$this->send_message($q, $user, $q->blog_id, $q->site_id);

				// Find if there is another message to schedule and add it to the queue
				$this->queue_next_message($q);

				// delete the now processed item
				$this->db->query($this->db->prepare("DELETE FROM {$this->am_queue} WHERE id = %d", $q->id));
			}
			update_site_option('automessage_lastrunon', $lastrun);
		}

	}

	// Unsubscribe actions
	function flush_rewrite() {
		// This function clears the rewrite rules and forces them to be regenerated

		global $wp_rewrite;

		$wp_rewrite->flush_rules();

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
			if(isset($unsub)) {
				$sql = $this->db->prepare( "DELETE FROM {$this->am_queue} WHERE MD5(CONCAT(user_id,'16224')) = %s", $unsub);

				$this->db->query($sql);

				$this->output_unsubscribe_message();
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

			/**
			 * The author ID for the post.  Usually 1 is the sys admin.  Your
			 * plugin can find out the real author ID without any trouble.
			 */
			$post->post_author = 1;

			/**
			 * The safe name for the post.  This is the post slug.
			 */
			$post->post_name = 'unsubscribe';

			/**
			 * Not sure if this is even important.  But gonna fill it up anyway.
			 */

			add_filter('the_permalink',create_function('$permalink', 'return "' . get_option('home') . '";'));


			$post->guid = get_bloginfo('wpurl') . '/' . 'unsubscribe';


			/**
			 * The title of the page.
			 */
			$post->post_title = 'Unsubscription request';

			/**
			 * This is the content of the post.  This is where the output of
			 * your plugin should go.  Just store the output from all your
			 * plugin function calls, and put the output into this var.
			 */
			$post->post_content = '<p>Your unsubscription request has been processed successfully.</p>';
			$post->post_excerpt = 'Your unsubscription request has been processed successfully.';
			/**
			 * Fake post ID to prevent WP from trying to show comments for
			 * a post that doesn't really exist.
			 */
			$post->ID = -1;

			/**
			 * Static means a page, not a post.
			 */
			$post->post_status = 'publish';
			$post->post_type = 'post';

			/**
			 * Turning off comments for the post.
			 */
			$post->comment_status = 'closed';

			/**
			 * Let people ping the post?  Probably doesn't matter since
			 * comments are turned off, so not sure if WP would even
			 * show the pings.
			 */
			$post->ping_status = 'open';

			$post->comment_count = 0;

			/**
			 * You can pretty much fill these up with anything you want.  The
			 * current date is fine.  It's a fake post right?  Maybe the date
			 * the plugin was activated?
			 */
			$post->post_date = current_time('mysql');
			$post->post_date_gmt = current_time('mysql', 1);

			/**
			 * Now add our fake post to the $wp_query->posts var.  When "The Loop"
			 * begins, WordPress will find one post: The one fake post we just
			 * created.
			 */
			$wp_query->posts[] = $post;
			$wp_query->post_count = 1;
			$wp_query->is_home = false;

			/**
			 * And load up the template file.
			 */
			ob_start('template');

			load_template(TEMPLATEPATH . '/' . 'page.php');

			ob_end_flush();

			/**
			 * YOU MUST DIE AT THE END.  BAD THINGS HAPPEN IF YOU DONT
			 */
			die();
		}

		return $post;
	}

}


$automsg =& new automessageadmin();

function process_automessage() {
	global $automsg, $wpdb;

	$automsg->process_schedule();
}

?>