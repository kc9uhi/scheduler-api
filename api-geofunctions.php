<?php
    if (! defined('API_LOADED')) exit('No direct script access allowed');

    // Qra.php from wavelog -- application/libraries/Qra.php
    // remove direct script access 'if' statement at the top of file
    require_once('include/Qra.php');

    // Brick\Geo GIS geometry library
    // https://github.com/brick/geo
    require_once('vendor/autoload.php');
    use Brick\Geo\Engine\PdoEngine;
    use Brick\Geo\Engine\GeosEngine;
    use Brick\Geo\Point;
    use Brick\Geo\Io\GeoJsonReader;


    /* ==============================
    *  wl_get_zone
    * 
    *  get CQ/ITU zone for center of given gridsquare
    *
    *  @param (string) $grid -- gridsquare
    *  @param (string) $type itu|cq -- zone type to lookup
    *
    * =============================== */
    function wl_get_zone($grid, $type) {
        global $config;
        if(extension_loaded('geos')) {
            // use php-geos module if available
            $geometryEngine = new GeosEngine();
        } else {
            // fallback to DB engine
            $pdo = new PDO("mysql:host=" . $config->geodb_host . ";dbname=" . $config->geodb_db, $config->geodb_user, $config->geodb_pass);
            $geometryEngine = new PdoEngine($pdo);
        }

        list($lat,$lon) = qra2latlong($grid);
        $geogrid = Point::xy($lon, $lat);

        $z = wl_load_itucq_data($type);
        foreach ($z as $zone => $geo) {
            if ($geometryEngine->within($geogrid, $geo) === TRUE) return $zone;
        }
    }


    /* ==============================
    *  wl_load_itucq_data
    * 
    *  loads ITU/CQ zone data from geojson
    *  geojson files from wavelog -- https://github.com/wavelog/wavelog
    *
    *  @param (string) $t itu|cq -- zone type to load
    *
    * =============================== */
    function wl_load_itucq_data($t) {
        $reader = new GeoJsonReader();
        $geojson_data = json_decode(file_get_contents("json/".$t."zones.geojson"), TRUE);
        if ($geojson_data && $geojson_data['type'] === 'FeatureCollection' && isset($geojson_data['features'])) {
            $a = $t . '_zone_number';
            foreach ($geojson_data['features'] as $feature) {
                $zone[$feature['properties'][$a]] = $reader->read(json_encode($feature['geometry']));
            }
            return $zone;
        } else {
            return false;
        }
    }


    /* ==============================
    *  wl_station_create
    * 
    *  creates a new station location in wavelog
    *
    *  @param (string) $data -- 'payload' from client
    *  @param (string) $callsign_data -- callbook data for callsign
    *  @param (string) $grid -- derived gridsquare
    *  @param (string) $stnname -- name for new station location
    *
    * =============================== */
    function wl_station_create($data, $callsign_data, $grid, $stnname) {
        global $config;
        // check for empty callsign data
        if (empty($callsign_data['dxcc_id'])) $callsign_data['dxcc_id'] = 291;  //default USA
        if (empty($callsign_data['callbook'])) {
            $r = wl_get_census($grid);
            if($r !== FALSE) {
                $callsign_data['callbook'] = $r;
            } else {
                $callsign_data['callbook']['state'] = '';
                $callsign_data['callbook']['us_county'] = '';
                $callsign_data['callbook']['city'] = '';
            }
            $callsign_data['callbook']['cqzone'] = wl_get_zone($grid, 'cq');
            $callsign_data['callbook']['ituzone'] = wl_get_zone($grid, 'itu');
            
        }
        $payload = json_encode([
			'station_profile_name'  => $stnname,
			'station_gridsquare'    => $grid,
			'station_city'          => $callsign_data['callbook']['city'],
			'station_callsign'      => $data['station_call'],
			'station_power'         => 100,
			'station_dxcc'          => $callsign_data['dxcc_id'],
			'station_cq'            => $callsign_data['callbook']['cqzone'],
			'station_itu'           => $callsign_data['callbook']['ituzone'],
			'state'                 => $callsign_data['callbook']['state'],
			'station_cnty'          => $callsign_data['callbook']['us_county']
        ]);
        $ch = curl_init($config->wl_api_url . '/api/create_station/' . $data['key']);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);

        curl_exec($ch);
    }


    /* ==============================
    *  wl_get_census
    * 
    *  gets state, county, and city names from US Census Bureau Geocoder
    *  https://geocoding.geo.census.gov/geocoder/
    * 
    *  @param (string) $grid -- gridsquare to lookup
    *
    * =============================== */
    function wl_get_census($grid) {
        list($lat,$lon) = qra2latlong($grid);
        $ch = curl_init('https://geocoding.geo.census.gov/geocoder/geographies/coordinates?benchmark=4&vintage=4&layers=80,82,28&format=json&x='.round($lon,4).'&y='.round($lat,4));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        $resp =  json_decode(curl_exec($ch), TRUE);

        $r['us_county'] = empty($resp['result']['geographies']['Counties'][0]['BASENAME']) ? '' : $resp['result']['geographies']['Counties'][0]['BASENAME'];
        $r['state'] = empty($resp['result']['geographies']['States'][0]['STUSAB']) ? '' : $resp['result']['geographies']['States'][0]['STUSAB'];
        $r['city'] = empty($resp['result']['geographies']['Incorporated Places'][0]['BASENAME']) ? '': $resp['result']['geographies']['Incorporated Places'][0]['BASENAME'];
        return $r;
    }
?>