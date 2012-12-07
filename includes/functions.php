<?php
/* -------------------- Update Notifications Notice -------------------- */
if ( !function_exists( 'wdp_un_check' ) ) {
  add_action( 'admin_notices', 'wdp_un_check', 5 );
  add_action( 'network_admin_notices', 'wdp_un_check', 5 );
  function wdp_un_check() {
    if ( !class_exists( 'WPMUDEV_Update_Notifications' ) && current_user_can( 'edit_users' ) )
      echo '<div class="error fade"><p>' . __('Please install the latest version of <a href="http://premium.wpmudev.org/project/update-notifications/" title="Download Now &raquo;">our free Update Notifications plugin</a> which helps you stay up-to-date with the most stable, secure versions of WPMU DEV themes and plugins. <a href="http://premium.wpmudev.org/wpmu-dev/update-notifications-plugin-information/">More information &raquo;</a>', 'wpmudev') . '</a></p></div>';
  }
}
/* --------------------------------------------------------------------- */

function set_automessage_url($base) {

	global $automessage_url;

	if(defined('WPMU_PLUGIN_URL') && defined('WPMU_PLUGIN_DIR') && file_exists(WPMU_PLUGIN_DIR . '/' . basename($base))) {
		$automessage_url = trailingslashit(WPMU_PLUGIN_URL);
	} elseif(defined('WP_PLUGIN_URL') && defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/automessage/' . basename($base))) {
		$automessage_url = trailingslashit(WP_PLUGIN_URL . '/automessage');
	} else {
		$automessage_url = trailingslashit(WP_PLUGIN_URL . '/automessage');
	}

}

function set_automessage_dir($base) {

	global $automessage_dir;

	if(defined('WPMU_PLUGIN_DIR') && file_exists(WPMU_PLUGIN_DIR . '/' . basename($base))) {
		$automessage_dir = trailingslashit(WPMU_PLUGIN_DIR);
	} elseif(defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/automessage/' . basename($base))) {
		$automessage_dir = trailingslashit(WP_PLUGIN_DIR . '/automessage');
	} else {
		$automessage_dir = trailingslashit(WP_PLUGIN_DIR . '/automessage');
	}


}

function automessage_url($extended) {

	global $automessage_url;

	return $automessage_url . $extended;

}

function automessage_dir($extended) {

	global $automessage_dir;

	return $automessage_dir . $extended;


}

function automessage_db_prefix(&$wpdb, $table) {

	if( defined('AUTOMESSSAGE_GLOBAL_TABLES') && AUTOMESSSAGE_GLOBAL_TABLES == true ) {
		if(!empty($wpdb->base_prefix)) {
			return $wpdb->base_prefix . $table;
		} else {
			return $wpdb->prefix . $table;
		}
	} else {
		return $wpdb->prefix . $table;
	}

}

function get_automessage_option($key, $default = false) {

	if(defined( 'AUTOMESSSAGE_GLOBAL_TABLES' ) && AUTOMESSSAGE_GLOBAL_TABLES == true) {
		return get_site_option($key, $default);
	} else {
		return get_option($key, $default);
	}

}

function update_automessage_option($key, $value) {

	if(defined( 'AUTOMESSSAGE_GLOBAL_TABLES' ) && AUTOMESSSAGE_GLOBAL_TABLES == true) {
		return update_site_option($key, $value);
	} else {
		return update_option($key, $value);
	}

}

function delete_automessage_option($key) {

	if(defined( 'AUTOMESSSAGE_GLOBAL_TABLES' ) && AUTOMESSSAGE_GLOBAL_TABLES == true) {
		return delete_site_option($key);
	} else {
		return delete_option($key);
	}

}

function process_automessage() {
	global $automsg, $wpdb;

	$automsg->process_schedule();
}

// Dashboard options
function AM_oldtablesexist() {

	global $wpdb;

	$sql = $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->base_prefix . 'am_queue' );

	$col = $wpdb->get_col( $sql );

	if(!empty($col)) {
		return true;
	} else {
		return false;
	}

}

