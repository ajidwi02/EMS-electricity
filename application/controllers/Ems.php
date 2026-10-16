<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * EMS — Energy Management System
 * Satu controller untuk 5 halaman blank MVP:
 * Dashboard, LVMDP, Puma, Nagai, Export Data.
 */
class Ems extends CI_Controller {

  public function __construct()
  {
    parent::__construct();
    $this->load->helper('url');
  }

  /**
   * Render pola CI3 tanpa library template:
   * page fragment -> $content -> layouts/app.
   */
  private function render($page, $active, $title, $subtitle, $extra = array())
  {
    $data = array_merge($extra, array(
      'active_menu' => $active,
      'title'       => $title,
      'subtitle'    => $subtitle,
    ));
    $data['content'] = $this->load->view('pages/'.$page, $data, TRUE);
    $this->load->view('layouts/app', $data);
  }

  public function index()
  {
    $this->dashboard();
  }

  public function dashboard()
  {
    $this->render('dashboard', 'dashboard', 'Dashboard', 'Ringkasan konsumsi & status sistem');
  }

  // URL /ems/lvmdp, /ems/lvmDP, /ems/LVMDP semua masuk sini (PHP method case-insensitive).
  // M1 LVMDP-live: teruskan config MQTT (display saja, tanpa DB) ke view via $extra.
  public function lvmdp()
  {
    $this->config->load('ems', TRUE);
    $ems = $this->config->item('ems');
    $extra = array(
      'mqtt_ws_url' => (is_array($ems) && isset($ems['ems_mqtt_ws_url'])) ? $ems['ems_mqtt_ws_url'] : 'ws://localhost:8083/mqtt',
      'mqtt_topic'  => (is_array($ems) && isset($ems['ems_mqtt_topic'])) ? $ems['ems_mqtt_topic'] : 'data/ems/lvmdp/+',
      'mqtt_user'   => (is_array($ems) && isset($ems['ems_mqtt_user'])) ? $ems['ems_mqtt_user'] : '',
      'mqtt_pass'   => (is_array($ems) && isset($ems['ems_mqtt_pass'])) ? $ems['ems_mqtt_pass'] : '',
    );
    $this->render('lvmdp', 'lvmdp', 'LVMDP', 'Monitoring Low Voltage Main Distribution Panel', $extra);
  }

  public function puma()
  {
    $this->render('puma', 'puma', 'Puma', 'Monitoring panel / area Puma');
  }

  public function nagai()
  {
    $this->render('nagai', 'nagai', 'Nagai', 'Monitoring panel / area Nagai');
  }

  public function export_data()
  {
    $this->render('export_data', 'export', 'Export Data', 'Unduh data historis energi');
  }
}
