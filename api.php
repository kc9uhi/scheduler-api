<?php
    // MUST BE POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
    define('API_LOADED', TRUE);

    // load config data
    require_once('api_config.php');

    header('Content-type: application/json');

    $raw_in = json_decode(file_get_contents('php://input'), TRUE);
    $data = json_decode($raw_in['payload'], TRUE);


    /* ==============================
    *  MAIN API ROUTINE
    * 
    *  Post data consists of json encoded array
    *  [ 
    *    'method' -- api endpoint
    *    'paylod' -- data to be used
    *  ]
    *
    * =============================== */
    switch($raw_in['method']) {
        case 'opcheck':
            $message = '';
            // check if operator is a member of the event call
            if (!wl_check_membership($data['operator_call'], $data['key'])) {
                //not in event call memberlist, alert wavelog admin
                wl_admin_alert("Operator {$data['operator_call']} not a member of Event Station {$data['station_call']}");
                $message = "Operator not found in logger for station {$data['station_call']}. Admin notified. ";
            }
            echo json_encode(['status' => 'success', 'info' => $message]);
            break;

        case 'gridcheck':
            require_once('api-geofunctions.php');
            $callsign_data = FALSE;
            $message = '';
            // check for station location matching operator's intended operating grid square
            $grid = $data['clubstation_grid'];  //if clubstation_grid is provided, OP will be not at 'home'
            
            if ($grid == '') {  // no clubstation_grid, check if alt grid is in notes
                preg_match('/([A-R]{2}[0-9]{2}[A-X]{2})/i', $data['notes'], $matches);
                if (!empty($matches)) { // found a grid in the notes
                    $grid = $matches[0];
                }
            }
            if($grid == '') { //no clubstation_grid or grid in notes, check callsign cache
                $db = new mysqli($config->geodb_host, $config->geodb_user, $config->geodb_pass, $config->geodb_db);
                $ret = $db->query("SELECT * FROM `gridcache` WHERE `callsign`='".strtoupper($data['operator_call'])."' LIMIT 1");
                if ($ret->num_rows > 0) {
                    $row = $ret->fetch_assoc();
                    $grid = $row['grid'];
                }
                $dbopen = true;
            }
            if($grid == '') {  // still no grid, try looking up the OP callsign for a grid
                $callsign_data = wl_lookup_callsign($data['operator_call'], $data['key']);
                if ($callsign_data !== FALSE) {
                    $grid = $callsign_data['grid'] ?? $callsign_data['callbook']['grid'];
                    // add to cache
                    error_log("adding $grid to cache for {$data['operator_call']}");
                    $db->query("INSERT INTO `gridcache` (`callsign`,`grid`) VALUES ('".strtoupper($data['operator_call'])."','$grid')");
                }
            }
            if(!empty($dbopen)) $db->close();

            if ($grid == '') { // not going to happen. no sense continuing. return.
                wl_admin_alert("{$data['operator_call']} has no grid info.\nNotes: {$data['notes']}\n");
                $message .= "Unable to determine operating gridsquare. Admin notified. ";
                echo json_encode(['status' => 'fail', 'info' => $message]);
                break;
            }
            $grid = strtoupper($grid);

            $stations = wl_get_locations($data['key']);
            
            $stationmatch = FALSE;
            if (!empty($stations)) {
                // check if existing stations matches grid
                foreach($stations as $loc) {
                    if (strtoupper($loc['station_gridsquare']) == $grid) { $stationmatch = TRUE; break; }
                }
            }
            if ($stationmatch === FALSE) {   // no matching station found, make a new one
                error_log("creating station for grid $grid");
                $data['station_call'] = strtoupper($data['station_call']);
                wl_admin_alert("No location found\n operator: {$data['operator_call']}\n event call: {$data['station_call']}\n grid:$grid\n Creating new location");
                $stnname = substr($grid,0,-2) . strtolower(substr($grid,-2));
                if (!empty($data['club_station'])) {
                    $stnname = $data['club_station'] . '-' . $stnname;
                }
                if(empty($callsign_data)) $callsign_data = wl_lookup_callsign($data['operator_call'], $data['key']);
                wl_station_create($data, $callsign_data, $grid, $stnname);
            }
			echo json_encode(['status' => 'success', 'info' => $message]);
            break;

        default:
            // unknown method
            echo json_encode(['status' => 'fail', 'info' => "unknown method"]);
    }
    /* ==============================
    *  END MAIN API ROUTINE
    * =============================== */


    /* ==============================
    *  wl_lookup_callsign
    * 
    *  get callbook info via wavelog for given callsign
    *
    *  @param (string) $callsign -- callsign to look up
    *  @param (string) $key -- wavelog api key
    *
    * =============================== */
    function wl_lookup_callsign($callsign, $key) {
        global $config;
        $payload = json_encode([
            'key' => $key,
            'callsign' => $callsign,
            'callbook' => 'true'
        ]);
        $ch = curl_init($config->wl_api_url . '/api/private_lookup');
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        $response = curl_exec($ch);
        if ($response !== FALSE) {
            return json_decode($response, TRUE);
        } else {
            return FALSE;
        }
    }


    /* ==============================
    *  wl_get_locations
    * 
    *  get station logbook locations
    *  station call is defined by wavelog api key
    *
    *  @param (string) $key -- wavelog api key
    *
    * =============================== */
    function wl_get_locations($key) {
        global $config;
        $ch = curl_init($config->wl_api_url . '/api/station_info/' . $key);
        curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        if (!empty($response)) {
            return json_decode($response, true);
        } else {
            return FALSE;
        }
    }


    /* ==============================
    *  wl_check_membership
    * 
    *  check if callsign is member of clubstation
    *  clubstation is defined by wavelog api key
    *
    *  @param (string) $callsign -- callsign to check
    *  @param (string) $key -- wavelog api key
    *
    * =============================== */
    function wl_check_membership($callsign, $key) {
        global $config;
        $ch = curl_init($config->wl_api_url . '/api/list_clubmembers');
        $payload = json_encode([
            'key' => $key
        ]);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        if ($response !== FALSE) {
            $data = json_decode($response, TRUE);
            if ($data['status'] == 'successful') {
                foreach($data['members'] as $member) {
                    if (strtoupper($member['callsign']) == strtoupper($callsign)) {
                        return TRUE;
                    }
                }
            }
        }
        return FALSE;
    }


    /* ==============================
    *  wl_admin_alert
    * 
    *  wrapper function to send alert to admin team
    *
    *  @param (string) $msg -- message to send
    *
    * =============================== */
    function wl_admin_alert($msg) {
        global $config;
        switch ($config->notification_type) {
            case 'discord':
                wl_msg_discord($msg);
                break;
            case 'pushover':
                wl_msg_pushover($msg);
        return;
        }
    }


    /* ==============================
    *  wl_msg_pushover
    * 
    *  send message via pushover
    *  https://pushover.net/
    *
    *  @param (string) $msg -- message to send
    *
    * =============================== */
    function wl_msg_pushover($msg) {
        global $config;
        $payload = json_encode([
            'token' => $config->pushover_token,
            'user' => $config->pushover_user,
            'message' => $msg
        ]);
        $ch = curl_init("https://api.pushover.net/1/messages.json");
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
    }


    /* ==============================
    *  wl_msg_discord
    * 
    *  send message to discord channel
    *
    *  @param (string) $msg -- message to send
    *
    * =============================== */
    function wl_msg_discord($msg) {
        global $config;
        $payload = json_encode([
            'content' => $msg,
            'username' => 'Scheduler Notifications'
        ]);
        $ch = curl_init($config->discord_admin_url);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
    }
?>