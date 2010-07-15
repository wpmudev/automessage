<?php

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

?>