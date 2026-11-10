<?php
/**
 * ==========================================================================
 * TEST: Korelasi Data ems_tariff_daily ↔ data_electricity_iot5
 * ==========================================================================
 *
 * Tujuan: Memverifikasi bahwa data yang tercatat pada tabel `ems_tariff_daily`
 *   berkorelasi (sesuai) dengan data setiap panel di `data_electricity_iot5`.
 *
 * Skenario:
 *   1. INSERT data dummy ke data_electricity_iot5 (simulasi 3 panel × 3 hari)
 *   2. Hitung kWh per blok TOU langsung dari data dummy (hitungan independen)
 *   3. Gunakan logika existing (kwh_by_tou_block + tarif_calculator) untuk
 *      menghasilkan baris ems_tariff_daily
 *   4. COMPARE: pastikan angka ems_tariff_daily == hitungan independen
 *   5. Verifikasi format tampilan tabel Tarif Konsumsi
 *   6. CLEANUP: hapus semua data dummy
 *
 * Jalankan: php index.php ems test_tariff_correlation
 *   (dari root project c:\laragon\www\electrical)
 *
 * CATATAN: Test ini AMAN — menggunakan tanggal dummy (2000-01-10 s/d 2000-01-12)
 *   yang tidak mungkin bentrok dengan data produksi.
 */

// File ini di-require dari controller Ems::test_tariff_correlation()
// CI3 sudah aktif saat ini — langsung pakai get_instance().

// Ambil CI instance
$CI =& get_instance();
$CI->load->model('Readings_model');

// ── Konfigurasi Test ────────────────────────────────────────────────
date_default_timezone_set('Asia/Jakarta');

$TEST_DATES = array('2000-01-10', '2000-01-11', '2000-01-12');
$TARIF_LWBP = 1035.78;
$FAKTOR_K   = 1.5;
$TARIF_WBP  = $TARIF_LWBP * $FAKTOR_K;  // 1553.67

// Blok TOU default (dari ems_tariff_config)
$TOU_HOURS = array(
  'wbp'   => array(17, 22),   // 17:00 - 21:59
  'lwbp1' => array(6, 17),    // 06:00 - 16:59
  'lwbp2' => array(22, 6),    // 22:00 - 05:59 (bungkus tengah malam)
);

// Panel yang digunakan untuk test
$TEST_PANELS = array();

// ── Counters ────────────────────────────────────────────────────────
// Menggunakan $GLOBALS agar bisa diakses dari function di dalam file yang di-require
$GLOBALS['_TEST_COUNTERS'] = array('total' => 0, 'passed' => 0, 'failed' => 0, 'warnings' => 0);

// ── Helper functions ────────────────────────────────────────────────

function test_header($title)
{
  echo "\n" . str_repeat('=', 70) . "\n";
  echo "  TEST: $title\n";
  echo str_repeat('=', 70) . "\n\n";
}

function test_section($title)
{
  echo "\n  -- $title " . str_repeat('-', max(2, 60 - strlen($title))) . "\n\n";
}

function test_assert($label, $condition, $detail = '')
{
  $GLOBALS['_TEST_COUNTERS']['total']++;
  if ($condition) {
    $GLOBALS['_TEST_COUNTERS']['passed']++;
    echo "    [PASS]: $label\n";
  } else {
    $GLOBALS['_TEST_COUNTERS']['failed']++;
    echo "    [FAIL]: $label\n";
    if ($detail !== '') {
      echo "           -> $detail\n";
    }
  }
}

function test_warn($msg)
{
  $GLOBALS['_TEST_COUNTERS']['warnings']++;
  echo "    [WARN]: $msg\n";
}

function test_info($msg)
{
  echo "    [INFO] $msg\n";
}

function fmt_rp($v)
{
  return ($v === NULL) ? '—' : 'Rp ' . number_format((float) $v, 0, ',', '.');
}

function fmt_kwh($v)
{
  return ($v === NULL) ? '—' : number_format((float) $v, 2, '.', '') . ' kWh';
}


// ══════════════════════════════════════════════════════════════════════
// FASE 0: Pre-flight checks
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 0 — Pre-flight Checks');

// Cek tabel ada
$tables_needed = array('data_electricity_iot5', 'electricity_panel_iot5', 'ems_tariff_config', 'ems_tariff_daily');
foreach ($tables_needed as $tbl) {
  $exists = $CI->db->query("SHOW TABLES LIKE ?", array($tbl))->num_rows() > 0;
  test_assert("Tabel `$tbl` ada di DB", $exists);
}

// Cek config tarif
$config = $CI->Readings_model->get_tariff_config();
test_assert('Config tarif aktif tersedia', $config !== NULL);
if ($config !== NULL) {
  test_assert('Tarif LWBP = 1035.78', abs((float) $config['tarif_lwbp'] - 1035.78) < 0.01,
    'Aktual: ' . $config['tarif_lwbp']);
  test_assert('Faktor K = 1.5', abs((float) $config['faktor_k'] - 1.5) < 0.01,
    'Aktual: ' . $config['faktor_k']);
}

// Cek panel terdaftar
$panels = $CI->db->select('id, code, name')
  ->order_by('sort_order', 'ASC')
  ->get('electricity_panel_iot5')->result_array();
test_assert('Minimal 1 panel terdaftar', count($panels) >= 1,
  'Jumlah panel: ' . count($panels));

foreach ($panels as $p) {
  $TEST_PANELS[$p['code']] = (int) $p['id'];
  test_info("Panel terdaftar: id={$p['id']}, code={$p['code']}, name={$p['name']}");
}

// ══════════════════════════════════════════════════════════════════════
// FASE 1: Generate & INSERT data dummy ke data_electricity_iot5
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 1 — Generate Data Dummy di data_electricity_iot5');

/**
 * Generate data dummy per menit untuk 1 panel × 1 hari.
 * kWh = kumulatif naik (simulasi meter).
 * kW = rata-rata acak realistis per blok (pabrik kecil–menengah).
 *
 * Return: array of row arrays, + 'expected' => kWh per blok (ground truth).
 */
