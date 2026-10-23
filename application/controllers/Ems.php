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

  // /ems/lvmdp, /ems/lvmDP, /ems/LVMDP semua masuk sini (PHP method case-insensitive).
  // /lvmdp = gauge MQTT realtime (TIDAK berubah, CP-006…CP-008)
  //        + kartu "Tren 24 Jam" dummy (L-T fase 1, CP-020 — kontrak §2.5 plans/ems-lvmdp-trend-charts.md)
  //        + kartu "Konsumsi & Tarif Hari Kemarin" dummy (K-T fase 1 — kontrak §2.3
  //          plans/ems-lvmdp-tarif-kemarin.md; rumus plans/perhitungan-tarif-listrik-wbp-lwbp.md §3.2B).
  //        + kartu "Power Hari Ini" dummy (P-H fase 1 — kontrak §2
  //          plans/ems-lvmdp-power-hari-ini.md; skala Y 0-600 kW pin user).
  //        + kartu "Total Tarif Harian" dummy (T-T-H fase 1 — kontrak §2
  //          plans/ems-lvmdp-total-tarif-harian.md; skala Y Rp 0-10 juta pin user).
  public function lvmdp()
  {
    $this->config->load('ems', TRUE);
    $ems = $this->config->item('ems');
    $extra = array(
      'mqtt_ws_url' => (is_array($ems) && isset($ems['ems_mqtt_ws_url'])) ? $ems['ems_mqtt_ws_url'] : 'ws://localhost:8083/mqtt',
      'mqtt_topic'  => (is_array($ems) && isset($ems['ems_mqtt_topic'])) ? $ems['ems_mqtt_topic'] : 'data/ems/lvmdp/+',
      'mqtt_user'   => (is_array($ems) && isset($ems['ems_mqtt_user'])) ? $ems['ems_mqtt_user'] : '',
      'mqtt_pass'   => (is_array($ems) && isset($ems['ems_mqtt_pass'])) ? $ems['ems_mqtt_pass'] : '',
      'trends'      => $this->trend_dummy(),
      'tarif'       => $this->tarif_kemarin_dummy(),
      'power'       => $this->power_today_dummy(),
      'tarif7'      => $this->tarif7_harian_dummy(),
    );
    $this->render('lvmdp', 'lvmdp', 'LVMDP', 'Monitoring Low Voltage Main Distribution Panel', $extra);
  }

  // L-T: data dummy Tren 24 Jam — deterministik, 289 titik (00:00..24:00, 5 menit).
  // Cerita beban mengikuti spec induk §1: malam ringan -> lonjakan ~08:12 ->
  // piringan siang -> naik tipis saat WBP. Tegangan antikorelasi beban; arus T terendah.
  private function trend_dummy()
  {
    $tz    = new DateTimeZone('Asia/Jakarta');
    $tgl   = new DateTime('today', $tz);
    $bulan = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                   7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');
    $tgl_lbl = $tgl->format('j') . ' ' . $bulan[(int) $tgl->format('n')] . ' '
             . $tgl->format('Y') . ' (WIB)';

    $clamp = function ($v, $lo, $hi) {
      if ($v < $lo) return $lo;
      if ($v > $hi) return $hi;
      return $v;
    };

    $n = 288; // interval; 289 titik = 00:00 + n x 5 menit
    $series = array('r' => array(), 's' => array(), 't' => array(),
                    'rs' => array(), 'st' => array(), 'rt' => array(),
                    'ir' => array(), 'is' => array(), 'it' => array());
    $times  = array(); // label 'HH:MM' per titik (WIB)

    for ($i = 0; $i <= $n; $i++) {
      $h    = $i / 12;
      $b1   = exp(-0.5 * pow(($h - 8.2) / 0.45, 2));
      $b2   = exp(-0.5 * pow(($h - 13.0) / 2.0, 2));
      $b3   = exp(-0.5 * pow(($h - 19.5) / 1.1, 2));
      $load = 300 + 520 * $b1 + 250 * $b2 + 90 * $b3;
      $vln  = 229.6 - 9.2 * ($load / 870);
      $vll  = 1.7320508 * $vln;

      $series['r'][]  = $clamp($vln + 1.10 * sin($i / 24) + 0.40, 190, 240);
      $series['s'][]  = $clamp($vln + 0.90 * cos($i / 21), 190, 240);
      $series['t'][]  = $clamp($vln + 1.05 * sin($i / 27), 190, 240);
      $series['rs'][] = $clamp($vll + 1.80 * sin($i / 17) + 1.80, 330, 420);
      $series['st'][] = $clamp($vll + 1.50 * cos($i / 15), 330, 420);
      $series['rt'][] = $clamp($vll + 1.40 * sin($i / 19), 330, 420);
      $series['ir'][] = $clamp($load * (1.035 + 0.025 * sin($i / 13.7)), 0, 1200);
      $series['is'][] = $clamp($load * (0.975 + 0.025 * cos($i / 11.3)), 0, 1200);
      $series['it'][] = $clamp($load * (0.930 + 0.025 * sin($i / 9.1)), 0, 1200);

      $mins    = $i * 5; // menit sejak 00:00
      $times[] = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }

    $round = function ($a) {
      $b = array();
      foreach ($a as $v) { $b[] = round($v, 2); }
      return $b;
    };
    $stat = function ($vals, $times) {
      $min = $vals[0]; $max = $vals[0]; $imin = 0; $imax = 0; $sum = 0.0;
      foreach ($vals as $k => $v) {
        if ($v < $min) { $min = $v; $imin = $k; }
        if ($v > $max) { $max = $v; $imax = $k; }
        $sum += $v;
      }
      return array('min' => $min, 'rmin' => $times[$imin], 'max' => $max,
                   'rmax' => $times[$imax], 'avg' => $sum / count($vals));
    };
    $mk = function ($key) use ($series, $times, $round, $stat) {
      $v = $round($series[$key]);
      return array('vals' => $v) + $stat($v, $times);
    };

    return array(
      'tgl' => $tgl_lbl,
      'charts' => array(
        'ln' => array('title' => 'Tegangan Fasa (L-N)', 'unit' => 'Volt (V)',
          'series' => array('R' => $mk('r'), 'S' => $mk('s'), 'T' => $mk('t'))),
        'll' => array('title' => 'Tegangan Antar Fasa (L-L)', 'unit' => 'Volt (V)',
          'series' => array('R' => $mk('rs'), 'S' => $mk('st'), 'T' => $mk('rt'))),
        'a'  => array('title' => 'Arus', 'unit' => 'Ampere (A)',
          'series' => array('R' => $mk('ir'), 'S' => $mk('is'), 'T' => $mk('it'))),
      ),
    );
  }

  // P-H fase 1: dummy "Power Hari Ini" — 1 line chart, 289 titik (00:00..24:00, 5 menit),
  // skala tetap 0-600 kW (pin user, LOCKED). Deterministik: lantai malam 300 kW (senada
  // dasar cerita kartu Tren) + lonjakan pagi-siang + puncak WBP + ripple harmonik kecil.
  // Display saja; sumber data nyata menunggu P-H fase 2 (spec §12).
  private function power_today_dummy()
  {
    $tz    = new DateTimeZone('Asia/Jakarta');
    $tgl   = new DateTime('today', $tz);
    $bulan = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                   7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');
    $tgl_lbl = $tgl->format('j') . ' ' . $bulan[(int) $tgl->format('n')] . ' '
             . $tgl->format('Y') . ' (WIB)';

    $n    = 288;         // interval 5 menit -> 289 titik (00:00..24:00)
    $ymax = 600.0;       // LOCKED (user) — jangan auto-fit
    $ymin = 0.0;

    $vals  = array();
    $times = array();
    for ($i = 0; $i <= $n; $i++) {
      $h  = $i / 12;                                   // jam desimal 0..24
      $g1 = exp(-0.5 * pow(($h - 10.5) / 1.90, 2));    // lonjakan pagi-siang
      $g2 = exp(-0.5 * pow(($h - 19.6) / 1.15, 2));    // puncak WBP (beban malam)
      $pw = 300.0 + 140.0 * $g1 + 120.0 * $g2
          + 7.0 * sin($i / 11.0) + 3.5 * sin($i / 23.0);  // ripple kecil
      $vals[]  = round($pw, 2);
      $mins    = $i * 5;
      $times[] = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }

    // Statistik: nilai PERTAMA yang menang (pola konsisten $stat trend_dummy)
    $min = $vals[0]; $max = $vals[0]; $imin = 0; $imax = 0; $sum = 0.0;
    foreach ($vals as $k => $v) {
      if ($v < $min) { $min = $v; $imin = $k; }
      if ($v > $max) { $max = $v; $imax = $k; }
      $sum += $v;
    }
    $stat = array('min' => $min, 'rmin' => $times[$imin], 'avg' => $sum / 289,
                  'max' => $max, 'rmax' => $times[$imax]);

    // Puncak global: persen utk chip + koordinat svg utk titik ink
    $peak = array(
      'x'   => sprintf('%.2F', ($imax / $n) * 100),
      'y'   => sprintf('%.2F', (1 - $vals[$imax] / $ymax) * 100),
      'cy'  => sprintf('%.2F', (1 - $vals[$imax] / $ymax) * 100 - 3),
      'cpx' => sprintf('%.2F', 800 * $imax / $n),
      'cpy' => sprintf('%.2F', 300 - 300 * $vals[$imax] / $ymax),
      'val' => $vals[$imax],
      't'   => $times[$imax],
    );

    return array(
      'tgl'   => $tgl_lbl,
      'ymin'  => $ymin,
      'ymax'  => $ymax,
      'ystep' => 100.0,
      'vals'  => $vals,
      'stat'  => $stat,
      'peak'  => $peak,
    );
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

    // Statistik: nilai PERTAMA yang menang (pola $stat trend_dummy)
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
    );
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
