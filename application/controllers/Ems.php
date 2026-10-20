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

  // M0/M2 — dashboard LIVE data (CP-016): KPI, grafik kW/jam, ringkasan panel
  // dari DB `ems` via Readings_model. Tanpa data -> tampil '—' + grafik menunggu.
  public function dashboard()
  {
    $this->load->model('Readings_model');
    $tz = new DateTimeZone('Asia/Jakarta');

    $panels   = $this->Readings_model->get_active_panels();
    $now      = new DateTime('now', $tz);
    $panel_rows = array();

    $kwh  = 0.0;
    $peak = 0.0;
    $pf_ws = 0.0;
    $pf_n  = 0;
    $online = 0;
    $has_data = FALSE;
    $chart_p  = NULL;
    $chart_name = '';

    foreach ($panels as $p) {
      $stat = $this->Readings_model->today_stats($p['id']);
      $last = $this->Readings_model->latest_reading($p['id']);
      $n    = (int) (isset($stat['n']) ? $stat['n'] : 0);

      $last_kw = NULL;
      $last_pf = NULL;
      $last_at = '';
      if ($last !== NULL && !empty($last['recorded_at'])) {
        $last_at = (string) $last['recorded_at'];
        $last_kw = ($last['kw'] === NULL) ? NULL : (float) $last['kw'];
        $last_pf = ($last['pf'] === NULL) ? NULL : (float) $last['pf'];
      }

      $kwh_today = NULL;
      $peak_kw   = NULL;
      $avg_pf    = NULL;
      if ($n > 0) {
        $has_data  = TRUE;
        $kwh_today = ($stat['kwh_max'] !== NULL && $stat['kwh_min'] !== NULL)
          ? ((float) $stat['kwh_max'] - (float) $stat['kwh_min']) : NULL;
        $peak_kw   = ($stat['peak_kw'] !== NULL) ? (float) $stat['peak_kw'] : NULL;
        $avg_pf    = ($stat['avg_pf'] !== NULL) ? (float) $stat['avg_pf'] : NULL;
        if ($kwh_today !== NULL) { $kwh += $kwh_today; }
        if ($peak_kw !== NULL && $peak_kw > $peak) { $peak = $peak_kw; }
        if ($avg_pf !== NULL) { $pf_ws += $avg_pf * $n; $pf_n += $n; }
        if ($chart_p === NULL) { $chart_p = $p['id']; $chart_name = $p['name']; } // panel pertama (sort_order) dgn data
      }

      // umur update terakhir (menit) — KEDUA sisi pakai TZ WIB eksplisit (GR-05)
      $mins = NULL;
      if ($last_at !== '') {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', substr($last_at, 0, 19), $tz);
        if ($dt instanceof DateTime) {
          $mins = (int) floor(($now->getTimestamp() - $dt->getTimestamp()) / 60);
        }
      }
      if ($mins !== NULL && $mins <= 5) { $online++; }

      $panel_rows[] = array(
        'name'      => $p['name'],
        'kw'        => $last_kw,
        'pf'        => $last_pf,
        'kwh_today' => $kwh_today,
        'mins_old'  => $mins,
      );
    }

    $buckets = array();
    if ($chart_p !== NULL) {
      $buckets = $this->Readings_model->hourly_avg_today($chart_p);
    }

    $this->render('dashboard', 'dashboard', 'Dashboard', 'Ringkasan konsumsi & status sistem', array(
      'panel_rows' => $panel_rows,
      'chart_name' => $chart_name,
      'chart'      => $buckets,
      'kpi'        => array(
        'kwh_today' => $has_data ? $kwh : NULL,
        'peak_kw'   => $has_data ? $peak : NULL,
        'avg_pf'    => ($pf_n > 0) ? ($pf_ws / (float) $pf_n) : NULL,
        'online'    => $online,
        'panels_n'  => count($panels),
      ),
    ));
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

  // M4 fase 4a-2 + 4b: tabel historis dari DB `ems` + filter periode
  // (today / week / month / prev-month / custom). Kontrak URL:
  // plans/ems-m4-export-filters.md §2. Fase 4b: COUNT rentang + LIMIT/OFFSET
  // via Readings_model — view TIDAK berubah.
  public function export_data()
  {
    $allowed = array('today', 'week', 'month', 'prev-month', 'custom');

    // 1) Filter: whitelist enum; nilai asing/kosong -> 'today' TANPA warning.
    $filter      = (string) $this->input->get('filter');
    $custom_open = FALSE;
    if (!in_array($filter, $allowed, TRUE)) {
      $filter = 'today';
    }
    if ($filter === 'custom') {
      $custom_open = TRUE;   // form custom terbuka selama permintaan filter=custom
    }

    // 2) Resolusi rentang [from, to] (Y-m-d WIB).
    $from_raw = (string) $this->input->get('from');
    $to_raw   = (string) $this->input->get('to');
    $from     = '';
    $to       = '';
    $warning  = '';

    if ($filter === 'custom'
        && $this->valid_date($from_raw) && $this->valid_date($to_raw)
        && $from_raw <= $to_raw) {
      $from = $from_raw;                  // custom VALID (string Y-m-d -> aman leksikal)
      $to   = $to_raw;
    } else {
      if ($filter === 'custom' && ($from_raw !== '' || $to_raw !== '')) {
        $warning = 'Rentang tanggal tidak valid. Menampilkan data Hari ini.';
      }
      $range = $this->filter_range($filter === 'custom' ? 'today' : $filter);
      $from  = $range[0];
      $to    = $range[1];
      if ($filter === 'custom') {         // custom tapi tidak valid -> data = today
        $filter = 'today';                // chip "Hari ini" aktif; form tetap terbuka
      }
    }

    // 3) Sumber data NYATA (fase 4b): COUNT rentang dulu -> pagination clamp
    //    -> satu query LIMIT/OFFSET (spec filters §9 — jangan seret semua baris DB ke PHP).
    $this->load->model('Readings_model');
    $per   = 50;
    $total = $this->Readings_model->count_range($from, $to);
    $pages = ($total > 0) ? (int) ceil($total / $per) : 1;
    $page  = (int) $this->input->get('page');
    if ($page < 1)      { $page = 1; }
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $per;

    // Kontrak 4a-2 dipertahankan: baris shape sama (`time` 'Y-m-d H:i' + `panel` + 12 metrik).
    $rows = array();
    if ($total > 0) {
      $rows = $this->Readings_model->range_page($from, $to, $per, $offset);
    }

    $this->render('export_data', 'export', 'Export Data', 'Unduh data historis energi', array(
      'rows'           => $rows,
      'total'          => $total,
      'page'           => $page,
      'pages'          => $pages,
      'floor_num'      => ($total > 0) ? $offset + 1 : 0,
      'top_num'        => ($total > 0) ? min($offset + $per, $total) : 0,
      'per_page'       => $per,
      'filter_key'     => $filter,
      'from'           => $from,
      'to'             => $to,
      'custom_open'    => $custom_open,
      'custom_warning' => $warning,
    ));
  }

  // Resolusi rentang [from, to] (Y-m-d, inklusif) per kategori — SEMUA WIB (GR-05).
  private function filter_range($key)
  {
    $tz  = new DateTimeZone('Asia/Jakarta');
    $now = new DateTime('today', $tz);

    $from = clone $now;
    $to   = clone $now;

    if ($key === 'week') {
      $from->modify('-' . ((int) $from->format('N') - 1) . ' days'); // Senin minggu ini
      $to->modify('+' . (7 - (int) $to->format('N')) . ' days');     // Minggu minggu ini
    } elseif ($key === 'month') {
      $from->modify('first day of this month');
      $to->modify('last day of this month');
    } elseif ($key === 'prev-month') {
      $from->modify('first day of previous month');
      $to->modify('last day of previous month');
    }
    return array($from->format('Y-m-d'), $to->format('Y-m-d'));      // 'today' -> default: hari ini
  }

  // Validasi KETAT tanggal 'YYYY-MM-DD': regex format + kalender nyata (checkdate).
  private function valid_date($s)
  {
    if (!is_string($s) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
      return FALSE;
    }
    $p = explode('-', $s);
    return checkdate((int) $p[1], (int) $p[2], (int) $p[0]);
  }
}