function generate_daily_dummy($panel_id, $date, $seed_kwh)
{
  $rows     = array();
  $kwh_cur  = (float) $seed_kwh;
  $expected = array(
    'lwbp1_kwh' => 0.0, 'lwbp2_kwh' => 0.0, 'wbp_kwh' => 0.0,
    'lwbp1_min' => PHP_FLOAT_MAX, 'lwbp1_max' => 0.0,
    'lwbp2_pagi_min' => PHP_FLOAT_MAX, 'lwbp2_pagi_max' => 0.0,
    'lwbp2_malam_min' => PHP_FLOAT_MAX, 'lwbp2_malam_max' => 0.0,
    'wbp_min' => PHP_FLOAT_MAX, 'wbp_max' => 0.0,
    'n_lwbp1' => 0, 'n_lwbp2' => 0, 'n_wbp' => 0,
  );

  // Simulasi setiap menit dari 00:00 s/d 23:59
  for ($h = 0; $h < 24; $h++) {
    for ($m = 0; $m < 60; $m++) {
      $time_str = sprintf('%s %02d:%02d:00', $date, $h, $m);

      // Konsumsi per menit bergantung blok waktu (realistis pabrik)
      // LWBP1 (06-17): ~0.20 kWh/menit (12 kW avg)
      // WBP (17-22): ~0.15 kWh/menit (9 kW avg, operasi berkurang)
      // LWBP2 (22-06): ~0.05 kWh/menit (3 kW avg, hanya beban dasar)
      if ($h >= 6 && $h < 17) {
        $delta = 0.18 + (mt_rand(0, 40) / 1000.0);  // 0.18 - 0.22
        $kw_val = $delta * 60;  // approx kW
        $blok   = 'lwbp1';
      } elseif ($h >= 17 && $h < 22) {
        $delta = 0.13 + (mt_rand(0, 40) / 1000.0);  // 0.13 - 0.17
        $kw_val = $delta * 60;
        $blok   = 'wbp';
      } else {
        // LWBP2: jam 22-23 (malam) atau 00-05 (pagi)
        $delta = 0.03 + (mt_rand(0, 40) / 1000.0);  // 0.03 - 0.07
        $kw_val = $delta * 60;
        $blok   = ($h >= 22) ? 'lwbp2_malam' : 'lwbp2_pagi';
      }

      $kwh_cur += $delta;
      $kwh_cur = round($kwh_cur, 2);

      // Track min/max per blok (ground truth untuk verifikasi)
      if ($blok === 'lwbp1') {
        $expected['n_lwbp1']++;
        if ($kwh_cur < $expected['lwbp1_min']) $expected['lwbp1_min'] = $kwh_cur;
        if ($kwh_cur > $expected['lwbp1_max']) $expected['lwbp1_max'] = $kwh_cur;
      } elseif ($blok === 'wbp') {
        $expected['n_wbp']++;
        if ($kwh_cur < $expected['wbp_min']) $expected['wbp_min'] = $kwh_cur;
        if ($kwh_cur > $expected['wbp_max']) $expected['wbp_max'] = $kwh_cur;
      } elseif ($blok === 'lwbp2_pagi') {
        $expected['n_lwbp2']++;
        if ($kwh_cur < $expected['lwbp2_pagi_min']) $expected['lwbp2_pagi_min'] = $kwh_cur;
        if ($kwh_cur > $expected['lwbp2_pagi_max']) $expected['lwbp2_pagi_max'] = $kwh_cur;
      } elseif ($blok === 'lwbp2_malam') {
        $expected['n_lwbp2']++;
        if ($kwh_cur < $expected['lwbp2_malam_min']) $expected['lwbp2_malam_min'] = $kwh_cur;
        if ($kwh_cur > $expected['lwbp2_malam_max']) $expected['lwbp2_malam_max'] = $kwh_cur;
      }

      // Simulasi tegangan & arus realistis (3 phase, pabrik)
      $v_r  = 218.0 + (mt_rand(-30, 30) / 10.0);
      $v_s  = 219.0 + (mt_rand(-30, 30) / 10.0);
      $v_t  = 220.0 + (mt_rand(-30, 30) / 10.0);
      $v_rs = 380.0 + (mt_rand(-20, 20) / 10.0);
      $v_st = 381.0 + (mt_rand(-20, 20) / 10.0);
      $v_rt = 379.0 + (mt_rand(-20, 20) / 10.0);
      $i_r  = $kw_val / (3 * $v_r * 0.85) * 1000;
      $i_s  = $kw_val / (3 * $v_s * 0.85) * 1000;
      $i_t  = $kw_val / (3 * $v_t * 0.85) * 1000;
      $pf   = 0.82 + (mt_rand(0, 60) / 1000.0);

      $rows[] = array(
        'panel_id'    => (int) $panel_id,
        'recorded_at' => $time_str,
        'v_r'         => round($v_r, 2),
        'v_s'         => round($v_s, 2),
        'v_t'         => round($v_t, 2),
        'v_rs'        => round($v_rs, 2),
        'v_st'        => round($v_st, 2),
        'v_rt'        => round($v_rt, 2),
        'i_r'         => round($i_r, 2),
        'i_s'         => round($i_s, 2),
        'i_t'         => round($i_t, 2),
        'pf'          => round($pf, 4),
        'kw'          => round($kw_val, 2),
        'kwh'         => $kwh_cur,
      );
    }
  }

  // Hitung expected kWh per blok dari ground truth min/max
  $expected['lwbp1_kwh'] = ($expected['n_lwbp1'] >= 2)
    ? round($expected['lwbp1_max'] - $expected['lwbp1_min'], 2) : 0.0;

  $lwbp2_pagi_kwh  = ($expected['lwbp2_pagi_min'] < PHP_FLOAT_MAX && $expected['lwbp2_pagi_max'] > 0)
    ? max(0, $expected['lwbp2_pagi_max'] - $expected['lwbp2_pagi_min']) : 0.0;
  $lwbp2_malam_kwh = ($expected['lwbp2_malam_min'] < PHP_FLOAT_MAX && $expected['lwbp2_malam_max'] > 0)
    ? max(0, $expected['lwbp2_malam_max'] - $expected['lwbp2_malam_min']) : 0.0;
  $expected['lwbp2_kwh'] = round($lwbp2_pagi_kwh + $lwbp2_malam_kwh, 2);

  $expected['wbp_kwh'] = ($expected['n_wbp'] >= 2)
    ? round($expected['wbp_max'] - $expected['wbp_min'], 2) : 0.0;

  return array('rows' => $rows, 'expected' => $expected, 'last_kwh' => $kwh_cur);
}

