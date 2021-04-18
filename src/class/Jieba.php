<?php
declare(strict_types=1);

namespace Sethink\Jieba;

use Sethink\JiebaCo\JiebaCo;
use Sethink\MultiArray\MultiArray;

define("MIN_FLOAT", -3.14e+100);


class Jieba
{
    protected static $total = 0.0;
    protected static $trie = array();
    public static $FREQ = array();
    protected static $original_freq = array();
    protected static $min_freq = 0.0;
    public static $route = array();
    public static $dictname = '';
    public static $user_dictname = array();
    protected static $cjk_all = false;
    protected static $dag_cache = array();

    /** @var Finalseg $Finalseg */
    protected static $Finalseg = null;

    public static function init($options = array())
    {
        $defaults = [
            'mode' => 'default',
            'dict' => 'normal',
            'cjk' => 'chinese'
        ];

        $options = array_merge($defaults, $options);

        if ($options['dict'] == 'small') {
            $f_name = "/dict/dict.small.txt";
        } elseif ($options['dict'] == 'big') {
            $f_name = "/dict/dict.big.txt";
        } else {
            $f_name = "/dict/dict.txt";
        }

        $f_name = dirname(__DIR__) . $f_name;
        self::$dictname = $f_name;

        if ($options['cjk'] == 'all') {
            self::$cjk_all = true;
        } else {
            self::$cjk_all = false;
        }

        self::$dag_cache = array();
        self::$trie = Jieba::genTrie($f_name);

        self::__calcFreq();

        self::$Finalseg = new Finalseg();
        self::$Finalseg::init();
    }


    private static function __calcFreq()
    {
        foreach (self::$original_freq as $key => $value) {
            self::$FREQ[$key] = log($value / self::$total);
        }
        self::$min_freq = min(self::$FREQ);
    }


    public static function calc($sentence, $DAG)
    {
        $N = mb_strlen($sentence, 'UTF-8');
        self::$route = array();
        self::$route[$N] = array($N => 0.0);
        for ($i = ($N - 1); $i >= 0; $i--) {
            $candidates = array();
            foreach ($DAG[$i] as $x) {
                $w_c = mb_substr($sentence, $i, (($x + 1) - $i), 'UTF-8');
                $previous_freq = current(self::$route[$x + 1]);
                if (isset(self::$FREQ[$w_c])) {
                    $current_freq = (float)$previous_freq + self::$FREQ[$w_c];
                } else {
                    $current_freq = (float)$previous_freq + self::$min_freq;
                }
                $candidates[$x] = $current_freq;
            }
            arsort($candidates);
            $max_prob = reset($candidates);
            $max_key = key($candidates);
            self::$route[$i] = array($max_key => $max_prob);
        }

        return self::$route;
    }

    private static function genTrie($f_name)
    {
        self::$trie = new MultiArray(JiebaCo::getFileContent($f_name . '.json', 'json'));

        $content = JiebaCo::getFileContent($f_name, 'txt');
        foreach ($content as $line) {
            $word = $line[0];
            $freq = $line[1];
            $tag = $line[2];
            $freq = (float)$freq;
            if (isset(self::$original_freq[$word])) {
                self::$total -= self::$original_freq[$word];
            }
            self::$original_freq[$word] = $freq;
            self::$total += $freq;
        }

        return self::$trie;
    }

    /**
     * @param string $f_name 绝对路径
     * @return array
     */
    public static function loadUserDict(string $f_name)
    {
        self::$user_dictname[] = $f_name;

        $content = JiebaCo::getFileContent($f_name,'txt');

        foreach ($content as $line) {
            $word = $line[0];
            $freq = isset($line[1]) ? $line[1] : 1;
            $tag = isset($line[2]) ? $line[2] : null;
            $freq = (float)$freq;
            if (isset(self::$original_freq[$word])) {
                self::$total -= self::$original_freq[$word];
            }
            self::$original_freq[$word] = $freq;
            self::$total += $freq;
            $l = mb_strlen($word, 'UTF-8');
            $word_c = array();
            for ($i = 0; $i < $l; $i++) {
                $c = mb_substr($word, $i, 1, 'UTF-8');
                $word_c[] = $c;
            }
            $word_c_key = implode('.', $word_c);
            self::$trie->set($word_c_key, array("end" => ""));
        }

        self::__calcFreq();
        self::$dag_cache = array();

        return self::$trie;
    }

