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

// Fetch feed list
$existing_feeds = json_decode(http_request("GET", $host . "feed/list.json", array("apikey"=>$apikey)), true);
print "Existing feeds: " . count($existing_feeds) . "\n";

// get timestamp midnight this morning
$date = new DateTime();
$date->setTimezone(new DateTimeZone('Europe/London'));
$now = $date->getTimestamp();

$dir = "phpfina/";
// get list of feeds from dataset directory phpfina
$feeds = glob($dir."*.meta");
foreach ($feeds as $feed) {
    $feed = str_replace("phpfina/", "", $feed);
    $feedname = trim(str_replace(".meta", "", $feed));

    // 1. Get feed meta data
    $dataset_meta = get_meta($dir, $feedname);
    $interval = $dataset_meta->interval;

    // Check if feed already exists
    $feed_exists = false;
    $feedid = null;
    foreach ($existing_feeds as $existing_feed) {
        if ($existing_feed['name'] == $feedname) {
            $feed_exists = true;
            $feedid = $existing_feed['id'];
            print "Feed already exists: " . $feedname . "\n";
            break;
        }
    }

    if ((!$feed_exists)) {
        // 2. Add feed to emoncms account
        $feedid = create_feed($host, $apikey, "testdataset", $feedname, $interval);
        if ($feedid === false) continue;

        // Get start_time of new feed using datetime now -1 year
        $date = new DateTime();
        $date->setTimezone(new DateTimeZone('Europe/London'));
        $date->modify('-1 year');
        $date->setTime(0, 0, 0);
        $start_time = $date->getTimestamp();
        $start_time = floor($start_time / $interval) * $interval;
        create_meta($phpfina_dir, $feedid, $interval, $start_time);

        // Calculate start position and number of datapoints to copy from dataset file
        $start_time_in_period = rebase_time($start_time,$dataset_meta);
        $start_pos = floor($start_time_in_period / $interval);
        $dp_to_copy = $dataset_meta->npoints - $start_pos;

        // Read block of data from dataset file
        $fh = fopen($dir. $feedname . ".dat", "r");
        fseek($fh, $start_pos*4);
        $data = fread($fh, $dp_to_copy * 4);
        fclose($fh);

        // Write block of data to target feed file
        $fh = fopen($phpfina_dir. $feedid . ".dat", "w");
        fwrite($fh, $data);
        fclose($fh);
    }
    
    // Get target feed meta data
    $target_meta = get_meta($phpfina_dir, (int) $feedid);

    // 1. Calculate relative time within the dataset period for the target feed end time
    $target_end_time_in_period = rebase_time($target_meta->end_time, $dataset_meta);

    // 2. Calculate relative time within the dataset period for the time now
    $now_in_period = rebase_time($now, $dataset_meta);

    // 3. Calculate how many datapoints to copy from the dataset file to the target feed file
    $dp_to_copy = floor($now_in_period - $target_end_time_in_period) / $interval;

    if ($dp_to_copy > 0) {
        $seek_pos = floor($target_end_time_in_period / $interval);

        // Read block of data from dataset file
        $fh = fopen($dir. $feedname . ".dat", "r");
        fseek($fh, $seek_pos*4);
        $data = fread($fh, $dp_to_copy * 4);
        fclose($fh);

        // Append block of data to target feed file
        $fh = fopen($phpfina_dir. $feedid . ".dat", "a");
        fwrite($fh, $data);
        fclose($fh);
    }
}

function rebase_time($time, $dataset_meta) {
    $full_period = $dataset_meta->end_time - $dataset_meta->start_time;
    // Rebase time to dataset start time
    $time_rebase = $time - $dataset_meta->start_time;
    // Calculate how many full periods have elapsed since start time
    $period_multiple = floor($time_rebase / $full_period);
    // Calculate the current position within the dataset period
    $time_in_period = $time_rebase - ($period_multiple * $full_period);
    return $time_in_period;
}
