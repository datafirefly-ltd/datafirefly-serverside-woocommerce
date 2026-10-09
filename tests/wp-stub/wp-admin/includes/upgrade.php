<?php
// Test stand-in for WordPress' dbDelta(): runs the test database's own migration.
function dbDelta($sql)
{
    $GLOBALS['wpdb']->migrate();
}
