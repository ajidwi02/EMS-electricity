<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Readings_model — data layer time-series EMS (tabel `data_electricity_iot5` + `electricity_panel_iot5`).
 * Spesifikasi: plans/ems-database-schema.md §3, §5.3, §8 (SSOT CP-009/CP-010).
 */
class Readings_model extends CI_Model {

  protected $panel_cache = array();

  protected $metric_keys = array(
    'v_r', 'v_s', 'v_t', 'v_rs', 'v_st', 'v_rt',
    'i_r', 'i_s', 'i_t', 'pf', 'kw', 'kwh',
  );

  public function __construct()
  {
    parent::__construct();
    $this->load->database();
  }

  /**
   * Daftar panel aktif (subscribe & record), urut sort_order.
   * Return: array rows (id, code, name, mqtt_topic, sort_order).
   */
  public function get_active_panels()
  {
    return $this->db
      ->select('id, code, name, mqtt_topic, sort_order')
      ->where('is_active', 1)
      ->order_by('sort_order', 'ASC')
      ->get('electricity_panel_iot5')
      ->result_array();
  }

  /**
   * Lookup panel_id dari code, cache in-memory (strategi §5.1 langkah 3).
   * Return: int id, atau NULL bila code tidak terdaftar.
   */
  public function panel_id_by_code($code)
  {
    $code = strtolower(trim((string) $code));
    if ($code === '') {
      return NULL;
    }
    if (array_key_exists($code, $this->panel_cache)) {
      return $this->panel_cache[$code];
    }
    $row = $this->db->select('id')->where('code', $code)->get('electricity_panel_iot5')->row_array();
    if ($row === NULL) {
      return NULL;
    }
    $this->panel_cache[$code] = (int) $row['id'];
    return $this->panel_cache[$code];
  }

  /**
   * INSERT ... ON DUPLICATE KEY UPDATE — SQL persis plan §5.3.
   * idempoten: retry aman, last-write-wins per (panel_id, recorded_at).
   * Return: bool.
   */
  public function upsert_reading($panel_id, $recorded_at, $terminal_time, $metrics)
  {
    $m = $this->sanitize_metrics($metrics);
    $sql = "INSERT INTO `data_electricity_iot5`"
      . " (`panel_id`, `recorded_at`, `terminal_time`,"
      . "  `v_r`, `v_s`, `v_t`, `v_rs`, `v_st`, `v_rt`,"
      . "  `i_r`, `i_s`, `i_t`, `pf`, `kw`, `kwh`)"
      . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
      . " ON DUPLICATE KEY UPDATE"
      . "  `terminal_time` = VALUES(`terminal_time`),"
      . "  `v_r`  = VALUES(`v_r`),"
      . "  `v_s`  = VALUES(`v_s`),"
      . "  `v_t`  = VALUES(`v_t`),"
      . "  `v_rs` = VALUES(`v_rs`),"
      . "  `v_st` = VALUES(`v_st`),"
      . "  `v_rt` = VALUES(`v_rt`),"
      . "  `i_r`  = VALUES(`i_r`),"
      . "  `i_s`  = VALUES(`i_s`),"
      . "  `i_t`  = VALUES(`i_t`),"
      . "  `pf`   = VALUES(`pf`),"
      . "  `kw`   = VALUES(`kw`),"
      . "  `kwh`  = VALUES(`kwh`)";

    $binds = array(
      (int) $panel_id,
      (string) $recorded_at,
      $terminal_time !== NULL ? (string) $terminal_time : NULL,
      $m['v_r'], $m['v_s'], $m['v_t'], $m['v_rs'], $m['v_st'], $m['v_rt'],
      $m['i_r'], $m['i_s'], $m['i_t'], $m['pf'], $m['kw'], $m['kwh'],
    );
    return (bool) $this->db->query($sql, $binds);
  }

