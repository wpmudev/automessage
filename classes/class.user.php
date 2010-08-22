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




	}


}
?>