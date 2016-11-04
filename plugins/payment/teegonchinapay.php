<?php

/**
 * ECTouch Open Source Project
 * ============================================================================
 * Copyright (c) 2012-2014 http://ectouch.cn All rights reserved.
 * ----------------------------------------------------------------------------
 * 文件名称：teegonchinapay.php
 * ----------------------------------------------------------------------------
 * 功能描述：天工银联支付插件
 * ----------------------------------------------------------------------------
 * Licensed (  http://www.teegon.com  )
 * ----------------------------------------------------------------------------
 */

/* 访问控制 */
defined('IN_ECTOUCH') or die('Deny Access');

$payment_lang = ROOT_PATH . 'plugins/payment/language/' . C('lang') . '/' . basename(__FILE__);

if (file_exists($payment_lang)) {
    include_once ($payment_lang);
    L($_LANG);
}

/* 模块的基本信息 */
if (isset($set_modules) && $set_modules == TRUE) {
    $i = isset($modules) ? count($modules) : 0;
    /* 代码 */
    $modules[$i]['code'] = basename(__FILE__, '.php');
    /* 描述对应的语言项 */
    $modules[$i]['desc'] = 'teegonchinapay_desc';
    /* 是否支持货到付款 */
    $modules[$i]['is_cod'] = '0';
    /* 是否支持在线支付 */
    $modules[$i]['is_online'] = '1';
    /* 作者 */
    $modules[$i]['author'] = 'TEEGON TEAM';
    /* 网址 */
    $modules[$i]['website'] = 'http://www.teegon.cn';
    /* 版本号 */
    $modules[$i]['version'] = '1.0.0';
    /* 配置信息 */
    $modules[$i]['config'] = array(
        array(
            'name' => 'client_id',
            'type' => 'text',
            'value' => ''
        ),
        array(
            'name' => 'client_secret',
            'type' => 'text',
            'value' => ''
        )
    );

    return;
}

/**
 * 支付插件类
 */
class teegonchinapay
{

    /**
     * 生成支付代码
     *
     * @param array $order 订单信息
     * @param array $payment 支付方式信息
     */
    function get_code($order, $payment)
    {
        $param['order_no'] = $order['order_sn']; //订单号
        $param['channel'] = 'chinapay';
        $param['return_url'] = return_url(basename(__FILE__, '.php'), array('type'=>0));
        $param['notify_url'] = return_url(basename(__FILE__, '.php'), array('type'=>1));//支付成功后天工支付网关通知
        $param['amount'] = $order['goods_amount'];
        $param['subject'] = $order['order_sn'];
        $param['metadata'] = $order['log_id'];
        $param['client_ip'] = $_SERVER['REMOTE_ADDR'];
        $param['client_id'] = $payment['client_id'];
        $param['sign'] = $this->sign($param,$payment['client_secret']);
        $def_url  = '<br /><form style="text-align:center;" action="https://api.teegon.com/charge/pay" method="post" target="_blank">' .   // 不能省略
            "<input type='hidden' name='order_no' value='$param[order_no]'>" .                 // 贝宝帐号
            "<input type='hidden' name='channel' value='$param[channel]'>" .                 // payment for
            "<input type='hidden' name='return_url' value='$param[return_url]'>" .                        // 订单金额
            "<input type='hidden' name='notify_url' value='$param[notify_url]'>" .            // 货币
            "<input type='hidden' name='amount' value='$param[amount]'>" .                      // 订单号
            "<input type='hidden' name='subject' value='$param[subject]'>" .                              // 字符集
            "<input type='hidden' name='metadata' value='$param[metadata]'>" .                              // 不要求客户提供收货地址
            "<input type='hidden' name='client_ip' value='$param[client_ip]'>" .                                  // 付款说明
            "<input type='hidden' name='client_id' value='$param[client_id]'>" .
            "<input type='hidden' name='sign' value='$param[sign]'>" .
            "<input type='submit' value='" . L('button') . "' class='btn btn-info' style='padding:0.8rem'>" .                      // 按钮
            "</form><br />";
        return $def_url;
    }





    /**
     * 同步响应操作
     *
     * @return boolean
     */
    public function callback($data)
    {
        if (! empty($_GET)) {
            $payment = model('Payment')->get_payment($data['code']);
            $record_data = in($_GET);
            $sign = $record_data['sign'];
            $key = $payment['client_secret'];
            if($this->get_sign_veryfy($record_data,$sign,$key))
            {
                if($record_data['is_success'])
                {
                    model('Payment')->order_paid($record_data['metadata'], 2);
                    return true;
                }else{
                    return false;
                }
            }else{
                return false;
            }
        }
    }

    /**
     * 异步通知
     *
     * @return string
     */
    public function notify($data)
    {
            $payment = model('Payment')->get_payment($data['code']);
            $record_data = in($_POST);
            $sign = $record_data['sign'];
            $key = $payment['client_secret'];
            if($this->get_sign_veryfy($record_data,$sign,$key))
            {
                if($record_data['is_success'])
                {
                    model('Payment')->order_paid($record_data['metadata'], 2);
                    return true;

                }else{
                    return false;
                }


            }else{
                return false;
            }
    }

    /**
        * @synopsis  sign 天工签名生成方法
        *
        * @param $para_temp
        *
        * @returns
     */
    public function sign($para_temp,$key){
        //除去待签名参数数组中的空值和签名参数
        $para_filter = $this->para_filter($para_temp);

        //对待签名参数数组排序
        $para_sort = $this->arg_sort($para_filter);
        //生成加密字符串
        $prestr = $this->create_string($para_sort);

        $prestr = $key .$prestr . $key;
        return strtoupper(md5($prestr));
    }


    private function para_filter($para) {
        $para_filter = array();
        reset($para);//重置指针的位置  用以排除几率极低的指针位置混乱导致的参数丢失
        while (list ($key, $val) = each ($para)) {
            if($key == "sign")continue;
            else	$para_filter[$key] = $para[$key];
        }
        return $para_filter;
    }

    private function arg_sort($para) {
        ksort($para);
        reset($para);
        return $para;
    }

    private function create_string($para) {
        $arg  = "";
        while (list ($key, $val) = each ($para)) {
            $arg.=$key.$val;
        }
        //如果存在转义字符，那么去掉转义
        if(get_magic_quotes_gpc()){$arg = stripslashes($arg);}

        return $arg;
    }
    public function get_sign_veryfy($para_temp, $sign,$key){
        //除去待签名参数数组中的空值和签名参数
        $para_filter = $this->para_filter($para_temp);

        //对待签名参数数组排序
        $para_sort = $this->arg_sort($para_filter);
        //生成加密字符串
        $prestr = $this->create_string($para_sort);

        $isSgin = $this->md5_verify($prestr, $sign, $key);

        return $isSgin;
    }
    private function md5_verify($prestr, $sign, $key) {
        $prestr = $key .$prestr . $key;
        $mysgin = strtoupper(md5($prestr));
        if($mysgin == $sign) {
            return true;
        }
        else {
            return false;
        }
    }
}