    /**
     * Static method addWord
     *
     * @param string $word
     * @param float $freq
     * @param string $tag
     *
     * @return array self::$trie
     */
    public static function addWord($word, $freq, $tag = '', $options = array())
    {
        if (isset(self::$original_freq[$word])) {
            self::$total -= self::$original_freq[$word];
        }
        self::$original_freq[$word] = $freq;
        self::$total += $freq;
        $l = mb_strlen($word, 'UTF-8');
        $word_c = array();
        for ($i = 0; $i < $l; $i++) {
            $c = mb_substr($word, $i, 1, 'UTF-8');
            $word_c[] = $c;
        }
        $word_c_key = implode('.', $word_c);
        self::$trie->set($word_c_key, array("end" => ""));
        self::__calcFreq();
        self::$dag_cache = array();
        return self::$trie;
    }

    /**
     * 返回原文起止位置
     * @param $sentence
     * @param bool[] $options
     * @return array
     */
    public static function tokenize($sentence, $options = array("HMM" => true))
    {
        $seg_list = self::cut($sentence, false, array("HMM" => $options["HMM"]));
        $tokenize_list = [];
        $start = 0;
        $end = 0;
        foreach ($seg_list as $seg) {
            $end = $start + mb_strlen($seg, 'UTF-8');
            $tokenize = [
                'word' => $seg,
                'start' => $start,
                'end' => $end
            ];
            $start = $end;
            $tokenize_list[] = $tokenize;
        }
        return $tokenize_list;
    }


    private static function __cutAll($sentence)
    {
        $words = array();

        $DAG = self::getDAG($sentence);
        $old_j = -1;

        foreach ($DAG as $k => $L) {
            if (count($L) == 1 && $k > $old_j) {
                $word = mb_substr($sentence, $k, (($L[0] - $k) + 1), 'UTF-8');
                $words[] = $word;
                $old_j = $L[0];
            } else {
                foreach ($L as $j) {
                    if ($j > $k) {
                        $word = mb_substr($sentence, $k, ($j - $k) + 1, 'UTF-8');
                        $words[] = $word;
                        $old_j = $j;
                    }
                }
            }
        }

        return $words;
    }

    public static function getDAG($sentence, $options = array())
    {
        $defaults = array(
            'mode' => 'default'
        );

        $options = array_merge($defaults, $options);

        $N = mb_strlen($sentence, 'UTF-8');
        $i = 0;
        $j = 0;
        $DAG = array();
        $word_c = array();

        while ($i < $N) {
            $c = mb_substr($sentence, $j, 1, 'UTF-8');
            if (count($word_c) == 0) {
                $next_word_key = $c;
            } else {
                $next_word_key = implode('.', $word_c) . '.' . $c;
            }

            if (isset(self::$dag_cache[$next_word_key])) {
                if (self::$dag_cache[$next_word_key]['exist']) {
                    $word_c[] = $c;
                    if (self::$dag_cache[$next_word_key]['end']) {
                        if (!isset($DAG[$i])) {
                            $DAG[$i] = array();
                        }
                        $DAG[$i][] = $j;
                    }
                    $j += 1;
                    if ($j >= $N) {
                        $word_c = array();
                        $i += 1;
                        $j = $i;
                    }
                } else {
                    $word_c = array();
                    $i += 1;
                    $j = $i;
                }
                continue;
            }

            if (self::$trie->exists($next_word_key)) {
                self::$dag_cache[$next_word_key] = array('exist' => true, 'end' => false);
                $word_c[] = $c;
                $next_word_key_value = self::$trie->get($next_word_key);
                if ($next_word_key_value == array("end" => "")
                    || isset($next_word_key_value["end"])
                    || isset($next_word_key_value[0]["end"])
                ) {
                    self::$dag_cache[$next_word_key]['end'] = true;
                    if (!isset($DAG[$i])) {
                        $DAG[$i] = array();
                    }
                    $DAG[$i][] = $j;
                }
                $j += 1;
                if ($j >= $N) {
                    $word_c = array();
                    $i += 1;
                    $j = $i;
                }
            } else {
                $word_c = array();
                $i += 1;
                $j = $i;
                self::$dag_cache[$next_word_key] = array('exist' => false);
            }
        }

        for ($i = 0; $i < $N; $i++) {
            if (!isset($DAG[$i])) {
                $DAG[$i] = array($i);
            }
        }

        return $DAG;
    }// end function getDAG


