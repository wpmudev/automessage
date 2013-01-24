<?php
// Use a global set of tables - set this to false in order to have a specific set per blog (you can leave it on a single site WP system).
if( !defined('AUTOMESSSAGE_GLOBAL_TABLES') ) define( 'AUTOMESSSAGE_GLOBAL_TABLES', false );
// If the actions aren't working then use this to check up on users.
if( !defined('AUTOMESSAGE_POLL_USERS') ) define('AUTOMESSAGE_POLL_USERS', true);
// If the actions aren't working then use this to check up on users.
if( !defined('AUTOMESSAGE_POLL_BLOGS') ) define('AUTOMESSAGE_POLL_BLOGS', true);
// The maximum delay in days that a message can be scheduled
if( !defined('AUTOMESSAGE_POLL_MAX_DELAY') ) define('AUTOMESSAGE_POLL_MAX_DELAY', 31);
// Enable migration
if( !defined('AUTOMESSAGE_SHOW_MIGRATE') ) define('AUTOMESSAGE_SHOW_MIGRATE', false);
// Removes user from the user message queue if they also create a blog.
if( !defined('AUTOMESSAGE_SINGLE_PATH') ) define('AUTOMESSAGE_SINGLE_PATH', true);
?>