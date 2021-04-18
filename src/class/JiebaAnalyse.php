<?php

namespace Sethink\Jieba;

use Sethink\JiebaCo\JiebaCo;

class JiebaAnalyse
{

    public static $idf_freq = array();
    public static $max_idf = 0;
    public static $median_idf = 0;
    public static $stop_words = [
        "the", "of", "is", "and", "to", "in", "that", "we",
        "for", "an", "are", "by", "be", "as", "on", "with",
        "can", "if", "from", "which", "you", "it", "this",
        "then", "at", "have", "all", "not", "one", "has",
        "or", "that"
    ];

    /** @var Jieba $jieba */
    protected static $posseg;


    /**
     * @param Posseg $posseg
     */
    public static function init(Posseg $posseg)
    {
        self::$posseg = $posseg;

        $content = JiebaCo::getFileContent(dirname(__DIR__) . '/dict/idf.txt', 'txt');
        foreach ($content as $line) {
            $word = $line[0];
            $freq = $line[1];
            $freq = (float)$freq;
            self::$idf_freq[$word] = $freq;
        }

        asort(self::$idf_freq);
        $keys = array_keys(self::$idf_freq);
        $middle_key = $keys[count(self::$idf_freq) / 2];
        self::$max_idf = max(self::$idf_freq);
        self::$median_idf = self::$idf_freq[$middle_key];
    }


    /**
     * @param $stop_words_path
     */
    public static function setStopWords($stop_words_path)
    {
        $content = JiebaCo::getFileContent($stop_words_path, 'txt');
        foreach ($content as $line) {
            $stop_word = strtolower(trim($line));
            if (!in_array($stop_word, self::$stop_words)) {
                self::$stop_words[] = $stop_word;
            }
        }
    }


    /**
     * @param $content
     * @param int $top_k
     * @param array $options
     * @return array
     */
    public static function extractTags($content, $top_k = 20, $options = array())
    {

        $defaults = array(
            'mode' => 'default'
        );
        $options = array_merge($defaults, $options);

        $tags = array();

        if (isset($options['allowPOS']) && is_array($options['allowPOS']) && !empty($options['allowPOS'])) {
            $wordsPos = self::$posseg::cut($content);

            $words = array();
            foreach ($wordsPos as $key => $word) {
                if (in_array($word['tag'], $options['allowPOS'])) {
                    $words[] = $word['word'];
                }
            }
        } else {
            $words = self::$posseg::$jieba::cut($content);
        }

        $freq = array();
        $total = 0.0;

        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w, 'UTF-8') < 2) {
                continue;
            }

            if (in_array(strtolower($w), self::$stop_words)) {
                continue;
            }
            if (isset($freq[$w])) {
                $freq[$w] = $freq[$w] + 1.0;
            } else {
                $freq[$w] = 0.0 + 1.0;
            }
            $total = $total + 1.0;
        }

        foreach ($freq as $k => $v) {
            $freq[$k] = $v / $total;
        }
        $tf_idf_list = array();

        foreach ($freq as $k => $v) {
            if (isset(self::$idf_freq[$k])) {
                $idf_freq = self::$idf_freq[$k];
            } else {
                $idf_freq = self::$median_idf;
            }
            $tf_idf_list[$k] = $v * $idf_freq;
        }

        arsort($tf_idf_list);
        $tags = array_slice($tf_idf_list, 0, $top_k, true);

        return $tags;
    }
}
