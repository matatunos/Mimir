<?php
function fetch($url, $hostHeader=null, $follow=true){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if($hostHeader){
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Host: $hostHeader"]);
    }
    $res = curl_exec($ch);
    if($res === false){
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return ['error'=>$err,'info'=>$info];
    }
    $info = curl_getinfo($ch);
    curl_close($ch);
    // split headers/body
    $header_text = substr($res, 0, $info['header_size']);
    $body = substr($res, $info['header_size']);
    $headers = [];
    foreach (explode("\r\n", $header_text) as $line) {
        if (strpos($line, ':') !== false) {
            list($k,$v) = explode(':', $line, 2);
            $headers[trim($k)] = trim($v);
        }
    }
    return ['info'=>$info,'headers'=>$headers,'body'=>substr($body,0,1000)];
}

$targets = [
    ['url'=>'http://192.168.1.24/','host'=>'doc.favala.es','label'=>'proxy-http'],
    ['url'=>'http://192.168.1.24/login.php','host'=>'doc.favala.es','label'=>'proxy-login'],
    ['url'=>'http://192.168.1.16/','host'=>'doc.favala.es','label'=>'app-http'],
    ['url'=>'http://192.168.1.16/login.php','host'=>'doc.favala.es','label'=>'app-login'],
    ['url'=>'https://doc.favala.es/','host'=>null,'label'=>'public-https']
];

foreach($targets as $t){
    echo "---- {$t['label']} ({$t['url']}) ----\n";
    $res = fetch($t['url'],$t['host']);
    if(isset($res['error'])){
        echo "ERROR: " . $res['error'] . "\n";
        if(isset($res['info'])){ print_r($res['info']); }
        echo "\n";
        continue;
    }
    $info = $res['info'];
    echo "HTTP_CODE: " . ($info['http_code'] ?? 'N/A') . "\n";
    echo "Content-Type: " . ($res['headers']['Content-Type'] ?? '') . "\n";
    echo "Server: " . ($res['headers']['Server'] ?? '') . "\n";
    echo "Location: " . ($res['headers']['Location'] ?? '') . "\n";
    if(isset($res['headers']['Set-Cookie'])){
        echo "Set-Cookie: " . $res['headers']['Set-Cookie'] . "\n";
    }
    echo "X-Forwarded-For: " . ($res['headers']['X-Forwarded-For'] ?? '') . "\n";
    echo "X-Forwarded-Proto: " . ($res['headers']['X-Forwarded-Proto'] ?? '') . "\n";
    echo "Body (truncated):\n" . $res['body'] . "\n";
    echo "\n";
}

echo "Script complete.\n";