  /**
   * Record terbaru 1 panel (§8.1) — pakai index uq_panel_recorded.
   * Return: row array, atau NULL bila kosong.
   */
  public function latest_reading($panel_id)
  {
    $sql = "SELECT r.*, p.name AS panel_name"
      . " FROM `data_electricity_iot5` r"
      . " JOIN `electricity_panel_iot5` p ON p.`id` = r.`panel_id`"
      . " WHERE r.`panel_id` = ?"
      . " ORDER BY r.`recorded_at` DESC"
      . " LIMIT 1";
    $row = $this->db->query($sql, array((int) $panel_id))->row_array();
    return empty($row) ? NULL : $row;
  }

  /**
   * Data 24 jam terakhir 1 panel (§8.2), max 1.440 baris.
   */
  public function range_24h($panel_id)
  {
    $sql = "SELECT `recorded_at`, `v_r`, `v_s`, `v_t`, `kw`, `kwh`"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ?"
      . "   AND `recorded_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
      . " ORDER BY `recorded_at` ASC";
    return $this->db->query($sql, array((int) $panel_id))->result_array();
  }

  /**
   * Rata-rata per jam hari ini 1 panel (§8.3) — dipakai report/dashboard.
   */
  public function hourly_avg_today($panel_id)
  {
    $sql = "SELECT"
      . " DATE_FORMAT(`recorded_at`, '%Y-%m-%d %H:00') AS hour_bucket,"
      . " ROUND(AVG(`v_r`), 1) AS avg_v_r,"
      . " ROUND(AVG(`v_s`), 1) AS avg_v_s,"
      . " ROUND(AVG(`v_t`), 1) AS avg_v_t,"
      . " ROUND(AVG(`kw`), 1)  AS avg_kw,"
      . " MAX(`kwh`) AS last_kwh"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ?"
      . "   AND `recorded_at` >= CURDATE()"
      . " GROUP BY hour_bucket"
      . " ORDER BY hour_bucket";
    return $this->db->query($sql, array((int) $panel_id))->result_array();
  }

  /**
   * Deret per-baris hari ini untuk 1 panel (L-T/P-H fase 2, spec
   * plans/ems-lvmdp-db-trend.md §SS5-T2). Tanpa GROUP: uq_panel_recorded
   * menjamin 1 baris per (panel, menit); ORDER ASC (recorded_at, id)
   * deterministik; LIMIT 1441 guard (1 hari 1440 menit + margin).
   * ROWS: recorded_at + 10 kolom chart (hrs sesuai D6), NULL diperbolehkan
   * (baris publisher parsial) — dipilah di controller, bukan di sini.
   */
  public function day_series($panel_id)
  {
    $sql = "SELECT"
      . " `recorded_at`,"
      . " `v_r`, `v_s`, `v_t`, `v_rs`, `v_st`, `v_rt`,"
      . " `i_r`, `i_s`, `i_t`, `kw`"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ?"
      . "   AND `recorded_at` >= CURDATE()"
      . " ORDER BY `recorded_at` ASC, `id` ASC"
      . " LIMIT 1441";
    return $this->db->query($sql, array((int) $panel_id))->result_array();
  }

  /**
   * Statistik hari-ini 1 panel (baru CP-016, gaya §8.x schema):
   * beban puncak (MAX kw), pf rata-rata, kWh min/max, update terakhir, jumlah baris.
   * Return: row array (NULL field bila 0 baris hari ini; n = 0).
   */
  public function today_stats($panel_id)
  {
    $sql = "SELECT"
      . " MAX(`kw`) AS peak_kw,"
      . " AVG(`pf`) AS avg_pf,"
      . " MIN(`kwh`) AS kwh_min,"
      . " MAX(`kwh`) AS kwh_max,"
      . " MAX(`recorded_at`) AS last_at,"
      . " COUNT(*) AS n"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ?"
      . "   AND `recorded_at` >= CURDATE()";
    $row = $this->db->query($sql, array((int) $panel_id))->row_array();
    return $row === NULL ? array() : $row;
  }

