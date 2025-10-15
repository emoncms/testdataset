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
    $full_period = $dataset_meta->end_time - $dataset_meta->start_time;

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
        print "Adding feed: " . $feedname . "\n";
        $result = http_request(
            "GET", 
            $host."feed/create.json", 
            array(
                'name' => $feedname, 
                'engine' => 5, 
                'tag' => 'testdataset',
                'options'=>json_encode(array(
                    'interval'=>$dataset_meta->interval
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

        $from = $dir. $feedname . ".meta";
        $to = $phpfina_dir . $feedid . ".meta";
        print "- Copying $from to $to\n";
        copy($from, $to);

        // $from = $dir. $feedname . ".dat";
        // $to = $phpfina_dir . $feedid . ".dat";
        // print "- Copying $from to $to\n";
        // copy($from, $to);

        // 4. Get start_time of new feed using datetime now -1 year
        $date = new DateTime();
        $date->setTimezone(new DateTimeZone('Europe/London'));
        $date->modify('-1 year');
        // midnight
        $date->setTime(0, 0, 0);
        $start_time = $date->getTimestamp();
        $start_time = floor($start_time / $dataset_meta->interval) * $dataset_meta->interval;

        // Update meta start_time to this value
        $fh = fopen($phpfina_dir. $feedid . ".meta", "r+");
        fseek($fh, 12);
        fwrite($fh, pack('I', $start_time));
        fclose($fh);


        $start_time_rebase = $start_time - $dataset_meta->start_time;
        $start_period_multiple = floor($start_time_rebase / $full_period);
        $start_time_in_period = $start_time_rebase - ($start_period_multiple * $full_period);

        $start_pos = floor($start_time_in_period / $dataset_meta->interval);
        $dp_to_copy = $dataset_meta->npoints - $start_pos;
        $fh = fopen($dir. $feedname . ".dat", "r");
        fseek($fh, $start_pos*4);
        $data = fread($fh, $dp_to_copy * 4);
        fclose($fh);

        $fh = fopen($phpfina_dir. $feedid . ".dat", "w");
        fwrite($fh, $data);
        fclose($fh);
    }
    

    // 1. Calculate relative time within the dataset period for the target feed end time
    // Get target feed meta data
    $target_meta = get_meta($phpfina_dir, (int) $feedid);
    // Rebase target end time to dataset start time
    $target_end_time_rebase = $target_meta->end_time - $dataset_meta->start_time;
    // Calculate how many full periods have elapsed since start time
    $target_period_multiple = floor($target_end_time_rebase / $full_period);
    // Calculate the current position within the dataset period
    $target_end_time_in_period = $target_end_time_rebase - ($target_period_multiple * $full_period);

    // 2. Calculate relative time within the dataset period for the time now
    // Rebase by subtracting dataset_meta->stream_set_time
    $now_rebase = $now - $dataset_meta->start_time;
    // Calculate how many full periods have elapsed since start time
    $period_multiple = floor($now_rebase / $full_period);
    // Calculate the current position within the dataset period
    $now_in_period = $now_rebase - ($period_multiple * $full_period);

    // 3. Calculate how many seconds to add to the target feed to bring it up to now
    // This is the difference between the two positions in the period
    $seconds_to_add = $now_in_period - $target_end_time_in_period;

    // Read this period from the dataset file:
    if ($seconds_to_add > 0) {
        $dp_to_copy = floor($seconds_to_add / $dataset_meta->interval);
        if ($dp_to_copy > 0) {
            $seek_pos = floor($target_end_time_in_period / $dataset_meta->interval);
            $fh = fopen($dir. $feedname . ".dat", "r");
            fseek($fh, $seek_pos*4);
            $data = fread($fh, $dp_to_copy * 4);
            fclose($fh);

            $fh = fopen($phpfina_dir. $feedid . ".dat", "a");
            fwrite($fh, $data);
            fclose($fh);

        }
    }
}