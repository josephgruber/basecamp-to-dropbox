<?php
if (PHP_SAPI !== "cli") {
  echo "This program was meant to be run from the command-line and not as a web app.  Bad value for
                        PHP_SAPI.  Expected \"cli\", given \"" . PHP_SAPI . "\".";
  exit();
}

require_once "Mail.php";
require_once dirname(__FILE__) . "/lib/Dropbox/autoload.php";
use \Dropbox as dbx;

ini_set("max_execution_time", 540); // Set to 9 minutes timeout due to 10 min cycle
date_default_timezone_set('UTC');

if (isset($argv[1])) {
  if ($argv[1] == "debug") {
    error_reporting(E_ALL);
    ini_set('error_reporting', E_ALL);
    ini_set("display_errors", 1);
  }
}

function exception_handler($ex) {
  $error_msg = "An Error Has Been Detected!\r\nLine: " . $ex->getLine() . "\r\nError Code: "
                . $ex->getCode() . "\r\nError Message: " . $ex->getMessage() . "\r\n";

  echo $error_msg;

  file_put_contents(dirname(__FILE__) . "/last_run", 4102401599); // Set last run to Dec 31st, 2009 11:59:59 PM to prevent future runs

  $str = file_get_contents(dirname(__FILE__) . "/settings.json");
  $settings = json_decode($str);

  $subject = "HI-SEAS Basecamp Sync Error";
  $body = $error_msg;
  $headers = array("From" => $settings->exception_email_from, "To" => $settings->exception_email_to, "Subject" => $subject );
  $smtp = Mail::factory("smtp", array("host" => $settings->smtp_server, "port" => $settings->smtp_port, "auth" => true,
                        "username" => $settings->smtp_username, "password" => $settings->smtp_password));
  $mail = $smtp->send($settings->exception_email_to, $headers, $body);
}

function loadSettings() {
  $settings_file = dirname(__FILE__) . "/settings.json";

  if (!file_exists($settings_file)) {
    throw new \Exception("The settings file was not found at " . $settings_file);
  }

  $str = file_get_contents($settings_file);

  $jsonArr = json_decode($str);

  if (is_null($jsonArr)) {
    throw new \Exception("The settings file at " . $settings_file . " was empty or not in JSON format");
  }

  return $jsonArr;
}

function get_attachments_list($username, $password, $basecamp_id, $basecamp_project_id, $basecamp_useragent_email, $last_run) {
  $handle = curl_init("https://basecamp.com/" . $basecamp_id . "/api/v1/projects/" . $basecamp_project_id . "/attachments.json");

  curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json","If-Modified-Since: " . gmdate('D, d M Y H:i:s T', $last_run)));
  curl_setopt($handle, CURLOPT_USERPWD, $username . ":" . $password);
  curl_setopt($handle, CURLOPT_USERAGENT, "HI-SEAS (" . $basecamp_useragent_email . ")");
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);

  $result = curl_exec($handle);
  $info = curl_getinfo($handle);

  curl_close($handle);

  if ($info["http_code"] == 200) {
    $result = json_decode($result, true);
    return $result;
  } elseif ($info["http_code"] == 304) { // The attachments.json has not been modified since last run, save run time & exit.
    save_last_run_time();
    exit();
  } else {
    throw new \Exception("Unable to retrieve list of attachments from Basecamp", $info["http_code"]);
  }
}

function save_last_run_time() {
  file_put_contents(dirname(__FILE__) . "/last_run", time());
}

function get_last_run_time() {
  if (!file_exists(dirname(__FILE__) . "/last_run")) {
    save_last_run_time();
  }

  $time = intval(file_get_contents(dirname(__FILE__) . "/last_run"));

  if (is_null($time)) {
    $time = time();
    save_last_run_time();
  }

  return $time;
}

