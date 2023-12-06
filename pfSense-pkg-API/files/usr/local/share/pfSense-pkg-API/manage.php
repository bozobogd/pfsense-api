#!/usr/local/bin/php -f
<?php
//   Copyright 2023 Jared Hendrickson
//
//   Licensed under the Apache License, Version 2.0 (the "License");
//   you may not use this file except in compliance with the License.
//   You may obtain a copy of the License at
//
//       http://www.apache.org/licenses/LICENSE-2.0
//
//   Unless required by applicable law or agreed to in writing, software
//   distributed under the License is distributed on an "AS IS" BASIS,
//   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//   See the License for the specific language governing permissions and
//   limitations under the License.
require_once("api/core/Tools.inc");
require_once("api/core/TestCase.inc");

use API\Dispatchers\WebGUIRestartDispatcher;
use API\Models\APISettings;

function build_endpoints() {
    # Use a variable to keep track of the privileges associated with this endpoint
    $endpoint_privs = [];

    # Import each endpoint class
    foreach(glob("/etc/inc/api/endpoints/*.inc") as $file) {
        # Import classes files and create object
        require_once($file);
        $endpoint_class = "\\API\\Endpoints\\".str_replace(".inc", "", basename($file));
        $endpoint_obj = new $endpoint_class();
        $endpoint_obj->build_endpoint_url();
        $endpoint_privs += $endpoint_obj->generate_pfsense_privs();
    }

    # Write the API endpoint privileges to privs.json
    file_put_contents("/usr/local/share/pfSense-pkg-API/privs.json", json_encode($endpoint_privs));
}

function build_forms() {
    # Import each form class
    foreach(glob("/etc/inc/api/forms/*.inc") as $file) {
        # Import classes files and create object
        require_once($file);
        $form_class = "\\API\\Forms\\".str_replace(".inc", "", basename($file));
        $form_obj = new $form_class();
        $form_obj->build_form_url();
    }
}

function notify_dispatcher(string $dispatcher_name) {
    # Format the fully qualified class name
    $class = "\\API\\Dispatchers\\$dispatcher_name";

    # Ensure this Dispatcher class exists
    if (class_exists($class)) {
        # Start the Dispatcher process
        echo "Running $class process... ";
        $dispatcher = new $class();
        $dispatcher->process();
        echo "done.".PHP_EOL;
        exit(1);
    }
    else {
        echo "No dispatcher exists at $class".PHP_EOL;
        exit(1);
    }
}

function run_tests($contains = "") {
    # Variables
    $test_cases = glob("/etc/inc/api/tests/*.inc");
    $exit_code = 0;
    $test_count = count($test_cases);
    $skipped_count = 0;
    $succeed_count = 0;

    # Import each test class and run the test
    foreach($test_cases as $test_case_file) {
        # Import classes files and create object
        require_once($test_case_file);
        $test_case = "\\API\\Tests\\".str_replace(".inc", "", basename($test_case_file));

        # Print that we're starting this test
        echo "Running test case $test_case... ";

        # Only run this test case if the test name contains the $contains string
        if (!str_contains($test_case, $contains)) {
            echo "skipped.".PHP_EOL;
            $skipped_count++;
            continue;
        }

        # Try to run the test case.
        $test_obj = new $test_case();
        try {
            $test_obj->run();
            echo "done.".PHP_EOL;
            $succeed_count++;
            $fail_results = null;
        }
        # If an AssertionError is received, there was a test failure. Print the traceback.
        catch (AssertionError $fail_results) {
            echo "failed!".PHP_EOL;
            $exit_code = 2;
        }
        catch (Exception | Error $fail_results) {
            echo "fatal!".PHP_EOL;
            $exit_code = 1;
        }

        if ($fail_results) {
            $exc_class = $fail_results::class;
            $fail_msg = $fail_results->getMessage();
            echo "---------------------------------------------------------".PHP_EOL;
            echo "Failure message: [$exc_class] $fail_msg".PHP_EOL;
            echo "Test name: $test_obj->method".PHP_EOL;
            echo "Test description: $test_obj->method_docstring".PHP_EOL.PHP_EOL;
            echo $fail_results->getTraceAsString().PHP_EOL;
            echo "---------------------------------------------------------".PHP_EOL;
        }
    }

    # Adjust the total test count if tests were skipped.
    if ($skipped_count > 0) {
        $test_count = $test_count - $skipped_count;
    }

    echo "---------------------------------------------------------".PHP_EOL;
    echo "Ran all tests: $succeed_count/$test_count tests passed. $skipped_count tests skipped.".PHP_EOL;
    exit($exit_code);
}

