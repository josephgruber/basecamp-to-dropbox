<?php
require_once "Mail.php";
require_once dirname(__FILE__) . "/lib/Dropbox/autoload.php";
use \Dropbox as dbx;

function exception_handler($ex) {
  $error_msg = "An Error Has Been Detected!\r\nLine: " . $ex->getLine() . "\r\nError Code: "
                . $ex->getCode() . "\r\nError Message: " . $ex->getMessage() . "\r\n";

  echo $error_msg;

  $from = "<INSERT_FROM_EMAIL_ADDRESS>";
  $to = "<INSERT_TO_EMAIL_ADDRESS>";
  $subject = "HI-SEAS Basecamp Sync Error";
  $body = $error_msg;
  $headers = array("From" => $from, "To" => $to, "Subject" => $subject );
  $smtp = Mail::factory("smtp", array("host" => "ssl://smtp.gmail.com", "port" => "465", "auth" => true,
                        "username" => "INSERT_GOOGLE_EMAIL_ADDRESS", "password" => "INSERT_GOOGLE_PASSWORD"));
  $mail = $smtp->send($to, $headers, $body);

  file_put_contents(dirname(__FILE__) . "/last_run", 4102401599); // Set last run to Dec 31st, 2009 11:59:59 PM to prevent future runs
}

if (isset($argv[1])) {
  if ($argv[1] == "debug") {
    error_reporting(E_ALL);
    ini_set('error_reporting', E_ALL);
    ini_set("display_errors", 1);
  }
}

ini_set("max_execution_time", 540); // Set to 9 minutes timeout due to 10 min cycle
date_default_timezone_set('UTC');
set_exception_handler('exception_handler');

if (PHP_SAPI !== "cli") {
  throw new \Exception("This program was meant to be run from the command-line and not as a web app.  Bad value for
                        PHP_SAPI.  Expected \"cli\", given \"".PHP_SAPI."\".");
}

function loadAuth($path) {
  if (!file_exists($path)) {
    throw new \Exception("The API and user auth file was not found at " . $path);
  }

  $str = file_get_contents($path);
  $jsonArr = json_decode($str);

  if (is_null($jsonArr)) {
    throw new \Exception("The API and user auth file at " . $path . " was empty or not in JSON format");
  }

  return $jsonArr;
}

function get_attachments_list($username, $password) {
  $handle = curl_init("https://basecamp.com/BASECAMP_ID/api/v1/projects/PROJECT_ID/attachments.json");

  curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
  curl_setopt($handle, CURLOPT_USERPWD, $username . ":" . $password);
  curl_setopt($handle, CURLOPT_USERAGENT, "HI-SEAS (INSERT_EMAIL_ADDRESS)");
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);

  $result = curl_exec($handle);
  $info = curl_getinfo($handle);

  curl_close($handle);

  if ($info["http_code"] == 200) {
    return $result;
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

function download_from_basecamp($file_url, $file_name, $username, $password) {
  try {
    $handle = curl_init($file_url);
    $file = fopen(dirname(__FILE__) . "/tmp/" . $file_name, "x");

    curl_setopt($handle, CURLOPT_HEADER, 0);
    curl_setopt($handle, CURLOPT_USERPWD, $username . ":" . $password);
    curl_setopt($handle, CURLOPT_USERAGENT, "HI-SEAS (INSERT_EMAIL_ADDRESS)");
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

function upload_to_dropbox($client, $file_path, $file_name) {
  try {
    $size = filesize($file_path);

    $f = fopen($file_path, "rb");
    $result = $client->uploadFile("DROPBOX_PATH". $file_name, dbx\WriteMode::add(),
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

// Retrieve last run time of the Basecamp Sync Client
$last_run = get_last_run_time();
if ($last_run > time()) { exit(); }

// Clear any existing files in the temporary Directory
clear_temporary_directory();

// Load API keys, passwords, and authorization files
$auth = loadAuth(dirname(__FILE__) . "/user-auth.json");
try {
  $appInfo = dbx\AppInfo::loadFromJsonFile(dirname(__FILE__) . "/dropbox-app-info.json");
} catch (dbx\AppInfoLoadException $ex) {
  throw new \Exception("Unable to load Dropbox Application info");
}

// Create Dropbox client
$dbxClient = new dbx\Client($auth->dropbox_access_token, "HI-SEAS - Basecamp Sync");

// Retrieve latest list of attachments on Basecamp project and decode JSON
// into an array
$attachmentJsonArr = json_decode(get_attachments_list($auth->basecamp_username, $auth->basecamp_password), true);

// Save the last run time of the Basecamp Sync Client immediately after retrieving latest attachments list
save_last_run_time();

// Loop through attachments JSON array, check if attachment has been created after the last run time and if so
// download from Basecamp and upload to Dropbox
foreach($attachmentJsonArr as $attachmentArr){
  if ($last_run < strtotime($attachmentArr['created_at'])) {
    $basecamp_result = download_from_basecamp($attachmentArr['url'], $attachmentArr['name'], $auth->basecamp_username,
                                              $auth->basecamp_password);

    if ($basecamp_result) {
      $dropbox_result = upload_to_dropbox($dbxClient, dirname(__FILE__) . "/tmp/" . $attachmentArr['name'],
                                          $attachmentArr['name']);

      file_put_contents(dirname(__FILE__) . "/log.txt", date("c", time()) . ": " . $attachmentArr['name'] . "\r\n", FILE_APPEND);
    } else {
      throw new \Exception("Unable to download " . $attachmentArr['name'] . " from Basecamp");
    }
  }
}
?>
