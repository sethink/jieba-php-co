<?php

ini_set('memory_limit', '-1');

require 'src/JiebaCo.php';
require 'src/class/Jieba.php';
require 'src/class/Finalseg.php';
require 'src/class/Posseg.php';
require 'src/vendor/multi-array/MultiArray.php';


echo sprintf("[%d]: %s\n", 1, getMemory());
//$r = \Sethink\JiebaCo\JiebaCo::getPossegInstance()::cut("这是一段测试");
$r = \Sethink\JiebaCo\JiebaCo::getInstance()::cut("这是一段测试");
var_dump($r);
echo sprintf("[%d]: %s\n", 9, getMemory());

function getMemory($memory = 0,$index=0){
    $index++;
    if ($memory == 0){
        $memory = memory_get_usage();
    }

    $memory = round($memory / 1024,3);

    if ($memory >= 1000){
        return getMemory($memory,$index);
    }

    if ($index == 1){
        $unit = 'KB';
    }elseif ($index == 2){
        $unit = 'MB';
    }elseif($index == 3){
        $unit = 'GB';
    }else{
        $unit = '';
    }
    return $memory.' '.$unit;
}