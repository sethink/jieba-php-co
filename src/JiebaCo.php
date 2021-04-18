<?php
declare(strict_types=1);

namespace Sethink\JiebaCo;

use Sethink\Jieba\Jieba;
use Sethink\Jieba\Posseg;

class JiebaCo
{
    protected static $fileList = [];

    /**
     * @param array $options
     * @return Jieba
     */
    public static function getInstance(array $options = [])
    {
        $jieba = new Jieba();
        $jieba::init($options);
        return $jieba;
    }

    /**
     * @param array $option
     * @return Posseg
     */
    public static function getPossegInstance(array $option = [])
    {
        $jieba = new Jieba();
        $jieba::init($option);
        $posseg = new Posseg();
        $posseg::init($jieba);
        return $posseg;
    }

    public static function getFileContent($file, $type = 'txt')
    {
        if (isset(self::$fileList[$file]) && !empty(self::$fileList[$file])) {
            return self::$fileList[$file];
        }

        $content = '';
        if (file_exists($file)) {
            switch ($type) {
                case 'txt':
                    $content = [];
                    $handle = fopen($file, 'r');
                    while (($line = fgets($handle)) !== false) {
                        $explodeLine = explode(" ", trim($line));
                        $content[] = [
                            0 => isset($explodeLine[0]) ? $explodeLine[0] : '',
                            1 => isset($explodeLine[1]) ? $explodeLine[1] : '',
                            2 => isset($explodeLine[2]) ? $explodeLine[2] : ''
                        ];
                    }
                    fclose($handle);
                    break;
                case 'json':
                    $content = file_get_contents($file);
                    $content = json_decode($content, true);
                    break;
                default:
                    $content = file_get_contents($file);
            }
        }
        self::$fileList[$file] = $content;

        return $content;
    }


}