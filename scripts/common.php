<?php

// Compact get_meta function to read metadata from phpfina files
function get_meta($dir, $id)
{
    $metafile = $dir . $id . '.meta';
    if (!file_exists($metafile)) return false;

    // Read interval and start_time in one operation
    $data = file_get_contents($metafile, false, null, 8, 8);
    if (strlen($data) !== 8) return false;
    
    list($interval, $start_time) = array_values(unpack('I2', $data));
    
    // Calculate npoints from .dat file size
    $datfile = $dir . $id . '.dat';
    $npoints = file_exists($datfile) ? floor(filesize($datfile) / 4.0) : 0;
    $end_time = max(0, $start_time + ($interval * ($npoints - 1)));
    
    return (object) compact('interval', 'start_time', 'npoints', 'end_time');
}

function http_request($method, $url, $data)
{
    $options = array();

    if ($method=="GET") {
        $urlencoded = http_build_query($data);
        $url = "$url?$urlencoded";
    } elseif ($method=="POST") {
        $options[CURLOPT_POST] = 1;
        $options[CURLOPT_POSTFIELDS] = $data;
    }

    $options[CURLOPT_URL] = $url;
    $options[CURLOPT_RETURNTRANSFER] = 1;
    $options[CURLOPT_CONNECTTIMEOUT] = 2;
    $options[CURLOPT_TIMEOUT] = 5;

    $curl = curl_init();
    curl_setopt_array($curl, $options);
    $resp = curl_exec($curl);
    curl_close($curl);
    return $resp;
}