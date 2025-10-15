<?php
if (posix_geteuid() != 0) {
    die("This script must be run as root/sudo\n");
}
include "/var/www/emoncms/Lib/load_emoncms.php";
include "Modules/postprocess/postprocess_model.php";
$postprocess = new PostProcess($mysqli, $redis, $feed);
$processes = $postprocess->get_processes("$linked_modules_dir/postprocess");
$process_classes = $postprocess->get_process_classes();

$userid = 2;

$power_feeds = array(
    "heatpump_elec",
    "heatpump_heat",
    "power_appliances",
    "power_car",
    "power_cooker",
    "power_heatpump",
    "power_lighting",
    "power_solar"
);

foreach ($power_feeds as $feedname) {
    echo "Processing feed: $feedname\n";
    $power_feedid = $feed->get_id($userid, $feedname);
    if ($power_feedid) {
        echo "- $feedname exists with id: $power_feedid\n";

        $kwh_feedname = $feedname . "_kwh";
        $kwh_feedid = $feed->get_id($userid, $kwh_feedname);
        if (!$kwh_feedid) {
            echo "- Creating $kwh_feedname\n";
            $meta = $feed->get_meta($power_feedid);
            $kwh_feedid = $feed->create($userid, "kwh", $kwh_feedname, 5, $meta);
            if ($kwh_feedid['success']) {
                echo "- Created $kwh_feedname with id: " . $kwh_feedid['feedid'] . "\n";
                $kwh_feedid = $kwh_feedid['feedid'];
            }
        } else {
            echo "- $kwh_feedname exists with id: $kwh_feedid\n";
        }

        $params = (object) array(
            "input" => $power_feedid,
            "output" => $kwh_feedid,
            "max_power" => 100000,
            "min_power" => -100000,
            "process_mode" => "all",
            "process" => "powertokwh"
        );
        $result = $process_classes[$params->process]->process($params);
        echo json_encode($result)."\n";
    }
}