function AM_addaction($hook, $subject, $message, $period, $type, $paused = 0) {

		global $user;

		$post = array(
		'post_title' => $subject,
		'post_content' => $message,
		'post_name' => sanitize_title($subject),
		'post_status' => 'private', // You can also make this pending, or whatever you want, really.
		'post_author' => $user->ID,
		'post_category' => array(get_option('default_category')),
		'post_type' => 'automessage',
		'comment_status' => 'closed',
		'menu_order' => $period
		);

		if($paused == 1) {
			$post['post_status'] = 'draft';
		}

		// update the post
		$message_id = wp_insert_post($post);

		if(!is_wp_error($message_id)) {
			update_metadata('post', $message_id, '_automessage_hook', $hook);
			update_metadata('post', $message_id, '_automessage_level', $type);
			update_metadata('post', $message_id, '_automessage_period', $period . ' day');
		}

		return $message_id;
}

function AM_movesitemessages() {

	global $wpdb;

	$sql = $wpdb->prepare( "SELECT * FROM {$wpdb->base_prefix}am_schedule WHERE action_id = 1 ORDER by period ASC");

	$actions = $wpdb->get_results( $sql );
	if(!empty($actions)) {
		foreach($actions as $action) {
			$message_id = AM_addaction('wpmu_new_blog', $action->subject, $action->message, $action->period, 'blog');

			//transfer the users
			$scheds = $wpdb->get_results( $wpdb->prepare("SELECT user_id, runon FROM {$wpdb->base_prefix}am_queue WHERE schedule_id = %d", $action->id) );

			foreach((array) $scheds as $sched) {
				update_user_meta($sched->user_id, '_automessage_run_action', $sched->runon);
				update_user_meta($sched->user_id, '_automessage_on_action', $message_id);
			}

		}
	}

}

function AM_moveusermessages() {

	global $wpdb;

	$sql = $wpdb->prepare( "SELECT * FROM {$wpdb->base_prefix}am_schedule WHERE action_id = 2 ORDER by period ASC");

	$actions = $wpdb->get_results( $sql );
	if(!empty($actions)) {
		foreach($actions as $action) {
			$message_id = AM_addaction('wpmu_new_user', $action->subject, $action->message, $action->period, 'user');

			//transfer the users
			$scheds = $wpdb->get_results( $wpdb->prepare("SELECT user_id, runon FROM {$wpdb->base_prefix}am_queue WHERE schedule_id = %d", $action->id) );

			foreach((array) $scheds as $sched) {
				update_user_meta($sched->user_id, '_automessage_run_action', $sched->runon);
				update_user_meta($sched->user_id, '_automessage_on_action', $message_id);
			}
		}
	}

}

function AM_transfer() {
	?>
	<div class="postbox ">
		<h3 class="hndle"><span><?php _e('Data migration','automessage'); ?></span></h3>
		<div class="inside">
			<?php
			if(AM_oldtablesexist()) {
				?>
				<p><?php _e('You have a previous install of Automessage on this server to migrate from.','automessage'); ?></p>
				<p><?php _e('Click on the button below to start a migration. This may take some time.','automessage'); ?></p>

				<?php
				if(!empty($_GET['migrate'])) {
					check_admin_referer('automessage_migrate');
					?>
					<p><strong><?php _e('Migrating data.','automessage'); ?></strong></p>
					<p><?php _e('Please wait whilst we migrate your data.','automessage'); ?></p>
					<p><strong>1.</strong> <?php _e('Moving blog level messages...','automessage'); ?></p>
					<?php echo AM_movesitemessages(); ?>
					<p><strong>2.</strong> <?php _e('Moving user level messages...','automessage'); ?></p>
					<?php echo AM_moveusermessages(); ?>
					<p><strong><?php _e('Migration complete.','automessage'); ?></strong></p>
					<?php
				} else {
					?>
					<form method='GET' action=''>
						<?php wp_nonce_field('automessage_migrate'); ?>
						<p>
							<input type='hidden' name='page' value='<?php echo 'automessage'; ?>' />
							<input type='submit' name='migrate' value='Migrate data' />
						</p>
					</form>

					<?php
				}

			} else {
				?>
				<p>
					<?php _e('You have not got a previous install of Automessage on this server to migrate from.','automessage'); ?>
				</p>
				<?php
			}
			?>
			<br class="clear">
		</div>
	</div>
	<?php
}
if(defined('AUTOMESSAGE_SHOW_MIGRATE') && AUTOMESSAGE_SHOW_MIGRATE == true) add_action( 'automessage_dashboard_right', 'AM_transfer' );
?>