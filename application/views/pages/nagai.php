<?php defined('BASEPATH') OR exit('No direct script access allowed');
// CP-055: Panel 3 (Nagai) = duplikat 1:1 dashboard Panel 1 (satu sumber markup:
// pages/lvmdp.php). FASE 1 — topic & data masih data/ems/lvmdp/+ (spec
// plans/ems-duplicate-lvmdp-puma-nagai.md). Stub hanya menyalurkan render;
// variabel payload ($mqtt_*, $trends, $power, $tarif, $tarif7, dll.) diwariskan
// dari $data render() via CI3 Loader cached-vars (Loader.php L944-945).
$this->load->view('pages/lvmdp');
