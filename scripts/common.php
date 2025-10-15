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
    clearstatcache($datfile);
    $npoints = file_exists($datfile) ? floor(filesize($datfile) / 4.0) : 0;
    $end_time = max(0, $start_time + ($interval * ($npoints - 1)));
    
    return (object) compact('interval', 'start_time', 'npoints', 'end_time');
}

function create_meta($dir, $id, $interval, $start_time)
{
    $metafile = $dir . $id . '.meta';
    if (!file_exists($metafile)) return false;

    $fp = fopen($metafile, "wb");
    fwrite($fp, pack("I",0));
    fwrite($fp, pack("I",0));
    fwrite($fp, pack('I', $interval));
    fwrite($fp, pack('I', $start_time));
    fclose($fp);
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

function create_feed($host, $apikey, $node, $name, $interval) {
    print "Adding feed: " . $node.":".$name . "\n";
    $result = http_request(
        "GET", 
        $host."feed/create.json", 
        array(
            'name' => $name, 
            'engine' => 5, 
            'tag' => $node,
            'options'=>json_encode(array(
                'interval'=>$interval
            )), 
            'apikey' => $apikey
        )
    );
    $result = json_decode($result);
    if (!isset($result->success) || $result->success == false) {
        print "Failed to add feed: " . $name . "\n";
        return false;
    }
    print "- Feed added with id: " . $result->feedid . "\n";
    return $result->feedid;
}