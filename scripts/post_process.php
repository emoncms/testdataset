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

// --------------------------------------------------------------------------
// Create "use" feed as sum of appliances, lighting, cooker, heatpump and car
// --------------------------------------------------------------------------
$formula = "";
$formula .= "f".$feed->get_id($userid, "power_appliances")."+";
$formula .= "f".$feed->get_id($userid, "power_lighting")."+";
$formula .= "f".$feed->get_id($userid, "power_cooker")."+";
$formula .= "f".$feed->get_id($userid, "power_heatpump")."+";
$formula .= "f".$feed->get_id($userid, "power_car");

// Using basic formula
$params = (object) array(
    "formula" => $formula,
    "output" => get_or_create_feed($userid, "power", "use", 10),
    "process_mode" => "recent",
    "process_start" => 0,
    "process" => "basic_formula"
);
$result = $process_classes[$params->process]->process($params);
echo json_encode($result)."\n";

// --------------------------------------------------------------------------
// Create kWh feeds from power feeds
// --------------------------------------------------------------------------
$power_feeds = array(
    "heatpump_elec",
    "heatpump_heat",
    "power_appliances",
    "power_car",
    "power_cooker",
    "power_heatpump",
    "power_lighting",
    "solar",
    "use"
);

foreach ($power_feeds as $feedname) {
    echo "Processing feed: $feedname\n";
    $power_feedid = $feed->get_id($userid, $feedname);
    if ($power_feedid) {
        echo "- $feedname exists with id: $power_feedid\n";

        $meta = $feed->get_meta($power_feedid);
        $params = (object) array(
            "input" => $power_feedid,
            "output" => get_or_create_feed($userid, "kwh", $feedname . "_kwh", $meta->interval),
            "max_power" => 100000,
            "min_power" => -100000,
            "process_mode" => "recent",
            "process" => "powertokwh"
        );
        $result = $process_classes[$params->process]->process($params);
        echo json_encode($result)."\n";
    }
}

// --------------------------------------------------------------------------
// Battery simulator
// --------------------------------------------------------------------------
$battery_config = (object) array(
    // Input feeds
    "solar" => $feed->get_id($userid, "solar"),
    "consumption" => $feed->get_id($userid, "use"),
    // Simulator params
    "capacity" => 9.5,
    "max_charge_rate" => 3000,
    "max_discharge_rate" => 3000,
    "round_trip_efficiency" => 0.8,
    "timezone" => "Europe/London",
    "offpeak_soc_target" => 0,
    "offpeak_start" => 3,
    // New feeds for battery simulator output
    "charge" => get_or_create_feed($userid, "battery", "battery_charge", 10),
    "discharge" => get_or_create_feed($userid, "battery", "battery_discharge", 10),
    "soc" => get_or_create_feed($userid, "battery", "battery_soc", 10),
    "import" => get_or_create_feed($userid, "battery", "import", 10),
    "charge_kwh" => get_or_create_feed($userid, "battery", "battery_charge_kwh", 10),
    "discharge_kwh" => get_or_create_feed($userid, "battery", "battery_discharge_kwh", 10),
    "import_kwh" => get_or_create_feed($userid, "battery", "import_kwh", 10),
    "solar_direct_kwh" => get_or_create_feed($userid, "battery", "solar_direct_kwh", 10),
    // Control params
    "process_mode" => "all",
    "process_start" => 0,
    "process" => "batterysimulator"
);

$result = $process_classes[$battery_config->process]->process($battery_config);
echo json_encode($result)."\n";

// --------------------------------------------------------------------------
// Helper functions
// --------------------------------------------------------------------------

function get_or_create_feed($userid, $node, $feedname, $interval) {
    global $feed;
    $feedid = $feed->get_id($userid, $feedname);
    if (!$feedid) {
        echo "Creating $feedname\n";
        $meta = new stdClass();
        $meta->interval = $interval;
        $result = $feed->create($userid, $node, $feedname, 5, $meta);
        if ($result['success']) {
            echo "- Created $feedname with id: " . $result['feedid'] . "\n";
            $feedid = $result['feedid'];
        }
    } else {
        echo "- $feedname exists with id: $feedid\n";
    }
    return $feedid;
}