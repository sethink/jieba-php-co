<?php
declare(strict_types=1);

namespace Sethink\Jieba;

use Sethink\JiebaCo\JiebaCo;

class Finalseg
{
    protected static $prob_start = array();
    protected static $prob_trans = array();
    protected static $prob_emit = array();


    public static function init()
    {
        self::$prob_start = self::loadModel(dirname(__DIR__).'/model/prob_start.json');
        self::$prob_trans = self::loadModel(dirname(__DIR__).'/model/prob_trans.json');
        self::$prob_emit = self::loadModel(dirname(__DIR__).'/model/prob_emit.json');
    }


    private static function loadModel($f_name)
    {
        return JiebaCo::getFileContent($f_name,'json');
    }

    private static function viterbi($sentence)
    {
        $obs = $sentence;
        $states = array('B', 'M', 'E', 'S');
        $V = array();
        $V[0] = array();
        $path = array();

        foreach ($states as $key => $state) {
            $y = $state;
            $c = mb_substr($obs, 0, 1, 'UTF-8');
            $prob_emit = 0.0;
            if (isset(self::$prob_emit[$y][$c])) {
                $prob_emit = self::$prob_emit[$y][$c];
            } else {
                $prob_emit = MIN_FLOAT;
            }
            $V[0][$y] = self::$prob_start[$y] + $prob_emit;
            $path[$y] = $y;
        }

        for ($t = 1; $t < mb_strlen($obs, 'UTF-8'); $t++) {
            $c = mb_substr($obs, $t, 1, 'UTF-8');
            $V[$t] = array();
            $newpath = array();
            foreach ($states as $key => $state) {
                $y = $state;
                $temp_prob_array = array();
                foreach ($states as $key => $state0) {
                    $y0 = $state0;
                    $prob_trans = 0.0;
                    if (isset(self::$prob_trans[$y0][$y])) {
                        $prob_trans = self::$prob_trans[$y0][$y];
                    } else {
                        $prob_trans = MIN_FLOAT;
                    }
                    $prob_emit = 0.0;
                    if (isset(self::$prob_emit[$y][$c])) {
                        $prob_emit = self::$prob_emit[$y][$c];
                    } else {
                        $prob_emit = MIN_FLOAT;
                    }
                    $temp_prob_array[$y0] = $V[$t - 1][$y0] + $prob_trans + $prob_emit;
                }
                arsort($temp_prob_array);
                $max_prob = reset($temp_prob_array);
                $max_key = key($temp_prob_array);
                $V[$t][$y] = $max_prob;
                if (is_array($path[$max_key])) {
                    $newpath[$y] = array();
                    foreach ($path[$max_key] as $key => $path_value) {
                        $newpath[$y][] = $path_value;
                    }
                    $newpath[$y][] = $y;
                } else {
                    $newpath[$y] = array($path[$max_key], $y);
                }
            }
            $path = $newpath;
        }

        $es_states = array('E', 'S');
        $temp_prob_array = array();
        $len = mb_strlen($obs, 'UTF-8');
        foreach ($es_states as $key => $state) {
            $y = $state;
            $temp_prob_array[$y] = $V[$len - 1][$y];
        }
        arsort($temp_prob_array);
        $prob = reset($temp_prob_array);
        $state = key($temp_prob_array);

        return array("prob" => $prob, "pos_list" => $path[$state]);
    }


    private static function __cut($sentence)
    {
        $words = array();

        $viterbi_array = self::viterbi($sentence);
        $prob = $viterbi_array['prob'];
        $pos_list = $viterbi_array['pos_list'];

        $begin = 0;
        $next = 0;
        $len = mb_strlen($sentence, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($sentence, $i, 1, 'UTF-8');
            $pos = $pos_list[$i];
            if ($pos == 'B') {
                $begin = $i;
            } elseif ($pos == 'E') {
                $words[] = mb_substr($sentence, $begin, (($i + 1) - $begin), 'UTF-8');
                $next = $i + 1;
            } elseif ($pos == 'S') {
                $words[] = $char;
                $next = $i + 1;
            }
        }
        if ($next < $len) {
            $words[] = mb_substr($sentence, $next, null, 'UTF-8');
        }
        return $words;
    }


    public static function cut($sentence)
    {
        $seg_list = array();

        $re_cjk_pattern = '([\x{3040}-\x{309F}]+)|([\x{30A0}-\x{30FF}]+)|([\x{4E00}-\x{9FA5}]+)|([\x{AC00}-\x{D7AF}]+)';
        $re_skip_pattern = '([a-zA-Z0-9+#&=\._\r\n]+)';
        preg_match_all(
            '/(' . $re_cjk_pattern . '|' . $re_skip_pattern . ')/u',
            $sentence,
            $matches,
            PREG_PATTERN_ORDER
        );
        $blocks = $matches[0];

        foreach ($blocks as $blk) {
            if (preg_match('/' . $re_cjk_pattern . '/u', $blk)) {
                $words = self::__cut($blk);
                foreach ($words as $word) {
                    $seg_list[] = $word;
                }
            } else {
                $seg_list[] = $blk;
            }
        }
        return $seg_list;
    }
}
