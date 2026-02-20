<?php    
    $config = new stdClass();

    // associated WaveLog API URL
    $config->wl_api_url = '<WaveLog API URL>';

    // Notification service to use for admin messages  'discord'|'pushover'
    $config->notification_type = 'discord';

    // Discord webhook url
    // Edit Channel -> Integrations -> Webhooks
    $config->discord_admin_url = '<Discord channel webhook URL>';

    //pushover api tokens
    $config->pushover_token = '<Pushover API token>';
    $config->pushover_user = '<Pushover user key>';

    // used for Brick/Geo GIS geometry calculations if php-geos not installed
    // used for gridcache
    $config->geodb_host = '<mysql db hostname>';
    $config->geodb_user = '<mysql db user>';
    $config->geodb_pass = '<mysql db password>';
    $config->geodb_db = '<mysql databse>';
?>