function restart_webgui() {
    echo "Scheduling webConfigurator restart... ";
    (new WebGUIRestartDispatcher())->spawn_process();
    echo "done.".PHP_EOL;
    exit(0);
}

function backup() {
    echo "Backing up API configuration... ";
    echo match (APISettings::backup_to_file()) {
        API\Models\API_SETTINGS_BACKUP_SUCCESS => "done." . PHP_EOL,
        API\Models\API_SETTINGS_BACKUP_NOT_CONFIGURED => "not configured." . PHP_EOL,
        default => "unknown error occurred." . PHP_EOL,
    };
}

function restore() {
    echo "Restoring API configuration... ";
    echo match (APISettings::restore_from_backup()) {
        API\Models\API_SETTINGS_RESTORE_SUCCESS => "done." . PHP_EOL,
        API\Models\API_SETTINGS_BACKUP_NOT_CONFIGURED => "not configured." . PHP_EOL,
        API\Models\API_SETTINGS_RESTORE_NO_CHANGE => "no changes found." . PHP_EOL,
        API\Models\API_SETTINGS_RESTORE_NO_BACKUP => "no backup found." . PHP_EOL,
        default => "unknown error occurred." . PHP_EOL,
    };
}

function sync() {
    API\Core\Tools\sync();
}

function update() {
    $pf_version = API\Core\Tools\get_pfsense_version()["base"];
    echo shell_exec("/usr/local/sbin/pkg-static delete -y pfSense-pkg-API");
    echo shell_exec("/usr/local/sbin/pkg-static -C /dev/null add https://github.com/jaredhendrickson13/pfsense-api/releases/latest/download/pfSense-".$pf_version."-pkg-API.pkg");
    echo shell_exec("/etc/rc.restart_webgui");
}

function revert($version) {
    # Local variables
    $pf_version = API\Core\Tools\get_pfsense_version()["base"];
    $url = "https://github.com/jaredhendrickson13/pfsense-api/releases/download/".urlencode($version)."/pfSense-".$pf_version."-pkg-API.pkg";
    echo "Locating pfSense-pkg-API-".$version." at ".$url."... ";

    # Check our URL for the existence of this version
    $headers = @get_headers($url);

    # If this release exists, remove the existing package and install the requested one. Otherwise return error
    if($headers && strpos($headers[0], '302')) {
        echo "done.".PHP_EOL;
        echo shell_exec("/usr/local/sbin/pkg-static delete -y pfSense-pkg-API");
        echo shell_exec("/usr/local/sbin/pkg-static -C /dev/null add " . escapeshellarg($url));
        echo shell_exec("/etc/rc.restart_webgui");
    } else {
        echo "no package found.".PHP_EOL;
        exit(1);
    }
}

function delete() {
    echo shell_exec("/usr/local/sbin/pkg-static delete -y pfSense-pkg-API");
    echo shell_exec("/etc/rc.restart_webgui");
}

function rotate_server_key() {
    $pkg_index = API\Core\Tools\get_api_config()[0];
    config_set_path("installedpackages/package/{$pkg_index}/conf/keys", []);
    echo "Rotating API server key... ";
    API\Core\Tools\create_jwt_server_key(true);
    echo "done.".PHP_EOL;
    sync();
}

