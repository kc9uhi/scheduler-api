<?php
    require_once('api_config.php');

    // MUST BE POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
    header('Content-type: application/json');

    $raw_in = json_decode(file_get_contents('php://input'), TRUE);
    $data = json_decode($raw_in['payload'], TRUE);
    
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
            if($grid == '') {  // still no grid, try looking up the OP callsign for a grid
                $callsign_data = lookup_callsign($data['operator_call'], $data['key']);
                if ($callsign_data !== FALSE) {
                    $grid = $callsign_data['grid'] ?? $callsign_data['callbook']['grid'];
                }
            }

            if ($grid == '') { // not going to happen. no sense continuing. return.
                wl_admin_alert("{$data['operator_call']} has no grid info.\nNotes: {$data['notes']}\n");
                $message .= "Unable to determine operating gridsquare. Admin notified. ";
            }
            $grid = strtoupper($grid);

            $stations = wl_get_locations($data['key']);
            
            $stationmatch = FALSE;
            if (!empty($stations)) {
                // check if existing stations matches grid
                foreach($stations as $loc) {
                    if (strtoupper($loc['station_gridsquare']) == $grid) $stationmatch = TRUE;
                }
            }
            if ($stationmatch === FALSE) {   // no matching station found, make a new one
                wl_admin_alert("No location found\n event call: {$data['station_call']}\n grid:$grid\n Creating new location");
                $stnname = substr($grid,0,-2) . strtolower(substr($grid,-2));
                if (!empty($data['club_station'])) {
                    $stnname = $data['club_station'] . '-' . $stnname;
                }
                wl_station_create($data, $callsign_data, $grid, $stnname);
            }
			echo json_encode(['status' => 'success', 'info' => $message]);
            break;

        default:
            // unknown method
            echo json_encode(['status' => 'fail', 'info' => "unknown method"]);
    }

    function lookup_callsign($callsign, $key) {
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

        $response = curl_exec($ch);
        if ($response !== FALSE) {
            return json_decode($response, TRUE);
        } else {
            return FALSE;
        }
    }

    function wl_get_locations($key) {
        global $config;
        $ch = curl_init($config->wl_api_url . '/api/station_info/' . $key);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);

        $response = curl_exec($ch);
        if ($response !== FALSE) {
            return json_decode($response, true);
        } else {
            return FALSE;
        }
    }

    function wl_check_membership($callsign, $key) {
        global $config;

        $ch = curl_init($config->wl_api_url . '/api/list_clubmembers/'. $key);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);

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

    function wl_station_create($data, $callsign_data, $grid, $stnname) {
        global $config;
        $payload = json_encode([
			'station_profile_name'  => $stnname,
			'station_gridsquare'    => $grid,
			'station_city'          => $callsign_data['callbook']['city'] ?? '',
			'station_callsign'      => $data['station_call'],
			'station_power'         => 100,
			'station_dxcc'          => $callsign_data['dxcc_id'] ?? 291,  //default USA
			'station_cq'            => $callsign_data['callbook']['cqzone'] ?? '',
			'station_itu'           => $callsign_data['callbook']['ituzone'] ?? '',
			'state'                 => $callsign_data['callbook']['state'] ?? '',
			'station_cnty'          => $callsign_data['callbook']['us_county'] ?? ''
        ]);

        $ch = curl_init($config->wl_api_url . '/api/create_station/' . $data['key']);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);

        curl_exec($ch);
    }

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
        curl_exec($ch);
    }

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
        curl_exec($ch);
    }
?>