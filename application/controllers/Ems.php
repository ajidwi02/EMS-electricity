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

  // M4 fase 4a: tampilan tabel historis (data DUMMY deterministik).
  // Fase 4b nanti: tukar sumber ke Readings_model — view TIDAK berubah.
  public function export_data()
  {
    $all    = $this->dummy_readings();
    $per    = 50;
    $total  = count($all);
    $pages  = ($total > 0) ? (int) ceil($total / $per) : 1;
    $page   = (int) $this->input->get('page');
    if ($page < 1)   { $page = 1; }
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $per;

    $this->render('export_data', 'export', 'Export Data', 'Unduh data historis energi', array(
      'rows'      => array_slice($all, $offset, $per),
      'total'     => $total,
      'page'      => $page,
      'pages'     => $pages,
      'floor_num' => $offset + 1,
      'top_num'   => min($offset + $per, $total),
      'per_page'  => $per,
    ));
  }

  private function dummy_readings()
  {
    $tz = new DateTimeZone('Asia/Jakarta');
    $start = new DateTime('today 08:00', $tz);
    $rows = array();
    for ($i = 0; $i < 137; $i++) {
      $t = (clone $start)->modify('+' . $i . ' minutes');
      $kw  = 555.0 + 40.0 * sin($i / 12) + 8.0 * sin($i / 3.4);
      $kwh = 693.00 + ($kw / 60.0) * $i;
      $rows[] = array(
        'time'   => $t->format('Y-m-d H:i'),
        'panel'  => 'LVMDP',
        'v_r'    => round(235.0 + 3.0 * sin($i / 14), 2),
        'v_s'    => round(244.0 + 2.6 * cos($i / 12), 2),
        'v_t'    => round(250.0 + 2.2 * sin($i / 9), 2),
        'v_rs'   => round(371.0 + 6.0 * sin($i / 16), 2),
        'v_st'   => round(376.0 + 5.0 * cos($i / 13), 2),
        'v_rt'   => round(383.0 + 4.0 * sin($i / 11), 2),
        'i_r'    => round(790.0 + 40.0 * sin($i / 12), 2),
        'i_s'    => round(755.0 + 35.0 * sin($i / 12 + 0.7), 2),
        'i_t'    => round(765.0 + 38.0 * cos($i / 9), 2),
        'pf'     => round(0.586 + 0.03 * sin($i / 9), 4),
        'kw'     => round($kw, 2),
        'kwh'    => round($kwh, 2),
      );
    }
    return $rows;
  }
}
