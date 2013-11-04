<?php
/*
Plugin Name: Automessage
Plugin URI: http://premium.wpmudev.org/project/automatic-follow-up-emails-for-new-users
Description: This plugin allows emails to be scheduled and sent to new users.
Author: Barry (Incsub)
Version: 2.3.3
Author URI: http://caffeinatedb.com
WDP ID: 81
*/

/*
Copyright Incsub (http://incsub.com)

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

global $automessage_basename;
$automessage_basename = plugin_basename(__FILE__);

// Load the libraries
require_once('includes/config.php');
require_once('includes/functions.php');
require_once('classes/class.automessage.php');
require_once('classes/class.user.php');
// Set up our location
set_automessage_url(__FILE__);
set_automessage_dir(__FILE__);

if(is_admin()) {
	include_once('external/wpmudev-dash-notification.php');
}

// Instantiate the class
$automsg = new automessage();

?>