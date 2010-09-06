<?php
if(!class_exists('Auto_User')) {

	class Auto_User extends WP_User {

		var $db;
		var $blog_id = false;

		function Auto_User( $id, $name = '' ) {

			global $wpdb;

			if($id != 0) {
				parent::WP_User( $id, $name = '' );
			}


			$this->db =& $wpdb;

		}

		function set_blog_id( $blog_id ) {
			$this->blog_id = (int) $blog_id;
		}

		function send_message( $subject, $message ) {

		}

		function schedule_message( $message_id, $timestamp ) {

			update_usermeta($this->ID, '_automessage_on_action', (int) $message_id);
			update_usermeta($this->ID, '_automessage_run_action', (int) $timestamp);

		}

		function has_message_scheduled( $message_id ) {
			//return !! get_usermeta($this->ID, '_automessage_on_action');
		}

		function on_message( $message_id ) {
			//return !! get_usermeta($this->ID, '_automessage_on_action');
		}

		function next_message() {

		}





	}


}
?>