  /**
   * Snapshot semua panel aktif pada menit tertentu (§8.4).
   */
  public function snapshot_panels($recorded_at)
  {
    $sql = "SELECT p.`code`, p.`name`, r.`kw`, r.`pf`, r.`kwh`"
      . " FROM `electricity_panel_iot5` p"
      . " LEFT JOIN `data_electricity_iot5` r"
      . "        ON r.`panel_id` = p.`id`"
      . "       AND r.`recorded_at` = ?"
      . " WHERE p.`is_active` = 1"
      . " ORDER BY p.`sort_order`";
    return $this->db->query($sql, array((string) $recorded_at))->result_array();
  }

  /**
   * Konsumsi kWh harian selisih MAX-MIN (§8.5), default 7 hari ke belakang.
   */
  public function kwh_daily($days = 7)
  {
    $days = max(1, (int) $days);
    $sql = "SELECT p.`code`, DATE(r.`recorded_at`) AS tanggal,"
      . " MAX(r.`kwh`) - MIN(r.`kwh`) AS konsumsi_kwh"
      . " FROM `data_electricity_iot5` r"
      . " JOIN `electricity_panel_iot5` p ON p.`id` = r.`panel_id`"
      . " WHERE r.`recorded_at` >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
      . " GROUP BY p.`code`, DATE(r.`recorded_at`)"
      . " ORDER BY tanggal DESC, p.`sort_order`";
    return $this->db->query($sql, array($days))->result_array();
  }

  /**
   * Jumlah baris rentang tanggal (fase 4b, spec filters §9/§12) — COUNT terpisah
   * agar pagination tidak menyeret seluruh baris ke PHP (index idx_readings_recorded).
   * Rentang inklusif [from 00:00:00 .. to 23:59:59]; server MySQL = WIB (CP-009).
   * Return: int.
   */
  public function count_range($from, $to)
  {
    $sql = "SELECT COUNT(*) AS n"
      . " FROM `data_electricity_iot5`"
      . " WHERE `recorded_at` >= ?"
      . "   AND `recorded_at` <= ?";
    $row = $this->db->query($sql, array(
      (string) $from . ' 00:00:00',
      (string) $to . ' 23:59:59',
    ))->row_array();
    return ($row === NULL || !isset($row['n'])) ? 0 : (int) $row['n'];
  }

  /**
   * Satu halaman baris rentang tanggal (fase 4b, spec filters §12).
   * Kolom result SENGAJA di-alias ke kontrak view (`time` 'Y-m-d H:i'
   * + `panel` nama tampil + 12 metrik) supaya view tidak berubah sama sekali.
   * ORDER BY recorded_at ASC, id ASC — deterministik menyeberang halaman
   * (id = PK BIGINT, tie-breaker bila panel berbeda dalam menit sama).
   * LIMIT/OFFSET inline (int)-cast: mysqli native prepare menolak string
   * utk LIMIT; nilai dari kontroller internal, bukan user raw.
   */
  public function range_page($from, $to, $per, $offset)
  {
    $sql = "SELECT"
      . " DATE_FORMAT(`r`.`recorded_at`, '%Y-%m-%d %H:%i') AS `time`,"
      . " `p`.`name` AS `panel`,"
      . " `r`.`v_r`, `r`.`v_s`, `r`.`v_t`, `r`.`v_rs`, `r`.`v_st`, `r`.`v_rt`,"
      . " `r`.`i_r`, `r`.`i_s`, `r`.`i_t`, `r`.`pf`, `r`.`kw`, `r`.`kwh`"
      . " FROM `data_electricity_iot5` `r`"
      . " JOIN `electricity_panel_iot5` `p` ON `p`.`id` = `r`.`panel_id`"
      . " WHERE `r`.`recorded_at` >= ?"
      . "   AND `r`.`recorded_at` <= ?"
      . " ORDER BY `r`.`recorded_at` ASC, `r`.`id` ASC"
      . " LIMIT " . (int) $per . " OFFSET " . (int) $offset;
    return $this->db->query($sql, array(
      (string) $from . ' 00:00:00',
      (string) $to . ' 23:59:59',
    ))->result_array();
  }