function download_from_basecamp($file_url, $file_name, $username, $password, $user_agent_email) {
  try {
    $handle = curl_init($file_url);
    $file = fopen(dirname(__FILE__) . "/tmp/" . $file_name, "x");

    curl_setopt($handle, CURLOPT_HEADER, 0);
    curl_setopt($handle, CURLOPT_USERPWD, $username . ":" . $password);
    curl_setopt($handle, CURLOPT_USERAGENT, "HI-SEAS (" . $user_agent_email . ")");
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($handle, CURLOPT_BINARYTRANSFER, 1);
    curl_setopt($handle, CURLOPT_FILE , $file);

    $result = curl_exec($handle);
    $info = curl_getinfo($handle);

    curl_close($handle);
    fclose($file);
  } catch (Exception $e) {
    throw new \Exception("Unable to download attachment from Basecamp. " . $e->getMessage());
  }

  if ($info["http_code"] == 200) {
    return true;
  } else {
    return false;
  }
}

function upload_to_dropbox($client, $file_path, $file_name, $dropbox_path) {
  try {
    $size = filesize($file_path);

    $f = fopen($file_path, "rb");
    $result = $client->uploadFile($dropbox_path. $file_name, dbx\WriteMode::add(),
                                  $f, $size);
    fclose($f);
  } catch (Exception $e) {
    throw new \Exception("Unable to upload to Dropbox. " . $e->getMessage());
  }

  return $result;
}

function clear_temporary_directory() {
  $files = glob(dirname(__FILE__) . "/tmp/*");

  foreach($files as $file) {
    if(is_file($file)) { unlink($file); }
  }
}

// ============================================================================
// ============================================================================
// ============================================================================
// ============================================================================
// ============================================================================

set_exception_handler('exception_handler');

// Retrieve last run time of the Basecamp Sync Client
$last_run = get_last_run_time();
if ($last_run > time()) {
  if ($last_run == "4102401599") { // If an error has been previously detected, exit
    echo "Please check error log and adjust last_run time\r\n";
  }
  exit();
} elseif ($last_run == 0) { // If this is first run, update the last_run time to now
  save_last_run_time();
  $last_run = get_last_run_time();
}

// Retrieve application settings
$settings = loadSettings();
$crew_ids = explode(',', $settings->basecamp_crew_ids);

// Clear any existing files in the temporary Directory
clear_temporary_directory();

// Load Dropbox API keys
try {
  $appInfo = dbx\AppInfo::loadFromJsonFile(dirname(__FILE__) . "/dropbox-app-info.json");
} catch (dbx\AppInfoLoadException $ex) {
  throw new \Exception("Unable to load Dropbox Application info");
}

// Create Dropbox client
$dbxClient = new dbx\Client($settings->dropbox_access_token, "HI-SEAS - Basecamp Sync");

// Retrieve latest list of attachments on Basecamp project
$attachmentJsonArr = get_attachments_list($settings->basecamp_username, $settings->basecamp_password, $settings->basecamp_id,
                                          $settings->basecamp_project_id, $settings->basecamp_useragent_email, $last_run);

// Save the last run time of the Basecamp Sync Client immediately after retrieving latest attachments list
save_last_run_time();

// Loop through attachments JSON array, check if attachment has been created after the last run time and if so
// download from Basecamp and upload to Dropbox
foreach($attachmentJsonArr as $attachmentArr){
  if (in_array($attachmentArr['creator']['id'], $crew_ids)) {
    continue; // Skip the attachment if it was originally uploaded by a member of the crew
  }

  if ($last_run < strtotime($attachmentArr['created_at'])) {
    $basecamp_result = download_from_basecamp($attachmentArr['url'], $attachmentArr['name'], $settings->basecamp_username,
                                              $settings->basecamp_password, $settings->basecamp_useragent_email);

    if ($basecamp_result) {
      $dropbox_result = upload_to_dropbox($dbxClient, dirname(__FILE__) . "/tmp/" . $attachmentArr['name'],
                                          $attachmentArr['name'], $settings->dropbox_path);

      file_put_contents(dirname(__FILE__) . "/log.txt", date("c", time()) . ": " . $attachmentArr['name'] . "\r\n", FILE_APPEND);
    } else {
      throw new \Exception("Unable to download " . $attachmentArr['name'] . " from Basecamp");
    }
  }
}
?>
