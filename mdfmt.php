<?php
/*
 * 格式化markdown表格的工具, 支持utf-8, 支持任意可自定义外部命令的编辑器
 * 
 * @link https://github.com/zh-five/MarkdownFormat
 */

if (count($argv) != 4) {
    echo $argv[0], "<要格式化的markdown文件> <汉字数> <等宽空格数>\n";
    exit;
}
$file = $argv[1]; //md文件
$w_cn = $argv[2]; //汉字数
$w_sp = $argv[3]; //等宽空格数


$obj = new MarkdownFormat($file, $w_cn, $w_sp);
$obj->run();

class MarkdownFormat {
    protected $_md_file    = '';
    protected $_tmp_file   = '';
    protected $_with_mb    = ''; //多字节字符数
    protected $_with_space = ''; //等宽空格数

    protected $_fp_in  = null;
    protected $_fp_out = null;
    
    protected $_table_num = 0;

    function __construct($md_file, $with_cn, $with_space) {
        $this->_md_file    = $md_file;
        $this->_tmp_file   = $md_file . '.tmp';
        list($this->_with_mb, $this->_with_space) = $this->baseNum($with_cn, $with_space);
    }

    public function run() {
        $this->check();
        $this->openFile();
        $this->loop();
        $this->closeFile();
        $this->mv();
    }
    
    protected function check() {
        $arr = explode('.', $this->_md_file);
        $ext_name = array_pop($arr);
        if (strtolower($ext_name) != 'md') {
            echo "不是md文件, 放弃处理\n";
            exit;
        }
    }
    
    
    /**
     * 约分
     * @param $with_cn
     * @param $with_space
     *
     * @return array
     */
    protected function baseNum($with_cn, $with_space) {
        $a = $with_space;
        $b = $with_cn;
        
        //辗转相除法求最大公约数
        do{
            $c = $a % $b;
            if ($c == 0) {
                break;
            }
            $a = $b;
            $b = $c;
        }while(1);
        
        return [$with_cn / $b, $with_space /$b];
    }

    protected function openFile() {
        $this->_fp_in  = fopen($this->_md_file, 'r');
        $this->_fp_out = fopen($this->_tmp_file, 'w');
        if (!$this->_fp_in || !$this->_fp_out) {
            echo "打开文件失败\n";
            exit;
        }
    }
    
    protected function mv() {
        echo "格式化了 {$this->_table_num} 个表格\n";
        if ($this->_table_num) {
            //file_put_contents($this->_md_file, file_get_contents($this->_tmp_file));
            //unlink($this->_tmp_file);
            unlink($this->_md_file);
            rename($this->_tmp_file, $this->_md_file);
        }
    }

    protected function loop() {
        $row_idx    = 0; //行号 0~n
        $arr_tb     = []; //表信息
        $num_col    = 0; //列数
        $blank_line = true; //之前是否为空行

        //逐行读取数据进行处理
        do {
            $line = fgets($this->_fp_in, 4096);
            if ($line === false) {
                break;
            }
            $fmt_line = trim($line);
            if (strpos($line, '|') === false || !$blank_line) {//不含|或者之前没有空行,非表格开始
                if ($arr_tb) {
                    $this->emptyTable($arr_tb); //清空表
                    $row_idx    = 0;
                    $blank_line = false;
                }
                fputs($this->_fp_out, $line);
                $blank_line = ($fmt_line === '');
                continue;
            }

            //分割列
            $arr_col = explode('|', $fmt_line);
            
            //第二行
            if ($row_idx == 1){
                //不合法
                if (trim($fmt_line, ":-|\t ") !== '') {
                    $this->emptyTable($arr_tb); //清空表
                    $row_idx    = 0;
                    $blank_line = false;
                    fputs($this->_fp_out, $line);
                    continue;
                }
                //合法
                array_walk($arr_col, function(&$str){
                    $str = trim($str);
                    if ($str === '') {
                        $str = 'l';
                    }
                    $l = $str{0} == ':'; //left
                    $last = strlen($str) - 1;
                    $r = $last > 0 && $str{$last} == ':'; //right
                    if ($l && $r) {
                        $str = 'c'; //center
                    } else {
                        $str = $r ? 'r' : 'l';
                    }
                });
            }
            
            //列数
            $num = count($arr_col);
            if ($row_idx == 0) {
                $num_col = $num;
            } elseif($num != $num_col) { //列数不一样
                $this->emptyTable($arr_tb); //清空表
                $row_idx    = 0;
                $blank_line = false;
                fputs($this->_fp_out, $line);
                continue;
            }
            
            $arr_tb[$row_idx++] = [
                'org_line' => $line,
                'arr_col'  => $arr_col,
            ];
        } while (!feof($this->_fp_in));
    }