  /**
   * Ambil konfigurasi tarif aktif (1 baris, terbaru).
   * Return: array row, atau NULL bila tidak ada config aktif.
   */
  public function get_tariff_config()
  {
    $sql = "SELECT *"
      . " FROM `ems_tariff_config`"
      . " WHERE `is_active` = 1"
      . " ORDER BY `id` DESC"
      . " LIMIT 1";
    $row = $this->db->query($sql)->row_array();
    return empty($row) ? NULL : $row;
  }

  /**
   * Helper: selisih MAX-MIN kWh kumulatif dalam rentang waktu untuk 1 panel.
   * Butuh minimal 2 titik data untuk selisih; 0/1 titik → kwh = 0.0.
   * Guard: jika meter di-reset (selisih negatif), treat as 0.
   *
   * @param int    $panel_id  ID panel
   * @param string $from      'Y-m-d H:i:s' awal rentang (inklusif)
   * @param string $to        'Y-m-d H:i:s' akhir rentang (inklusif)
   * @return array  ('kwh' => float, 'n' => int jumlah baris)
   */
  protected function _kwh_range($panel_id, $from, $to)
  {
    $sql = "SELECT MAX(`kwh`) AS kwh_max, MIN(`kwh`) AS kwh_min, COUNT(*) AS n"
      . " FROM `data_electricity_iot5`"
      . " WHERE `panel_id` = ?"
      . "   AND `recorded_at` >= ?"
      . "   AND `recorded_at` <= ?";
    $row = $this->db->query($sql, array(
      (int) $panel_id,
      (string) $from,
      (string) $to,
    ))->row_array();

    $n = ($row === NULL || !isset($row['n'])) ? 0 : (int) $row['n'];
    if ($n < 2 || $row['kwh_max'] === NULL || $row['kwh_min'] === NULL) {
      return array('kwh' => 0.0, 'n' => $n);
    }
    $diff = (float) $row['kwh_max'] - (float) $row['kwh_min'];
    return array('kwh' => ($diff > 0.0) ? $diff : 0.0, 'n' => $n);
  }

