<?php

$DEBUG = false;

// This is a super-hacky proxy for sentry events
// We will only run this for a few months while we move over to new sentry
// infrastructure. After that time, we'll probably shut this down and ignore
// errors from old versions of Keyman, Bloom

if($DEBUG) {
  $DEBUG_INT = random_int(0,1000);
  $fp = fopen("./entry.$DEBUG_INT.txt", "w");
  fputs($fp, print_r($_GET, true) . "\n");
  fputs($fp, "\n");
  fclose($fp);
}

$projectMap =
[
  23 => [ "slug" => "bloom-desktop",        "ioId" => 5983534, "ingest" => 'o1009031.ingest.sentry.io' ],
  18 => [ "slug" => "bloomlibrary",         "ioId" => 5983533, "ingest" => 'o1009031.ingest.sentry.io' ],
  21 => [ "slug" => "fv-android",           "ioId" => 5983532, "ingest" => 'o1005580.ingest.sentry.io' ],
  19 => [ "slug" => "kab-android",          "ioId" => 5983531, "ingest" => 'o1005580.ingest.sentry.io' ],
  17 => [ "slug" => "s-keyman-com",         "ioId" => 5983530, "ingest" => 'o1005580.ingest.sentry.io' ],
  16 => [ "slug" => "downloads-keyman-com", "ioId" => 5983529, "ingest" => 'o1005580.ingest.sentry.io' ],
  15 => [ "slug" => "donate-keyman-com",    "ioId" => 5983528, "ingest" => 'o1005580.ingest.sentry.io' ],
  14 => [ "slug" => "developer-keyman-com", "ioId" => 5983527, "ingest" => 'o1005580.ingest.sentry.io' ],
  13 => [ "slug" => "status-keyman-com",    "ioId" => 5983526, "ingest" => 'o1005580.ingest.sentry.io' ],
  12 => [ "slug" => "keyman-linux",         "ioId" => 5983525, "ingest" => 'o1005580.ingest.sentry.io' ],
  11 => [ "slug" => "keyman-web",           "ioId" => 5983524, "ingest" => 'o1005580.ingest.sentry.io' ],
  10 => [ "slug" => "keymanweb-com",        "ioId" => 5983523, "ingest" => 'o1005580.ingest.sentry.io' ],
  9  => [ "slug" => "keyman-mac",           "ioId" => 5983522, "ingest" => 'o1005580.ingest.sentry.io' ],
  8  => [ "slug" => "keyman-ios",           "ioId" => 5983521, "ingest" => 'o1005580.ingest.sentry.io' ],
  7  => [ "slug" => "keyman-android",       "ioId" => 5983520, "ingest" => 'o1005580.ingest.sentry.io' ],
  6  => [ "slug" => "keyman-developer",     "ioId" => 5983519, "ingest" => 'o1005580.ingest.sentry.io' ],
  5  => [ "slug" => "keyman-windows",       "ioId" => 5983518, "ingest" => 'o1005580.ingest.sentry.io' ],
  4  => [ "slug" => "api-keyman-com",       "ioId" => 5983517, "ingest" => 'o1005580.ingest.sentry.io' ],
  3  => [ "slug" => "keyman-com",           "ioId" => 5983516, "ingest" => 'o1005580.ingest.sentry.io' ],
  2  => [ "slug" => "help-keyman-com",      "ioId" => 5983515, "ingest" => 'o1005580.ingest.sentry.io' ],
];

if(!isset($_GET['path']) || !isset($_GET['project'])) {
  header('400 invalid parameter', true, 400);
  exit;
}

$path = $_GET['path'];
$projectId = $_GET['project'];

if(!isset($projectMap[$projectId])) {
  header('400 invalid project', true, 400);
  exit;
}

$project = $projectMap[$projectId];

$data = @file_get_contents('php://input');

if($path == 'envelope/') {
  // Rewrite the DSN endpoint in the envelope
  // DSN project_keys have not changed between sentry.keyman.com and sentry.io
  $data = str_replace(
    "@sentry.keyman.com.local/$projectId",
    "@{$project['ingest']}/{$project['ioId']}",
    $data);
}

