<?php
if(!class_exists('Auto_User')) {

	class Auto_User extends WP_User {

		var $db;

		function M_Membership( $id, $name = '' ) {

			global $wpdb;

			if($id != 0) {
				parent::WP_User( $id, $name = '' );
			}


			$this->db =& $wpdb;

		}

		function sendmessage( $subject, $message ) {

		}

		function schedule_message( $message_id, $timestamp ) {

		}

		function has_message_scheduled( $message_id ) {

		}

		function on_message( $message_id ) {

		}

		function next_message() {

		}





	}


}
?>