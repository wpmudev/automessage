<?php

function process_automessage() {
	global $automsg, $wpdb;

	$automsg->process_schedule();
}

?>