// Cleanup data dummy lama (jika ada dari test sebelumnya)
echo "  Cleanup data dummy lama...\n";
foreach ($TEST_DATES as $d) {
  $CI->db->query(
    "DELETE FROM `data_electricity_iot5` WHERE `recorded_at` >= ? AND `recorded_at` < ?",
    array($d . ' 00:00:00', $d . ' 23:59:59')
  );
  $CI->db->query(
    "DELETE FROM `ems_tariff_daily` WHERE `tanggal` = ?",
    array($d)
  );
}

// Generate & insert per panel × per hari
$all_expected = array();  // [panel_code][date] => expected
$seed = 1000.00;  // kWh kumulatif awal

foreach ($TEST_PANELS as $code => $pid) {
  $panel_seed = $seed;
  $all_expected[$code] = array();

  foreach ($TEST_DATES as $date) {
    $gen = generate_daily_dummy($pid, $date, $panel_seed);

    // Batch insert (60 rows per batch untuk efisiensi)
    $batch_size = 60;
    $chunks = array_chunk($gen['rows'], $batch_size);
    $inserted = 0;

    foreach ($chunks as $chunk) {
      // Build multi-value INSERT
      $sql_vals = array();
      $binds    = array();
      foreach ($chunk as $row) {
        $sql_vals[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $binds[] = $row['panel_id'];
        $binds[] = $row['recorded_at'];
        $binds[] = $row['v_r'];
        $binds[] = $row['v_s'];
        $binds[] = $row['v_t'];
        $binds[] = $row['v_rs'];
        $binds[] = $row['v_st'];
        $binds[] = $row['v_rt'];
        $binds[] = $row['i_r'];
        $binds[] = $row['i_s'];
        $binds[] = $row['i_t'];
        $binds[] = $row['pf'];
        $binds[] = $row['kw'];
        $binds[] = $row['kwh'];
      }
      $sql = "INSERT INTO `data_electricity_iot5`"
        . " (`panel_id`,`recorded_at`,`v_r`,`v_s`,`v_t`,`v_rs`,`v_st`,`v_rt`,"
        . "`i_r`,`i_s`,`i_t`,`pf`,`kw`,`kwh`) VALUES "
        . implode(',', $sql_vals);
      $CI->db->query($sql, $binds);
      $inserted += count($chunk);
    }

    $all_expected[$code][$date] = $gen['expected'];
    $panel_seed = $gen['last_kwh'];

    test_info("Panel $code ($pid), $date: $inserted baris dummy inserted"
      . " | LWBP1=" . fmt_kwh($gen['expected']['lwbp1_kwh'])
      . " | WBP=" . fmt_kwh($gen['expected']['wbp_kwh'])
      . " | LWBP2=" . fmt_kwh($gen['expected']['lwbp2_kwh']));
  }
}

// Verifikasi data dummy tercatat
test_section('Verifikasi data dummy di DB');
foreach ($TEST_PANELS as $code => $pid) {
  foreach ($TEST_DATES as $date) {
    $cnt = $CI->db->query(
      "SELECT COUNT(*) AS n FROM `data_electricity_iot5` WHERE `panel_id` = ? AND DATE(`recorded_at`) = ?",
      array($pid, $date)
    )->row_array();
    $n = (int) $cnt['n'];
    test_assert("Panel $code, $date: 1440 baris tercatat", $n === 1440,
      "Aktual: $n baris");
  }
}


// ══════════════════════════════════════════════════════════════════════
// FASE 2: Hitung kWh via Readings_model (logika existing) & bandingkan
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 2 — Korelasi: kwh_by_tou_block() vs Ground Truth');

foreach ($TEST_PANELS as $code => $pid) {
  test_section("Panel: $code (id=$pid)");

  foreach ($TEST_DATES as $date) {
    echo "\n    ── Tanggal: $date ──\n";

    // Hitung via model (logika yang sama dipakai card dashboard & job tarif harian)
    $model_result = $CI->Readings_model->kwh_by_tou_block($pid, $date, $TOU_HOURS);

    // Ground truth dari generate
    $expected = $all_expected[$code][$date];

    // Test: kWh per blok
    $epsilon = 0.02;  // toleransi rounding DECIMAL(14,2)

    test_assert(
      "LWBP1 kWh: model=" . $model_result['lwbp1'] . " vs expected=" . $expected['lwbp1_kwh'],
      abs($model_result['lwbp1'] - $expected['lwbp1_kwh']) <= $epsilon,
      'Diff: ' . abs($model_result['lwbp1'] - $expected['lwbp1_kwh'])
    );

    test_assert(
      "WBP kWh: model=" . $model_result['wbp'] . " vs expected=" . $expected['wbp_kwh'],
      abs($model_result['wbp'] - $expected['wbp_kwh']) <= $epsilon,
      'Diff: ' . abs($model_result['wbp'] - $expected['wbp_kwh'])
    );

    test_assert(
      "LWBP2 kWh: model=" . $model_result['lwbp2'] . " vs expected=" . $expected['lwbp2_kwh'],
      abs($model_result['lwbp2'] - $expected['lwbp2_kwh']) <= $epsilon,
      'Diff: ' . abs($model_result['lwbp2'] - $expected['lwbp2_kwh'])
    );

    // Test: n_data per blok
    test_assert(
      "n_lwbp1: model=" . $model_result['n_lwbp1'] . " vs expected=" . $expected['n_lwbp1'],
      $model_result['n_lwbp1'] === $expected['n_lwbp1']
    );
    test_assert(
      "n_wbp: model=" . $model_result['n_wbp'] . " vs expected=" . $expected['n_wbp'],
      $model_result['n_wbp'] === $expected['n_wbp']
    );
    test_assert(
      "n_lwbp2: model=" . $model_result['n_lwbp2'] . " vs expected=" . $expected['n_lwbp2'],
      $model_result['n_lwbp2'] === $expected['n_lwbp2']
    );

    // Test: total kWh = sum of blocks
    $sum_blocks = $model_result['lwbp1'] + $model_result['lwbp2'] + $model_result['wbp'];
    test_assert(
      "Total kWh = LWBP1+LWBP2+WBP: " . $model_result['total'] . " == $sum_blocks",
      abs($model_result['total'] - $sum_blocks) < 0.001
    );

    // Test: has_data = true (kita punya data lengkap)
    test_assert('has_data = TRUE', $model_result['has_data'] === TRUE);
  }
}


// ══════════════════════════════════════════════════════════════════════
// FASE 3: Simulasi populasi ems_tariff_daily (mirip job daily_tarif_job)
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 3 — Populasi ems_tariff_daily dari data dummy');

$tarif_config = $CI->Readings_model->get_tariff_config();
$tarif_config_id = (int) $tarif_config['id'];

foreach ($TEST_PANELS as $code => $pid) {
  foreach ($TEST_DATES as $date) {
    // 1) Hitung kWh per blok
    $kwh = $CI->Readings_model->kwh_by_tou_block($pid, $date, $TOU_HOURS);

    if (!$kwh['has_data']) {
      test_warn("Panel $code, $date: no data — skip (seharusnya tidak terjadi)");
      continue;
    }

    // 2) Hitung biaya (logika tarif_calculator)
    $biaya_lwbp1 = (int) round($kwh['lwbp1'] * $TARIF_LWBP);
    $biaya_lwbp2 = (int) round($kwh['lwbp2'] * $TARIF_LWBP);
    $biaya_lwbp  = $biaya_lwbp1 + $biaya_lwbp2;
    $biaya_wbp   = (int) round($kwh['wbp'] * $TARIF_WBP);
    $biaya_total = $biaya_lwbp + $biaya_wbp;

    // 3) UPSERT ke ems_tariff_daily
    $kwh_lwbp1 = ($kwh['n_lwbp1'] >= 2) ? $kwh['lwbp1'] : NULL;
    $kwh_lwbp2 = ($kwh['n_lwbp2'] >= 2) ? $kwh['lwbp2'] : NULL;
    $kwh_lwbp_val = ($kwh_lwbp1 === NULL && $kwh_lwbp2 === NULL) ? NULL
      : (($kwh_lwbp1 === NULL ? 0 : $kwh_lwbp1) + ($kwh_lwbp2 === NULL ? 0 : $kwh_lwbp2));
    $kwh_wbp_val = ($kwh['n_wbp'] >= 2) ? $kwh['wbp'] : NULL;

    $biaya_lwbp_val = ($kwh_lwbp1 === NULL && $kwh_lwbp2 === NULL) ? NULL : $biaya_lwbp;
    $biaya_wbp_val  = ($kwh_wbp_val === NULL) ? NULL : $biaya_wbp;
    $biaya_total_val = ($biaya_lwbp_val === NULL && $biaya_wbp_val === NULL) ? NULL : $biaya_total;

    $sql = "INSERT INTO `ems_tariff_daily`"
      . " (`panel_id`,`tanggal`,`kwh_lwbp1`,`kwh_lwbp2`,`kwh_lwbp`,`kwh_wbp`,"
      . "`tarif_lwbp_rp`,`faktor_k`,`tarif_wbp_rp`,"
      . "`biaya_lwbp`,`biaya_wbp`,`biaya_total`,"
      . "`n_lwbp1`,`n_lwbp2`,`n_wbp`,`is_final`,`tarif_config_id`)"
      . " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
      . " ON DUPLICATE KEY UPDATE"
      . " `kwh_lwbp1`=VALUES(`kwh_lwbp1`), `kwh_lwbp2`=VALUES(`kwh_lwbp2`),"
      . " `kwh_lwbp`=VALUES(`kwh_lwbp`), `kwh_wbp`=VALUES(`kwh_wbp`),"
      . " `tarif_lwbp_rp`=VALUES(`tarif_lwbp_rp`), `faktor_k`=VALUES(`faktor_k`),"
      . " `tarif_wbp_rp`=VALUES(`tarif_wbp_rp`),"
      . " `biaya_lwbp`=VALUES(`biaya_lwbp`), `biaya_wbp`=VALUES(`biaya_wbp`),"
      . " `biaya_total`=VALUES(`biaya_total`),"
      . " `n_lwbp1`=VALUES(`n_lwbp1`), `n_lwbp2`=VALUES(`n_lwbp2`), `n_wbp`=VALUES(`n_wbp`),"
      . " `is_final`=VALUES(`is_final`), `tarif_config_id`=VALUES(`tarif_config_id`)";
    $CI->db->query($sql, array(
      $pid, $date, $kwh_lwbp1, $kwh_lwbp2, $kwh_lwbp_val, $kwh_wbp_val,
      $TARIF_LWBP, $FAKTOR_K, $TARIF_WBP,
      $biaya_lwbp_val, $biaya_wbp_val, $biaya_total_val,
      $kwh['n_lwbp1'], $kwh['n_lwbp2'], $kwh['n_wbp'],
      1, $tarif_config_id,
    ));

    test_info("UPSERT ems_tariff_daily: panel=$code, tanggal=$date"
      . " | kWh LWBP=" . fmt_kwh($kwh_lwbp_val) . " | kWh WBP=" . fmt_kwh($kwh_wbp_val)
      . " | Biaya=" . fmt_rp($biaya_total_val));
  }
}


// ══════════════════════════════════════════════════════════════════════
// FASE 4: Verifikasi korelasi ems_tariff_daily ↔ data_electricity_iot5
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 4 — Verifikasi Korelasi ems_tariff_daily ↔ data_electricity_iot5');

foreach ($TEST_PANELS as $code => $pid) {
  test_section("Panel: $code (id=$pid)");

  foreach ($TEST_DATES as $date) {
    echo "\n    ── Tanggal: $date ──\n";

    // A) Baca baris ems_tariff_daily
    $daily_row = $CI->db->query(
      "SELECT * FROM `ems_tariff_daily` WHERE `panel_id` = ? AND `tanggal` = ?",
      array($pid, $date)
    )->row_array();

    test_assert("Baris ems_tariff_daily ada untuk panel $code, $date", $daily_row !== NULL);

    if ($daily_row === NULL) {
      test_warn("SKIP verifikasi detail — baris tidak ditemukan");
      continue;
    }

    // B) Hitung ulang langsung dari data_electricity_iot5 (query independen)
    //    Ini BUKAN via model — ini raw SQL langsung, sebagai verifikasi independen

    // LWBP1: 06:00 - 16:59
    $raw_lwbp1 = $CI->db->query(
      "SELECT MAX(`kwh`) AS mx, MIN(`kwh`) AS mn, COUNT(*) AS n"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ? AND `recorded_at` >= ? AND `recorded_at` <= ?",
      array($pid, "$date 06:00:00", "$date 16:59:00")
    )->row_array();
    $raw_lwbp1_kwh = ((int) $raw_lwbp1['n'] >= 2)
      ? round((float) $raw_lwbp1['mx'] - (float) $raw_lwbp1['mn'], 2) : 0.0;

    // WBP: 17:00 - 21:59
    $raw_wbp = $CI->db->query(
      "SELECT MAX(`kwh`) AS mx, MIN(`kwh`) AS mn, COUNT(*) AS n"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ? AND `recorded_at` >= ? AND `recorded_at` <= ?",
      array($pid, "$date 17:00:00", "$date 21:59:00")
    )->row_array();
    $raw_wbp_kwh = ((int) $raw_wbp['n'] >= 2)
      ? round((float) $raw_wbp['mx'] - (float) $raw_wbp['mn'], 2) : 0.0;

    // LWBP2 pagi: 00:00 - 05:59
    $raw_l2p = $CI->db->query(
      "SELECT MAX(`kwh`) AS mx, MIN(`kwh`) AS mn, COUNT(*) AS n"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ? AND `recorded_at` >= ? AND `recorded_at` <= ?",
      array($pid, "$date 00:00:00", "$date 05:59:00")
    )->row_array();
    $raw_l2p_kwh = ((int) $raw_l2p['n'] >= 2)
      ? round((float) $raw_l2p['mx'] - (float) $raw_l2p['mn'], 2) : 0.0;

    // LWBP2 malam: 22:00 - 23:59
    $raw_l2m = $CI->db->query(
      "SELECT MAX(`kwh`) AS mx, MIN(`kwh`) AS mn, COUNT(*) AS n"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ? AND `recorded_at` >= ? AND `recorded_at` <= ?",
      array($pid, "$date 22:00:00", "$date 23:59:00")
    )->row_array();
    $raw_l2m_kwh = ((int) $raw_l2m['n'] >= 2)
      ? round((float) $raw_l2m['mx'] - (float) $raw_l2m['mn'], 2) : 0.0;

    $raw_lwbp2_kwh = round($raw_l2p_kwh + $raw_l2m_kwh, 2);
    $raw_lwbp_kwh  = round($raw_lwbp1_kwh + $raw_lwbp2_kwh, 2);

    // C) Hitung biaya dari raw data (independen)
    $raw_biaya_lwbp1 = (int) round($raw_lwbp1_kwh * $TARIF_LWBP);
    $raw_biaya_lwbp2 = (int) round($raw_lwbp2_kwh * $TARIF_LWBP);
    $raw_biaya_lwbp  = $raw_biaya_lwbp1 + $raw_biaya_lwbp2;
    $raw_biaya_wbp   = (int) round($raw_wbp_kwh * $TARIF_WBP);
    $raw_biaya_total = $raw_biaya_lwbp + $raw_biaya_wbp;

    // D) COMPARE: ems_tariff_daily vs raw calculation dari data_electricity_iot5
    $eps = 0.02;

    test_assert(
      "kWh LWBP: tariff_daily=" . $daily_row['kwh_lwbp'] . " vs raw=$raw_lwbp_kwh",
      abs((float) $daily_row['kwh_lwbp'] - $raw_lwbp_kwh) <= $eps,
      'Diff=' . abs((float) $daily_row['kwh_lwbp'] - $raw_lwbp_kwh)
    );

    test_assert(
      "kWh WBP: tariff_daily=" . $daily_row['kwh_wbp'] . " vs raw=$raw_wbp_kwh",
      abs((float) $daily_row['kwh_wbp'] - $raw_wbp_kwh) <= $eps,
      'Diff=' . abs((float) $daily_row['kwh_wbp'] - $raw_wbp_kwh)
    );

    test_assert(
      "Biaya LWBP: tariff_daily=" . $daily_row['biaya_lwbp'] . " vs raw=$raw_biaya_lwbp",
      (int) $daily_row['biaya_lwbp'] === $raw_biaya_lwbp,
      'Diff=' . abs((int) $daily_row['biaya_lwbp'] - $raw_biaya_lwbp)
    );

    test_assert(
      "Biaya WBP: tariff_daily=" . $daily_row['biaya_wbp'] . " vs raw=$raw_biaya_wbp",
      (int) $daily_row['biaya_wbp'] === $raw_biaya_wbp,
      'Diff=' . abs((int) $daily_row['biaya_wbp'] - $raw_biaya_wbp)
    );

    test_assert(
      "Biaya Total: tariff_daily=" . $daily_row['biaya_total'] . " vs raw=$raw_biaya_total",
      (int) $daily_row['biaya_total'] === $raw_biaya_total,
      'Diff=' . abs((int) $daily_row['biaya_total'] - $raw_biaya_total)
    );

    // E) Cek snapshot tarif tersimpan benar
    test_assert(
      "Snapshot tarif_lwbp_rp=" . $daily_row['tarif_lwbp_rp'],
      abs((float) $daily_row['tarif_lwbp_rp'] - $TARIF_LWBP) < 0.01
    );
    test_assert(
      "Snapshot faktor_k=" . $daily_row['faktor_k'],
      abs((float) $daily_row['faktor_k'] - $FAKTOR_K) < 0.01
    );
    test_assert(
      "Snapshot tarif_wbp_rp=" . $daily_row['tarif_wbp_rp'],
      abs((float) $daily_row['tarif_wbp_rp'] - $TARIF_WBP) < 0.01
    );

    // F) Cek konsistensi internal baris
    test_assert(
      "biaya_total = biaya_lwbp + biaya_wbp",
      (int) $daily_row['biaya_total'] === ((int) $daily_row['biaya_lwbp'] + (int) $daily_row['biaya_wbp'])
    );
    test_assert(
      "kwh_lwbp ≈ kwh_lwbp1 + kwh_lwbp2",
      abs((float) $daily_row['kwh_lwbp'] - ((float) $daily_row['kwh_lwbp1'] + (float) $daily_row['kwh_lwbp2'])) < 0.02
    );

    // G) Cek tarif efektif wajar (antara LWBP dan WBP)
    if ((float) $daily_row['biaya_total'] > 0) {
      $total_kwh = (float) $daily_row['kwh_lwbp'] + (float) $daily_row['kwh_wbp'];
      if ($total_kwh > 0) {
        $tarif_efektif = (float) $daily_row['biaya_total'] / $total_kwh;
        $wajar = ($tarif_efektif >= $TARIF_LWBP * 0.95 && $tarif_efektif <= $TARIF_WBP * 1.05);
        test_assert(
          "Tarif efektif wajar: Rp " . number_format($tarif_efektif, 2) . "/kWh"
            . " (antara " . number_format($TARIF_LWBP, 2) . " dan " . number_format($TARIF_WBP, 2) . ")",
          $wajar
        );
      }
    }
  }
}


// ══════════════════════════════════════════════════════════════════════
// FASE 5: Verifikasi tampilan tabel Tarif Konsumsi (query view)
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 5 — Verifikasi Query Tampilan Tabel "Tarif Konsumsi"');

// Simulasi query yang dipakai oleh tariff_daily_latest()
$view_rows = $CI->db->query(
  "SELECT"
  . " `d`.`tanggal`, `p`.`name` AS `panel`, `p`.`code` AS `panel_code`,"
  . " `d`.`kwh_lwbp`, `d`.`kwh_wbp`,"
  . " `d`.`biaya_lwbp`, `d`.`biaya_wbp`, `d`.`biaya_total`,"
  . " `d`.`is_final`"
  . " FROM `ems_tariff_daily` `d`"
  . " JOIN `electricity_panel_iot5` `p` ON `p`.`id` = `d`.`panel_id`"
  . " WHERE `d`.`tanggal` IN (?,?,?)"
  . " ORDER BY `d`.`tanggal` DESC, `p`.`sort_order` ASC, `d`.`id` DESC",
  $TEST_DATES
)->result_array();

$expected_rows = count($TEST_PANELS) * count($TEST_DATES);
test_assert(
  "Jumlah baris view = $expected_rows (panel × hari)",
  count($view_rows) === $expected_rows,
  'Aktual: ' . count($view_rows)
);

// Tampilkan simulasi tabel
echo "\n  ┌──────────────┬──────────┬──────────────┬──────────────┬───────────────┬───────────────┬───────────────┐\n";
echo "  │ Tanggal      │ Lokasi   │ LWBP (KWh)   │ WBP (KWh)    │ Tarif LWBP Rp │ Tarif WBP Rp  │ Total Tarif   │\n";
echo "  ├──────────────┼──────────┼──────────────┼──────────────┼───────────────┼───────────────┼───────────────┤\n";

$bulan = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
               7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');
foreach ($view_rows as $row) {
  $parts  = explode('-', $row['tanggal']);
  $tgl    = $parts[2] . ' ' . $bulan[(int) $parts[1]] . ' ' . $parts[0];
  $kwh_l  = ($row['kwh_lwbp'] !== NULL) ? number_format((float) $row['kwh_lwbp'], 2) : '—';
  $kwh_w  = ($row['kwh_wbp'] !== NULL) ? number_format((float) $row['kwh_wbp'], 2) : '—';
  $rp_l   = ($row['biaya_lwbp'] !== NULL) ? number_format((float) $row['biaya_lwbp'], 0, ',', '.') : '—';
  $rp_w   = ($row['biaya_wbp'] !== NULL) ? number_format((float) $row['biaya_wbp'], 0, ',', '.') : '—';
  $rp_t   = ($row['biaya_total'] !== NULL) ? number_format((float) $row['biaya_total'], 0, ',', '.') : '—';

  echo "  │ " . str_pad($tgl, 12) . " │ "
    . str_pad($row['panel'], 8) . " │ "
    . str_pad($kwh_l, 12, ' ', STR_PAD_LEFT) . " │ "
    . str_pad($kwh_w, 12, ' ', STR_PAD_LEFT) . " │ "
    . str_pad($rp_l, 13, ' ', STR_PAD_LEFT) . " │ "
    . str_pad($rp_w, 13, ' ', STR_PAD_LEFT) . " │ "
    . str_pad($rp_t, 13, ' ', STR_PAD_LEFT) . " │\n";
}
echo "  └──────────────┴──────────┴──────────────┴──────────────┴───────────────┴───────────────┴───────────────┘\n";

// Verifikasi setiap baris view berkorelasi dengan data sumber
test_section('Verifikasi korelasi setiap baris view ↔ data sumber');

foreach ($view_rows as $row) {
  $panel_code = $row['panel_code'];
  $date       = $row['tanggal'];
  $pid        = $TEST_PANELS[$panel_code];

  // Hitung ulang dari sumber (data_electricity_iot5) langsung
  $model_kwh = $CI->Readings_model->kwh_by_tou_block($pid, $date, $TOU_HOURS);

  $eps = 0.02;
  $kwh_lwbp_expected = round($model_kwh['lwbp'], 2);
  $kwh_wbp_expected  = round($model_kwh['wbp'], 2);

  $match_lwbp = abs((float) $row['kwh_lwbp'] - $kwh_lwbp_expected) <= $eps;
  $match_wbp  = abs((float) $row['kwh_wbp'] - $kwh_wbp_expected) <= $eps;

  test_assert(
    "View baris $date/$panel_code: kWh LWBP cocok dgn sumber",
    $match_lwbp,
    "view=" . $row['kwh_lwbp'] . " vs sumber=$kwh_lwbp_expected"
  );
  test_assert(
    "View baris $date/$panel_code: kWh WBP cocok dgn sumber",
    $match_wbp,
    "view=" . $row['kwh_wbp'] . " vs sumber=$kwh_wbp_expected"
  );
}


// ══════════════════════════════════════════════════════════════════════
// FASE 6: Test Edge Cases
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 6 — Edge Cases');

// Test: idempoten — re-UPSERT harus menghasilkan data yang sama
test_section('Idempoten: re-UPSERT ems_tariff_daily');

$test_pid  = reset($TEST_PANELS);  // panel pertama
$test_date = $TEST_DATES[0];
$test_code = array_keys($TEST_PANELS)[0];

// Baca sebelum re-upsert
$before = $CI->db->query(
  "SELECT * FROM `ems_tariff_daily` WHERE `panel_id` = ? AND `tanggal` = ?",
  array($test_pid, $test_date)
)->row_array();

// Re-hitung + re-upsert
$kwh_re = $CI->Readings_model->kwh_by_tou_block($test_pid, $test_date, $TOU_HOURS);
$biaya_lwbp1_re = (int) round($kwh_re['lwbp1'] * $TARIF_LWBP);
$biaya_lwbp2_re = (int) round($kwh_re['lwbp2'] * $TARIF_LWBP);
$biaya_lwbp_re  = $biaya_lwbp1_re + $biaya_lwbp2_re;
$biaya_wbp_re   = (int) round($kwh_re['wbp'] * $TARIF_WBP);
$biaya_total_re = $biaya_lwbp_re + $biaya_wbp_re;

$CI->db->query(
  "INSERT INTO `ems_tariff_daily`"
  . " (`panel_id`,`tanggal`,`kwh_lwbp1`,`kwh_lwbp2`,`kwh_lwbp`,`kwh_wbp`,"
  . "`tarif_lwbp_rp`,`faktor_k`,`tarif_wbp_rp`,"
  . "`biaya_lwbp`,`biaya_wbp`,`biaya_total`,"
  . "`n_lwbp1`,`n_lwbp2`,`n_wbp`,`is_final`,`tarif_config_id`)"
  . " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
  . " ON DUPLICATE KEY UPDATE"
  . " `kwh_lwbp1`=VALUES(`kwh_lwbp1`), `kwh_lwbp2`=VALUES(`kwh_lwbp2`),"
  . " `kwh_lwbp`=VALUES(`kwh_lwbp`), `kwh_wbp`=VALUES(`kwh_wbp`),"
  . " `tarif_lwbp_rp`=VALUES(`tarif_lwbp_rp`), `faktor_k`=VALUES(`faktor_k`),"
  . " `tarif_wbp_rp`=VALUES(`tarif_wbp_rp`),"
  . " `biaya_lwbp`=VALUES(`biaya_lwbp`), `biaya_wbp`=VALUES(`biaya_wbp`),"
  . " `biaya_total`=VALUES(`biaya_total`),"
  . " `n_lwbp1`=VALUES(`n_lwbp1`), `n_lwbp2`=VALUES(`n_lwbp2`), `n_wbp`=VALUES(`n_wbp`),"
  . " `is_final`=VALUES(`is_final`), `tarif_config_id`=VALUES(`tarif_config_id`)",
  array(
    $test_pid, $test_date, $kwh_re['lwbp1'], $kwh_re['lwbp2'],
    $kwh_re['lwbp1'] + $kwh_re['lwbp2'], $kwh_re['wbp'],
    $TARIF_LWBP, $FAKTOR_K, $TARIF_WBP,
    $biaya_lwbp_re, $biaya_wbp_re, $biaya_total_re,
    $kwh_re['n_lwbp1'], $kwh_re['n_lwbp2'], $kwh_re['n_wbp'],
    1, $tarif_config_id,
  )
);

$after = $CI->db->query(
  "SELECT * FROM `ems_tariff_daily` WHERE `panel_id` = ? AND `tanggal` = ?",
  array($test_pid, $test_date)
)->row_array();

test_assert('Idempoten: id tidak berubah', $before['id'] === $after['id']);
test_assert('Idempoten: kwh_lwbp tidak berubah', $before['kwh_lwbp'] === $after['kwh_lwbp']);
test_assert('Idempoten: biaya_total tidak berubah', $before['biaya_total'] === $after['biaya_total']);

// Test: FK constraint — panel harus valid
test_section('FK Constraint: panel_id harus valid');

// Matikan db_debug sementara agar CI3 tidak die() saat FK error
$old_debug = $CI->db->db_debug;
$CI->db->db_debug = FALSE;

$fk_result = $CI->db->query(
  "INSERT INTO `ems_tariff_daily`"
  . " (`panel_id`,`tanggal`,`tarif_lwbp_rp`,`faktor_k`,`tarif_wbp_rp`,`tarif_config_id`)"
  . " VALUES (?,?,?,?,?,?)",
  array(255, '2000-01-01', $TARIF_LWBP, $FAKTOR_K, $TARIF_WBP, $tarif_config_id)
);
$db_error = $CI->db->error();
$fk_rejected = ($fk_result === FALSE || (int) $db_error['code'] !== 0);

// Restore db_debug
$CI->db->db_debug = $old_debug;

test_assert('FK panel_id: INSERT panel_id=255 ditolak oleh FK constraint', $fk_rejected,
  $fk_rejected ? 'FK constraint berjalan: ' . $db_error['message'] : 'INSERT seharusnya gagal');

// Cleanup baris gagal (jika ada — seharusnya tidak)
$CI->db->db_debug = FALSE;
$CI->db->query("DELETE FROM `ems_tariff_daily` WHERE `panel_id` = 255");
$CI->db->db_debug = $old_debug;

// Test: UNIQUE constraint
test_section('UNIQUE Constraint: (panel_id, tanggal)');
$cnt_before = $CI->db->query(
  "SELECT COUNT(*) AS n FROM `ems_tariff_daily` WHERE `panel_id` = ? AND `tanggal` = ?",
  array($test_pid, $test_date)
)->row_array();
test_assert('UNIQUE: hanya 1 baris per panel per tanggal', (int) $cnt_before['n'] === 1);


// ══════════════════════════════════════════════════════════════════════
// FASE 7: Cross-check total konsumsi harian
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 7 — Cross-check: Total kWh Harian (sum blok) vs MAX-MIN seluruh hari');

foreach ($TEST_PANELS as $code => $pid) {
  test_section("Panel: $code");

  foreach ($TEST_DATES as $date) {
    // Total kWh dari ems_tariff_daily (sum blok)
    $daily = $CI->db->query(
      "SELECT kwh_lwbp, kwh_wbp FROM `ems_tariff_daily` WHERE `panel_id` = ? AND `tanggal` = ?",
      array($pid, $date)
    )->row_array();
    $sum_blocks = (float) $daily['kwh_lwbp'] + (float) $daily['kwh_wbp'];

    // Total kWh dari data_electricity_iot5 (MAX-MIN seluruh hari)
    $raw_day = $CI->db->query(
      "SELECT MAX(`kwh`) AS mx, MIN(`kwh`) AS mn"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ? AND `recorded_at` >= ? AND `recorded_at` <= ?",
      array($pid, "$date 00:00:00", "$date 23:59:00")
    )->row_array();
    $total_day = round((float) $raw_day['mx'] - (float) $raw_day['mn'], 2);

    // Perbedaan kecil wajar karena gap transisi antar blok (spec §11.5)
    $diff = abs($sum_blocks - $total_day);
    $pct  = ($total_day > 0) ? ($diff / $total_day * 100) : 0;

    test_assert(
      "$date: sum_blok=" . number_format($sum_blocks, 2) . " vs day_total="
        . number_format($total_day, 2) . " (diff=" . number_format($diff, 2) . ", "
        . number_format($pct, 2) . "%)",
      $pct < 5.0,   // toleransi < 5%
      "Diff " . number_format($pct, 2) . "% — " . ($pct < 5 ? 'OK' : 'MELEBIHI toleransi 5%')
    );
  }
}


// ══════════════════════════════════════════════════════════════════════
// FASE 8: CLEANUP
// ══════════════════════════════════════════════════════════════════════

test_header('FASE 8 — Cleanup Data Dummy');

foreach ($TEST_DATES as $d) {
  // Hapus ems_tariff_daily dulu (FK)
  $CI->db->query("DELETE FROM `ems_tariff_daily` WHERE `tanggal` = ?", array($d));
  $del_tarif = $CI->db->affected_rows();

  // Hapus data_electricity_iot5
  $CI->db->query(
    "DELETE FROM `data_electricity_iot5` WHERE `recorded_at` >= ? AND `recorded_at` <= ?",
    array("$d 00:00:00", "$d 23:59:00")
  );
  $del_readings = $CI->db->affected_rows();

  test_info("Cleanup $d: $del_tarif baris tariff_daily + $del_readings baris readings dihapus");
}

// Verifikasi cleanup bersih
$cnt_tarif = $CI->db->query(
  "SELECT COUNT(*) AS n FROM `ems_tariff_daily` WHERE `tanggal` IN (?,?,?)",
  $TEST_DATES
)->row_array();
test_assert('Cleanup ems_tariff_daily bersih', (int) $cnt_tarif['n'] === 0);

$cnt_readings = $CI->db->query(
  "SELECT COUNT(*) AS n FROM `data_electricity_iot5`"
  . " WHERE `recorded_at` >= '2000-01-10 00:00:00' AND `recorded_at` <= '2000-01-12 23:59:00'"
)->row_array();
test_assert('Cleanup data_electricity_iot5 bersih', (int) $cnt_readings['n'] === 0);


// ══════════════════════════════════════════════════════════════════════
// RINGKASAN
// ══════════════════════════════════════════════════════════════════════

$C = $GLOBALS['_TEST_COUNTERS'];
echo "\n" . str_repeat('=', 70) . "\n";
echo "  RINGKASAN HASIL TEST KORELASI\n";
echo str_repeat('=', 70) . "\n\n";
echo "    Total test  : " . $C['total'] . "\n";
echo "    PASSED      : " . $C['passed'] . "\n";
echo "    FAILED      : " . $C['failed'] . "\n";
echo "    Warnings    : " . $C['warnings'] . "\n";
echo "\n";

if ($C['failed'] === 0) {
  echo "  +================================================================+\n";
  echo "  |  SEMUA TEST PASSED — Data ems_tariff_daily BERKORELASI         |\n";
  echo "  |  dengan data_electricity_iot5 untuk SETIAP panel.              |\n";
  echo "  |  Tampilan tabel Tarif Konsumsi di Export Data KONSISTEN.       |\n";
  echo "  +================================================================+\n";
} else {
  echo "  +================================================================+\n";
  echo "  |  ADA " . $C['failed'] . " TEST GAGAL — periksa detail di atas.              |\n";
  echo "  +================================================================+\n";
}
echo "\n";

