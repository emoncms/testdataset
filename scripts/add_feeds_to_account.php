<?php

// Script to add dataset feeds to a specific local emoncms account

// check if sudo
if (posix_geteuid() != 0) {
    die("This script must be run as root/sudo\n");
}

$cwd = getcwd()."/";
include "settings.php";
include "common.php";

include "/var/www/emoncms/Lib/load_emoncms.php";
$userid = 2;

// get timestamp midnight this morning
$date = new DateTime();
$date->setTimezone(new DateTimeZone('Europe/London'));
$now = $date->getTimestamp();

$dir = $cwd."phpfina/";
echo "Dataset directory: $dir\n";
// get list of feeds from dataset directory phpfina
$feeds = glob($dir."*.meta");
foreach ($feeds as $feedfile) {
    $feedfile = str_replace($dir, "", $feedfile);
    $feedname = trim(str_replace(".meta", "", $feedfile));
    // split feedname by _ to get node name
    $parts = explode("_", $feedname);
    $node = $parts[0];

    // 1. Get feed meta data
    $dataset_meta = get_meta($dir, $feedname);
    $interval = $dataset_meta->interval;

    $emoncms_feed_name = $feedname;
    if ($feedname == "power_solar") {
        $emoncms_feed_name = "solar";
    }

    $feedid = $feed->exists_tag_name($userid, $node, $emoncms_feed_name);

    if (!$feedid) 
    {
        $result = $feed->create($userid,$node,$emoncms_feed_name,5,$dataset_meta);
        if (!$result['success']) {
            print "Failed to add feed: " . $emoncms_feed_name . "\n";
            continue;
        }
        $feedid = $result['feedid'];
        print "Feed created with id: " . $feedid . "\n";

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
    $dp_to_copy = floor(($now_in_period - $target_end_time_in_period) / $interval);

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

        print "- Appended $dp_to_copy datapoints to feedid: $feedid\n";
    }

    $timevalue = lastvalue($phpfina_dir, $feedid);
    $feed->set_timevalue($feedid, $timevalue['value'], $timevalue['time']);
}

$feed->update_user_feeds_size($userid);

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