    protected function emptyTable(&$arr_tb) {
        if (count($arr_tb) > 2) {
            $str = $this->format($arr_tb);
            fputs($this->_fp_out, $str);
        } else {
            foreach ($arr_tb as $row) {
                fputs($this->_fp_out, $row['org_line']);
            }
        }

        $arr_tb = [];
    }

    function format($arr_tb) {
        $this->_table_num ++;
        $arr_data = [];
        foreach ($arr_tb as $row) {
            $arr_data[] = $row['arr_col'];
        }

        //统计各列的最大长度 [字节宽度,字符数]
        $arr_data_len = []; //每个单元格的长度 [$k][$i] => len
        $arr_col_max_len = []; //各列最大长度
        foreach ($arr_data[0] as $k => $v) { //$k: 列号
            $max_len     = 0;
            $arr_data_len[$k] = [];
            foreach ($arr_data as $i => $row) {//$i : 行号
                if ($i == 1) {
                    continue;
                }
                
                $r_or_l = $i == 0 ? 'c' : $arr_data[1][$k];
                list($str, $len) = $this->formatMbStr($row[$k], $r_or_l, $this->_with_mb, $this->_with_space);
                $arr_data[$i][$k] = $str;

                $max_len < $len && $max_len = $len;
                $arr_data_len[$k][$i] = $len; //[列号][行号] => [字节宽度,字符数]
            }
            
            $arr_col_max_len[$k] = $max_len == 1 ? 2 : $max_len; //列宽度
        }

        $out_str = '';
        $arr_rl = $arr_data[1];
        foreach ($arr_data as $i => $row) { 
            foreach ($row as $k => $str) {
                if ($arr_col_max_len[$k] == 0) {
                    $row[$k] = '';
                    continue;
                }
                $r_or_l = $arr_rl[$k];
                if ($i == 0) {
                    $r_or_l = 'c';
                }
                if ($i == 1) { //第二行
                    if ($r_or_l == 'r') {
                        $row[$k] = str_repeat('-', $arr_col_max_len[$k] - 1).':';
                    } elseif ($r_or_l == 'c') {
                        $row[$k] = ':'.str_repeat('-', $arr_col_max_len[$k] - 2).':';
                    } else {
                        $row[$k] = ':'.str_repeat('-', $arr_col_max_len[$k] - 1);
                    }
                    continue;
                }

                $blank_num = $arr_col_max_len[$k] - $arr_data_len[$k][$i];
                if ($r_or_l == 'r') {
                    $row[$k] = str_repeat(' ', $blank_num).$row[$k];
                } elseif ($r_or_l == 'c') {
                    $left_num = (int)($blank_num/2);
                    $row[$k] = str_repeat(' ', $left_num).$row[$k].str_repeat(' ', $blank_num-$left_num);
                } else {
                    $row[$k] .= str_repeat(' ', $blank_num);
                }
            }
            
            $out_str .= implode('|', $row)."\n";
        }

        return $out_str;
    }

    /**
     * @param $str
     * @param $r_or_l
     * @param $with_mb
     * @param $with_space
     *
     * @return array  [字符串, 宽度]
     */
    protected function formatMbStr($str, $r_or_l, $with_mb, $with_space) {
    	$str = trim($str);
        if ($str === '') {
            return [$str, 0];
        }
        $str = preg_replace('/(^　+|　+$)/u', '', $str); //去掉首尾全角空格
        $arr_mb_char = preg_split('/(?<!^)(?!$)/u', $str);
        if (count($arr_mb_char) == strlen($str)) { //单字节字符
            return [$str, strlen($str)];
        }
        
        $mb_num = 0; //多字节字符数
        foreach ($arr_mb_char as $char) {
            if (isset($char{1})) {
                ++$mb_num;
            }
        }
        $ascii_num = count($arr_mb_char) - $mb_num;
        
        //补空白
        $m = $mb_num % $with_mb;
        if ($m != 0) {
            $blank_num = $with_mb - $m;
            $blank = str_repeat('　', $blank_num);
            if ($r_or_l == 'r') {
                $str = $blank.$str;
            } elseif($r_or_l == 'c'){
                $half_num = intval($blank_num/2);
                $str = str_repeat('　', $half_num) . $str . str_repeat('　', $blank_num - $half_num);
            } else {
                $str .= $blank;
            }
            $mb_num += $blank_num;
        }
        
        return [$str, $mb_num * $with_space / $with_mb + $ascii_num];
    }

    function __destruct() {
        $this->closeFile();
    }
    
    function closeFile(){
        if ($this->_fp_in) {
            fclose($this->_fp_in);
            $this->_fp_in = null;
        }
        if ($this->_fp_out) {
            fclose($this->_fp_out);
            $this->_fp_out = null;
        }
    }
}