function version() {
    echo shell_exec("/usr/local/sbin/pkg-static info pfSense-pkg-API");
}

function help() {
    echo "pfsense-api - CLI tool for pfSense API management".PHP_EOL;
    echo "Copyright - ".date("Y")."© - Jared Hendrickson".PHP_EOL;
    echo "SYNTAX:".PHP_EOL;
    echo "  pfsense-api <command> <args>".PHP_EOL;
    echo "COMMANDS:".PHP_EOL;
    echo "  version          : Display the current package version and build information".PHP_EOL;
    echo "  help             : Display the help page (this page)".PHP_EOL;
    echo "  buildendpoints   : Build all API endpoints included in this package".PHP_EOL;
    echo "  buildforms       : Build all API forms included in this package".PHP_EOL;
    echo "  generatedocs     : Regenerates the OpenAPI documentation".PHP_EOL;
    echo "  notifydispatcher : Start a dispatcher process".PHP_EOL;
    echo "  runtests         : Run all API unit tests. Warning: this may be disruptive!".PHP_EOL;
    echo "  restartwebgui    : Restart the webConfigurator in the background".PHP_EOL;
    echo "  update           : Update package to the latest stable version available".PHP_EOL;
    echo "  revert           : Revert package to a specified version".PHP_EOL;
    echo "  delete           : Delete package from this system".PHP_EOL;
    echo "  rotateserverkey  : Rotate the API server key and remove all existing tokens".PHP_EOL;
    echo "  backup           : Create a backup of the API configuration".PHP_EOL;
    echo "  restore          : Restore the API configuration from the latest backup".PHP_EOL;
    echo "  sync             : Sync this system's API configuration to configured HA nodes".PHP_EOL;
    echo PHP_EOL;
}

# BUILD_FORMS COMMAND
if (in_array($argv[1], ["buildforms"])) {
    build_forms();
}
# BUILDENDPOINTS COMMAND
elseif (in_array($argv[1], ["buildendpoints"])) {
    build_endpoints();
}
# GENERATEDOCUMENTATION COMMAND
elseif (in_array($argv[1], ["generatedocs"])) {
    echo "Generating OpenAPI documentation... ";
    API\Core\Tools\generate_documentation();
    echo "done.".PHP_EOL;
}
# NOTIFY_DISPATCHER COMMAND
elseif (in_array($argv[1], ["notifydispatcher"])) {
    notify_dispatcher(dispatcher_name: $argv[2]);
}
# RUNTESTS COMMAND
elseif (in_array($argv[1], ["runtests"])) {
    run_tests(contains: $argv[2]);
}
# RESTART_WEBGUI COMMAND
elseif (in_array($argv[1], ["restartwebgui"])) {
    restart_webgui();
}
# BACKUP COMMAND
elseif (in_array($argv[1], ["backup"])) {
    backup();
}
# RESTORE COMMAND
elseif (in_array($argv[1], ["restore"])) {
    restore();
    sync();
}
# SYNC COMMAND
elseif (in_array($argv[1], ["sync"])) {
    sync();
}
# UPDATE COMMAND
elseif (in_array($argv[1], ["update"])) {
    update();
}
# REVERT COMMAND
elseif (in_array($argv[1], ["revert"])) {
    revert($argv[2]);
}
# DELETE COMMAND
elseif (in_array($argv[1], ["delete"])) {
    delete();
}
# ROTATESERVERKEY COMMAND
elseif (in_array($argv[1], ["rotateserverkey"])) {
    rotate_server_key();
}
# VERSION COMMAND
elseif (in_array($argv[1], ["version"])) {
    version();
}
# HELP COMMAND
elseif (in_array($argv[1], ["help", null])) {
    help();

}
# UNKNOWN COMMAND/DEFAULT
else {
    echo "Error: Unknown command".PHP_EOL.PHP_EOL;
    help();
}

exit;