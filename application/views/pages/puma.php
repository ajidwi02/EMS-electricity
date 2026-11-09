<?php defined('BASEPATH') OR exit('No direct script access allowed');
// CP-055: Panel 2 (Puma) memakai markup bersama dari pages/lvmdp.php.
// Topic realtime dan tren DB dipilih controller sesuai kode panel (lvmdp2).
// Stub hanya menyalurkan render;
// variabel payload ($mqtt_*, $trends, $power, $tarif, $tarif7, dll.) diwariskan
// dari $data render() via CI3 Loader cached-vars (Loader.php L944-945).
$this->load->view('pages/lvmdp');
