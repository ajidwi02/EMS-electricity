<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * EMS — Energy Management System
 * Satu controller untuk 5 halaman: Dashboard, LVMDP, Puma, Nagai, Export Data.
 * CP-054: Puma & Nagai dibuka kembali (amandemen CP-036) — view empty-state.
 * CP-055: /puma & /nagai = duplikat 1:1 dashboard LVMDP — satu logika
 * panel_page(); topic & payload sama dengan Panel 1 (FASE topic per-panel =
 * spec terpisah; plans/ems-duplicate-lvmdp-puma-nagai.md).
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

  // Panel 1/2/3 (LVMDP/Puma/Nagai) — SATU logika dashboard (CP-055; spec
  // plans/ems-duplicate-lvmdp-puma-nagai.md).
  // FASE 1: duplikat 1:1 — topic MQTT data/ems/lvmdp/+ & payload DB panel
  // 'lvmdp' dipakai SEMUA panel (instruksi user "meanwhile with the same topic").
  // FASE-T (CP-062; plans/ems-tarif-per-panel-puma-nagai.md): topic realtime
  // per panel (CP-058) + kartu K-T/T-T-H dari panel DB per panel
  // (lvmdp/lvmdp2/lvmdp3).
  public function lvmdp()
  {
    $this->panel_page('lvmdp');
  }

  public function puma()
  {
    $this->panel_page('puma');
  }

  public function nagai()
  {
    $this->panel_page('nagai');
  }

  private function panel_page($key)
  {
    // FASE-T (CP-062; plans/ems-tarif-per-panel-puma-nagai.md): peta SATU titik
    // key → code (kode DB = group topik, tanpa peta topik kedua).
    $pages = array(
      'lvmdp' => array('view' => 'lvmdp', 'nav' => 'lvmdp', 'title' => 'LVMDP', 'code' => 'lvmdp',
        'subtitle' => 'Monitoring Low Voltage Main Distribution Panel'),
      'puma'  => array('view' => 'puma', 'nav' => 'puma', 'title' => 'Puma', 'code' => 'lvmdp2',
        'subtitle' => 'Monitoring panel / area Puma'),
      'nagai' => array('view' => 'nagai', 'nav' => 'nagai', 'title' => 'Nagai', 'code' => 'lvmdp3',
        'subtitle' => 'Monitoring panel / area Nagai'),
    );
    if (!isset($pages[$key])) {
      $key = 'lvmdp';
    }
    $p    = $pages[$key];
    $code = $p['code'];

    $mqtt_topic = 'data/ems/' . $code . '/+';   // deriver: hasil string PERSIS sama dengan hard-code lama

    $this->config->load('ems', TRUE);
    $ems = $this->config->item('ems');
    $extra = array(
      'mqtt_ws_url' => (is_array($ems) && isset($ems['ems_mqtt_ws_url'])) ? $ems['ems_mqtt_ws_url'] : 'ws://localhost:8083/mqtt',
      'mqtt_topic'  => $mqtt_topic,
      'mqtt_user'   => (is_array($ems) && isset($ems['ems_mqtt_user'])) ? $ems['ems_mqtt_user'] : '',
      'mqtt_pass'   => (is_array($ems) && isset($ems['ems_mqtt_pass'])) ? $ems['ems_mqtt_pass'] : '',
      'day_payload' => $this->lvmdp_day_payload(),
      'tarif'       => $this->tarif_kemarin_db($code),   // FASE-T: per-panel
      'tarif7'      => $this->tarif7_harian_db($code),   // FASE-T: per-panel
    );
    $extra['trends'] = $extra['day_payload'];
    $extra['power']  = $extra['day_payload']['power'];
    $extra['trends_json'] = json_encode($extra['day_payload']);
    $extra['trends_json'] = str_replace(array('<', '>'), array('\\u003c', '\\u003e'), $extra['trends_json']);
    $this->render($p['view'], $p['nav'], $p['title'], $p['subtitle'], $extra);
  }

  // L-T/P-H fase 2: payload chart 1 hari dari ems.data_electricity_iot5 (kontrak SS2-D6
  // plans/ems-lvmdp-db-trend.md). Skala & refs pindah ke payload (SSR + JS
  // satu kontrak). pts = pair [menitSejak00:00, nilai], HANYA baris non-NULL
  // per kolom; stat dari titik tercatat; peak pakai skema dummy cpx/cpy/x/y/cy.
  private function lvmdp_day_payload()
  {
    $this->load->model('Readings_model');
    $tz    = new DateTimeZone('Asia/Jakarta');
    $bulan = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                   7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');
    $today   = new DateTime('today', $tz);
    $tgl_lbl = $today->format('j') . ' ' . $bulan[(int) $today->format('n')] . ' '
             . $today->format('Y') . ' (WIB)';

    $pid  = $this->Readings_model->panel_id_by_code('lvmdp');
    $rows = ($pid === NULL) ? array() : $this->Readings_model->day_series($pid);

    $mken = function ($row) {
      // recorded_at 'Y-m-d H:i:s[.v]' -> menit sejak 00:00 WIB
      $h = (int) substr($row['recorded_at'], 11, 2);
      $m = (int) substr($row['recorded_at'], 14, 2);
      return $h * 60 + $m;
    };

    $charts = array();
    // skala dasar & refs: nilai PERSIS $trend_defs lama (LOCKED CP-018/025);
    // skala efektif 3 chart tren menyesuaikan data (amandemen CP-049)
    $charts['ln'] = $this->day_chart($rows, array('R' => 'v_r', 'S' => 'v_s', 'T' => 'v_t'), 2,
      'Tegangan Fasa (L-N)', 'Volt (V)', 190.0, 240.0, 10.0, array(
        array('v' => 231.0, 'label' => 'Batas +5% 231',  'c' => '#9AA1AD'),
        array('v' => 220.0, 'label' => 'Nominal 220',    'c' => '#6B7280'),
        array('v' => 198.0, 'label' => 'Batas −10% 198', 'c' => '#9AA1AD'),
      ), $mken);
    $charts['ll'] = $this->day_chart($rows, array('R' => 'v_rs', 'S' => 'v_st', 'T' => 'v_rt'), 2,
      'Tegangan Antar Fasa (L-L)', 'Volt (V)', 330.0, 420.0, 10.0, array(
        array('v' => 399.0, 'label' => 'Batas +5% 399',  'c' => '#9AA1AD'),
        array('v' => 380.0, 'label' => 'Nominal 380',    'c' => '#6B7280'),
        array('v' => 342.0, 'label' => 'Batas −10% 342', 'c' => '#9AA1AD'),
      ), $mken);
    $charts['a'] = $this->day_chart($rows, array('R' => 'i_r', 'S' => 'i_s', 'T' => 'i_t'), 0,
      'Arus', 'Ampere (A)', 0.0, 1200.0, 200.0, array(
        array('v' => 1000.0, 'label' => 'Nominal FLA 1000', 'c' => '#C2410C'),
      ), $mken);

    $power = $this->day_chart($rows, array('K' => 'kw'), 2,
      'Power Hari Ini', 'kW', 0.0, 600.0, 100.0, array(), $mken, FALSE);
    $peakK = $power['series']['K'];
    $power['scale'] = array('ymin' => 0.0, 'ymax' => 600.0, 'ystep' => 100.0, 'refs' => array());
    $power['pts']  = $peakK['pts'];
    $power['stat'] = $peakK['stat'];
    $power['tgl']  = $tgl_lbl;
    $peak = array('val' => NULL, 't' => '-', 'cpx' => '0.00', 'cpy' => '300.00', 'x' => '0.00', 'y' => '100.00', 'cy' => '97.00');
    if (count($peakK['pts']) > 0) {
      $pmax = $peakK['pts'][0]; $tmax = $pmax[0]; $vmax = $pmax[1];
      foreach ($peakK['pts'] as $p) {
        if ($p[1] > $vmax) { $vmax = $p[1]; $tmax = $p[0]; }
      }
      $px = 800.0 * $tmax / 1440.0;
      $py = 300.0 - 300.0 * $vmax / 600.0;
      $peak['val'] = $vmax;
      $peak['t']   = sprintf('%02d:%02d', intdiv($tmax, 60), $tmax % 60);
      $peak['cpx'] = sprintf('%.2F', $px);
      $peak['cpy'] = sprintf('%.2F', $py);
      $peak['x']   = sprintf('%.2F', 100.0 * $tmax / 1440.0);
      $peak['y']   = sprintf('%.2F', 100.0 * (1 - $vmax / 600.0));
      $peak['cy']  = sprintf('%.2F', 100.0 * (1 - $vmax / 600.0) - 3);
    }
    $power['peak'] = $peak;

    return array(
      'day'    => $today->format('Y-m-d'),
      'tgl'    => $tgl_lbl,
      'n'      => count($rows),
      'charts' => $charts,
      'power'  => $power,
    );
  }

  // Seri per-kolom -> series {R,S,T} / {K}: pts ASC menit + stat (nilai PERTAMA
  // menang pada tie, pola dummy $stat) atau '-' bila kosong.
  private function day_chart($rows, $map, $dec, $title, $unit, $ymin, $ymax, $ystep, $refs, $mken, $adaptive = TRUE)
  {
    $series = array();
    foreach ($map as $sk => $col) {
      $pts = array();
      $sum = 0.0;
      $vmin = $vmax = NULL;
      $imin = $imax = 0;
      foreach ($rows as $row) {
        if (!array_key_exists($col, $row) || $row[$col] === NULL) {
          continue;
        }
        $m = $mken($row);
        $v = round((float) $row[$col], $dec);
        $pts[] = array($m, $v);
        if ($vmin === NULL || $v < $vmin) { $vmin = $v; $imin = count($pts) - 1; }
        if ($vmax === NULL || $v > $vmax) { $vmax = $v; $imax = count($pts) - 1; }
        $sum += $v;
      }
      if (count($pts) === 0) {
        $series[$sk] = array('pts' => array(), 'stat' => array(
          'min' => NULL, 'rmin' => '-', 'max' => NULL, 'rmax' => '-', 'avg' => NULL));
      } else {
        $series[$sk] = array('pts' => $pts, 'stat' => array(
          'min' => $vmin, 'rmin' => sprintf('%02d:%02d', intdiv($pts[$imin][0], 60), $pts[$imin][0] % 60),
          'max' => $vmax, 'rmax' => sprintf('%02d:%02d', intdiv($pts[$imax][0], 60), $pts[$imax][0] % 60),
          'avg' => round($sum / count($pts), ($dec === 0) ? 0 : 2)));
      }
    }
    return array(
      'title'  => $title,
      'unit'   => $unit,
      'scale'  => $adaptive
        ? $this->adaptive_scale($series, $ymin, $ymax, $ystep, $ymax, $refs)
        : array('ymin' => $ymin, 'ymax' => $ymax, 'ystep' => $ystep, 'refs' => $refs),
      'series' => $series,
    );
  }

  private function adaptive_scale($series, $ymin, $ymax, $ystep, $limit = NULL, $refs = array())
  {
    $data_min = NULL;
    $data_max = NULL;
    foreach ((array) $series as $item) {
      foreach (isset($item['pts']) ? $item['pts'] : array() as $pt) {
        if (!is_array($pt) || count($pt) < 2) { continue; }
        $v = (float) $pt[1];
        if ($data_min === NULL || $v < $data_min) { $data_min = $v; }
        if ($data_max === NULL || $v > $data_max) { $data_max = $v; }
      }
    }

    $eff_min = (float) $ymin;
    $eff_max = (float) $ymax;
    $headroom = (float) $ystep * 0.5;
    if ($data_min !== NULL && $data_min < $eff_min) {
      $eff_min = floor(($data_min - $headroom) / $ystep) * $ystep;
    }
    if ($data_max !== NULL && $data_max > $eff_max) {
      $eff_max = ceil(($data_max + $headroom) / $ystep) * $ystep;
    }

    $step = (float) $ystep;
    while (($eff_max - $eff_min) / $step > 12.0) { $step *= 2.0; }
    if ($step !== $ystep) {
      $eff_min = floor($eff_min / $step) * $step;
      $eff_max = ceil($eff_max / $step) * $step;
    }

    return array(
      'ymin'      => (float) $eff_min,
      'ymax'      => (float) $eff_max,
      'ystep'     => (float) $step,
      'refs'      => (array) $refs,
      'base_min'  => (float) $ymin,
      'limit'     => (float) ($limit === NULL ? $ymax : $limit),
      'expanded'  => (abs($eff_min - (float) $ymin) > 0.001 || abs($eff_max - (float) $ymax) > 0.001),
      'data_min'  => $data_min,
      'data_max'  => $data_max,
    );
  }

  public function lvmdp_trend()
  {
    if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'GET') {
      header('Content-Type: application/json; charset=utf-8', TRUE, 405);
      header('Allow: GET');
      echo json_encode(array('error' => 'method not allowed'));
      exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($this->lvmdp_day_payload());
    exit;
  }

  // K-T fase 1: dummy "Konsumsi & Tarif Hari Kemarin" — deterministik (spec §4
  // plans/ems-lvmdp-tarif-kemarin.md). kWh = KONSTANTA contoh user (display saja,
  // bukan DB/MQTT). Biaya dari rumus Permen ESDM 7/2024 (docs §3.2B): round(kwh x tarif)
  // LALU dijumlahkan hasil pembulatannya per blok — jangan hitung ulang (docs §6.2).
  // K = 1.5 DUMMY (dari sampel user; nilai SPJBTL wajib dikonfirmasi nanti, docs §2.2).
  private function tarif_kemarin_dummy()
  {
    $tz     = new DateTimeZone('Asia/Jakarta');

    // Tanggal "kemarin" WIB — DINAMIS (GR-05). kWh/biaya TETAP nilai dummy.
    $tgl    = new DateTime('yesterday', $tz);
    $bulan  = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                    7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');
    $tgl_lbl = 'Kemarin — ' . $tgl->format('j') . ' ' . $bulan[(int) $tgl->format('n')]
             . ' ' . $tgl->format('Y') . ' (WIB)';

    $rate_l = 1035.78;  // Permen ESDM 7/2024, gol. I-3/TM
    $k_wbp  = 1.5;      // faktor pengali WBP (dummy) — tarif WBP = 1.5 x 1035.78 = 1553.67

    $kwh_l1 = 7131.29;  // data dummy = angka contoh user (spec §2.1)
    $kwh_l2 = 440.84;
    $kwh_w  = 1614.29;

    $b_l1 = round($kwh_l1 * $rate_l);
    $b_l2 = round($kwh_l2 * $rate_l);
    $b_w  = round($kwh_w * ($k_wbp * $rate_l));
    $b_l  = $b_l1 + $b_l2;                 // jumlah hasil pembulatan (docs §6.2)

    $kwh_l = $kwh_l1 + $kwh_l2;

    return array(
      'tgl'         => $tgl_lbl,
      'rows'        => array(
        array('t' => 'item',     'label' => 'LWBP 1', 'win' => '06:00–17:00',     'kwh' => 7131.29, 'biaya' => $b_l1),
        array('t' => 'item',     'label' => 'LWBP 2', 'win' => '22:00–06:00',     'kwh' => 440.84,  'biaya' => $b_l2),
        array('t' => 'subtotal', 'label' => 'LWBP',   'win' => 'LWBP 1 + LWBP 2', 'kwh' => $kwh_l,  'biaya' => $b_l),
        array('t' => 'item',     'label' => 'WBP',    'win' => '17:00–22:00',     'kwh' => 1614.29, 'biaya' => $b_w),
      ),
      'kwh_total'   => $kwh_l + $kwh_w,
      'biaya_total' => $b_l + $b_w,
      'rate_l'      => $rate_l,
      'k'           => $k_wbp,
      'src'         => 'dummy',   // CP-051: penanda sumber utk badge view
    );
  }

  // T-T-H fase 1: dummy "Total Tarif Harian" — grouped bar chart 7 hari (3 bar/hari:
  // LWBP, WBP, Total = LWBP + WBP) dari konstanta dummy (spec §4
  // plans/ems-lvmdp-total-tarif-harian.md). Skala Rp 0-10.000.000 pin user LOCKED
  // ("Rp 10M" = 10 juta). Tanggal 7 hari dinamis (tertua -> hari ini di kanan) via
  // DateTime Asia/Jakarta (GR-05 — TANPA date()). Total TIDAK pernah ditulis
  // hardcode. Data nyata per blok menunggu T-T-H fase 2 (spec §12).
  private function tarif7_harian_dummy()
  {
    $tz    = new DateTimeZone('Asia/Jakarta');
    $bulan = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                   7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');

    // Dummy KONSTANTA: pasangan [LWBP, WBP] per offset hari (6 = 6 hari lalu … 0 = hari ini).
    // DILARANG mengganti nilai tanpa checkpoint (spec §4.1).
    $konst = array(
      6 => array(3412000, 918000),
      5 => array(3150000, 850000),
      4 => array(3664000, 1012000),
      3 => array(4842000, 1486000),
      2 => array(6012000, 2188000),
      1 => array(4326000, 1238000),
      0 => array(3918000, 1106000),
    );

    $lbl = function ($dt) use ($bulan) {
      return $dt->format('j') . ' ' . $bulan[(int) $dt->format('n')];
    };

    $days = array();
    $sum  = 0;
    foreach (array(6, 5, 4, 3, 2, 1, 0) as $i) {
      $dt   = new DateTime($i === 0 ? 'today' : '-' . $i . ' days', $tz);
      $lwbp = $konst[$i][0];
      $wbp  = $konst[$i][1];
      $days[] = array('lbl' => $lbl($dt), 'lwbp' => $lwbp, 'wbp' => $wbp,
                      'total' => $lwbp + $wbp);
      $sum += $lwbp + $wbp;
    }

    // Statistik: nilai PERTAMA yang menang (pola $stat lama — nilai pertama menang)
    $max  = $days[0]['total'];
    $min  = $days[0]['total'];
    $imax = 0;
    $imin = 0;
    foreach ($days as $k => $row) {
      if ($row['total'] > $max) { $max = $row['total']; $imax = $k; }
      if ($row['total'] < $min) { $min = $row['total']; $imin = $k; }
    }

    // Label periode header — 3 cabang: lintas tahun / lintas bulan / satu bulan (GR-05)
    $f   = new DateTime('-6 days', $tz);
    $l   = new DateTime('today', $tz);
    $f_m = (int) $f->format('n');
    $f_y = (int) $f->format('Y');
    $l_m = (int) $l->format('n');
    $l_y = (int) $l->format('Y');
    if ($f_y !== $l_y) {
      $tgl_lbl = $lbl($f) . ' ' . $f_y . ' – ' . $lbl($l) . ' ' . $l_y . ' (WIB)';
    } elseif ($f_m !== $l_m) {
      $tgl_lbl = $lbl($f) . ' – ' . $lbl($l) . ' ' . $l_y . ' (WIB)';
    } else {
      $tgl_lbl = $f->format('j') . '–' . $l->format('j') . ' ' . $bulan[$l_m]
               . ' ' . $l_y . ' (WIB)';
    }

    return array(
      'tgl'   => $tgl_lbl,
      'ymin'  => 0.0,
      'ymax'  => 10000000.0,   // LOCKED (user): "Rp 10M" = Rp 10.000.000
      'ystep' => 2000000.0,
      'days'  => $days,
      'stat'  => array('max' => $max, 'rmax' => $days[$imax]['lbl'],
                       'avg' => $sum / 7, 'min' => $min, 'rmin' => $days[$imin]['lbl']),
      'sum'   => $sum,
      'src'   => 'dummy',   // CP-051: penanda sumber utk badge view
    );
  }

  /**
   * Hitung biaya energi per blok TOU — rumus Permen ESDM 7/2024 §3.2B
   * (plans/perhitungan-tarif-listrik-wbp-lwbp.md).
   *
   * ATURAN: Biaya_LWBP = round(L1×tarif) + round(L2×tarif), BUKAN round((L1+L2)×tarif).
   * Tarif_WBP = faktor_k × tarif_LWBP.
   *
   * @param array $kwh_blocks  Dari kwh_by_tou_block(): keys 'lwbp1','lwbp2','wbp' (float kWh)
   * @param array $config      Dari get_tariff_config(): keys 'tarif_lwbp','faktor_k'
   * @return array  'biaya_lwbp1','biaya_lwbp2','biaya_lwbp','biaya_wbp','biaya_total',
   *                'tarif_lwbp','tarif_wbp','faktor_k'
   */
  private function tarif_calculator($kwh_blocks, $config)
  {
    $tarif_lwbp = (float) $config['tarif_lwbp'];
    $faktor_k   = (float) $config['faktor_k'];
    $tarif_wbp  = $faktor_k * $tarif_lwbp;

    $biaya_lwbp1 = (int) round($kwh_blocks['lwbp1'] * $tarif_lwbp);
    $biaya_lwbp2 = (int) round($kwh_blocks['lwbp2'] * $tarif_lwbp);
    $biaya_lwbp  = $biaya_lwbp1 + $biaya_lwbp2;  // jumlah pembulatan (docs §6.2)
    $biaya_wbp   = (int) round($kwh_blocks['wbp'] * $tarif_wbp);
    $biaya_total = $biaya_lwbp + $biaya_wbp;

    return array(
      'biaya_lwbp1' => $biaya_lwbp1,
      'biaya_lwbp2' => $biaya_lwbp2,
      'biaya_lwbp'  => $biaya_lwbp,
      'biaya_wbp'   => $biaya_wbp,
      'biaya_total' => $biaya_total,
      'tarif_lwbp'  => $tarif_lwbp,
      'tarif_wbp'   => $tarif_wbp,
      'faktor_k'    => $faktor_k,
    );
  }

  /**
   * K-T fase 2: "Konsumsi & Tarif Hari Kemarin" — DATA NYATA dari DB.
   * Kontrak output SAMA PERSIS dengan tarif_kemarin_dummy() sehingga view BEKU.
   * Jika tidak ada data/config → fallback ke data dummy (safety).
   */
  // FASE-T (CP-062; plans/ems-tarif-per-panel-puma-nagai.md): $code = kode DB
  // panel (lvmdp|lvmdp2|lvmdp3) yang dipassing panel_page().
  private function tarif_kemarin_db($code = 'lvmdp')
  {
    $this->load->model('Readings_model');
    $tz    = new DateTimeZone('Asia/Jakarta');
    $bulan = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                   7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');

    // 1) Config tarif
    $config = $this->Readings_model->get_tariff_config();
    if ($config === NULL) {
      return $this->tarif_kemarin_dummy();
    }

    // 2) Parse jam blok TOU dari config
    $tou_hours = array(
      'wbp'   => array((int) substr($config['wbp_start'], 0, 2),   (int) substr($config['wbp_end'], 0, 2)),
      'lwbp1' => array((int) substr($config['lwbp1_start'], 0, 2), (int) substr($config['lwbp1_end'], 0, 2)),
      'lwbp2' => array((int) substr($config['lwbp2_start'], 0, 2), (int) substr($config['lwbp2_end'], 0, 2)),
    );

    // 3) Tanggal kemarin (WIB)
    $yesterday = new DateTime('yesterday', $tz);
    $date_str  = $yesterday->format('Y-m-d');
    $tgl_lbl   = 'Kemarin — ' . $yesterday->format('j') . ' '
               . $bulan[(int) $yesterday->format('n')] . ' '
               . $yesterday->format('Y') . ' (WIB)';

    // 4) Panel sesuai halaman (FASE-T CP-062: sebelumnya hard-code 'lvmdp')
    $pid = $this->Readings_model->panel_id_by_code($code);
    if ($pid === NULL) {
      return $this->tarif_kemarin_dummy();   // panel tak terdaftar -> dummy JUJUR
    }

    // 5) Hitung kWh per blok
    $kwh = $this->Readings_model->kwh_by_tou_block($pid, $date_str, $tou_hours);
    if (!$kwh['has_data']) {
      return $this->tarif_kemarin_dummy();
    }

    // 6) Hitung biaya
    $biaya = $this->tarif_calculator($kwh, $config);

    // 7) Build payload — kontrak PERSIS sama dengan tarif_kemarin_dummy()
    $rate_l = (float) $config['tarif_lwbp'];
    $k_wbp  = (float) $config['faktor_k'];

    return array(
      'tgl'         => $tgl_lbl,
      'rows'        => array(
        array('t' => 'item',     'label' => 'LWBP 1',
              'win' => sprintf('%02d:00–%02d:00', $tou_hours['lwbp1'][0], $tou_hours['lwbp1'][1]),
              'kwh' => round($kwh['lwbp1'], 2), 'biaya' => $biaya['biaya_lwbp1']),
        array('t' => 'item',     'label' => 'LWBP 2',
              'win' => sprintf('%02d:00–%02d:00', $tou_hours['lwbp2'][0], $tou_hours['lwbp2'][1]),
              'kwh' => round($kwh['lwbp2'], 2), 'biaya' => $biaya['biaya_lwbp2']),
        array('t' => 'subtotal', 'label' => 'LWBP',
              'win' => 'LWBP 1 + LWBP 2',
              'kwh' => round($kwh['lwbp'], 2),  'biaya' => $biaya['biaya_lwbp']),
        array('t' => 'item',     'label' => 'WBP',
              'win' => sprintf('%02d:00–%02d:00', $tou_hours['wbp'][0], $tou_hours['wbp'][1]),
              'kwh' => round($kwh['wbp'], 2),   'biaya' => $biaya['biaya_wbp']),
      ),
      'kwh_total'   => round($kwh['total'], 2),
      'biaya_total' => $biaya['biaya_total'],
      'rate_l'      => $rate_l,
      'k'           => $k_wbp,
      'src'         => 'db',     // CP-051: penanda sumber utk badge view
    );
  }

  /**
   * T-T-H fase 2: "Total Tarif Harian" — DATA NYATA 7 hari dari DB.
   * Kontrak output SAMA PERSIS dengan tarif7_harian_dummy() sehingga view BEKU.
   * Jika tidak ada data/config → fallback ke data dummy (safety).
   */
  // FASE-T (CP-062; plans/ems-tarif-per-panel-puma-nagai.md): $code = kode DB
  // panel (lvmdp|lvmdp2|lvmdp3) yang dipassing panel_page().
  private function tarif7_harian_db($code = 'lvmdp')
  {
    $this->load->model('Readings_model');
    $tz    = new DateTimeZone('Asia/Jakarta');
    $bulan = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                   7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');

    // 1) Config tarif
    $config = $this->Readings_model->get_tariff_config();
    if ($config === NULL) {
      return $this->tarif7_harian_dummy();
    }

    // 2) Parse jam blok TOU
    $tou_hours = array(
      'wbp'   => array((int) substr($config['wbp_start'], 0, 2),   (int) substr($config['wbp_end'], 0, 2)),
      'lwbp1' => array((int) substr($config['lwbp1_start'], 0, 2), (int) substr($config['lwbp1_end'], 0, 2)),
      'lwbp2' => array((int) substr($config['lwbp2_start'], 0, 2), (int) substr($config['lwbp2_end'], 0, 2)),
    );

    // 3) Panel sesuai halaman (FASE-T CP-062: sebelumnya hard-code 'lvmdp')
    $pid = $this->Readings_model->panel_id_by_code($code);
    if ($pid === NULL) {
      return $this->tarif7_harian_dummy();   // panel tak terdaftar -> dummy JUJUR
    }

    // 4) Data 7 hari per blok
    $daily = $this->Readings_model->kwh_daily_by_tou($pid, 7, $tou_hours);

    // 5) Cek apakah ada data sama sekali
    $any_data = FALSE;
    foreach ($daily as $d) {
      if ($d['has_data']) { $any_data = TRUE; break; }
    }
    if (!$any_data) {
      return $this->tarif7_harian_dummy();
    }

    // 6) Build payload: hitung biaya per hari
    $days = array();
    $sum  = 0;
    foreach ($daily as $d) {
      $biaya   = $this->tarif_calculator($d, $config);
      $lwbp_rp = $biaya['biaya_lwbp'];
      $wbp_rp  = $biaya['biaya_wbp'];
      $total   = $lwbp_rp + $wbp_rp;
      $days[]  = array(
        'lbl'   => $d['label'],
        'lwbp'  => $lwbp_rp,
        'wbp'   => $wbp_rp,
        'total' => $total,
      );
      $sum += $total;
    }

    // 7) Statistik
    $max  = $days[0]['total'];
    $min  = $days[0]['total'];
    $imax = 0;
    $imin = 0;
    foreach ($days as $k => $row) {
      if ($row['total'] > $max) { $max = $row['total']; $imax = $k; }
      if ($row['total'] < $min) { $min = $row['total']; $imin = $k; }
    }

    // 8) Label periode header — 3 cabang (pola existing tarif7_harian_dummy)
    $lbl = function ($dt) use ($bulan) {
      return $dt->format('j') . ' ' . $bulan[(int) $dt->format('n')];
    };
    $f   = new DateTime('-6 days', $tz);
    $l   = new DateTime('today', $tz);
    $f_m = (int) $f->format('n');
    $f_y = (int) $f->format('Y');
    $l_m = (int) $l->format('n');
    $l_y = (int) $l->format('Y');
    if ($f_y !== $l_y) {
      $tgl_lbl = $lbl($f) . ' ' . $f_y . ' – ' . $lbl($l) . ' ' . $l_y . ' (WIB)';
    } elseif ($f_m !== $l_m) {
      $tgl_lbl = $lbl($f) . ' – ' . $lbl($l) . ' ' . $l_y . ' (WIB)';
    } else {
      $tgl_lbl = $f->format('j') . '–' . $l->format('j') . ' ' . $bulan[$l_m]
               . ' ' . $l_y . ' (WIB)';
    }

    // 9) Skala Y: baseline 10 juta LOCKED user; auto-expand jika data > 10 juta
    $ymax_raw = ($max > 0) ? (float) $max : 10000000.0;
    $ymax  = max(10000000.0, ceil($ymax_raw / 2000000.0) * 2000000.0);
    $ystep = 2000000.0;
    // Jika lebih dari 6 tick → naikkan step
    while (($ymax - 0.0) / $ystep > 6.0) {
      $ystep *= 2.0;
    }

    return array(
      'tgl'   => $tgl_lbl,
      'ymin'  => 0.0,
      'ymax'  => $ymax,
      'ystep' => $ystep,
      'days'  => $days,
      'stat'  => array('max' => $max, 'rmax' => $days[$imax]['lbl'],
                        'avg' => (count($days) > 0) ? $sum / count($days) : 0,
                        'min' => $min, 'rmin' => $days[$imin]['lbl']),
      'sum'   => $sum,
      'src'   => 'db',      // CP-051: penanda sumber utk badge view
    );
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

    // 2b) Filter panel: whitelist panel nama ("Panel 1".."Panel N", '' = Semua).
    $panel_raw = (string) $this->input->get('panel');
    $valid_panels = array();
    foreach (array(1, 2, 3) as $p) { $valid_panels[] = 'Panel ' . $p; }
    $panel_id = 0;
    if ($panel_raw !== '' && in_array($panel_raw, $valid_panels, TRUE)) {
      $this->load->model('Readings_model');
      $row = $this->db->select('id')
        ->where('name', $panel_raw)
        ->get('electricity_panel_iot5')->row_array();
      $panel_id = isset($row['id']) ? (int) $row['id'] : 0;
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
    $total = $this->Readings_model->count_range($from, $to, $panel_id);
    $pages = ($total > 0) ? (int) ceil($total / $per) : 1;
    $page  = (int) $this->input->get('page');
    if ($page < 1)      { $page = 1; }
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $per;

    // Kontrak 4a-2 dipertahankan: baris shape sama (`time` 'Y-m-d H:i' + `panel` + 12 metrik).
    $rows = array();
    if ($total > 0) {
      $rows = $this->Readings_model->range_page($from, $to, $per, $offset, $panel_id);
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
      'panel_filter'   => ($panel_raw !== '' && in_array($panel_raw, $valid_panels, TRUE)) ? $panel_raw : '',
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

  // -------------------------------------------------------------------------
  // Export unduh (CSV / XLSX / PDF) — native PHP, tanpa dependensi eksternal
  // -------------------------------------------------------------------------

  public function export_download()
  {
    $allowed_format = array('csv', 'xlsx', 'pdf');
    $format = strtolower((string) $this->input->get('format'));
    if (!in_array($format, $allowed_format, TRUE)) {
      $format = 'csv';
    }

    $allowed = array('today', 'week', 'month', 'prev-month', 'custom');
    $filter = (string) $this->input->get('filter');
    if (!in_array($filter, $allowed, TRUE)) {
      $filter = 'today';
    }

    $panel_raw = (string) $this->input->get('panel');
    $valid_panels = array('', 'Panel 1', 'Panel 2', 'Panel 3');
    $panel_id = 0;
    $this->load->database();
    if ($panel_raw !== '' && in_array($panel_raw, $valid_panels, TRUE)) {
      $row = $this->db->select('id')
        ->where('name', $panel_raw)
        ->get('electricity_panel_iot5')->row_array();
      $panel_id = isset($row['id']) ? (int) $row['id'] : 0;
    }

    $from_raw = (string) $this->input->get('from');
    $to_raw   = (string) $this->input->get('to');
    $from = '';
    $to   = '';
    if ($filter === 'custom'
        && $this->valid_date($from_raw)
        && $this->valid_date($to_raw)
        && $from_raw <= $to_raw) {
      $from = $from_raw;
      $to   = $to_raw;
    } else {
      $range = $this->filter_range($filter === 'custom' ? 'today' : $filter);
      $from  = $range[0];
      $to    = $range[1];
    }

    $this->load->model('Readings_model');
    $rows = $this->Readings_model->range_all($from, $to, $panel_id);

    $panel_label = ($panel_id > 0) ? $panel_raw : 'semua';
    $filename    = 'ems_export_' . $filter . '_' . $from . '_' . $to . '_' . $panel_label . '.' . $format;

    // Hentikan output buffering agar header/file langsung terkirim (stream)
    while (ob_get_level()) {
      ob_end_clean();
    }

    header('Pragma: public');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Content-Description: File Transfer');
    header('Content-Transfer-Encoding: binary');

    if ($format === 'csv') {
      $this->_export_csv($rows, $filename);
    } elseif ($format === 'xlsx') {
      $this->_export_xlsx($rows, $filename);
    } else {
      $this->_export_pdf($rows, $filename, $filter, $from, $to, $panel_label);
    }
    exit;
  }

  private function _export_csv(array $rows, $filename)
  {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM agar Excel membaca benar
    fputcsv($out, array(
      'Waktu Catat', 'Panel',
      'Tegangan R-N (V)', 'Tegangan S-N (V)', 'Tegangan T-N (V)',
      'Tegangan R-S (V)', 'Tegangan S-T (V)', 'Tegangan R-T (V)',
      'Arus R (A)', 'Arus S (A)', 'Arus T (A)',
      'PF', 'Daya Aktif (kW)', 'Energi (kWh)',
    ));
    foreach ($rows as $r) {
      fputcsv($out, array(
        $r['time'], $r['panel'],
        $r['v_r'], $r['v_s'], $r['v_t'], $r['v_rs'], $r['v_st'], $r['v_rt'],
        $r['i_r'], $r['i_s'], $r['i_t'], $r['pf'], $r['kw'], $r['kwh'],
      ));
    }
    fclose($out);
  }

  private function _export_xlsx(array $rows, $filename)
  {
    $tmp = tempnam(sys_get_temp_dir(), 'ems_xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
      header('HTTP/1.1 500 Internal Server Error');
      echo 'Gagal membuat file Excel.';
      exit;
    }

    $esc = function ($s) {
      return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    $col = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N');
    $headers = array(
      'Waktu Catat', 'Panel',
      'Tegangan R-N (V)', 'Tegangan S-N (V)', 'Tegangan T-N (V)',
      'Tegangan R-S (V)', 'Tegangan S-T (V)', 'Tegangan R-T (V)',
      'Arus R (A)', 'Arus S (A)', 'Arus T (A)',
      'PF', 'Daya Aktif (kW)', 'Energi (kWh)',
    );

    $sheet = '<sheetData>';
    $sheet .= '<row r="1" spans="1:14">';
    foreach ($headers as $i => $h) {
      $sheet .= '<c r="' . $col[$i] . '1" t="inlineStr"><is><t>' . $esc($h) . '</t></is></c>';
    }
    $sheet .= '</row>';

    $r = 2;
    foreach ($rows as $row) {
      $sheet .= '<row r="' . $r . '" spans="1:14">';
      $vals = array(
        $row['time'], $row['panel'],
        $row['v_r'], $row['v_s'], $row['v_t'], $row['v_rs'], $row['v_st'], $row['v_rt'],
        $row['i_r'], $row['i_s'], $row['i_t'], $row['pf'], $row['kw'], $row['kwh'],
      );
      foreach ($vals as $i => $v) {
        $ref = $col[$i] . $r;
        if ($i <= 1) {
          $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc($v !== NULL ? $v : '') . '</t></is></c>';
        } elseif ($v !== NULL) {
          $sheet .= '<c r="' . $ref . '"><v>' . (float) $v . '</v></c>';
        }
      }
      $sheet .= '</row>';
      $r++;
    }
    $sheet .= '</sheetData>';

    $zip->addFromString('[Content_Types].xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
      '<Default Extension="xml" ContentType="application/xml"/>' .
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
      '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
      '</Types>');
    $zip->addFromString('_rels/.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
      '</Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
      '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
      '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
      '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/worksheets/sheet1.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
      '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $sheet . '</worksheet>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
  }

  private function _to_latin1($s)
  {
    if (function_exists('mb_convert_encoding')) {
      return mb_convert_encoding((string) $s, 'Windows-1252', 'UTF-8');
    }
    return (string) $s;
  }

  private function _pdf_txt($s)
  {
    $s = $this->_to_latin1($s);
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $s);
  }

  private function _export_pdf(array $rows, $filename, $filter, $from, $to, $panel_label)
  {
    $W = 792; $H = 612;                       // landscape letter
    $ml = 30; $mr = 30; $mt = 34; $mb = 36;
    $usableW = $W - $ml - $mr;

    $colW = array(70, 44, 46, 46, 46, 46, 46, 46, 40, 40, 40, 34, 42, 44);
    $headers = array('Waktu Catat', 'Panel',
      'Teg R-N', 'Teg S-N', 'Teg T-N', 'Teg R-S', 'Teg S-T', 'Teg R-T',
      'Arus R', 'Arus S', 'Arus T', 'PF', 'kW', 'kWh');
    $unit = array('', '', 'V', 'V', 'V', 'V', 'V', 'V', 'A', 'A', 'A', '', 'kW', 'kWh');

    $rowH  = 13;
    $headH = 16;
    $title = 'Export Data — ' . ucfirst($filter) . ' (' . $from . ' s/d ' . $to . ') · Panel ' . $panel_label;

    // PENTING: tiap teks satu blok BT..ET sendiri karena Td setelah Td pertama
    // sifatnya RELATIF — packing satu BT berisi banyak Td membuat kolom ke-2 dst
    // melayang keluar halaman (penyebab PDF "kosong" sebelumnya).
    $txtOp = function ($size, $font, $x, $yPdf, $t) {
      return 'BT /' . $font . ' ' . $size . ' Tf ' . round($x, 2) . ' ' . round($yPdf, 2) . ' Td (' . $this->_pdf_txt($t) . ') Tj ET';
    };
    $lnOp = function ($x1, $y1, $x2, $y2) {
      return $x1 . ' ' . $y1 . ' m ' . $x2 . ' ' . $y2 . ' l 0.5 w S';
    };

    $xBounds = array($ml);
    $acc = $ml;
    foreach ($colW as $w) { $acc += $w; $xBounds[] = $acc; }

    $drawTitle = function (&$s) use ($H, $ml, $mt, $txtOp, $title) {
      $s .= $txtOp(11, 'F2', $ml, $H - $mt + 10, $title) . "\n";
    };

    $drawHeader = function (&$s, $ycTop) use ($H, $ml, $usableW, $colW, $headers, $unit, $headH, $lnOp, $txtOp) {
      $s .= $lnOp($ml, $H - $ycTop, $ml + $usableW, $H - $ycTop) . "\n";
      $s .= $lnOp($ml, $H - $ycTop - $headH, $ml + $usableW, $H - $ycTop - $headH) . "\n";
      $x = $ml;
      foreach ($headers as $i => $h) {
        $label = $h . ($unit[$i] !== '' ? ' ' . $unit[$i] : '');
        $tw = strlen($label) * 0.55 * 7.5;
        $tx = $x + $colW[$i] / 2 - $tw / 2;
        $s .= $txtOp(7.5, 'F2', $tx, $H - ($ycTop + $headH - 5), $label) . "\n";
        $x += $colW[$i];
      }
      return $ycTop + $headH;
    };

    $closePage = function (&$s, $ycBottom) use ($H, $ml, $mt, $xBounds, $lnOp) {
      foreach ($xBounds as $xb) {
        $s .= $lnOp($xb, $H - $mt, $xb, $H - $ycBottom) . "\n";
      }
    };

    $pages = array();
    $cur = '';
    $drawTitle($cur);
    $yc = $drawHeader($cur, $mt);            // cursor baris (jarak dari atas)

    $nap = function ($n, $dec = 1) {
      return ($n === NULL || $n === '') ? '—' : number_format((float) $n, $dec, '.', '');
    };

    if (empty($rows)) {
      $cur .= $txtOp(8, 'F1', $ml + 3, $H - ($yc + 10), 'Tidak ada data pada rentang ini.') . "\n";
      $yc += 14;
    }

    foreach ($rows as $row) {
      if ($yc + $rowH > $H - $mb) {
        $cur = 'BT /F1 7 Tf ' . $ml . ' 18 Td (Halaman ' . (count($pages) + 1) . ') Tj ET' . "\n" . $cur;
        $closePage($cur, $yc);
        $pages[] = $cur;
        $cur = '';
        $drawTitle($cur);
        $yc = $drawHeader($cur, $mt);
      }
      $vals = array(
        $row['time'], $row['panel'],
        $row['v_r'], $row['v_s'], $row['v_t'], $row['v_rs'], $row['v_st'], $row['v_rt'],
        $row['i_r'], $row['i_s'], $row['i_t'], $row['pf'], $row['kw'], $row['kwh'],
      );
      $cur .= $lnOp($ml, $H - ($yc + $rowH), $ml + $usableW, $H - ($yc + $rowH)) . "\n";
      $x = $ml;
      foreach ($vals as $i => $v) {
        $txt = ($v === NULL) ? '—' : (($i <= 1) ? (string) $v : $nap($v));
        $cur .= $txtOp(7, 'F1', $x + 3, $H - ($yc + $rowH - 5), $txt) . "\n";
        $x += $colW[$i];
      }
      $yc += $rowH;
    }

    $cur = 'BT /F1 7 Tf ' . $ml . ' 18 Td (Halaman ' . (count($pages) + 1) . ') Tj ET' . "\n" . $cur;
    $closePage($cur, $yc);
    $pages[] = $cur;

    // Susun objek PDF (1-based): 1=Catalog, 2=Pages, 3=F1, 4=F2, lalu per halaman [content, page]
    $objs = array();
    $objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objs[] = null;
    $objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    $pageRefs = '';
    $totalPages = count($pages);
    for ($i = 0; $i < $totalPages; $i++) {
      $contentNum = 5 + ($i * 2);
      $pageNum    = 6 + ($i * 2);
      $objs[] = '<< /Length ' . strlen($pages[$i]) . ' >> stream' . "\n" . $pages[$i] . "\nendstream";
      $objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] /Contents ' . $contentNum . ' 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>';
      $pageRefs .= $pageNum . ' 0 R ';
    }
    $objs[1] = '<< /Type /Pages /Kids [' . trim($pageRefs) . '] /Count ' . $totalPages . ' >>';

    $pdf = '%PDF-1.4' . "\n";
    $xref = array('0000000000 65535 f ');
    $pos = strlen($pdf);
    foreach ($objs as $i => $obj) {
      $num = $i + 1;
      $xref[] = sprintf('%010d 00000 n ', $pos);
      $pdf .= $num . ' 0 obj' . "\n" . $obj . "\nendobj" . "\n";
      $pos = strlen($pdf);
    }
    $xrefPos = strlen($pdf);
    $pdf .= 'xref' . "\n";
    $pdf .= '0 ' . (count($objs) + 1) . "\n";
    foreach ($xref as $line) {
      $pdf .= $line . "\n";
    }
    $pdf .= 'trailer' . "\n";
    $pdf .= '<< /Size ' . (count($objs) + 1) . ' /Root 1 0 R >>' . "\n";
    $pdf .= 'startxref' . "\n";
    $pdf .= $xrefPos . "\n";
    $pdf .= '%%EOF' . "\n";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
  }

}
