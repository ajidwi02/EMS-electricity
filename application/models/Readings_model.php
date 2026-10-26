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