$headersin = getallheaders();
$headers = [];
foreach($headersin as $name => $value) {
  if($name == 'Content-Length')
    array_push($headers, "$name: ".strlen($data));
  else if ($name == 'Host')
    array_push($headers, "$name: {$project['ingest']}");
  else if ($name == 'X-Original-Url') {
    // skip X-Original-Url
  }
  else if ($name == 'Connection') {
    // skip Connection: Keep-Alive
  }
  else
    array_push($headers, "$name: $value");
}

if($DEBUG) {
  $fp = fopen("./request.$DEBUG_INT.txt", 'w');
  fputs($fp, $_GET['path'] . "\n");
  fputs($fp, join(', ', $headers));
  fputs($fp, "\n");
  fwrite($fp, $data);
  fputs($fp, "\n\n");
  fclose($fp);
}

$params = '';
foreach($_GET as $key => $value) {
  if($key == 'path' || $key == 'project') continue;
  if(empty($params)) $params = '?'; else $params .= '&';
  $params .= urlencode($key) . '=' . urlencode($value);
}

$curl = curl_init("https://{$project['ingest']}/api/{$project['ioId']}/$path$params");
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

$responseHeaders = [];
// this function is called by curl for each header received
curl_setopt($curl, CURLOPT_HEADERFUNCTION,
  function($curl, $header) use (&$responseHeaders)
  {
    $len = strlen($header);
    $header = explode(':', $header, 2);
    if (count($header) < 2) // ignore invalid headers
      return $len;

    $responseHeaders[strtolower(trim($header[0]))][] = trim($header[1]);

    return $len;
  }
);

$response = curl_exec($curl);
$error = curl_error($curl);
$responseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

if(!empty($error)) {
  header('500 '.$error);
  exit;
}

$httpcodes = [
  //[Informational 1xx]
100=>"Continue",
101=>"Switching Protocols",

  //[Successful 2xx]
200=>"OK",
201=>"Created",
202=>"Accepted",
203=>"Non-Authoritative Information",
204=>"No Content",
205=>"Reset Content",
206=>"Partial Content",

  //[Redirection 3xx]
300=>"Multiple Choices",
301=>"Moved Permanently",
302=>"Found",
303=>"See Other",
304=>"Not Modified",
305=>"Use Proxy",
306=>"(Unused)",
307=>"Temporary Redirect",

  //[Client Error 4xx]
400=>"Bad Request",
401=>"Unauthorized",
402=>"Payment Required",
403=>"Forbidden",
404=>"Not Found",
405=>"Method Not Allowed",
406=>"Not Acceptable",
407=>"Proxy Authentication Required",
408=>"Request Timeout",
409=>"Conflict",
410=>"Gone",
411=>"Length Required",
412=>"Precondition Failed",
413=>"Request Entity Too Large",
414=>"Request-URI Too Long",
415=>"Unsupported Media Type",
416=>"Requested Range Not Satisfiable",
417=>"Expectation Failed",

//[Server Error 5xx]
500=>"Internal Server Error",
501=>"Not Implemented",
502=>"Bad Gateway",
503=>"Service Unavailable",
504=>"Gateway Timeout",
505=>"HTTP Version Not Supported"
];

if(!array_key_exists($responseCode, $httpcodes)) {
  header('500 Unknown response '.$responseCode);
  exit;
}

header("HTTP/1.1 $responseCode {$httpcodes[$responseCode]}", true, $responseCode);

$ContentType = empty($responseHeaders['content-type']) ? 'text/plain' : $responseHeaders['content-type'][0];
header("content-type: $ContentType", true);
header("Access-Control-Allow-Origin: *");

if($DEBUG) {
  //var_dump($responseHeaders);
  //echo $ContentType;

  $fp = fopen("./response.$DEBUG_INT.txt", 'w');
  fputs($fp, $_REQUEST['path'] . "\n");
  fputs($fp, $error . "\n");
  fputs($fp, print_r($responseHeaders, true)."\n\n");
  fwrite($fp, $response);
  fputs($fp, "\n\n");
  fclose($fp);
}

echo $response;