    private static function __cutDAG($sentence)
    {
        $words = array();

        $N = mb_strlen($sentence, 'UTF-8');
        $DAG = self::getDAG($sentence);

        self::calc($sentence, $DAG);

        $x = 0;
        $buf = '';

        while ($x < $N) {
            $current_route_keys = array_keys(self::$route[$x]);
            $y = $current_route_keys[0] + 1;
            $l_word = mb_substr($sentence, $x, ($y - $x), 'UTF-8');

            if (($y - $x) == 1) {
                $buf = $buf . $l_word;
            } else {
                if (mb_strlen($buf, 'UTF-8') > 0) {
                    if (mb_strlen($buf, 'UTF-8') == 1) {
                        $words[] = $buf;
                        $buf = '';
                    } else {
                        if (!isset(self::$FREQ[$buf])) {
                            $regognized = self::$Finalseg::cut($buf);
                            foreach ($regognized as $key => $word) {
                                $words[] = $word;
                            }
                        } else {
                            $elem_array = preg_split('//u', $buf, -1, PREG_SPLIT_NO_EMPTY);
                            foreach ($elem_array as $word) {
                                $words[] = $word;
                            }
                        }
                        $buf = '';
                    }
                }
                $words[] = $l_word;
            }
            $x = $y;
        }

        if (mb_strlen($buf, 'UTF-8') > 0) {
            if (mb_strlen($buf, 'UTF-8') == 1) {
                $words[] = $buf;
            } else {
                if (!isset(self::$FREQ[$buf])) {
                    $regognized = self::$Finalseg::cut($buf);

                    foreach ($regognized as $key => $word) {
                        $words[] = $word;
                    }
                } else {
                    $elem_array = preg_split('//u', $buf, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($elem_array as $word) {
                        $words[] = $word;
                    }
                }
            }
        }

        return $words;
    }

    private static function __cutDAGNoHMM($sentence)
    {
        $words = array();

        $N = mb_strlen($sentence, 'UTF-8');
        $DAG = self::getDAG($sentence);

        self::calc($sentence, $DAG);

        $x = 0;
        $buf = '';

        $re_eng_pattern = '[a-zA-Z+#]+';

        while ($x < $N) {
            $current_route_keys = array_keys(self::$route[$x]);
            $y = $current_route_keys[0] + 1;
            $l_word = mb_substr($sentence, $x, ($y - $x), 'UTF-8');

            if (preg_match('/' . $re_eng_pattern . '/u', $l_word)) {
                $buf = $buf . $l_word;
                $x = $y;
            } else {
                if (mb_strlen($buf, 'UTF-8') > 0) {
                    $words[] = $buf;
                    $buf = '';
                }
                $words[] = $l_word;
                $x = $y;
            }
        }

        if (mb_strlen($buf, 'UTF-8') > 0) {
            $words[] = $buf;
            $buf = '';
        }

        return $words;
    }

    /**
     * Static method cut
     *
     * @param string $sentence # input sentence
     * @param boolean $cut_all # cut_all or not
     * @param array $options # other options
     *
     * @return array $seg_list
     */
    public static function cut($sentence, $cut_all = false, $options = array("HMM" => true))
    {
        $defaults = array(
            'mode' => 'default'
        );

        $options = array_merge($defaults, $options);

        $seg_list = array();

        $re_han_pattern = '([\x{4E00}-\x{9FA5}]+)';
        $re_han_with_ascii_pattern = '([\x{4E00}-\x{9FA5}a-zA-Z0-9+#&=\._]+)';
        $re_kanjikana_pattern = '([\x{3040}-\x{309F}\x{4E00}-\x{9FA5}]+)';
        $re_katakana_pattern = '([\x{30A0}-\x{30FF}]+)';
        $re_hangul_pattern = '([\x{AC00}-\x{D7AF}]+)';
        $re_ascii_pattern = '([a-zA-Z0-9+#&=\._\r\n]+)';
        $re_skip_pattern = '(\s+)';
        if ($cut_all) {
            $re_skip_pattern = '([a-zA-Z0-9+#&=\._\r\n]+)';
        }
        $re_punctuation_pattern = '([\x{ff5e}\x{ff01}\x{ff08}\x{ff09}\x{300e}' .
            '\x{300c}\x{300d}\x{300f}\x{3001}\x{ff1a}\x{ff1b}' .
            '\x{ff0c}\x{ff1f}\x{3002}]+)';

        if (self::$cjk_all) {
            $filter_pattern = $re_kanjikana_pattern .
                '|' . $re_katakana_pattern .
                '|' . $re_hangul_pattern;
        } else {
            $filter_pattern = $re_han_with_ascii_pattern;
        }

        preg_match_all(
            '/(' . $filter_pattern . '|' . $re_ascii_pattern . '|' . $re_punctuation_pattern . ')/u',
            $sentence,
            $matches,
            PREG_PATTERN_ORDER
        );
        $blocks = $matches[0];

        foreach ($blocks as $blk) {
            if (mb_strlen($blk, 'UTF-8') == 0) {
                continue;
            }
            if (self::$cjk_all) {
                // skip korean
                $filter_pattern = $re_kanjikana_pattern . '|' . $re_katakana_pattern;
            } else {
                $filter_pattern = $re_han_with_ascii_pattern;
            }

            if (preg_match('/' . $filter_pattern . '/u', $blk)) {
                if ($cut_all) {
                    $words = Jieba::__cutAll($blk);
                } else {
                    if ($options['HMM']) {
                        $words = Jieba::__cutDAG($blk);
                    } else {
                        $words = Jieba::__cutDAGNoHMM($blk);
                    }
                }

                foreach ($words as $word) {
                    $seg_list[] = $word;
                }
            } elseif (preg_match('/' . $re_skip_pattern . '/u', $blk)) {
                preg_match_all(
                    '/(' . $re_skip_pattern . ')/u',
                    $blk,
                    $tmp,
                    PREG_PATTERN_ORDER
                );
                $tmp = $tmp[0];
                foreach ($tmp as $x) {
                    if (preg_match('/' . $re_skip_pattern . '/u', $x)) {
                        if (str_replace(' ', '', $x) != '') {
                            $seg_list[] = $x;
                        }
                    } else {
                        if (!$cut_all) {
                            $xx_array = preg_split('//u', $x, -1, PREG_SPLIT_NO_EMPTY);
                            foreach ($xx_array as $xx) {
                                $seg_list[] = $xx;
                            }
                        } else {
                            $seg_list[] = $x;
                        }
                    }
                }
            } elseif (preg_match('/' . $re_punctuation_pattern . '/u', $blk)) {
                $seg_list[] = $blk;
            }
        }

        return $seg_list;
    }

    /**
     * Static method cutForSearch
     *
     * @param string $sentence # input sentence
     * @param array $options # other options
     *
     * @return array $seg_list
     */
    public static function cutForSearch($sentence, $options = array("HMM" => true))
    {
        $defaults = array(
            'mode' => 'default'
        );

        $options = array_merge($defaults, $options);

        $seg_list = array();

        $cut_seg_list = Jieba::cut($sentence, false, array("HMM" => $options["HMM"]));

        foreach ($cut_seg_list as $w) {
            $len = mb_strlen($w, 'UTF-8');

            if ($len > 2) {
                for ($i = 0; $i < ($len - 1); $i++) {
                    $gram2 = mb_substr($w, $i, 2, 'UTF-8');

                    if (isset(self::$FREQ[$gram2])) {
                        $seg_list[] = $gram2;
                    }
                }
            }

            if (mb_strlen($w, 'UTF-8') > 3) {
                for ($i = 0; $i < ($len - 2); $i++) {
                    $gram3 = mb_substr($w, $i, 3, 'UTF-8');

                    if (isset(self::$FREQ[$gram3])) {
                        $seg_list[] = $gram3;
                    }
                }
            }

            $seg_list[] = $w;
        }

        return $seg_list;
    }
}
