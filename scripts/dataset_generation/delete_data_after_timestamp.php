<?php

// Script to delete newer data after a given timestamp
// Useful when generating new dataset files to create clean data set periods.
// Usage: php delete_data_after_timestamp.php
chdir(__DIR__ . "/../..");
include "scripts/settings.php";
include "scripts/common.php";

// Define date 01-10-2025 00:00:00
// use php datetime to get the timestamp
// Europe/London timezone
$date = new DateTime();
$date->setTimezone(new DateTimeZone('Europe/London'));
$date->setDate(2025, 10, 1);
$date->setTime(0, 0, 0);
$timestamp = $date->getTimestamp();


$dir = "phpfina/";
// get list of feeds from dataset directory phpfina
$feeds = glob($dir."*.meta");
foreach ($feeds as $feed) {
    $feed = str_replace("phpfina/", "", $feed);
    $feedname = trim(str_replace(".meta", "", $feed));

    // 1. Get feed meta data
    $meta = get_meta($dir, $feedname);

    
    $pos = floor(($timestamp - $meta->start_time) / $meta->interval);
    print "Truncating: " . $feedname . " at pos: " . $pos . " for timestamp: " . $timestamp . "\n";

    $byte_pos = $pos * 4;

    // truncate .dat file to byte pos
    $from = $dir. $feedname . ".dat";
    print "- Truncating $from to byte pos: " . $byte_pos . "\n";
    $fp = fopen($from, "r+");
    ftruncate($fp, $byte_pos);
    fclose($fp);


}