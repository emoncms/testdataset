<?php

// Script to rename phpfina files from id to name.
// Useful when generating new dataset files.
// Usage: php rename_ids_to_names.php

include "settings.php";
$feeds = json_decode(file_get_contents($host . "feed/list.json?apikey=" . $apikey), true);

foreach ($feeds as $feed) {
    $id = $feed['id'];
    $name = $feed['name'];
    print $id . " " . $name . "\n";

    // move phpfina/id.dat to phpfina/name.dat
    if (file_exists("phpfina/" . $id . ".dat")) {
        rename("phpfina/" . $id . ".dat", "phpfina/" . $name . ".dat");
    }
    // move phpfina/id.meta to phpfina/name.meta
    if (file_exists("phpfina/" . $id . ".meta")) {
        rename("phpfina/" . $id . ".meta", "phpfina/" . $name . ".meta");
    }
}