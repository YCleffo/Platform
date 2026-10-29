<?php
declare(strict_types=1);
// Local application only: no host ports, provider traffic or response bodies in logs.
$port=$argv[1]??'';
if (!in_array($port,['80','8000'],true)) throw new RuntimeException('Unexpected rehearsal HTTP port');
$checks=[];
foreach (['/health/live','/health/ready'] as $path) {
    $passed=false;
    for ($attempt=0;$attempt<30;$attempt++) {
        $context=stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true]]);
        $body=@file_get_contents('http://127.0.0.1:'.$port.$path,false,$context);
        $status=$http_response_header[0]??'';
        $data=is_string($body)?json_decode($body,true):null;
        if (preg_match('~^HTTP/\S+ 200\b~',$status) && is_array($data) && ($data['status']??null)==='ok') {$passed=true;break;}
        usleep(200000);
    }
    if (!$passed) throw new RuntimeException('Rehearsal HTTP readiness failed: '.$path);
    $checks[]=['path'=>$path,'http_status'=>200,'status'=>'ok'];
}
echo json_encode(['status'=>'PASS','checks'=>$checks]),"\n";
