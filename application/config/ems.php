<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * EMS live config — M1 LVMDP only (display saja, tanpa DB).
 * Broker EMQX lokal. Dashboard HTTP :18083 hanya untuk manusia,
 * browser memakai WS :8083/mqtt (lihat plans/ems-lvmdp-mqtt-realtime.md §2).
 * Kosongkan user/pass bila EMQX auth OFF (LAN only). JANGAN commit password.
 */
$ems_raw_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$ems_raw_host = preg_replace('/:\d+$/', '', $ems_raw_host);
if (!is_string($ems_raw_host) || $ems_raw_host === '') {
  $ems_raw_host = 'localhost';
}
$config['ems_mqtt_ws_url'] = 'ws://' . $ems_raw_host . ':8083/mqtt';
$config['ems_mqtt_topic']  = 'data/ems/lvmdp/+';
$config['ems_mqtt_user']   = '';
$config['ems_mqtt_pass']   = '';
$config['ems_group']       = 'lvmdp';

/* M2 worker (plans/ems-m2-mqtt-worker.md) — TCP broker untuk worker PHP CLI. */
$config['ems_mqtt_host']          = $ems_raw_host;
$config['ems_mqtt_tcp_port']      = 1883;
$config['ems_mqtt_topic_pattern'] = 'data/ems/+/+';
