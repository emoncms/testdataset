<?php

// Script to add dataset feeds to a specific local emoncms account

// check if sudo
if (posix_geteuid() != 0) {
    die("This script must be run as root/sudo\n");
}

include "settings.php";
include "common.php";

$username = "test";
$password = "test";

// Authenticate user and get apikey
$result = http_request("POST", $host . "user/auth.json", array("username" => $username, "password" => $password));
$result = json_decode($result, true);
if (!isset($result['success']) || $result['success'] == false) die("Authentication failed\n");
print $result['apikey_write'] . "\n";
$apikey = $result['apikey_write'];

$dir = "phpfina/";
// get list of feeds from dataset directory phpfina
$feeds = glob($dir."*.meta");
foreach ($feeds as $feed) {
    $feed = str_replace("phpfina/", "", $feed);
    $feedname = trim(str_replace(".meta", "", $feed));

    // 1. Get feed meta data
    $meta = get_meta($dir, $feedname);

    // 2. Add feed to emoncms account
    print "Adding feed: " . $feedname . "\n";
    $result = http_request(
        "GET", 
        $host."feed/create.json", 
        array(
            'name' => $feedname, 
            'engine' => 5, 
            'tag' => 'testdataset',
            'options'=>json_encode(array(
                'interval'=>$meta->interval
            )), 
            'apikey' => $apikey
        )
    );
    $result = json_decode($result);
    if (!isset($result->success) || $result->success == false) {
        print "Failed to add feed: " . $feedname . "\n";
        continue;
    }
    $feedid = $result->feedid;
    print "- Feed added with id: " . $feedid . "\n";

    // 3. Copy dataset phpfina files to emoncms phpfina directory directory directly
    // Rename name to id

    $from = $dir. $feedname . ".dat";
    $to = $phpfina_dir . $feedid . ".dat";
    print "- Copying $from to $to\n";
    copy($from, $to);

    $from = $dir. $feedname . ".meta";
    $to = $phpfina_dir . $feedid . ".meta";
    print "- Copying $from to $to\n";
    copy($from, $to);
}