  /**
   * Konsumsi kWh per blok TOU untuk 1 hari kalender (WIB).
   * Menghitung dari selisih kWh kumulatif (MAX - MIN) per rentang jam blok.
   * LWBP2 bungkus tengah malam: 2 query (22:00-23:59 + 00:00-05:59) per hari kalender.
   *
   * Kontrak $tou_hours: array('wbp' => array(17,22), 'lwbp1' => array(6,17), 'lwbp2' => array(22,6))
   *   — jam [0] = start (inklusif), jam [1] = end (eksklusif, menit terakhir = end-1 :59).
   *
   * @param int    $panel_id   ID panel
   * @param string $date       Tanggal 'Y-m-d' (WIB)
   * @param array  $tou_hours  Jam blok TOU dari config
   * @return array  'lwbp1','lwbp2','wbp' (float kWh), 'lwbp','total',
   *                'n_lwbp1','n_lwbp2','n_wbp' (jumlah baris data per blok), 'has_data' (bool)
   */
  public function kwh_by_tou_block($panel_id, $date, $tou_hours)
  {
    $result = array(
      'lwbp1' => 0.0, 'lwbp2' => 0.0, 'wbp' => 0.0,
      'lwbp'  => 0.0, 'total' => 0.0,
      'n_lwbp1' => 0, 'n_lwbp2' => 0, 'n_wbp' => 0,
      'has_data' => FALSE,
    );

    // WBP: contiguous — misal 17:00:00 s/d 21:59:00
    $wbp = $this->_kwh_range(
      $panel_id,
      $date . ' ' . sprintf('%02d:00:00', $tou_hours['wbp'][0]),
      $date . ' ' . sprintf('%02d:59:00', $tou_hours['wbp'][1] - 1)
    );
    $result['wbp']   = $wbp['kwh'];
    $result['n_wbp'] = $wbp['n'];

    // LWBP1: contiguous — misal 06:00:00 s/d 16:59:00
    $lwbp1 = $this->_kwh_range(
      $panel_id,
      $date . ' ' . sprintf('%02d:00:00', $tou_hours['lwbp1'][0]),
      $date . ' ' . sprintf('%02d:59:00', $tou_hours['lwbp1'][1] - 1)
    );
    $result['lwbp1']   = $lwbp1['kwh'];
    $result['n_lwbp1'] = $lwbp1['n'];

    // LWBP2: bungkus tengah malam — 2 rentang per hari KALENDER
    //   Bagian malam: lwbp2_start s/d 23:59 (misal 22:00:00 - 23:59:00)
    //   Bagian pagi:  00:00 s/d lwbp2_end-1 (misal 00:00:00 - 05:59:00)
    $lwbp2_malam = $this->_kwh_range(
      $panel_id,
      $date . ' ' . sprintf('%02d:00:00', $tou_hours['lwbp2'][0]),
      $date . ' 23:59:00'
    );
    $lwbp2_pagi = $this->_kwh_range(
      $panel_id,
      $date . ' 00:00:00',
      $date . ' ' . sprintf('%02d:59:00', $tou_hours['lwbp2'][1] - 1)
    );
    $result['lwbp2']   = $lwbp2_malam['kwh'] + $lwbp2_pagi['kwh'];
    $result['n_lwbp2'] = $lwbp2_malam['n'] + $lwbp2_pagi['n'];

    // Totals
    $result['lwbp']  = $result['lwbp1'] + $result['lwbp2'];
    $result['total'] = $result['lwbp'] + $result['wbp'];
    $result['has_data'] = ($result['n_lwbp1'] + $result['n_lwbp2'] + $result['n_wbp']) > 0;

    return $result;
  }

  /**
   * Konsumsi kWh per blok TOU per hari, N hari terakhir.
   * Memanggil kwh_by_tou_block() per hari secara terpisah.
   *
   * @param int   $panel_id   ID panel
   * @param int   $days       Jumlah hari (default 7)
   * @param array $tou_hours  Jam blok TOU (dari config)
   * @return array  Indexed 0..N-1 (tertua→terbaru), tiap elemen = kwh_by_tou_block()
   *                result + 'date' (Y-m-d) + 'label' (j Bln WIB)
   */
  public function kwh_daily_by_tou($panel_id, $days, $tou_hours)
  {
    $tz    = new DateTimeZone('Asia/Jakarta');
    $bulan = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                   7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');
    $result = array();
    $days = max(1, (int) $days);

    for ($offset = $days - 1; $offset >= 0; $offset--) {
      $dt = new DateTime(($offset === 0) ? 'today' : ('-' . $offset . ' days'), $tz);
      $date_str = $dt->format('Y-m-d');
      $label    = $dt->format('j') . ' ' . $bulan[(int) $dt->format('n')];

      $block = $this->kwh_by_tou_block($panel_id, $date_str, $tou_hours);
      $block['date']  = $date_str;
      $block['label'] = $label;
      $result[] = $block;
    }
    return $result;
  }

  /**
   * Sanitasi metrik: hanya 12 field whitelist; wajib numerik, else NULL (§7.2).
   * NULL = "tidak ada data", bukan 0 (§3.2 readings).
   */
  protected function sanitize_metrics($metrics)
  {
    $out = array();
    foreach ($this->metric_keys as $key) {
      $val = isset($metrics[$key]) ? $metrics[$key] : NULL;
      $out[$key] = ($val !== NULL && is_numeric($val)) ? (float) $val : NULL;
    }
    return $out;
  }
}
