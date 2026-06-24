<?php
/*
Plugin Name: Rezervační systém
Description: Rezervační systém prostor pro skautské středisko Chlumec.
Version: 1.0
Author: Honza & Claude
*/

if (!defined('ABSPATH')) exit;

// ═══ POST TYPES ══════════════════════════════════════════════════════════════

add_action('init', 'rs_registruj_post_types');
function rs_registruj_post_types() {
    register_post_type('rs_typ',      ['labels' => ['name' => 'Typy objektů',   'singular_name' => 'Typ objektu'],  'public' => false, 'show_ui' => false, 'supports' => ['title']]);
    register_post_type('rs_prostor',  ['labels' => ['name' => 'Objekty',        'singular_name' => 'Objekt'],        'public' => false, 'show_ui' => false, 'supports' => ['title'], 'hierarchical' => true]);
    register_post_type('rs_rezervace',['labels' => ['name' => 'Rezervace',       'singular_name' => 'Rezervace'],      'public' => false, 'show_ui' => false, 'supports' => ['title']]);
}

// ═══ ROLE SETUP ══════════════════════════════════════════════════════════════

register_activation_hook(__FILE__, 'rs_aktivace');
function rs_aktivace() {
    if (!get_role('admin_rezervacniho_systemu'))
        add_role('admin_rezervacniho_systemu', 'Admin rezervačního systému', ['read' => true, 'rs_admin' => true, 'rs_spravce' => true, 'rs_vedeni' => true]);
    if (!get_role('spravce_rezervaci'))
        add_role('spravce_rezervaci', 'Správce rezervací', ['read' => true, 'rs_spravce' => true, 'rs_vedeni' => true]);
    foreach (['author', 'administrator'] as $r) {
        $role = get_role($r);
        if ($role) $role->add_cap('rs_vedeni');
    }
    foreach (['administrator', 'admin_rezervacniho_systemu'] as $r) {
        $role = get_role($r);
        if ($role) { $role->add_cap('rs_admin'); $role->add_cap('rs_spravce'); }
    }
    wp_schedule_event(strtotime('08:00:00'), 'daily', 'rs_cron_ucastnici');
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, function() { wp_clear_scheduled_hook('rs_cron_ucastnici'); });

function rs_ma_pravo(string $cap): bool {
    $u = wp_get_current_user();
    if ($cap === 'admin')   return $u->has_cap('rs_admin');
    if ($cap === 'spravce') return $u->has_cap('rs_spravce') || $u->has_cap('rs_admin');
    if ($cap === 'vedeni')  return $u->has_cap('rs_vedeni')  || $u->has_cap('rs_spravce') || $u->has_cap('rs_admin');
    return false;
}

// ═══ HELPERS ═════════════════════════════════════════════════════════════════

function rs_alert(string $text, string $type = 'success'): string {
    $map = ['success' => 'rs-ok', 'error' => 'rs-err', 'info' => 'rs-info', 'warning' => 'rs-warn'];
    return "<div class='rs-alert " . ($map[$type] ?? 'rs-info') . "'>" . wp_kses_post($text) . "</div>";
}

function rs_token(): string { return bin2hex(random_bytes(20)); }

function rs_vypocti_velikonoce(int $rok): array {
    $a = $rok % 19; $b = intdiv($rok,100); $c = $rok % 100;
    $d = intdiv($b,4); $e = $b % 4; $f = intdiv($b+8,25);
    $g = intdiv($b-$f+1,3); $h = (19*$a+$b-$d-$g+15) % 30;
    $i = intdiv($c,4); $k = $c % 4; $l = (32+2*$e+2*$i-$h-$k) % 7;
    $m = intdiv($a+11*$h+22*$l,451);
    $month = intdiv($h+$l-7*$m+114,31);
    $day   = (($h+$l-7*$m+114) % 31)+1;
    $ned   = mktime(0,0,0,$month,$day,$rok);
    return [date('Y-m-d',strtotime('-2 days',$ned)), date('Y-m-d',strtotime('+1 day',$ned))];
}

function rs_je_vikend(string $d): bool    { return (int)date('N', strtotime($d)) >= 6; }
function rs_je_svatek(string $d): bool {
    $date = substr($d, 0, 10);
    if (in_array(substr($date, 5), get_option('rs_statni_svatky', []), true)) return true;
    return array_key_exists($date, get_option('rs_velikonoce', []));
}
function rs_jsou_prazdniny(string $d): bool {
    $ts = strtotime(substr($d,0,10));
    foreach (get_option('rs_prazdniny', []) as $p)
        if ($ts >= strtotime($p['od']) && $ts <= strtotime($p['do'])) return true;
    return false;
}
function rs_potreba_schvaleni_interni(string $datum_od): bool {
    return rs_je_vikend($datum_od) || rs_je_svatek($datum_od) || rs_jsou_prazdniny($datum_od);
}

function rs_je_volno(int $prostor_id, array $seg_ids, string $od, string $do, int $skip = 0): bool {
    $all = get_posts(['post_type' => 'rs_rezervace', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids']);
    $od_ts = strtotime($od); $do_ts = strtotime($do);
    foreach ($all as $rid) {
        if ($rid === $skip) continue;
        if (get_post_meta($rid, 'rs_stav', true) === 'zrusena') continue;
        $r_od = strtotime(get_post_meta($rid, 'rs_datum_od', true));
        $r_do = strtotime(get_post_meta($rid, 'rs_datum_do', true));
        if ($od_ts >= $r_do || $do_ts <= $r_od) continue;
        if ((int)get_post_meta($rid, 'rs_prostor_id', true) !== $prostor_id) continue;
        $r_seg = (array)get_post_meta($rid, 'rs_segmenty_ids', true);
        if (empty($seg_ids) || empty($r_seg) || array_intersect($seg_ids, $r_seg)) return false;
    }
    return true;
}

function rs_get_prostory(): array {
    return get_posts(['post_type' => 'rs_prostor', 'post_parent' => 0, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish']);
}
function rs_get_segmenty(int $pid): array {
    return get_posts(['post_type' => 'rs_prostor', 'post_parent' => $pid, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish']);
}
function rs_ma_segmenty(int $pid): bool { return get_post_meta($pid, 'rs_ma_segmenty', true) === '1'; }

function rs_je_ext_vypnuto(int $id): bool {
    if (get_post_meta($id, 'rs_ext_vypnuto', true) !== '1') return false;
    $od   = get_post_meta($id, 'rs_ext_vypnuto_od', true);
    $do_  = get_post_meta($id, 'rs_ext_vypnuto_do', true);
    $dnes = date('Y-m-d');
    if ($od  && $dnes < $od)  return false; // ještě nezačalo
    if ($do_ && $dnes > $do_) return false; // už skončilo
    return true;
}

function rs_ext_vypnuto_badge(int $id): string {
    if (get_post_meta($id, 'rs_ext_vypnuto', true) !== '1') return '';
    $od   = get_post_meta($id, 'rs_ext_vypnuto_od', true);
    $do_  = get_post_meta($id, 'rs_ext_vypnuto_do', true);
    $dnes = date('Y-m-d');
    $aktivni = !($od && $dnes < $od) && !($do_ && $dnes > $do_);
    $ods  = $od  ? date('j.n.', strtotime($od))  : '';
    $dos  = $do_ ? date('j.n.', strtotime($do_)) : '';
    $rozsah = ($ods || $dos) ? (' ' . ($ods ?: '?') . '–' . ($dos ?: '∞')) : '';
    return $aktivni
        ? "<span style='color:#c0392b;font-size:11px;white-space:nowrap'>🚫 Ext. vypnuto{$rozsah}</span>"
        : "<span style='color:#888;font-size:11px;white-space:nowrap'>⏳ Ext. vyp.{$rozsah}</span>";
}

function rs_stav_badge(string $stav): string {
    return match($stav) {
        'cekajici'  => "<span class='rs-badge rs-badge-warn'>Čeká na schválení</span>",
        'potvrzena' => "<span class='rs-badge rs-badge-ok'>Potvrzena</span>",
        'zrusena'   => "<span class='rs-badge rs-badge-err'>Zrušena</span>",
        default     => esc_html($stav),
    };
}

function rs_rez_jmeno(int $id): string {
    if (get_post_meta($id, 'rs_rez_typ', true) === 'pravnicka')
        return get_post_meta($id, 'rs_nazev', true) ?: '–';
    return trim(get_post_meta($id, 'rs_jmeno', true) . ' ' . get_post_meta($id, 'rs_prijmeni', true)) ?: '–';
}

function rs_vypocti_cenu(int $prostor_id, array $seg_ids, int $pocet_lidi, string $od, string $do): float {
    $ma_seg = rs_ma_segmenty($prostor_id);
    $rezim  = get_post_meta($prostor_id, 'rs_ceny_rezim', true) ?: 'celek';

    // Use segment-level prices only when prostor has segments AND mode is 'segmenty' AND segments are specified
    $ids = ($ma_seg && $rezim === 'segmenty' && !empty($seg_ids)) ? $seg_ids : [$prostor_id];

    $total = 0.0;
    foreach ($ids as $iid) {
        $za_osobu = (float)get_post_meta($iid, 'rs_cena_za_osobu', true);
        if ($za_osobu > 0) {
            $castka   = $za_osobu * $pocet_lidi;
            $cena_min = (float)get_post_meta($iid, 'rs_cena_min', true);
            if ($cena_min > 0) $castka = max($castka, $cena_min);
            $total += $castka;
        } else {
            $cena_min = (float)get_post_meta($iid, 'rs_cena_min', true);
            $total += $cena_min;
        }
    }
    return $total;
}

// ═══ ARES ════════════════════════════════════════════════════════════════════

add_action('wp_ajax_nopriv_rs_ares',       'rs_ares_ajax');
add_action('wp_ajax_rs_ares',             'rs_ares_ajax');
add_action('wp_ajax_nopriv_rs_check_volno', 'rs_ajax_check_volno');
add_action('wp_ajax_rs_check_volno',        'rs_ajax_check_volno');
function rs_ajax_check_volno(): void {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rs_public')) wp_send_json_error('nonce');
    $pid    = (int)($_POST['prostor_id'] ?? 0);
    $od_raw = sanitize_text_field($_POST['datum_od'] ?? '');
    $do_raw = sanitize_text_field($_POST['datum_do'] ?? '');
    if (!$pid || !$od_raw || !$do_raw) { wp_send_json_success(null); return; }
    $cd  = !empty($_POST['cely_den']);
    $od  = $cd ? $od_raw . ' 00:00:00' : str_replace('T', ' ', $od_raw) . ':00';
    $do_ = $cd ? $do_raw . ' 23:59:00' : str_replace('T', ' ', $do_raw) . ':00';
    if (strtotime($od) >= strtotime($do_)) { wp_send_json_success(null); return; }
    $seg_ids = array_map('intval', (array)($_POST['seg_ids'] ?? []));
    wp_send_json_success(['volno' => rs_je_volno($pid, $seg_ids, $od, $do_)]);
}
function rs_ares_ajax() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rs_public')) wp_send_json_error('Unauthorized');
    $ico = str_pad(preg_replace('/\D/', '', sanitize_text_field($_POST['ico'] ?? '')), 8, '0', STR_PAD_LEFT);
    if (strlen($ico) !== 8) wp_send_json_error('Zadejte platné IČO');
    $resp = wp_remote_get('https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' . $ico, ['timeout' => 8, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) wp_send_json_error('Subjekt nenalezen v ARES');
    $d = json_decode(wp_remote_retrieve_body($resp), true);
    if (!$d) wp_send_json_error('Chyba parsování');
    $a = $d['sidlo'] ?? [];
    $sidlo = $a['textovaAdresa'] ?? trim(($a['nazevUlice'] ?? '') . ' ' . ($a['cisloDomovni'] ?? '') . (isset($a['cisloOrientacni']) ? '/' . $a['cisloOrientacni'] : '') . ', ' . ($a['psc'] ?? '') . ' ' . ($a['nazevObce'] ?? ''));
    wp_send_json_success(['nazev' => $d['obchodniJmeno'] ?? '', 'sidlo' => trim($sidlo, ' ,')]);
}

// ═══ EMAILY ══════════════════════════════════════════════════════════════════

function rs_spravci_emaily(): array {
    $users = get_users(['role__in' => ['spravce_rezervaci', 'admin_rezervacniho_systemu', 'administrator']]);
    return array_unique(array_filter(array_map(fn($u) => $u->user_email, $users)));
}

function rs_mail(string $to, string $subj, string $body, string $reply_to = ''): void {
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if ($reply_to) $headers[] = 'Reply-To: ' . $reply_to;
    wp_mail($to, $subj, $body, $headers);
}

function rs_rez_udaje(int $id): string {
    $typ      = get_post_meta($id, 'rs_rez_typ', true);
    $pocet    = (int)get_post_meta($id, 'rs_pocet_lidi', true);
    $poznamka = get_post_meta($id, 'rs_poznamka', true);
    $tel_predv = get_post_meta($id, 'rs_tel_predvolba', true);
    $tel_mobil = get_post_meta($id, 'rs_mobil', true);
    $tel_fmt   = $tel_mobil ? ($tel_predv ? "+$tel_predv $tel_mobil" : $tel_mobil) : '';
    $ulice_v   = get_post_meta($id, 'rs_ulice', true);
    $adresa    = $ulice_v
        ? trim($ulice_v . ' ' . get_post_meta($id,'rs_cp',true) . ', ' . get_post_meta($id,'rs_psc',true) . ' ' . get_post_meta($id,'rs_obec',true))
        : get_post_meta($id, 'rs_bydliste', true);
    if ($typ === 'pravnicka') {
        $lines = [
            'Organizace:        ' . get_post_meta($id, 'rs_nazev', true),
            'IČO:               ' . get_post_meta($id, 'rs_ico', true),
            'Sídlo:             ' . get_post_meta($id, 'rs_sidlo', true),
            'Kontaktní osoba:   ' . get_post_meta($id, 'rs_kontakt_jmeno', true),
            'Mobil:             ' . $tel_fmt,
            'E-mail:            ' . get_post_meta($id, 'rs_email', true),
            'Počet osob:        ' . $pocet,
        ];
    } else {
        $nar = get_post_meta($id, 'rs_datum_narozeni', true);
        $lines = [
            'Jméno:             ' . trim(get_post_meta($id, 'rs_jmeno', true) . ' ' . get_post_meta($id, 'rs_prijmeni', true)),
            'Datum narození:    ' . ($nar ? rs_format_datum($nar . ' 00:00:00') : ''),
            'Bydliště:          ' . $adresa,
            'Mobil:             ' . $tel_fmt,
            'E-mail:            ' . get_post_meta($id, 'rs_email', true),
            'Počet osob:        ' . $pocet,
        ];
    }
    if ($poznamka) $lines[] = 'Poznámka:          ' . $poznamka;
    return implode("\n", $lines);
}

function rs_format_datum(string $d): string {
    $ts = strtotime($d);
    if (!$ts) return $d;
    $t = date('H:i', $ts);
    return date('j. n. Y', $ts) . ($t === '00:00' || $t === '23:59' ? '' : ' v ' . $t);
}

function rs_sprava_url(string $token): string {
    global $wpdb;
    $base = get_option('rs_formular_url', '');
    if (!$base) {
        $row  = $wpdb->get_row("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%[rs_formular]%' LIMIT 1");
        $base = $row ? (string)get_permalink((int)$row->ID) : home_url('/');
    }
    return add_query_arg('rs_sprava', $token, rtrim($base, '/') . '/');
}

function rs_prostor_label(int $prostor_id, array $seg_ids = []): string {
    $prostor = html_entity_decode(get_the_title($prostor_id), ENT_QUOTES | ENT_HTML5);
    $typ_id  = (int)get_post_meta($prostor_id, 'rs_typ_id', true);
    $typ     = $typ_id ? html_entity_decode(get_the_title($typ_id), ENT_QUOTES | ENT_HTML5) : '';
    $label   = $prostor . ($typ ? ' (' . $typ . ')' : '');
    if (!empty($seg_ids)) {
        $segs  = array_map(fn($sid) => html_entity_decode(get_the_title((int)$sid), ENT_QUOTES | ENT_HTML5), $seg_ids);
        $label = implode(', ', $segs) . ', ' . $label;
    }
    return $label;
}

function rs_admin_url(): string {
    global $wpdb;
    $row = $wpdb->get_row("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%[rs_admin]%' LIMIT 1");
    return $row ? (string)get_permalink((int)$row->ID) : home_url('/');
}

function rs_rez_prehled(int $prostor_id, string $od_raw, string $do_raw, int $nova_id = 0): string {
    $ts_od     = strtotime($od_raw);
    $ts_do     = strtotime($do_raw);
    $win_od    = date('Y-m-d H:i:s', $ts_od - 3 * 86400);
    $win_do    = date('Y-m-d H:i:s', $ts_do + 3 * 86400);
    $stav_map  = ['cekajici' => 'čeká na schválení', 'potvrzena' => 'potvrzena', 'zrusena' => 'zrušena'];

    $all = get_posts(['post_type' => 'rs_rezervace', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids',
        'meta_query' => [['key' => 'rs_prostor_id', 'value' => $prostor_id]]]);

    $rows = [];
    foreach ($all as $rid) {
        $r_od = get_post_meta($rid, 'rs_datum_od', true);
        $r_do = get_post_meta($rid, 'rs_datum_do', true);
        if (strtotime($r_do) <= strtotime($win_od) || strtotime($r_od) >= strtotime($win_do)) continue;
        $rows[$rid] = strtotime($r_od);
    }
    if (empty($rows)) return '';

    asort($rows);
    $lines = ['Obsazenost objektu v okolí termínu (±3 dny):'];
    foreach (array_keys($rows) as $rid) {
        $r_od  = get_post_meta($rid, 'rs_datum_od', true);
        $r_do  = get_post_meta($rid, 'rs_datum_do', true);
        $stav  = $stav_map[get_post_meta($rid, 'rs_stav', true)] ?? '?';
        $jmeno = rs_rez_jmeno($rid);
        $mark  = ($rid === $nova_id) ? ' <<< TATO ŽÁDOST' : '';
        $lines[] = '  ' . rs_format_datum($r_od) . ' – ' . rs_format_datum($r_do)
                 . '  ' . $jmeno . '  [' . $stav . ']' . $mark;
    }
    return implode("\n", $lines);
}

function rs_podpis(): string {
    $base  = "S pozdravem\nSkaut Chlumec nad Cidlinou, středisko Černého havrana";
    $jmeno = get_option('rs_stredisko_kontakt_jmeno', '');
    $mobil = get_option('rs_stredisko_kontakt_mobil', '');
    $email = get_option('rs_stredisko_kontakt_email', '');
    $radky = array_filter([$jmeno, $mobil, $email]);
    return $radky ? $base . "\n" . implode("\n", $radky) : $base;
}

function rs_notifikuj_nova(int $id) {
    $email      = get_post_meta($id, 'rs_email', true);
    $prostor_id = (int)get_post_meta($id, 'rs_prostor_id', true);
    $seg_ids    = array_filter((array)get_post_meta($id, 'rs_segmenty_ids', true));
    $label      = rs_prostor_label($prostor_id, $seg_ids);
    $od_raw     = get_post_meta($id, 'rs_datum_od', true);
    $do_raw     = get_post_meta($id, 'rs_datum_do', true);
    $od         = rs_format_datum($od_raw);
    $do_        = rs_format_datum($do_raw);
    $token      = get_post_meta($id, 'rs_token', true);
    $url        = rs_sprava_url($token);

    if ($email) rs_mail($email, "Žádost o rezervaci přijata – {$label}",
        "Dobrý den,\n\npřijali jsme vaši žádost o rezervaci objektu {$label} na termín {$od} – {$do_}. Rezervace čeká na schválení – jakmile ji potvrdíme, přijde vám e-mail s potvrzením.\n\nOdkaz pro správu vaší rezervace (uschovejte si jej):\n{$url}\n\n" . rs_podpis(),
        get_option('rs_stredisko_kontakt_email', ''));

    $prehled = rs_rez_prehled($prostor_id, $od_raw, $do_raw, $id);
    foreach (rs_spravci_emaily() as $se)
        rs_mail($se, "Nová žádost o rezervaci – {$label}",
            "Nová žádost o rezervaci.\n\nObjekt: {$label}\nTermín: {$od} – {$do_}\n\n"
            . ($prehled ? $prehled . "\n\n" : '')
            . "--- Žadatel ---\n" . rs_rez_udaje($id)
            . "\n\nAdministrace: " . rs_admin_url(),
            $email);
}

function rs_notifikuj_potvrzeni(int $id) {
    $email = get_post_meta($id, 'rs_email', true);
    if (!$email) return;
    $prostor_id = (int)get_post_meta($id, 'rs_prostor_id', true);
    $seg_ids    = array_filter((array)get_post_meta($id, 'rs_segmenty_ids', true));
    $label      = rs_prostor_label($prostor_id, $seg_ids);
    $od         = rs_format_datum(get_post_meta($id, 'rs_datum_od', true));
    $do_        = rs_format_datum(get_post_meta($id, 'rs_datum_do', true));
    $cena       = (float)get_post_meta($id, 'rs_cena_celkem', true);
    $token      = get_post_meta($id, 'rs_token', true);
    rs_mail($email, "Rezervace potvrzena – {$label}",
        "Dobrý den,\n\nvaše rezervace objektu {$label} na termín {$od} – {$do_} byla potvrzena.\nCena: " . ($cena > 0 ? number_format($cena, 0, ',', ' ') . ' Kč' : 'zdarma') . "\n\nSpráva rezervace:\n" . rs_sprava_url($token) . "\n\n" . rs_podpis(),
        get_option('rs_stredisko_kontakt_email', ''));
}

function rs_notifikuj_zruseni(int $id) {
    $email = get_post_meta($id, 'rs_email', true);
    if (!$email) return;
    $prostor_id = (int)get_post_meta($id, 'rs_prostor_id', true);
    $seg_ids    = array_filter((array)get_post_meta($id, 'rs_segmenty_ids', true));
    $label      = rs_prostor_label($prostor_id, $seg_ids);
    $od         = rs_format_datum(get_post_meta($id, 'rs_datum_od', true));
    $do_        = rs_format_datum(get_post_meta($id, 'rs_datum_do', true));
    rs_mail($email, "Rezervace zrušena – {$label}",
        "Dobrý den,\n\nvaše rezervace objektu {$label} na termín {$od} – {$do_} byla zrušena.\n\n" . rs_podpis(),
        get_option('rs_stredisko_kontakt_email', ''));
}

// Cron: upozornění na nevyplněné účastníky (7 dní a 1 den před začátkem)
add_action('rs_cron_ucastnici', 'rs_cron_upozorneni_ucastnici');
function rs_cron_upozorneni_ucastnici() {
    if (!get_option('rs_vzdusne_aktivni')) return;
    $all = get_posts(['post_type' => 'rs_rezervace', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids',
        'meta_query' => [['key' => 'rs_stav', 'value' => 'potvrzena']]]);
    foreach ($all as $id) {
        $email     = get_post_meta($id, 'rs_email', true);
        $ucastnici = get_post_meta($id, 'rs_ucastnici', true);
        if (!$email || !empty($ucastnici)) continue;
        $od_ts  = strtotime(get_post_meta($id, 'rs_datum_od', true));
        $diff   = $od_ts - time();
        $days   = (int)($diff / 86400);
        if ($days !== 7 && $days !== 1) continue;
        $prostor = html_entity_decode(get_the_title((int)get_post_meta($id, 'rs_prostor_id', true)), ENT_QUOTES | ENT_HTML5);
        $od      = rs_format_datum(get_post_meta($id, 'rs_datum_od', true));
        $token   = get_post_meta($id, 'rs_token', true);
        rs_mail($email, "Upozornění: vyplňte seznam ubytovaných – {$prostor}",
            "Dobrý den,\n\nvaše rezervace objektu {$prostor} začíná za {$days} " . ($days === 1 ? 'den' : 'dní') . " ({$od}).\n\nProsíme vyplňte seznam ubytovaných osob pro účely ubytovacího poplatku:\n" . rs_sprava_url($token) . "\n\n" . rs_podpis(),
            get_option('rs_stredisko_kontakt_email', ''));
    }
}

// ═══ CSS ═════════════════════════════════════════════════════════════════════

function rs_css() { ?>
<style>
.rs-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;color:#333;max-width:1000px}
.rs-menu{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:0;border-bottom:3px solid #1a5c2a}
.rs-menu button{font-size:12px;padding:7px 13px;background:#f0f0f0;color:#444;border:1px solid #ccc;border-bottom:none;border-radius:4px 4px 0 0;cursor:pointer;white-space:nowrap;transition:background .15s;margin-bottom:-1px}
.rs-menu button:hover{background:#e0e0e0}
.rs-menu button.rs-active{background:#1a5c2a;color:#fff;border-color:#1a5c2a;font-weight:600}
.rs-panels{padding:20px;background:#fff;border:1px solid #1a5c2a;border-top:none}
.rs-panels>div{display:none}.rs-panels>div.rs-active{display:block}
.rs-card{background:#fafafa;border:1px solid #ddd;border-radius:4px;padding:18px 20px;margin-bottom:20px}
.rs-card-title{margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid #1a5c2a;color:#1a5c2a;font-size:15px;font-weight:600}
.rs-section-title{margin:0 0 20px;font-size:18px;color:#222}
.rs-btn{display:inline-block;padding:6px 14px;font-size:13px;font-weight:500;border:none;border-radius:3px;cursor:pointer;text-decoration:none;line-height:1.6;transition:filter .15s;vertical-align:middle}
.rs-btn:hover{filter:brightness(.88)}
.rs-btn-primary{background:#1a5c2a;color:#fff!important}
.rs-btn-danger{background:#d63638;color:#fff!important}
.rs-btn-success{background:#2e7d32;color:#fff!important}
.rs-btn-secondary{background:#757575;color:#fff!important}
.rs-btn-sm{padding:3px 9px;font-size:12px}
.rs-btn-row{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;align-items:center}
.rs-alert{padding:10px 14px;border-radius:3px;margin-bottom:16px;font-size:13px;border-left:4px solid}
.rs-ok{background:#f0faf0;border-color:#2e7d32;color:#1b5e20}
.rs-err{background:#fff0f0;border-color:#d63638;color:#7b1c1e}
.rs-info{background:#e8f4fb;border-color:#0073aa;color:#004e73}
.rs-warn{background:#fff8e1;border-color:#f57c00;color:#7a4100}
.rs-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}
.rs-table th{background:#e8f2ea;color:#222;border:1px solid #b8d4be;padding:7px 10px;text-align:left;font-weight:600}
.rs-table td{border:1px solid #ddd;padding:6px 10px;vertical-align:middle}
.rs-table tr:nth-child(even) td{background:#f7f7f7}
.rs-table tr:hover td{background:#edf4ea}
.rs-form-group{margin-bottom:14px}
.rs-form-group>label{display:block;font-weight:600;margin-bottom:4px;font-size:13px;color:#444}
.rs-wrap input[type=text],.rs-wrap input[type=number],.rs-wrap input[type=date],.rs-wrap input[type=time],.rs-wrap input[type=email],.rs-wrap input[type=tel],.rs-wrap textarea,.rs-wrap select{padding:5px 8px;border:1px solid #8c8f94;border-radius:3px;font-size:13px;line-height:1.5;color:#2c3338;background:#fff;box-sizing:border-box;vertical-align:middle}
.rs-form-group input:not([type=checkbox]):not([type=radio]),.rs-form-group select,.rs-form-group textarea{width:100%;max-width:420px}
.rs-form-group textarea{max-width:100%;resize:vertical}
.rs-form-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.rs-form-inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.rs-badge{border-radius:3px;padding:2px 8px;font-size:12px;font-weight:500}
.rs-badge-warn{background:#fff3cd;color:#856404;border:1px solid #ffc107}
.rs-badge-ok{background:#d1e7dd;color:#0a5b2e;border:1px solid #a3cfbb}
.rs-badge-err{background:#f8d7da;color:#842029;border:1px solid #f5c2c7}
.rs-badge-info{background:#cfe2ff;color:#084298;border:1px solid #b6d4fe}
.rs-segment-box{border:1px solid #ddd;border-radius:4px;padding:14px;margin-bottom:10px;background:#fff}
.rs-foto-preview{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.rs-foto-preview img{width:80px;height:60px;object-fit:cover;border-radius:3px;border:1px solid #ddd}
.rs-foto-item{position:relative}
.rs-foto-item .rs-foto-del{position:absolute;top:-4px;right:-4px;background:#d63638;color:#fff;border:none;border-radius:50%;width:18px;height:18px;font-size:11px;cursor:pointer;line-height:16px;text-align:center;padding:0}
.rs-kal-table{width:100%;border-collapse:collapse;font-size:13px}
.rs-kal-table th{background:#1a5c2a;color:#fff;padding:8px;text-align:center}
.rs-kal-table td{border:1px solid #ddd;padding:6px;text-align:center;vertical-align:top;min-width:40px}
.rs-kal-free{display:inline-block;background:#d4edda;color:#155724;font-size:13px;border-radius:50%;width:22px;height:22px;line-height:22px;text-align:center}
.rs-kal-busy{display:inline-block;background:#f8d7da;color:#721c24;font-size:13px;border-radius:50%;width:22px;height:22px;line-height:22px;text-align:center}
.rs-kal-partial{display:inline-block;background:#fff3cd;color:#e65c00;font-size:16px;border-radius:50%;width:22px;height:22px;line-height:22px;text-align:center}
.rs-kal-table td[onclick]{cursor:pointer}.rs-kal-table td[onclick]:hover{background:#f0f4f0}
@media(max-width:640px){.rs-menu{flex-direction:column;border-bottom:none}.rs-menu button{border-radius:3px;border-bottom:1px solid #ccc;margin-bottom:2px}.rs-panels{border-top:3px solid #1a5c2a}.rs-form-group input,.rs-form-group select,.rs-form-group textarea{max-width:100%}}
</style>
<?php }

// ═══ JS ══════════════════════════════════════════════════════════════════════

function rs_js_tabs() { ?>
<script>
function rsTab(id, btn) {
    document.querySelectorAll('.rs-panels>div').forEach(d=>{d.classList.remove('rs-active')});
    document.querySelectorAll('.rs-menu button').forEach(b=>{b.classList.remove('rs-active')});
    var p=document.getElementById('rs-panel-'+id);
    if(p){p.classList.add('rs-active');}
    if(btn){btn.classList.add('rs-active');}
    try{sessionStorage.setItem('rs_tab',id);}catch(e){}
}
document.addEventListener('DOMContentLoaded',function(){
    var tabId=null;
    var hash=location.hash;
    if(hash.startsWith('#rs-')){tabId=hash.slice(4);}
    if(!tabId){try{tabId=sessionStorage.getItem('rs_tab');}catch(e){}}
    var activBtn=tabId?document.querySelector('.rs-menu button[data-tab="'+tabId+'"]'):null;
    if(activBtn){activBtn.click();}else{var first=document.querySelector('.rs-menu button');if(first)first.click();}
    // Obnovit pozici po odeslání formuláře
    try{var sy=sessionStorage.getItem('rs_scroll_y');if(sy!==null){sessionStorage.removeItem('rs_scroll_y');window.scrollTo(0,parseInt(sy));}}catch(e){}
    // Uložit pozici před odesláním (musí být uvnitř DOMContentLoaded, jinak formuláře ještě neexistují)
    document.querySelectorAll('.rs-wrap form').forEach(function(f){
        f.addEventListener('submit',function(){try{sessionStorage.setItem('rs_scroll_y',window.scrollY);}catch(e){}});
    });
});
</script>
<?php }

// ═══ ADMIN SHORTCODE ═════════════════════════════════════════════════════════

add_shortcode('rs_admin', 'rs_admin_sc');
function rs_admin_sc(): string {
    if (!rs_ma_pravo('vedeni')) return '<p>Nemáš oprávnění k rezervačnímu systému.</p>';
    if (rs_ma_pravo('admin') || rs_ma_pravo('spravce')) wp_enqueue_media();

    ob_start();
    rs_css();
    echo "<div class='rs-wrap'>";

    // Navigation
    echo "<div class='rs-menu'>";
    if (rs_ma_pravo('admin')) {
        echo "<button data-tab='typy'    onclick='rsTab(\"typy\",this)'   >Typy objektů</button>";
        echo "<button data-tab='prostory' onclick='rsTab(\"prostory\",this)'>Objekty</button>";
        echo "<button data-tab='prazdniny' onclick='rsTab(\"prazdniny\",this)'>Prázdniny & Svátky</button>";
        echo "<button data-tab='ceny'    onclick='rsTab(\"ceny\",this)'   >Ceny</button>";
        echo "<button data-tab='nastaveni' onclick='rsTab(\"nastaveni\",this)'>Nastavení</button>";
    }
    if (rs_ma_pravo('spravce')) echo "<button data-tab='rezervace' onclick='rsTab(\"rezervace\",this)'>Správa rezervací</button>";
    if (rs_ma_pravo('vedeni'))  echo "<button data-tab='interni'   onclick='rsTab(\"interni\",this)'  >Interní rezervace</button>";
    if (rs_ma_pravo('vedeni'))  echo "<button data-tab='napoveda'  onclick='rsTab(\"napoveda\",this)' >Popis aplikace</button>";
    echo "</div>";

    // Panels
    echo "<div class='rs-panels'>";
    if (rs_ma_pravo('admin')) {
        echo "<div id='rs-panel-typy'>"     . rs_sekce_typy()     . "</div>";
        echo "<div id='rs-panel-prostory'>" . rs_sekce_prostory() . "</div>";
        echo "<div id='rs-panel-prazdniny'>". rs_sekce_prazdniny(). "</div>";
        echo "<div id='rs-panel-ceny'>"     . rs_sekce_ceny()     . "</div>";
        echo "<div id='rs-panel-nastaveni'>". rs_sekce_nastaveni(). "</div>";
    }
    if (rs_ma_pravo('spravce')) echo "<div id='rs-panel-rezervace'>". rs_sekce_rezervace() . "</div>";
    if (rs_ma_pravo('vedeni'))  echo "<div id='rs-panel-interni'>"  . rs_sekce_interni()   . "</div>";
    if (rs_ma_pravo('vedeni'))  echo "<div id='rs-panel-napoveda'>" . rs_sekce_napoveda()  . "</div>";
    echo "</div>";

    echo "</div>"; // .rs-wrap
    rs_js_tabs();
    return ob_get_clean();
}

// ═══ SEKCE: TYPY PROSTORŮ ════════════════════════════════════════════════════

function rs_sekce_typy(): string {
    $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_typy_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rs_typy')) return rs_alert('Neplatný token.','error');
        $action = sanitize_key($_POST['rs_typy_action']);

        if ($action === 'pridat') {
            $nazev = sanitize_text_field($_POST['typ_nazev'] ?? '');
            $popis = sanitize_textarea_field($_POST['typ_popis'] ?? '');
            if (!$nazev) { $zprava = rs_alert('Zadejte název.','error'); }
            else {
                $existing = get_posts(['post_type'=>'rs_typ','title'=>$nazev,'numberposts'=>1,'fields'=>'ids']);
                if ($existing) $zprava = rs_alert('Typ s tímto názvem již existuje.','error');
                else { wp_insert_post(['post_type'=>'rs_typ','post_title'=>$nazev,'post_content'=>$popis,'post_status'=>'publish']); $zprava = rs_alert('Typ přidán.'); }
            }
        } elseif ($action === 'upravit') {
            $id    = (int)($_POST['typ_id'] ?? 0);
            $nazev = sanitize_text_field($_POST['typ_nazev'] ?? '');
            $popis = sanitize_textarea_field($_POST['typ_popis'] ?? '');
            if ($id && $nazev) { wp_update_post(['ID'=>$id,'post_title'=>$nazev,'post_content'=>$popis]); $zprava = rs_alert('Typ upraven.'); }
        } elseif ($action === 'smazat') {
            $id = (int)($_POST['typ_id'] ?? 0);
            if ($id) {
                $pouzit = get_posts(['post_type'=>'rs_prostor','numberposts'=>1,'fields'=>'ids','meta_query'=>[['key'=>'rs_typ_id','value'=>$id]]]);
                if ($pouzit) $zprava = rs_alert('Nelze smazat – existují objekty tohoto typu.','error');
                else { wp_delete_post($id, true); $zprava = rs_alert('Typ smazán.'); }
            }
        }
    }

    $typy = get_posts(['post_type'=>'rs_typ','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
    $edit_id = (int)($_GET['rs_edit_typ'] ?? 0);

    ob_start();
    echo "<h3 class='rs-section-title'>Typy objektů</h3>{$zprava}";

    // Form: add / edit
    $edit = $edit_id ? get_post($edit_id) : null;
    echo "<div class='rs-card'><h4 class='rs-card-title'>" . ($edit ? '✏️ Upravit typ' : '➕ Přidat typ') . "</h4>";
    echo "<form method='post'>" . wp_nonce_field('rs_typy','_wpnonce',true,false);
    echo "<input type='hidden' name='rs_typy_action' value='" . ($edit ? 'upravit' : 'pridat') . "'>";
    if ($edit) echo "<input type='hidden' name='typ_id' value='{$edit->ID}'>";
    echo "<div class='rs-form-group'><label>Název typu *</label><input type='text' name='typ_nazev' value='" . esc_attr($edit ? $edit->post_title : '') . "' required></div>";
    echo "<div class='rs-form-group'><label>Popis (nepovinné)</label><textarea name='typ_popis' rows='2'>" . esc_textarea($edit ? $edit->post_content : '') . "</textarea></div>";
    echo "<div class='rs-btn-row'><button type='submit' class='rs-btn rs-btn-primary'>" . ($edit ? '💾 Uložit' : '➕ Přidat') . "</button>";
    if ($edit) echo "<a href='" . esc_url(remove_query_arg('rs_edit_typ')) . "' class='rs-btn rs-btn-secondary'>Zrušit</a>";
    echo "</div></form></div>";

    // List
    if ($typy) {
        echo "<div class='rs-card'><h4 class='rs-card-title'>Přehled typů</h4><table class='rs-table'><thead><tr><th>Název</th><th>Popis</th><th>Akcí</th></tr></thead><tbody>";
        foreach ($typy as $t) {
            echo "<tr><td>" . esc_html($t->post_title) . "</td><td>" . esc_html($t->post_content) . "</td><td>";
            echo "<a href='" . esc_url(add_query_arg('rs_edit_typ',$t->ID)) . "' class='rs-btn rs-btn-sm rs-btn-secondary'>✏️</a> ";
            echo "<form method='post' style='display:inline' onsubmit='return confirm(\"Opravdu smazat?\")'>" . wp_nonce_field('rs_typy','_wpnonce',true,false);
            echo "<input type='hidden' name='rs_typy_action' value='smazat'><input type='hidden' name='typ_id' value='{$t->ID}'>";
            echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>🗑️</button></form>";
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
    }
    return ob_get_clean();
}

// ═══ SEKCE: PROSTORY & SEGMENTY ══════════════════════════════════════════════

function rs_sekce_prostory(): string {
    $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_prostor_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce_prostor'] ?? '', 'rs_prostor')) return rs_alert('Neplatný token.','error');
        $action = sanitize_key($_POST['rs_prostor_action']);
        $zprava = rs_prostor_zpracuj($action);
    }

    $prostory    = rs_get_prostory();
    $typy        = get_posts(['post_type'=>'rs_typ','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
    $edit_id     = (int)($_GET['rs_edit_prostor'] ?? 0);
    $edit        = $edit_id ? get_post($edit_id) : null;
    $edit_seg_id = (int)($_GET['rs_edit_seg'] ?? 0);
    $add_mode    = !empty($_GET['rs_add_prostor']);

    ob_start();
    echo "<h3 class='rs-section-title'>Objekty & Části</h3>{$zprava}";

    // === Seznam prostor (vždy nahoře) ===
    $url_base = remove_query_arg(['rs_edit_prostor','rs_edit_seg','rs_add_prostor']);
    if ($prostory) {
        echo "<div class='rs-card'><h4 class='rs-card-title'>Přehled objektů</h4><table class='rs-table'><thead><tr><th>Název</th><th>Typ</th><th>Části</th><th>Kapacita</th><th>Ext. rezervace</th><th>Akce</th></tr></thead><tbody>";
        foreach ($prostory as $p) {
            $typ_id = get_post_meta($p->ID,'rs_typ_id',true);
            $typ_n  = $typ_id ? get_the_title($typ_id) : '–';
            $segs   = rs_get_segmenty($p->ID);
            if (rs_ma_segmenty($p->ID)) {
                $kap_sum = array_sum(array_map(fn($s) => (int)get_post_meta($s->ID,'rs_kapacita',true), $segs));
                $kap_txt = $kap_sum ? $kap_sum . ' os.' : '–';
            } else {
                $kap_val = get_post_meta($p->ID,'rs_kapacita',true);
                $kap_txt = $kap_val ? esc_html($kap_val).' os.' : '–';
            }
            echo "<tr>";
            echo "<td>" . esc_html($p->post_title) . "</td>";
            echo "<td>" . esc_html($typ_n) . "</td>";
            echo "<td>" . (rs_ma_segmenty($p->ID) ? count($segs) . ' částí' : '–') . "</td>";
            echo "<td>" . $kap_txt . "</td>";
            echo "<td>" . (rs_ext_vypnuto_badge($p->ID) ?: '<span style="color:#1a5c2a;font-size:11px">✓ aktivní</span>') . "</td>";
            echo "<td>";
            echo "<a href='" . esc_url(add_query_arg('rs_edit_prostor',$p->ID,$url_base)) . "' class='rs-btn rs-btn-sm rs-btn-secondary'>✏️</a> ";
            echo "<form method='post' style='display:inline' onsubmit='return confirm(\"Smazat objekt? Možné jen bez rezervací.\")'>" . wp_nonce_field('rs_prostor','_wpnonce_prostor',true,false);
            echo "<input type='hidden' name='rs_prostor_action' value='smazat_prostor'><input type='hidden' name='prostor_id' value='{$p->ID}'>";
            echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>🗑️</button></form>";
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
    }

    // === Formulář prostory (jen při editaci nebo přidávání) ===
    if ($edit || $add_mode) {
        $ma_seg = $edit ? (get_post_meta($edit->ID,'rs_ma_segmenty',true) === '1') : false;
        echo "<div class='rs-card'><h4 class='rs-card-title'>" . ($edit ? '✏️ Upravit objekt' : '➕ Přidat objekt') . "</h4>";
        echo "<form method='post' enctype='multipart/form-data'>" . wp_nonce_field('rs_prostor','_wpnonce_prostor',true,false);
        echo "<input type='hidden' name='rs_prostor_action' value='" . ($edit ? 'upravit_prostor' : 'pridat_prostor') . "'>";
        if ($edit) echo "<input type='hidden' name='prostor_id' value='{$edit->ID}'>";

        echo "<div class='rs-form-row'>";
        echo "<div class='rs-form-group'><label>Název objektu *</label><input type='text' name='prostor_nazev' value='" . esc_attr($edit ? $edit->post_title : '') . "' required></div>";
        echo "<div class='rs-form-group'><label>Typ objektu *</label><select name='prostor_typ' required>";
        echo "<option value='' disabled" . ($edit && get_post_meta($edit->ID,'rs_typ_id',true) ? '' : ' selected') . ">– vyberte typ –</option>";
        foreach ($typy as $t) {
            $sel = ($edit && get_post_meta($edit->ID,'rs_typ_id',true) == $t->ID) ? 'selected' : '';
            echo "<option value='{$t->ID}' {$sel}>" . esc_html($t->post_title) . "</option>";
        }
        echo "</select></div></div>";

        echo "<div class='rs-form-group'><label>Popis</label><textarea name='prostor_popis' rows='3'>" . esc_textarea($edit ? $edit->post_content : '') . "</textarea></div>";

        $adr = esc_attr($edit ? get_post_meta($edit->ID,'rs_adresa',true) : '');
        $gps = esc_attr($edit ? get_post_meta($edit->ID,'rs_gps',true) : '');
        echo "<div class='rs-form-row'>";
        echo "<div class='rs-form-group'><label>Adresa</label><input type='text' name='prostor_adresa' value='{$adr}' placeholder='Např. Husova 123, Chlumec nad Cidlinou'></div>";
        echo "<div class='rs-form-group'><label>GPS souřadnice</label><input type='text' name='prostor_gps' value='{$gps}' placeholder='50.1234567, 15.7654321' style='max-width:220px'></div>";
        echo "</div>";

        $ma_seg_checked = $ma_seg ? 'checked' : '';
        echo "<div class='rs-form-group'><label><input type='checkbox' name='prostor_ma_segmenty' {$ma_seg_checked} onchange='rsToggleSegmenty(this)'> Objekt má části (místnosti)</label></div>";

        $seg_none_style = $ma_seg ? "style='display:none'" : '';
        echo "<div id='rs-noseg' {$seg_none_style}>";
        $roz = esc_attr($edit ? get_post_meta($edit->ID,'rs_rozloha',true) : '');
        $kap = esc_attr($edit ? get_post_meta($edit->ID,'rs_kapacita',true) : '');
        $dop = esc_textarea($edit ? get_post_meta($edit->ID,'rs_doplnujici',true) : '');
        echo "<div class='rs-form-row'>";
        echo "<div class='rs-form-group'><label>Rozloha (m²)</label><input type='number' name='prostor_rozloha' value='{$roz}' min='0' style='width:100px'></div>";
        echo "<div class='rs-form-group'><label>Kapacita (osob k přenocování)</label><input type='number' name='prostor_kapacita' value='{$kap}' min='0' style='width:100px'></div>";
        echo "</div>";
        echo "<div class='rs-form-group'><label>Doplňující informace</label><textarea name='prostor_doplnujici' rows='3'>{$dop}</textarea></div>";
        echo "</div>"; // #rs-noseg

        $fotky = $edit ? (array)get_post_meta($edit->ID,'rs_fotky',true) : [];
        echo rs_foto_field('prostor', $fotky);

        echo rs_ext_vypnuto_field($edit ? $edit->ID : 0);

        echo "<div class='rs-btn-row'><button type='submit' class='rs-btn rs-btn-primary'>" . ($edit ? '💾 Uložit objekt' : '➕ Přidat objekt') . "</button>";
        echo "<a href='" . esc_url($url_base) . "' class='rs-btn rs-btn-secondary'>Zrušit</a>";
        echo "</div></form></div>";
    } else {
        echo "<a href='" . esc_url(add_query_arg('rs_add_prostor','1',$url_base)) . "' class='rs-btn rs-btn-primary' style='margin-top:8px'>➕ Přidat objekt</a>";
    }

    // === Segmenty (jen při editaci prostory se segmenty) ===
    if ($edit && (get_post_meta($edit->ID,'rs_ma_segmenty',true) === '1')) {
        $ma_seg   = true;
        $segmenty = rs_get_segmenty($edit->ID);
        $edit_seg = $edit_seg_id ? get_post($edit_seg_id) : null;

        echo "<div class='rs-card'><h4 class='rs-card-title'>Části objektu: " . esc_html($edit->post_title) . "</h4>";

        echo "<form method='post'>" . wp_nonce_field('rs_prostor','_wpnonce_prostor',true,false);
        echo "<input type='hidden' name='rs_prostor_action' value='" . ($edit_seg ? 'upravit_segment' : 'pridat_segment') . "'>";
        echo "<input type='hidden' name='prostor_id' value='{$edit->ID}'>";
        if ($edit_seg) echo "<input type='hidden' name='segment_id' value='{$edit_seg->ID}'>";

        $s_roz  = esc_attr($edit_seg ? get_post_meta($edit_seg->ID,'rs_rozloha',true) : '');
        $s_kap  = esc_attr($edit_seg ? get_post_meta($edit_seg->ID,'rs_kapacita',true) : '');
        $s_dop  = esc_textarea($edit_seg ? get_post_meta($edit_seg->ID,'rs_doplnujici',true) : '');
        $s_fotky = $edit_seg ? (array)get_post_meta($edit_seg->ID,'rs_fotky',true) : [];

        echo "<h5>" . ($edit_seg ? '✏️ Upravit část' : '➕ Přidat část') . "</h5>";
        echo "<div class='rs-form-group'><label>Název části *</label><input type='text' name='segment_nazev' value='" . esc_attr($edit_seg ? $edit_seg->post_title : '') . "' required></div>";
        echo "<div class='rs-form-group'><label>Popis</label><textarea name='segment_popis' rows='2'>" . esc_textarea($edit_seg ? $edit_seg->post_content : '') . "</textarea></div>";
        echo "<div class='rs-form-row'>";
        echo "<div class='rs-form-group'><label>Rozloha (m²)</label><input type='number' name='segment_rozloha' value='{$s_roz}' min='0' style='width:100px'></div>";
        echo "<div class='rs-form-group'><label>Kapacita (osob)</label><input type='number' name='segment_kapacita' value='{$s_kap}' min='0' style='width:100px'></div>";
        echo "</div>";
        echo "<div class='rs-form-group'><label>Doplňující informace</label><textarea name='segment_doplnujici' rows='2'>{$s_dop}</textarea></div>";
        echo rs_foto_field('segment', $s_fotky);
        echo rs_ext_vypnuto_field($edit_seg ? $edit_seg->ID : 0);
        echo "<div class='rs-btn-row'><button type='submit' class='rs-btn rs-btn-primary'>💾 Uložit část</button>";
        if ($edit_seg) echo "<a href='" . esc_url(remove_query_arg('rs_edit_seg')) . "' class='rs-btn rs-btn-secondary'>Zrušit</a>";
        echo "</div></form>";

        if ($segmenty) {
            echo "<table class='rs-table' style='margin-top:16px'><thead><tr><th>Název</th><th>Rozloha</th><th>Kapacita</th><th>Ext. rezervace</th><th>Akce</th></tr></thead><tbody>";
            foreach ($segmenty as $seg) {
                echo "<tr><td>" . esc_html($seg->post_title) . "</td>";
                echo "<td>" . esc_html(get_post_meta($seg->ID,'rs_rozloha',true)) . " m²</td>";
                echo "<td>" . esc_html(get_post_meta($seg->ID,'rs_kapacita',true)) . " os.</td>";
                echo "<td>" . (rs_ext_vypnuto_badge($seg->ID) ?: '<span style="color:#1a5c2a;font-size:11px">✓ aktivní</span>') . "</td>";
                echo "<td>";
                echo "<a href='" . esc_url(add_query_arg(['rs_edit_prostor'=>$edit->ID,'rs_edit_seg'=>$seg->ID])) . "' class='rs-btn rs-btn-sm rs-btn-secondary'>✏️</a> ";
                echo "<form method='post' style='display:inline' onsubmit='return confirm(\"Smazat část?\")'>" . wp_nonce_field('rs_prostor','_wpnonce_prostor',true,false);
                echo "<input type='hidden' name='rs_prostor_action' value='smazat_segment'>";
                echo "<input type='hidden' name='segment_id' value='{$seg->ID}'>";
                echo "<input type='hidden' name='prostor_id' value='{$edit->ID}'>";
                echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>🗑️</button></form>";
                echo "</td></tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<p style='color:#777;font-size:13px'>Zatím žádné části.</p>";
        }
        echo "</div>"; // card
    }

    // JS pro toggle segmentů a WP media uploader
    ?>
    <script>
    function rsToggleSegmenty(cb){
        document.getElementById('rs-noseg').style.display = cb.checked ? 'none' : '';
    }
    function rsFotoInit(fieldName) {
        var btn = document.getElementById('rs-foto-btn-'+fieldName);
        if (!btn) return;
        btn.addEventListener('click', function(e){
            e.preventDefault();
            var frame = wp.media({title:'Vyberte fotky',button:{text:'Použít'},multiple:true});
            frame.on('select', function(){
                frame.state().get('selection').each(function(att){
                    var id = att.id;
                    var url = att.attributes.url || (att.attributes.sizes && att.attributes.sizes.thumbnail ? att.attributes.sizes.thumbnail.url : att.attributes.url);
                    if (document.getElementById('rs-foto-id-'+fieldName+'-'+id)) return;
                    var wrap = document.getElementById('rs-foto-preview-'+fieldName);
                    var item = document.createElement('div');
                    item.className = 'rs-foto-item';
                    item.id = 'rs-foto-id-'+fieldName+'-'+id;
                    item.innerHTML = '<img src="'+url+'"><button class="rs-foto-del" type="button" onclick="rsFotoDel(\''+fieldName+'\','+id+',this.parentNode)">×</button><input type="hidden" name="fotky_'+fieldName+'[]" value="'+id+'">';
                    wrap.appendChild(item);
                });
            });
            frame.open();
        });
    }
    function rsFotoDel(field, id, el){ el.remove(); }
    document.addEventListener('DOMContentLoaded', function(){
        rsFotoInit('prostor');
        rsFotoInit('segment');
    });
    </script>
    <?php
    return ob_get_clean();
}

function rs_ext_vypnuto_field(int $id): string {
    $on  = $id ? get_post_meta($id, 'rs_ext_vypnuto', true) === '1' : false;
    $od  = $id ? esc_attr(get_post_meta($id, 'rs_ext_vypnuto_od', true)) : '';
    $do_ = $id ? esc_attr(get_post_meta($id, 'rs_ext_vypnuto_do', true)) : '';
    $chk = $on ? 'checked' : '';
    $sty = $on ? '' : "style='display:none'";
    ob_start();
    echo "<div class='rs-form-group' style='margin-top:12px;padding:12px;background:#fff8f8;border:1px solid #f5c6cb;border-radius:4px'>";
    echo "<label><input type='checkbox' name='ext_vypnuto' {$chk} onchange=\"document.getElementById('rs-ev-dates-{$id}').style.display=this.checked?'':'none'\"> Vypnout pro externí rezervace</label>";
    echo "<div id='rs-ev-dates-{$id}' {$sty} style='display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:8px;align-items:center'>";
    echo "<div class='rs-form-group' style='margin:0'><label style='font-size:12px'>Od (nepovinné)</label><input type='date' name='ext_vypnuto_od' value='{$od}'></div>";
    echo "<div class='rs-form-group' style='margin:0'><label style='font-size:12px'>Do (nepovinné)</label><input type='date' name='ext_vypnuto_do' value='{$do_}'></div>";
    echo "<p style='font-size:11px;color:#888;margin:4px 0 0;width:100%'>Prázdné pole = bez omezení termínu (od ihned / do odvolání).</p>";
    echo "</div></div>";
    return ob_get_clean();
}

function rs_uloz_ext_vypnuto(int $id): void {
    update_post_meta($id, 'rs_ext_vypnuto', isset($_POST['ext_vypnuto']) ? '1' : '0');
    update_post_meta($id, 'rs_ext_vypnuto_od', sanitize_text_field($_POST['ext_vypnuto_od'] ?? ''));
    update_post_meta($id, 'rs_ext_vypnuto_do', sanitize_text_field($_POST['ext_vypnuto_do'] ?? ''));
}

function rs_foto_field(string $field, array $existing_ids): string {
    ob_start();
    echo "<div class='rs-form-group'><label>Fotky</label>";
    echo "<div id='rs-foto-preview-{$field}' class='rs-foto-preview'>";
    foreach ($existing_ids as $att_id) {
        $att_id = (int)$att_id;
        if (!$att_id) continue;
        $url = wp_get_attachment_image_url($att_id, 'thumbnail') ?: wp_get_attachment_url($att_id);
        if (!$url) continue;
        echo "<div class='rs-foto-item' id='rs-foto-id-{$field}-{$att_id}'>";
        echo "<img src='" . esc_url($url) . "'>";
        echo "<button class='rs-foto-del' type='button' onclick='rsFotoDel(\"{$field}\",{$att_id},this.parentNode)'>×</button>";
        echo "<input type='hidden' name='fotky_{$field}[]' value='{$att_id}'>";
        echo "</div>";
    }
    echo "</div>";
    echo "<button type='button' id='rs-foto-btn-{$field}' class='rs-btn rs-btn-secondary rs-btn-sm' style='margin-top:6px'>📷 Přidat fotky</button>";
    echo "</div>";
    return ob_get_clean();
}

function rs_prepocitej_kapacitu_prostoru(int $pid): void {
    $segs = rs_get_segmenty($pid);
    $sum  = array_sum(array_map(fn($s) => (int)get_post_meta($s->ID,'rs_kapacita',true), $segs));
    update_post_meta($pid, 'rs_kapacita', $sum ?: '');
}

function rs_prostor_zpracuj(string $action): string {
    switch ($action) {
        case 'pridat_prostor': {
            $nazev = sanitize_text_field($_POST['prostor_nazev'] ?? '');
            if (!$nazev) return rs_alert('Zadejte název objektu.','error');
            if (!(int)($_POST['prostor_typ']??0)) return rs_alert('Vyberte typ objektu.','error');
            $ex = get_posts(['post_type'=>'rs_prostor','title'=>$nazev,'post_parent'=>0,'numberposts'=>1,'fields'=>'ids']);
            if ($ex) return rs_alert('Objekt s tímto názvem již existuje.','error');
            $ma_seg = isset($_POST['prostor_ma_segmenty']) ? '1' : '0';
            $id = wp_insert_post(['post_type'=>'rs_prostor','post_title'=>$nazev,'post_content'=>sanitize_textarea_field($_POST['prostor_popis']??''),'post_status'=>'publish']);
            update_post_meta($id,'rs_typ_id',(int)($_POST['prostor_typ']??0));
            update_post_meta($id,'rs_ma_segmenty',$ma_seg);
            update_post_meta($id,'rs_adresa', sanitize_text_field($_POST['prostor_adresa']??''));
            update_post_meta($id,'rs_gps',    sanitize_text_field($_POST['prostor_gps']??''));
            if ($ma_seg === '0') {
                update_post_meta($id,'rs_rozloha',  (int)($_POST['prostor_rozloha']??0));
                update_post_meta($id,'rs_kapacita', (int)($_POST['prostor_kapacita']??0));
                update_post_meta($id,'rs_doplnujici',sanitize_textarea_field($_POST['prostor_doplnujici']??''));
            }
            rs_uloz_fotky($id, 'prostor');
            rs_uloz_ext_vypnuto($id);
            return rs_alert('Objekt přidán. <a href="' . esc_url(add_query_arg('rs_edit_prostor',$id)) . '">Přejít na úpravu / přidat části</a>');
        }
        case 'upravit_prostor': {
            $id = (int)($_POST['prostor_id'] ?? 0);
            if (!$id) return '';
            if (!(int)($_POST['prostor_typ']??0)) return rs_alert('Vyberte typ objektu.','error');
            $nazev = sanitize_text_field($_POST['prostor_nazev'] ?? '');
            wp_update_post(['ID'=>$id,'post_title'=>$nazev,'post_content'=>sanitize_textarea_field($_POST['prostor_popis']??'')]);
            update_post_meta($id,'rs_typ_id',(int)($_POST['prostor_typ']??0));
            $ma_seg = isset($_POST['prostor_ma_segmenty']) ? '1' : '0';
            update_post_meta($id,'rs_ma_segmenty',$ma_seg);
            update_post_meta($id,'rs_adresa', sanitize_text_field($_POST['prostor_adresa']??''));
            update_post_meta($id,'rs_gps',    sanitize_text_field($_POST['prostor_gps']??''));
            if ($ma_seg === '0') {
                update_post_meta($id,'rs_rozloha',  (int)($_POST['prostor_rozloha']??0));
                update_post_meta($id,'rs_kapacita', (int)($_POST['prostor_kapacita']??0));
                update_post_meta($id,'rs_doplnujici',sanitize_textarea_field($_POST['prostor_doplnujici']??''));
            }
            rs_uloz_fotky($id, 'prostor');
            rs_uloz_ext_vypnuto($id);
            return rs_alert('Objekt uložen.');
        }
        case 'smazat_prostor': {
            $id = (int)($_POST['prostor_id'] ?? 0);
            if (!$id) return '';
            $rez = get_posts(['post_type'=>'rs_rezervace','numberposts'=>1,'fields'=>'ids','meta_query'=>[['key'=>'rs_prostor_id','value'=>$id]]]);
            if ($rez) return rs_alert('Nelze smazat – objekt má rezervace.','error');
            foreach (rs_get_segmenty($id) as $seg) wp_delete_post($seg->ID, true);
            wp_delete_post($id, true);
            return rs_alert('Objekt smazán.');
        }
        case 'pridat_segment': {
            $pid   = (int)($_POST['prostor_id'] ?? 0);
            $nazev = sanitize_text_field($_POST['segment_nazev'] ?? '');
            if (!$pid || !$nazev) return rs_alert('Chybí data.','error');
            $ex = get_posts(['post_type'=>'rs_prostor','title'=>$nazev,'post_parent'=>$pid,'numberposts'=>1,'fields'=>'ids']);
            if ($ex) return rs_alert('Část s tímto názvem již existuje.','error');
            $sid = wp_insert_post(['post_type'=>'rs_prostor','post_title'=>$nazev,'post_content'=>sanitize_textarea_field($_POST['segment_popis']??''),'post_parent'=>$pid,'post_status'=>'publish']);
            update_post_meta($sid,'rs_rozloha',  (int)($_POST['segment_rozloha']??0));
            update_post_meta($sid,'rs_kapacita', (int)($_POST['segment_kapacita']??0));
            update_post_meta($sid,'rs_doplnujici',sanitize_textarea_field($_POST['segment_doplnujici']??''));
            rs_uloz_fotky($sid, 'segment');
            rs_uloz_ext_vypnuto($sid);
            rs_prepocitej_kapacitu_prostoru($pid);
            return rs_alert('Část přidána.');
        }
        case 'upravit_segment': {
            $sid = (int)($_POST['segment_id'] ?? 0);
            $pid = (int)($_POST['prostor_id'] ?? 0);
            if (!$sid) return '';
            wp_update_post(['ID'=>$sid,'post_title'=>sanitize_text_field($_POST['segment_nazev']??''),'post_content'=>sanitize_textarea_field($_POST['segment_popis']??'')]);
            update_post_meta($sid,'rs_rozloha',  (int)($_POST['segment_rozloha']??0));
            update_post_meta($sid,'rs_kapacita', (int)($_POST['segment_kapacita']??0));
            update_post_meta($sid,'rs_doplnujici',sanitize_textarea_field($_POST['segment_doplnujici']??''));
            rs_uloz_fotky($sid, 'segment');
            rs_uloz_ext_vypnuto($sid);
            if ($pid) rs_prepocitej_kapacitu_prostoru($pid);
            return rs_alert('Část uložena.');
        }
        case 'smazat_segment': {
            $sid = (int)($_POST['segment_id'] ?? 0);
            $pid = (int)($_POST['prostor_id'] ?? 0);
            if (!$sid) return '';
            $rez = get_posts(['post_type'=>'rs_rezervace','numberposts'=>1,'fields'=>'ids','meta_query'=>[['key'=>'rs_segmenty_ids','value'=>$sid,'compare'=>'LIKE']]]);
            if ($rez) return rs_alert('Nelze smazat – část má rezervace.','error');
            wp_delete_post($sid, true);
            if ($pid) rs_prepocitej_kapacitu_prostoru($pid);
            return rs_alert('Část smazána.');
        }
    }
    return '';
}

function rs_uloz_fotky(int $post_id, string $field) {
    $ids = array_map('intval', (array)($_POST['fotky_' . $field] ?? []));
    update_post_meta($post_id, 'rs_fotky', array_filter($ids));
}

// ═══ SEKCE: PRÁZDNINY & SVÁTKY ═══════════════════════════════════════════════

function rs_sekce_prazdniny(): string {
    $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_praz_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rs_prazdniny')) return rs_alert('Neplatný token.','error');
        $action = sanitize_key($_POST['rs_praz_action']);

        if ($action === 'pridat_svatek') {
            $den   = (int)($_POST['svatek_den']   ?? 0);
            $mesic = (int)($_POST['svatek_mesic'] ?? 0);
            $nazev = sanitize_text_field($_POST['svatek_nazev'] ?? '');
            $datum = ($den >= 1 && $den <= 31 && $mesic >= 1 && $mesic <= 12)
                     ? sprintf('%02d-%02d', $mesic, $den) : '';
            if ($datum && $nazev) {
                $svatky = get_option('rs_statni_svatky_data', []);
                $svatky[$datum] = $nazev;
                ksort($svatky);
                update_option('rs_statni_svatky_data', $svatky);
                update_option('rs_statni_svatky', array_keys($svatky));
                $zprava = rs_alert('Svátek přidán.');
            }
        } elseif ($action === 'smazat_svatek') {
            $datum = sanitize_text_field($_POST['svatek_datum'] ?? '');
            $svatky = get_option('rs_statni_svatky_data', []);
            unset($svatky[$datum]);
            update_option('rs_statni_svatky_data', $svatky);
            update_option('rs_statni_svatky', array_keys($svatky));
            $zprava = rs_alert('Svátek odstraněn.');
        } elseif ($action === 'pridat_velikonoce') {
            $rok = (int)($_POST['velikonoce_rok'] ?? 0);
            if ($rok >= 2020 && $rok <= 2060) {
                [$patek, $pondeli] = rs_vypocti_velikonoce($rok);
                $vel = get_option('rs_velikonoce', []);
                $vel[$patek]   = 'Velký pátek';
                $vel[$pondeli] = 'Velikonoční pondělí';
                ksort($vel);
                update_option('rs_velikonoce', $vel);
                $zprava = rs_alert("Přidány Velikonoce {$rok}: Velký pátek ({$patek}), Velikonoční pondělí ({$pondeli}).");
            }
        } elseif ($action === 'smazat_velikonoc') {
            $datum = sanitize_text_field($_POST['velikonoc_datum'] ?? '');
            $vel = get_option('rs_velikonoce', []);
            unset($vel[$datum]);
            update_option('rs_velikonoce', $vel);
            $zprava = rs_alert('Svátek odstraněn.');
        } elseif ($action === 'pridat_prazdniny') {
            $nazev = sanitize_text_field($_POST['praz_nazev'] ?? '');
            $od    = sanitize_text_field($_POST['praz_od'] ?? '');
            $do_   = sanitize_text_field($_POST['praz_do'] ?? '');
            if ($nazev && $od && $do_) {
                $prazdniny = get_option('rs_prazdniny', []);
                $duplicitni_nazev = false;
                $prekryv = false;
                foreach ($prazdniny as $p) {
                    if (strcasecmp($p['nazev'], $nazev) === 0) $duplicitni_nazev = true;
                    if ($od <= $p['do'] && $do_ >= $p['od']) $prekryv = true;
                }
                if ($prekryv) {
                    $zprava = rs_alert('Termín se překrývá s existujícími prázdninami. Zkontrolujte data.', 'error');
                } elseif ($duplicitni_nazev) {
                    $zprava = rs_alert('Prázdniny s názvem „' . esc_html($nazev) . '" již existují. Doplňte rok, např. „' . esc_html($nazev) . ' 2027".', 'error');
                } else {
                    $prazdniny[] = ['nazev' => $nazev, 'od' => $od, 'do' => $do_];
                    usort($prazdniny, fn($a, $b) => strcmp($a['od'], $b['od']));
                    update_option('rs_prazdniny', $prazdniny);
                    $zprava = rs_alert('Prázdniny přidány.');
                }
            }
        } elseif ($action === 'upravit_prazdniny') {
            $idx   = (int)($_POST['praz_idx'] ?? -1);
            $nazev = sanitize_text_field($_POST['praz_nazev'] ?? '');
            $od    = sanitize_text_field($_POST['praz_od'] ?? '');
            $do_   = sanitize_text_field($_POST['praz_do'] ?? '');
            if ($idx >= 0 && $nazev && $od && $do_) {
                $prazdniny = get_option('rs_prazdniny', []);
                $duplicitni_nazev = false;
                $prekryv = false;
                foreach ($prazdniny as $i => $p) {
                    if ($i === $idx) continue;
                    if (strcasecmp($p['nazev'], $nazev) === 0) $duplicitni_nazev = true;
                    if ($od <= $p['do'] && $do_ >= $p['od']) $prekryv = true;
                }
                if ($prekryv) {
                    $zprava = rs_alert('Termín se překrývá s existujícími prázdninami. Zkontrolujte data.', 'error');
                } elseif ($duplicitni_nazev) {
                    $zprava = rs_alert('Prázdniny s názvem „' . esc_html($nazev) . '" již existují. Doplňte rok, např. „' . esc_html($nazev) . ' 2027".', 'error');
                } else {
                    $prazdniny[$idx] = ['nazev' => $nazev, 'od' => $od, 'do' => $do_];
                    usort($prazdniny, fn($a, $b) => strcmp($a['od'], $b['od']));
                    update_option('rs_prazdniny', $prazdniny);
                    $zprava = rs_alert('Prázdniny uloženy.');
                }
            }
        } elseif ($action === 'smazat_prazdniny') {
            $idx = (int)($_POST['praz_idx'] ?? -1);
            $prazdniny = get_option('rs_prazdniny', []);
            unset($prazdniny[$idx]);
            update_option('rs_prazdniny', array_values($prazdniny));
            $zprava = rs_alert('Prázdniny odstraněny.');
        }
    }

    ob_start();
    echo "<h3 class='rs-section-title'>Prázdniny & Státní svátky</h3>{$zprava}";

    // Státní svátky
    $svatky = get_option('rs_statni_svatky_data', []);
    $mesice_cz = ['1'=>'leden','2'=>'únor','3'=>'březen','4'=>'duben','5'=>'květen','6'=>'červen','7'=>'červenec','8'=>'srpen','9'=>'září','10'=>'říjen','11'=>'listopad','12'=>'prosinec'];
    echo "<div class='rs-card'><h4 class='rs-card-title'>Státní svátky kromě Velikonoc</h4>";
    echo "<p style='font-size:13px;color:#777;margin:0 0 12px'>Svátky se opakují každý rok – zadejte jen den a měsíc.</p>";
    echo "<form method='post' class='rs-form-row'>" . wp_nonce_field('rs_prazdniny','_wpnonce',true,false);
    echo "<input type='hidden' name='rs_praz_action' value='pridat_svatek'>";
    echo "<div class='rs-form-group'><label>Den</label><select name='svatek_den' required style='width:80px'><option value=''>–</option>";
    for ($d=1;$d<=31;$d++) echo "<option value='{$d}'>{$d}.</option>";
    echo "</select></div>";
    echo "<div class='rs-form-group'><label>Měsíc</label><select name='svatek_mesic' required style='width:130px'><option value=''>–</option>";
    foreach ($mesice_cz as $k=>$v) echo "<option value='{$k}'>{$v}</option>";
    echo "</select></div>";
    echo "<div class='rs-form-group'><label>Název svátku</label><input type='text' name='svatek_nazev' required></div>";
    echo "<div class='rs-form-group' style='align-self:flex-end'><button type='submit' class='rs-btn rs-btn-primary'>➕ Přidat</button></div>";
    echo "</form>";
    if ($svatky) {
        ksort($svatky);
        echo "<table class='rs-table'><thead><tr><th>Datum</th><th>Název</th><th></th></tr></thead><tbody>";
        foreach ($svatky as $klic => $n) {
            [$mm,$dd] = explode('-', $klic) + ['01','01'];
            $popis = (int)$dd . '. ' . ($mesice_cz[(int)$mm] ?? $mm);
            echo "<tr><td>" . esc_html($popis) . "</td><td>" . esc_html($n) . "</td><td>";
            echo "<form method='post' style='display:inline'>" . wp_nonce_field('rs_prazdniny','_wpnonce',true,false);
            echo "<input type='hidden' name='rs_praz_action' value='smazat_svatek'><input type='hidden' name='svatek_datum' value='" . esc_attr($klic) . "'>";
            echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>🗑️</button></form></td></tr>";
        }
        echo "</tbody></table>";
    }
    echo "</div>";

    // Velikonoce
    $vel = get_option('rs_velikonoce', []);
    ksort($vel);
    $rok_nyni = (int)date('Y');
    echo "<div class='rs-card'><h4 class='rs-card-title'>Velikonoce (Velký pátek &amp; Velikonoční pondělí)</h4>";
    echo "<p style='font-size:13px;color:#777;margin:0 0 12px'>Data Velikonoc se mění každý rok – přidejte rok a data se vypočítají automaticky.</p>";
    echo "<form method='post' class='rs-form-row'>" . wp_nonce_field('rs_prazdniny','_wpnonce',true,false);
    echo "<input type='hidden' name='rs_praz_action' value='pridat_velikonoce'>";
    echo "<div class='rs-form-group'><label>Rok</label><select name='velikonoce_rok' style='width:110px'>";
    for ($y = $rok_nyni - 1; $y <= $rok_nyni + 5; $y++) {
        $already = false;
        [$pf,$pm] = rs_vypocti_velikonoce($y);
        if (isset($vel[$pf]) && isset($vel[$pm])) $already = true;
        $label = $y . ($already ? ' ✓' : '');
        echo "<option value='{$y}'" . ($y === $rok_nyni ? ' selected' : '') . ">{$label}</option>";
    }
    echo "</select></div>";
    echo "<div class='rs-form-group' style='align-self:flex-end'><button type='submit' class='rs-btn rs-btn-primary'>➕ Přidat Velikonoce</button></div>";
    echo "</form>";
    if ($vel) {
        echo "<table class='rs-table'><thead><tr><th>Datum</th><th>Název</th><th></th></tr></thead><tbody>";
        foreach ($vel as $datum => $nazev) {
            echo "<tr><td>" . esc_html($datum) . "</td><td>" . esc_html($nazev) . "</td><td>";
            echo "<form method='post' style='display:inline'>" . wp_nonce_field('rs_prazdniny','_wpnonce',true,false);
            echo "<input type='hidden' name='rs_praz_action' value='smazat_velikonoc'><input type='hidden' name='velikonoc_datum' value='" . esc_attr($datum) . "'>";
            echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>🗑️</button></form></td></tr>";
        }
        echo "</tbody></table>";
    }
    echo "</div>";

    // Prázdniny
    $prazdniny = get_option('rs_prazdniny', []);
    usort($prazdniny, fn($a, $b) => strcmp($a['od'], $b['od']));
    echo "<div class='rs-card'><h4 class='rs-card-title'>Prázdniny</h4>";
    echo "<form id='rs-praz-form' method='post' class='rs-form-row'>" . wp_nonce_field('rs_prazdniny','_wpnonce',true,false);
    echo "<input type='hidden' id='rs-praz-action' name='rs_praz_action' value='pridat_prazdniny'>";
    echo "<input type='hidden' id='rs-praz-idx' name='praz_idx' value=''>";
    echo "<div class='rs-form-group'><label>Název</label><input type='text' name='praz_nazev' required placeholder='např. Letní prázdniny 2025'></div>";
    echo "<div class='rs-form-group'><label>Od</label><input type='date' name='praz_od' required style='width:160px'></div>";
    echo "<div class='rs-form-group'><label>Do</label><input type='date' name='praz_do' required style='width:160px'></div>";
    echo "<div class='rs-form-group' style='align-self:flex-end'>";
    echo "<button id='rs-praz-submit' type='submit' class='rs-btn rs-btn-primary'>➕ Přidat</button> ";
    echo "<button id='rs-praz-cancel' type='button' class='rs-btn rs-btn-secondary' style='display:none' onclick='rsPrazReset()'>Zrušit</button>";
    echo "</div></form>";
    if ($prazdniny) {
        echo "<table class='rs-table'><thead><tr><th>Název</th><th>Od</th><th>Do</th><th></th></tr></thead><tbody>";
        foreach ($prazdniny as $i => $p) {
            $js_nazev = esc_js($p['nazev']);
            echo "<tr><td>" . esc_html($p['nazev']) . "</td><td>" . esc_html($p['od']) . "</td><td>" . esc_html($p['do']) . "</td><td style='white-space:nowrap'>";
            echo "<button type='button' class='rs-btn rs-btn-sm rs-btn-secondary' onclick='rsPrazEdit({$i},\"{$js_nazev}\",\"{$p['od']}\",\"{$p['do']}\")'>✏️</button> ";
            echo "<form method='post' style='display:inline'>" . wp_nonce_field('rs_prazdniny','_wpnonce',true,false);
            echo "<input type='hidden' name='rs_praz_action' value='smazat_prazdniny'><input type='hidden' name='praz_idx' value='{$i}'>";
            echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger' onclick='return confirm(\"Smazat prázdniny?\")'>🗑️</button></form></td></tr>";
        }
        echo "</tbody></table>";
    }
    echo "</div>";
    ?>
    <script>
    function rsPrazEdit(idx, nazev, od, do_) {
        var form = document.getElementById('rs-praz-form');
        form.querySelector('[name=praz_nazev]').value = nazev;
        form.querySelector('[name=praz_od]').value = od;
        form.querySelector('[name=praz_do]').value = do_;
        document.getElementById('rs-praz-action').value = 'upravit_prazdniny';
        document.getElementById('rs-praz-idx').value = idx;
        document.getElementById('rs-praz-submit').textContent = '💾 Uložit změny';
        document.getElementById('rs-praz-cancel').style.display = '';
        form.querySelector('[name=praz_nazev]').focus();
        form.scrollIntoView({behavior:'smooth', block:'nearest'});
    }
    function rsPrazReset() {
        var form = document.getElementById('rs-praz-form');
        form.reset();
        document.getElementById('rs-praz-action').value = 'pridat_prazdniny';
        document.getElementById('rs-praz-idx').value = '';
        document.getElementById('rs-praz-submit').textContent = '➕ Přidat';
        document.getElementById('rs-praz-cancel').style.display = 'none';
    }
    </script>
    <?php
    return ob_get_clean();
}

// ═══ SEKCE: CENY ═════════════════════════════════════════════════════════════

function rs_sekce_ceny(): string {
    $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_ceny_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rs_ceny')) return rs_alert('Neplatný token.','error');
        $pid = (int)($_POST['ceny_prostor_id'] ?? 0);
        if ($pid) {
            $rezim = in_array($_POST['ceny_rezim'] ?? '', ['celek','segmenty']) ? $_POST['ceny_rezim'] : 'celek';
            update_post_meta($pid, 'rs_ceny_rezim', $rezim);

            $fn = fn($k) => (float)str_replace(',','.', $_POST[$k] ?? 0);

            if ($rezim === 'celek' || !rs_ma_segmenty($pid)) {
                update_post_meta($pid, 'rs_cena_za_osobu', $fn('cena_za_osobu'));
                update_post_meta($pid, 'rs_cena_min',      $fn('cena_min'));
            } else {
                foreach (rs_get_segmenty($pid) as $seg) {
                    update_post_meta($seg->ID, 'rs_cena_za_osobu', $fn('seg_cena_osobu_'.$seg->ID));
                    update_post_meta($seg->ID, 'rs_cena_min',      $fn('seg_cena_min_'.$seg->ID));
                }
            }
            $zprava = rs_alert('Ceny uloženy.');
        }
    }

    $prostory = rs_get_prostory();
    $sel_pid  = (int)($_POST['ceny_prostor_id'] ?? ($_GET['ceny_pid'] ?? ($prostory[0]->ID ?? 0)));

    ob_start();
    echo "<h3 class='rs-section-title'>Ceník pronájmu objektů</h3>{$zprava}";
    echo "<div class='rs-card'>";
    echo "<form method='post'>" . wp_nonce_field('rs_ceny','_wpnonce',true,false);
    echo "<input type='hidden' name='rs_ceny_action' value='ulozit'>";
    echo "<div class='rs-form-group'><label>Vyberte objekt</label><select name='ceny_prostor_id' onchange='window.location.href=location.pathname+\"?ceny_pid=\"+this.value+\"#rs-ceny\"'>";
    foreach ($prostory as $p) {
        $sel = $p->ID === $sel_pid ? 'selected' : '';
        $typ_id = get_post_meta($p->ID,'rs_typ_id',true);
        $label  = esc_html($p->post_title) . ($typ_id ? ' – ' . esc_html(get_the_title($typ_id)) : '');
        echo "<option value='{$p->ID}' {$sel}>{$label}</option>";
    }
    echo "</select></div>";

    if ($sel_pid) {
        $ma_seg = rs_ma_segmenty($sel_pid);
        $rezim  = get_post_meta($sel_pid, 'rs_ceny_rezim', true) ?: 'celek';

        $hint = "<p style='font-size:13px;color:#777;margin:0 0 14px'>Zadejte <strong>cenu za osobu</strong> – volitelně s minimální cenou (uplatní se, pokud by cena za osoby byla nižší). Pro paušální cenu bez ohledu na počet osob stačí zadat jen minimální cenu.</p>";

        if ($ma_seg) {
            // Toggle celek vs segmenty
            $r_celek = $rezim === 'celek' ? 'checked' : '';
            $r_seg   = $rezim === 'segmenty' ? 'checked' : '';
            echo "<div class='rs-form-group' style='margin-bottom:16px'>";
            echo "<label style='margin-right:20px'><input type='radio' name='ceny_rezim' value='celek' {$r_celek} onchange='rsCenyRezim(\"celek\")'> Ceny za objekt jako celek</label>";
            echo "<label><input type='radio' name='ceny_rezim' value='segmenty' {$r_seg} onchange='rsCenyRezim(\"segmenty\")'> Ceny za každou část zvlášť</label>";
            echo "</div>";

            // Panel celek
            $co  = (float)get_post_meta($sel_pid,'rs_cena_za_osobu',true);
            $cm  = (float)get_post_meta($sel_pid,'rs_cena_min',true);
            $d_celek = $rezim === 'segmenty' ? "style='display:none'" : '';
            echo "<div id='rs-ceny-celek' {$d_celek}>";
            echo $hint;
            echo rs_ceny_fields('cena_za_osobu','cena_min', $co, $cm);
            echo "</div>";

            // Panel segmenty
            $d_seg = $rezim === 'celek' ? "style='display:none'" : '';
            echo "<div id='rs-ceny-segmenty' {$d_seg}>";
            echo $hint;
            foreach (rs_get_segmenty($sel_pid) as $seg) {
                $so = (float)get_post_meta($seg->ID,'rs_cena_za_osobu',true);
                $sm = (float)get_post_meta($seg->ID,'rs_cena_min',true);
                echo "<div class='rs-segment-box'><strong>" . esc_html($seg->post_title) . "</strong><div style='margin-top:8px'>";
                echo rs_ceny_fields('seg_cena_osobu_'.$seg->ID, 'seg_cena_min_'.$seg->ID, $so, $sm);
                echo "</div></div>";
            }
            echo "</div>";
        } else {
            $co = (float)get_post_meta($sel_pid,'rs_cena_za_osobu',true);
            $cm = (float)get_post_meta($sel_pid,'rs_cena_min',true);
            echo $hint;
            echo rs_ceny_fields('cena_za_osobu','cena_min', $co, $cm);
        }

        echo "<div class='rs-btn-row'><button type='submit' class='rs-btn rs-btn-primary'>💾 Uložit ceny</button></div>";
    }
    echo "</form></div>";
    ?>
    <script>
    function rsCenyRezim(r){
        document.getElementById('rs-ceny-celek').style.display    = r==='celek'    ? '' : 'none';
        document.getElementById('rs-ceny-segmenty').style.display = r==='segmenty' ? '' : 'none';
        if (r === 'segmenty') {
            var osobu = document.querySelector('[name=cena_za_osobu]').value;
            var min   = document.querySelector('[name=cena_min]').value;
            document.querySelectorAll('[name^=seg_cena_osobu_]').forEach(function(i){ i.value = osobu; });
            document.querySelectorAll('[name^=seg_cena_min_]').forEach(function(i){ i.value = min; });
        }
    }
    </script>
    <?php
    return ob_get_clean();
}

function rs_ceny_fields(string $n_osobu, string $n_min, float $v_osobu, float $v_min): string {
    $fmt = fn($v) => $v > 0 ? number_format($v, 0, '.', '') : '';
    ob_start();
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Cena za osobu (Kč)</label><input type='number' name='{$n_osobu}' value='" . $fmt($v_osobu) . "' min='0' step='1' style='width:140px' placeholder='0'></div>";
    echo "<div class='rs-form-group'><label>Minimální cena (Kč)</label><input type='number' name='{$n_min}' value='" . $fmt($v_min) . "' min='0' step='1' style='width:140px' placeholder='bez minima'></div>";
    echo "</div>";
    return ob_get_clean();
}

// ═══ SEKCE: NASTAVENÍ ════════════════════════════════════════════════════════

function rs_sekce_nastaveni(): string {
    $zprava = '';
    $zprava_oddil = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_oddil_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce_oddil'] ?? '', 'rs_oddily')) {
            $zprava_oddil = rs_alert('Neplatný token.','error');
        } else {
            $action = sanitize_key($_POST['rs_oddil_action']);
            $oddily = get_option('rs_oddily', []);
            if ($action === 'pridat_oddil') {
                $nazev = sanitize_text_field($_POST['oddil_nazev'] ?? '');
                if ($nazev) {
                    if (in_array($nazev, $oddily, true)) {
                        $zprava_oddil = rs_alert('Součást s tímto názvem již existuje.','error');
                    } else {
                        $oddily[] = $nazev;
                        sort($oddily);
                        update_option('rs_oddily', $oddily);
                        $zprava_oddil = rs_alert('Součást přidána.');
                    }
                }
            } elseif ($action === 'upravit_oddil') {
                $idx   = (int)($_POST['oddil_idx'] ?? -1);
                $nazev = sanitize_text_field($_POST['oddil_nazev'] ?? '');
                if ($idx >= 0 && isset($oddily[$idx]) && $nazev) {
                    $oddily[$idx] = $nazev;
                    sort($oddily);
                    update_option('rs_oddily', $oddily);
                    $zprava_oddil = rs_alert('Součást uložena.');
                }
            } elseif ($action === 'smazat_oddil') {
                $idx = (int)($_POST['oddil_idx'] ?? -1);
                if ($idx >= 0 && isset($oddily[$idx])) {
                    unset($oddily[$idx]);
                    update_option('rs_oddily', array_values($oddily));
                    $zprava_oddil = rs_alert('Součást smazána.');
                }
            }
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_nast_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rs_nastaveni')) return rs_alert('Neplatný token.','error');

        update_option('rs_vzdusne_aktivni', isset($_POST['vzdusne_aktivni']) ? '1' : '0');
        update_option('rs_doplnujici_info_ext', wp_kses_post($_POST['doplnujici_info_ext'] ?? ''));
        update_option('rs_doplnujici_info_int', wp_kses_post($_POST['doplnujici_info_int'] ?? ''));
        update_option('rs_stredisko_kontakt_jmeno', sanitize_text_field($_POST['stredisko_kontakt_jmeno'] ?? ''));
        update_option('rs_stredisko_kontakt_mobil', sanitize_text_field($_POST['stredisko_kontakt_mobil'] ?? ''));
        update_option('rs_stredisko_kontakt_email', sanitize_email($_POST['stredisko_kontakt_email'] ?? ''));
        // Vzdušné kategorie
        $kategorie = [];
        $kat_od  = (array)($_POST['kat_od']  ?? []);
        $kat_do  = (array)($_POST['kat_do']  ?? []);
        $kat_vyse = (array)($_POST['kat_vyse'] ?? []);
        foreach ($kat_od as $i => $od) {
            if ($od === '' && ($kat_do[$i] ?? '') === '') continue;
            $kategorie[] = ['od' => (int)$od, 'do' => (int)($kat_do[$i] ?? 0), 'vyse' => (float)str_replace(',','.',$kat_vyse[$i] ?? 0)];
        }
        update_option('rs_vzdusne_kategorie', $kategorie);
        update_option('rs_vzdusne_neplatici', isset($_POST['vzdusne_neplatici']) ? '1' : '0');
        update_option('rs_vzdusne_info', sanitize_textarea_field($_POST['vzdusne_info'] ?? ''));
        $zprava = rs_alert('Nastavení uloženo.');
    }

    $vzdusne_on  = get_option('rs_vzdusne_aktivni', '0') === '1';
    $kat         = get_option('rs_vzdusne_kategorie', []);
    $neplatici   = get_option('rs_vzdusne_neplatici', '0') === '1';
    $info        = get_option('rs_vzdusne_info', '');
    $dop_info_ext = get_option('rs_doplnujici_info_ext', get_option('rs_doplnujici_info', ''));
    $dop_info_int = get_option('rs_doplnujici_info_int', '');
    $kont_jmeno  = get_option('rs_stredisko_kontakt_jmeno', '');
    $kont_mobil  = get_option('rs_stredisko_kontakt_mobil', '');
    $kont_email  = get_option('rs_stredisko_kontakt_email', '');

    ob_start();
    echo "<h3 class='rs-section-title'>Nastavení</h3>{$zprava}";
    echo "<form method='post'>" . wp_nonce_field('rs_nastaveni','_wpnonce',true,false);
    echo "<input type='hidden' name='rs_nast_action' value='ulozit'>";

    // Doplňující informace
    echo "<div class='rs-card'><h4 class='rs-card-title'>Doplňující informace</h4>";
    echo "<div class='rs-form-group'><label>Pro externí rezervace <span style='font-weight:normal;font-size:12px;color:#666'>(zobrazí se na frontendu pod formulářem a v kalendáři)</span></label><textarea name='doplnujici_info_ext' rows='5' style='max-width:100%'>" . esc_textarea($dop_info_ext) . "</textarea></div>";
    echo "<div class='rs-form-group'><label>Pro interní rezervace <span style='font-weight:normal;font-size:12px;color:#666'>(zobrazí se správcům ve formuláři Interní rezervace)</span></label><textarea name='doplnujici_info_int' rows='5' style='max-width:100%'>" . esc_textarea($dop_info_int) . "</textarea></div>";
    echo "</div>";

    // Kontaktní osoba střediska
    echo "<div class='rs-card'><h4 class='rs-card-title'>Kontaktní osoba střediska</h4>";
    echo "<p style='font-size:13px;color:#555;margin-bottom:12px'>Údaje se zobrazí v podpisu e-mailů odesílaných žadatelům (potvrzení, zrušení, …). E-mail bude nastaven jako Reply-To.</p>";
    echo "<div style='display:flex;flex-wrap:wrap;gap:8px 20px'>";
    echo "<div class='rs-form-group' style='flex:1;min-width:180px'><label>Jméno a příjmení</label><input type='text' name='stredisko_kontakt_jmeno' value='" . esc_attr($kont_jmeno) . "' placeholder='Jan Novák'></div>";
    echo "<div class='rs-form-group' style='flex:1;min-width:160px'><label>Mobil</label><input type='tel' name='stredisko_kontakt_mobil' value='" . esc_attr($kont_mobil) . "' placeholder='+420 731 123 456'></div>";
    echo "<div class='rs-form-group' style='flex:1;min-width:200px'><label>E-mail</label><input type='email' name='stredisko_kontakt_email' value='" . esc_attr($kont_email) . "' placeholder='kontakt@skautchlumec.cz'></div>";
    echo "</div></div>";

    // Vzdušné
    echo "<div class='rs-card'><h4 class='rs-card-title'>Ubytovací poplatek – vzdušné</h4>";
    $vch = $vzdusne_on ? 'checked' : '';
    echo "<div class='rs-form-group'><label><input type='checkbox' name='vzdusne_aktivni' {$vch}> Zapnout výběr vzdušného</label></div>";
    $vsty = $vzdusne_on ? '' : "style='display:none'";
    echo "<div id='rs-vzdusne-detail' {$vsty}>";
    echo "<p style='font-size:13px;color:#555'>Nastavte věkové kategorie a výši poplatku (Kč/noc/osoba). Zadejte věkové rozmezí od–do. Pro kategorii dospělých zadejte velké číslo jako horní hranici (např. 99).</p>";
    echo "<div id='rs-kat-wrap'>";
    foreach ($kat as $i => $k) {
        echo rs_vzdusne_kat_row($i, $k['od'], $k['do'], $k['vyse']);
    }
    if (empty($kat)) echo rs_vzdusne_kat_row(0, '', '', '');
    echo "</div>";
    echo "<button type='button' class='rs-btn rs-btn-secondary rs-btn-sm' onclick='rsAddKat()' style='margin-bottom:12px'>➕ Přidat kategorii</button>";
    $nch = $neplatici ? 'checked' : '';
    echo "<div class='rs-form-group'><label><input type='checkbox' name='vzdusne_neplatici' {$nch}> Existuje kategorie neplatících (např. dospělý doprovod)</label></div>";
    echo "<div class='rs-form-group'><label>Informace o vzdušném ve vašem městě</label><textarea name='vzdusne_info' rows='4' style='max-width:100%'>" . esc_textarea($info) . "</textarea></div>";
    echo "</div>"; // rs-vzdusne-detail
    echo "</div>"; // card

    echo "<div class='rs-btn-row' style='margin-bottom:32px'><button type='submit' class='rs-btn rs-btn-primary'>💾 Uložit nastavení</button></div>";
    echo "</form>";

    // Součásti střediska
    $oddily = get_option('rs_oddily', []);
    echo "<div class='rs-card'><h4 class='rs-card-title'>Součásti střediska</h4>{$zprava_oddil}";
    echo "<form id='rs-oddil-form' method='post' class='rs-form-row'>" . wp_nonce_field('rs_oddily','_wpnonce_oddil',true,false);
    echo "<input type='hidden' id='rs-oddil-action' name='rs_oddil_action' value='pridat_oddil'>";
    echo "<input type='hidden' id='rs-oddil-idx' name='oddil_idx' value=''>";
    echo "<div class='rs-form-group'><label>Název</label><input type='text' name='oddil_nazev' required placeholder='např. Vlci, Bobříci…'></div>";
    echo "<div class='rs-form-group' style='align-self:flex-end'>";
    echo "<button id='rs-oddil-submit' type='submit' class='rs-btn rs-btn-primary'>➕ Přidat</button> ";
    echo "<button id='rs-oddil-cancel' type='button' class='rs-btn rs-btn-secondary' style='display:none' onclick='rsOddiluReset()'>Zrušit</button>";
    echo "</div></form>";
    if ($oddily) {
        echo "<table class='rs-table'><thead><tr><th>Název</th><th></th></tr></thead><tbody>";
        foreach ($oddily as $i => $o) {
            $js_o = esc_js($o);
            echo "<tr><td>" . esc_html($o) . "</td><td style='white-space:nowrap'>";
            echo "<button type='button' class='rs-btn rs-btn-sm rs-btn-secondary' onclick='rsOddiluEdit({$i},\"{$js_o}\")'>✏️</button> ";
            echo "<form method='post' style='display:inline'>" . wp_nonce_field('rs_oddily','_wpnonce_oddil',true,false);
            echo "<input type='hidden' name='rs_oddil_action' value='smazat_oddil'><input type='hidden' name='oddil_idx' value='{$i}'>";
            echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger' onclick='return confirm(\"Smazat součást střediska?\")'>🗑️</button></form>";
            echo "</td></tr>";
        }
        echo "</tbody></table>";
    }
    echo "</div>";
    ?>
    <script>
    document.querySelector('[name=vzdusne_aktivni]').addEventListener('change', function(){
        document.getElementById('rs-vzdusne-detail').style.display = this.checked ? '' : 'none';
    });
    var rsKatIdx = <?php echo max(count($kat), 1); ?>;
    function rsAddKat(){
        var wrap = document.getElementById('rs-kat-wrap');
        var row = document.createElement('div');
        row.innerHTML = <?php echo json_encode(rs_vzdusne_kat_row('__IDX__',  '', '', '')); ?>.replace(/__IDX__/g, rsKatIdx++);
        wrap.appendChild(row);
    }
    function rsRemoveKat(btn){ btn.closest('.rs-form-row').remove(); }
    function rsOddiluEdit(idx, nazev) {
        document.querySelector('[name=oddil_nazev]').value = nazev;
        document.getElementById('rs-oddil-action').value = 'upravit_oddil';
        document.getElementById('rs-oddil-idx').value = idx;
        document.getElementById('rs-oddil-submit').textContent = '💾 Uložit';
        document.getElementById('rs-oddil-cancel').style.display = '';
        document.querySelector('[name=oddil_nazev]').focus();
    }
    function rsOddiluReset() {
        document.getElementById('rs-oddil-form').reset();
        document.getElementById('rs-oddil-action').value = 'pridat_oddil';
        document.getElementById('rs-oddil-idx').value = '';
        document.getElementById('rs-oddil-submit').textContent = '➕ Přidat';
        document.getElementById('rs-oddil-cancel').style.display = 'none';
    }
    </script>
    <?php
    return ob_get_clean();
}

function rs_vzdusne_kat_row($idx, $od, $do_, $vyse): string {
    return "<div class='rs-form-row' style='margin-bottom:8px'>"
        . "<div class='rs-form-group'><label>Věk od</label><input type='number' name='kat_od[]' value='" . esc_attr($od) . "' min='0' max='99' style='width:80px'></div>"
        . "<div class='rs-form-group'><label>Věk do</label><input type='number' name='kat_do[]' value='" . esc_attr($do_) . "' min='0' max='99' style='width:80px'></div>"
        . "<div class='rs-form-group'><label>Výše (Kč/noc)</label><input type='number' name='kat_vyse[]' value='" . esc_attr($vyse) . "' min='0' step='0.01' style='width:120px'></div>"
        . "<div class='rs-form-group' style='align-self:flex-end'><button type='button' class='rs-btn rs-btn-sm rs-btn-danger' onclick='rsRemoveKat(this)'>✕</button></div>"
        . "</div>";
}

// ═══ SEKCE: SPRÁVA REZERVACÍ ═════════════════════════════════════════════════

function rs_sekce_rezervace(): string {
    $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_rez_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rs_rez_spravce')) return rs_alert('Neplatný token.','error');
        $action = sanitize_key($_POST['rs_rez_action']);
        $rid    = (int)($_POST['rez_id'] ?? 0);

        if ($action === 'potvrdit' && $rid) {
            update_post_meta($rid, 'rs_stav', 'potvrzena');
            // Spočítat a uložit cenu
            $pid   = (int)get_post_meta($rid,'rs_prostor_id',true);
            $segs  = (array)get_post_meta($rid,'rs_segmenty_ids',true);
            $pocet = (int)get_post_meta($rid,'rs_pocet_lidi',true);
            $od    = get_post_meta($rid,'rs_datum_od',true);
            $do_   = get_post_meta($rid,'rs_datum_do',true);
            $ind   = (float)get_post_meta($rid,'rs_cena_individualni',true);
            $cena  = $ind > 0 ? $ind : rs_vypocti_cenu($pid,$segs,$pocet,$od,$do_);
            update_post_meta($rid,'rs_cena_celkem',$cena);
            rs_notifikuj_potvrzeni($rid);
            $zprava = rs_alert('Rezervace potvrzena a žadatel informován.');
        } elseif ($action === 'zrusit' && $rid) {
            update_post_meta($rid, 'rs_stav', 'zrusena');
            rs_notifikuj_zruseni($rid);
            $zprava = rs_alert('Rezervace zrušena.');
        } elseif ($action === 'zrusit_skupinu_admin') {
            $sk_id = sanitize_text_field($_POST['skupina_id'] ?? '');
            if ($sk_id) {
                $sk_all = get_posts(['post_type'=>'rs_rezervace','numberposts'=>-1,'fields'=>'ids',
                    'meta_query'=>[['key'=>'rs_skupina_id','value'=>$sk_id]]]);
                $n = 0;
                foreach ($sk_all as $sid) {
                    if (get_post_meta($sid,'rs_stav',true) !== 'zrusena') {
                        update_post_meta($sid,'rs_stav','zrusena'); $n++;
                    }
                }
                $zprava = rs_alert("Série zrušena ({$n} termínů).");
            }
        } elseif ($action === 'ulozit_ind_cenu' && $rid) {
            $ind = (float)str_replace(',','.',($_POST['ind_cena'] ?? 0));
            update_post_meta($rid,'rs_cena_individualni',$ind);
            update_post_meta($rid,'rs_cena_celkem',$ind);
            $zprava = rs_alert('Individuální cena uložena.');
        } elseif ($action === 'odeslat_ucastnici' && $rid) {
            $ucastnici = rs_uloz_ucastniky($rid);
            if ($ucastnici !== false) {
                rs_notifikuj_ucastnici($rid, $ucastnici);
                $zprava = rs_alert('Seznam účastníků uložen a odeslán správcům.');
            } else {
                $zprava = rs_alert('Chyba při ukládání účastníků.','error');
            }
        }
    }

    $stav_filter = sanitize_key($_GET['rs_filter_stav'] ?? 'vse');
    $typ_filter  = ($_GET['rs_filter_typ'] ?? 'externi') === 'interni' ? 'interni' : 'externi';
    $detail_id   = (int)($_GET['rs_rez_detail'] ?? 0);

    ob_start();
    echo "<h3 class='rs-section-title'>Správa rezervací</h3>{$zprava}";

    if ($detail_id) {
        echo rs_rez_detail_admin($detail_id);
        echo "<a href='" . esc_url(remove_query_arg('rs_rez_detail')) . "' class='rs-btn rs-btn-secondary' style='margin-top:12px'>← Zpět na seznam</a>";
        return ob_get_clean();
    }

    // Typ filter (top level)
    echo "<div style='display:flex;gap:6px;margin-bottom:10px'>";
    foreach (['externi'=>'🌐 Externí', 'interni'=>'👥 Interní'] as $k=>$l) {
        $cls = $typ_filter === $k ? 'rs-btn-primary' : 'rs-btn-secondary';
        echo "<a href='" . esc_url(add_query_arg(['rs_filter_typ'=>$k,'rs_filter_stav'=>$stav_filter])) . "' class='rs-btn {$cls}'>{$l}</a>";
    }
    echo "</div>";

    // Stav filter
    echo "<div style='display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap'>";
    foreach (['vse'=>'Vše','cekajici'=>'Čekající','potvrzena'=>'Potvrzené','zrusena'=>'Zrušené'] as $k=>$l) {
        $cls = $stav_filter === $k ? 'rs-btn-primary' : 'rs-btn-secondary';
        echo "<a href='" . esc_url(add_query_arg(['rs_filter_stav'=>$k,'rs_filter_typ'=>$typ_filter])) . "' class='rs-btn rs-btn-sm {$cls}'>{$l}</a>";
    }
    echo "</div>";

    if ($typ_filter === 'externi') {
        // ── EXTERNÍ: jednoduchá tabulka ──────────────────────────────────────
        $args = ['post_type'=>'rs_rezervace','post_status'=>'publish','numberposts'=>-1,'orderby'=>'meta_value','meta_key'=>'rs_datum_od','order'=>'DESC',
            'meta_query'=>[['key'=>'rs_typ_rezervace','value'=>'externi']]];
        if ($stav_filter !== 'vse') $args['meta_query'][] = ['key'=>'rs_stav','value'=>$stav_filter];
        $rezervace = get_posts($args);

        if (empty($rezervace)) { echo rs_alert('Žádné rezervace.','info'); return ob_get_clean(); }

        echo "<table class='rs-table'><thead><tr><th>Žadatel</th><th>Objekt</th><th>Od</th><th>Do</th><th>Osob</th><th>Stav</th><th>Akce</th></tr></thead><tbody>";
        foreach ($rezervace as $r) {
            $prostor = get_the_title((int)get_post_meta($r->ID,'rs_prostor_id',true));
            $stav    = get_post_meta($r->ID,'rs_stav',true);
            echo "<tr>";
            echo "<td>" . esc_html(rs_rez_jmeno($r->ID)) . "</td>";
            echo "<td>" . esc_html($prostor) . "</td>";
            echo "<td>" . esc_html(get_post_meta($r->ID,'rs_datum_od',true)) . "</td>";
            echo "<td>" . esc_html(get_post_meta($r->ID,'rs_datum_do',true)) . "</td>";
            echo "<td>" . (int)get_post_meta($r->ID,'rs_pocet_lidi',true) . "</td>";
            echo "<td>" . rs_stav_badge($stav) . "</td>";
            echo "<td>";
            echo "<a href='" . esc_url(add_query_arg('rs_rez_detail',$r->ID)) . "' class='rs-btn rs-btn-sm rs-btn-secondary'>Detail</a> ";
            if ($stav === 'cekajici') {
                echo "<form method='post' style='display:inline'>" . wp_nonce_field('rs_rez_spravce','_wpnonce',true,false);
                echo "<input type='hidden' name='rs_rez_action' value='potvrdit'><input type='hidden' name='rez_id' value='{$r->ID}'>";
                echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-success'>✓ Potvrdit</button></form> ";
            }
            if ($stav !== 'zrusena') {
                echo "<form method='post' style='display:inline' onsubmit='return confirm(\"Zrušit rezervaci?\")'>" . wp_nonce_field('rs_rez_spravce','_wpnonce',true,false);
                echo "<input type='hidden' name='rs_rez_action' value='zrusit'><input type='hidden' name='rez_id' value='{$r->ID}'>";
                echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>✕ Zrušit</button></form>";
            }
            echo "</td></tr>";
        }
        echo "</tbody></table>";

    } else {
        // ── INTERNÍ: skupinové zobrazení ─────────────────────────────────────
        $all_rez = get_posts(['post_type'=>'rs_rezervace','post_status'=>'publish','numberposts'=>-1,
            'orderby'=>'meta_value','meta_key'=>'rs_datum_od','order'=>'ASC',
            'meta_query'=>[['key'=>'rs_typ_rezervace','value'=>'interni']]]);

        // Rozdělit na série (mají skupina_id) a jednorázové
        $groups  = [];
        $singles = [];
        foreach ($all_rez as $r) {
            $sk = get_post_meta($r->ID,'rs_skupina_id',true);
            if ($sk) $groups[$sk][] = $r;
            else     $singles[]     = $r;
        }

        // Aplikovat stav_filter: skupiny zobrazit jen pokud mají aspoň 1 odpovídající
        if ($stav_filter !== 'vse') {
            $groups  = array_filter($groups,  fn($g) => (bool)array_filter($g, fn($r) => get_post_meta($r->ID,'rs_stav',true) === $stav_filter));
            $singles = array_values(array_filter($singles, fn($r) => get_post_meta($r->ID,'rs_stav',true) === $stav_filter));
        }

        if (empty($groups) && empty($singles)) { echo rs_alert('Žádné rezervace.','info'); return ob_get_clean(); }

        echo "<table class='rs-table'><thead><tr><th>Název</th><th>Objekt</th><th>Termín</th><th>Rezervující</th><th>Součást střediska</th><th>Stav</th><th>Akce</th></tr></thead><tbody>";

        $g_idx = 0;
        foreach ($groups as $sk_id => $g_rez) {
            $first   = $g_rez[0];
            $last    = end($g_rez);
            $nazev   = get_post_meta($first->ID,'rs_nazev',true) ?: '–';
            $prostor = get_the_title((int)get_post_meta($first->ID,'rs_prostor_id',true));
            $oddil   = get_post_meta($first->ID,'rs_oddil',true);
            $uid     = (int)(get_post_meta($first->ID,'rs_int_rezervujici_id',true) ?: get_post_meta($first->ID,'rs_wp_user_id',true));
            $user    = get_userdata($uid);
            $od_str  = rs_format_datum(get_post_meta($first->ID,'rs_datum_od',true));
            $do_str  = rs_format_datum(get_post_meta($last->ID,'rs_datum_do',true));
            $count   = count($g_rez);
            $stavs   = array_map(fn($r) => get_post_meta($r->ID,'rs_stav',true), $g_rez);
            $n_pot   = count(array_filter($stavs, fn($s) => $s === 'potvrzena'));
            $n_cek   = count(array_filter($stavs, fn($s) => $s === 'cekajici'));
            $n_zru   = count(array_filter($stavs, fn($s) => $s === 'zrusena'));
            $has_act = ($n_pot + $n_cek) > 0;
            $gid     = 'rsgrp' . $g_idx++;

            echo "<tr style='background:#eef3ee;cursor:pointer' onclick='rsGrpToggle(\"" . esc_js($gid) . "\")'>";
            echo "<td><span id='{$gid}-ico' style='font-size:10px;margin-right:5px'>▶</span><strong>" . esc_html($nazev) . "</strong> <span style='color:#777;font-size:12px'>({$count}×)</span></td>";
            echo "<td>" . esc_html($prostor) . "</td>";
            echo "<td style='font-size:12px;white-space:nowrap'>" . esc_html($od_str) . "<br>– " . esc_html($do_str) . "</td>";
            echo "<td>" . esc_html($user ? $user->display_name : '–') . "</td>";
            echo "<td>" . esc_html($oddil ?: '–') . "</td>";
            echo "<td style='white-space:nowrap'>";
            if ($n_pot) echo "<span style='display:inline-block;padding:1px 5px;border-radius:3px;font-size:11px;background:#1a5c2a;color:#fff;margin-right:2px'>{$n_pot}✓</span>";
            if ($n_cek) echo "<span style='display:inline-block;padding:1px 5px;border-radius:3px;font-size:11px;background:#f59e0b;color:#fff;margin-right:2px'>{$n_cek}⏳</span>";
            if ($n_zru) echo "<span style='display:inline-block;padding:1px 5px;border-radius:3px;font-size:11px;background:#aaa;color:#fff'>{$n_zru}✕</span>";
            echo "</td><td>";
            if ($has_act) {
                echo "<form method='post' style='display:inline' onclick='event.stopPropagation()' onsubmit='return confirm(\"Zrušit celou sérii ({$count} termínů)?\")'>" . wp_nonce_field('rs_rez_spravce','_wpnonce',true,false);
                echo "<input type='hidden' name='rs_rez_action' value='zrusit_skupinu_admin'><input type='hidden' name='skupina_id' value='" . esc_attr($sk_id) . "'>";
                echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>✕ Série</button></form>";
            }
            echo "</td></tr>";

            foreach ($g_rez as $r) {
                $r_stav  = get_post_meta($r->ID,'rs_stav',true);
                $r_od    = get_post_meta($r->ID,'rs_datum_od',true);
                $opacity = $r_stav === 'zrusena' ? 'opacity:.5;' : '';
                echo "<tr class='{$gid}' style='display:none;background:#f8faf8;{$opacity}'>";
                echo "<td style='padding-left:26px;font-size:13px;color:#666'>↳ " . esc_html(substr($r_od,0,10)) . "</td>";
                echo "<td></td>";
                echo "<td style='font-size:12px'>" . esc_html($r_od) . "<br>" . esc_html(get_post_meta($r->ID,'rs_datum_do',true)) . "</td>";
                echo "<td></td><td></td>";
                echo "<td>" . rs_stav_badge($r_stav) . "</td>";
                echo "<td>";
                echo "<a href='" . esc_url(add_query_arg('rs_rez_detail',$r->ID)) . "' class='rs-btn rs-btn-sm rs-btn-secondary' style='font-size:11px'>Detail</a> ";
                if ($r_stav !== 'zrusena') {
                    echo "<form method='post' style='display:inline' onsubmit='return confirm(\"Zrušit tento termín?\")'>" . wp_nonce_field('rs_rez_spravce','_wpnonce',true,false);
                    echo "<input type='hidden' name='rs_rez_action' value='zrusit'><input type='hidden' name='rez_id' value='{$r->ID}'>";
                    echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger' style='font-size:11px'>✕</button></form>";
                }
                echo "</td></tr>";
            }
        }

        foreach ($singles as $r) {
            $nazev   = get_post_meta($r->ID,'rs_nazev',true) ?: '–';
            $prostor = get_the_title((int)get_post_meta($r->ID,'rs_prostor_id',true));
            $stav    = get_post_meta($r->ID,'rs_stav',true);
            $oddil   = get_post_meta($r->ID,'rs_oddil',true);
            $uid     = (int)(get_post_meta($r->ID,'rs_int_rezervujici_id',true) ?: get_post_meta($r->ID,'rs_wp_user_id',true));
            $user    = get_userdata($uid);
            echo "<tr>";
            echo "<td>" . esc_html($nazev) . "</td>";
            echo "<td>" . esc_html($prostor) . "</td>";
            echo "<td style='font-size:12px'>" . esc_html(get_post_meta($r->ID,'rs_datum_od',true)) . "</td>";
            echo "<td>" . esc_html($user ? $user->display_name : '–') . "</td>";
            echo "<td>" . esc_html($oddil ?: '–') . "</td>";
            echo "<td>" . rs_stav_badge($stav) . "</td>";
            echo "<td>";
            echo "<a href='" . esc_url(add_query_arg('rs_rez_detail',$r->ID)) . "' class='rs-btn rs-btn-sm rs-btn-secondary'>Detail</a> ";
            if ($stav !== 'zrusena') {
                echo "<form method='post' style='display:inline' onsubmit='return confirm(\"Zrušit rezervaci?\")'>" . wp_nonce_field('rs_rez_spravce','_wpnonce',true,false);
                echo "<input type='hidden' name='rs_rez_action' value='zrusit'><input type='hidden' name='rez_id' value='{$r->ID}'>";
                echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>✕ Zrušit</button></form>";
            }
            echo "</td></tr>";
        }

        echo "</tbody></table>";
        ?>
        <script>
        function rsGrpToggle(gid){
            var rows=document.querySelectorAll('tr.'+gid);
            var ico=document.getElementById(gid+'-ico');
            var open=rows.length&&rows[0].style.display!=='none';
            rows.forEach(function(r){r.style.display=open?'none':'';});
            if(ico)ico.textContent=open?'▶':'▼';
        }
        </script>
        <?php
    }

    return ob_get_clean();
}

function rs_rez_detail_admin(int $id): string {
    $pid    = (int)get_post_meta($id,'rs_prostor_id',true);
    $segs   = (array)get_post_meta($id,'rs_segmenty_ids',true);
    $stav   = get_post_meta($id,'rs_stav',true);
    $typ    = get_post_meta($id,'rs_typ_rezervace',true);
    $od     = get_post_meta($id,'rs_datum_od',true);
    $do_    = get_post_meta($id,'rs_datum_do',true);
    $pocet  = (int)get_post_meta($id,'rs_pocet_lidi',true);
    $cena   = (float)get_post_meta($id,'rs_cena_celkem',true);
    $ind    = (float)get_post_meta($id,'rs_cena_individualni',true);
    $token  = get_post_meta($id,'rs_token',true);
    $ucast  = get_post_meta($id,'rs_ucastnici',true);

    ob_start();
    echo "<div class='rs-card'><h4 class='rs-card-title'>Detail rezervace #" . $id . " – " . rs_stav_badge($stav) . "</h4>";

    // Základní info
    echo "<table class='rs-table' style='max-width:600px'>";
    echo "<tr><th style='width:180px'>Objekt</th><td>" . esc_html(get_the_title($pid));
    if ($segs) {
        $seg_names = array_map(fn($s) => get_the_title($s), $segs);
        echo " <em style='color:#777'>(" . esc_html(implode(', ',$seg_names)) . ")</em>";
    }
    echo "</td></tr>";
    echo "<tr><th>Termín</th><td>" . esc_html($od) . " – " . esc_html($do_) . "</td></tr>";
    echo "<tr><th>Počet osob</th><td>" . $pocet . "</td></tr>";
    echo "<tr><th>Typ</th><td>" . ($typ === 'interni' ? 'Interní' : 'Externí') . "</td></tr>";
    echo "<tr><th>Stav</th><td>" . rs_stav_badge($stav) . "</td></tr>";

    // Kontaktní info
    $rez_typ = get_post_meta($id,'rs_rez_typ',true);
    if ($rez_typ === 'pravnicka') {
        echo "<tr><th>Organizace</th><td>" . esc_html(get_post_meta($id,'rs_nazev',true)) . "</td></tr>";
        echo "<tr><th>IČO</th><td>" . esc_html(get_post_meta($id,'rs_ico',true)) . "</td></tr>";
        echo "<tr><th>Sídlo</th><td>" . esc_html(get_post_meta($id,'rs_sidlo',true)) . "</td></tr>";
        echo "<tr><th>Kontakt</th><td>" . esc_html(get_post_meta($id,'rs_kontakt_jmeno',true)) . "</td></tr>";
        $a_tel_p = get_post_meta($id,'rs_tel_predvolba',true); $a_tel_m = get_post_meta($id,'rs_mobil',true);
        echo "<tr><th>Mobil</th><td>" . esc_html($a_tel_m ? ($a_tel_p ? "+$a_tel_p $a_tel_m" : $a_tel_m) : '') . "</td></tr>";
        echo "<tr><th>E-mail</th><td>" . esc_html(get_post_meta($id,'rs_email',true)) . "</td></tr>";
    } else {
        echo "<tr><th>Jméno</th><td>" . esc_html(get_post_meta($id,'rs_jmeno',true)) . " " . esc_html(get_post_meta($id,'rs_prijmeni',true)) . "</td></tr>";
        echo "<tr><th>Datum nar.</th><td>" . esc_html(get_post_meta($id,'rs_datum_narozeni',true)) . "</td></tr>";
        $a_ulice = get_post_meta($id,'rs_ulice',true);
        $a_adr   = $a_ulice ? trim($a_ulice.' '.get_post_meta($id,'rs_cp',true).', '.get_post_meta($id,'rs_psc',true).' '.get_post_meta($id,'rs_obec',true)) : get_post_meta($id,'rs_bydliste',true);
        $a_tel_p = get_post_meta($id,'rs_tel_predvolba',true); $a_tel_m = get_post_meta($id,'rs_mobil',true);
        echo "<tr><th>Bydliště</th><td>" . esc_html($a_adr) . "</td></tr>";
        echo "<tr><th>Mobil</th><td>" . esc_html($a_tel_m ? ($a_tel_p ? "+$a_tel_p $a_tel_m" : $a_tel_m) : '') . "</td></tr>";
        echo "<tr><th>E-mail</th><td>" . esc_html(get_post_meta($id,'rs_email',true)) . "</td></tr>";
    }
    echo "<tr><th>Cena</th><td>" . ($cena > 0 ? number_format($cena,0,'.',' ') . ' Kč' : 'zatím neurčena') . "</td></tr>";
    if ($token) echo "<tr><th>Token</th><td><code>" . esc_html($token) . "</code></td></tr>";
    echo "</table>";

    // Individuální cena
    if ($stav !== 'zrusena') {
        echo "<div style='margin-top:16px'><h5>Nastavit individuální cenu (přepíše automatický výpočet):</h5>";
        echo "<form method='post' class='rs-form-row'>" . wp_nonce_field('rs_rez_spravce','_wpnonce',true,false);
        echo "<input type='hidden' name='rs_rez_action' value='ulozit_ind_cenu'><input type='hidden' name='rez_id' value='{$id}'>";
        echo "<input type='number' name='ind_cena' value='" . esc_attr($ind ?: '') . "' min='0' step='1' style='width:150px' placeholder='Kč'>";
        echo "<button type='submit' class='rs-btn rs-btn-primary rs-btn-sm'>Uložit cenu</button></form></div>";
    }

    // Účastníci (vzdušné)
    if (get_option('rs_vzdusne_aktivni') === '1' && $stav === 'potvrzena') {
        echo "<div style='margin-top:20px'><h5>Seznam ubytovaných (vzdušné)</h5>";
        if (!empty($ucast) && is_array($ucast)) {
            echo "<table class='rs-table'><thead><tr><th>Jméno</th><th>Příjmení</th><th>Dat. nar.</th><th>Adresa</th><th>Neplatí vzdušné</th></tr></thead><tbody>";
            foreach ($ucast as $u) {
                echo "<tr><td>" . esc_html($u['jmeno'] ?? '') . "</td><td>" . esc_html($u['prijmeni'] ?? '') . "</td><td>" . esc_html($u['datum_narozeni'] ?? '') . "</td><td>" . esc_html($u['adresa'] ?? '') . "</td><td>" . (($u['neplati'] ?? false) ? '✓' : '') . "</td></tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<p style='color:#777;font-size:13px'>Účastníci zatím nebyli vyplněni.</p>";
        }
        echo "</div>";
    }

    echo "</div>"; // card
    return ob_get_clean();
}

function rs_notifikuj_ucastnici(int $id, array $ucastnici) {
    $prostor = get_the_title((int)get_post_meta($id,'rs_prostor_id',true));
    $od = get_post_meta($id,'rs_datum_od',true);
    $do_ = get_post_meta($id,'rs_datum_do',true);

    $body = "Seznam ubytovaných pro rezervaci objektu {$prostor} ({$od} – {$do_}):\n\n";
    $body .= str_pad('Jméno',20) . str_pad('Příjmení',20) . str_pad('Dat. nar.',14) . "Adresa\n";
    $body .= str_repeat('-',80) . "\n";
    foreach ($ucastnici as $u) {
        $neplati = ($u['neplati'] ?? false) ? ' [neplatí]' : '';
        $body .= str_pad($u['jmeno'] ?? '',20) . str_pad($u['prijmeni'] ?? '',20) . str_pad($u['datum_narozeni'] ?? '',14) . ($u['adresa'] ?? '') . $neplati . "\n";
    }

    foreach (rs_spravci_emaily() as $email) {
        rs_mail($email, "Seznam ubytovaných – {$prostor}", $body);
    }
}

function rs_uloz_ucastniky(int $id) {
    $jmena  = (array)($_POST['ucast_jmeno'] ?? []);
    $prijm  = (array)($_POST['ucast_prijmeni'] ?? []);
    $dnar   = (array)($_POST['ucast_datum_nar'] ?? []);
    $adr    = (array)($_POST['ucast_adresa'] ?? []);
    $neplati = (array)($_POST['ucast_neplati'] ?? []);
    $result = [];
    foreach ($jmena as $i => $j) {
        if (!$j) continue;
        $result[] = [
            'jmeno'         => sanitize_text_field($j),
            'prijmeni'      => sanitize_text_field($prijm[$i] ?? ''),
            'datum_narozeni'=> sanitize_text_field($dnar[$i] ?? ''),
            'adresa'        => sanitize_text_field($adr[$i] ?? ''),
            'neplati'       => !empty($neplati[$i]),
        ];
    }
    update_post_meta($id, 'rs_ucastnici', $result);
    return $result;
}

// ═══ SEKCE: INTERNÍ REZERVACE ════════════════════════════════════════════════

function rs_sekce_interni(): string {
    $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_int_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rs_interni')) return rs_alert('Neplatný token.','error');
        $action = sanitize_key($_POST['rs_int_action']);
        $zprava = rs_interni_zpracuj($action);
    }

    $user_id    = get_current_user_id();
    $is_spravce = rs_ma_pravo('spravce');

    // Načíst rezervace pro zobrazení
    $filter_args = ['post_type'=>'rs_rezervace','post_status'=>'publish','numberposts'=>-1,'orderby'=>'meta_value','meta_key'=>'rs_datum_od','order'=>'ASC',
        'meta_query'=>[['key'=>'rs_typ_rezervace','value'=>'interni']]];
    if (!$is_spravce) {
        $filter_args['meta_query'][] = ['key'=>'rs_wp_user_id','value'=>$user_id];
    }
    $rezervace = get_posts($filter_args);
    $prostory = rs_get_prostory();

    ob_start();
    echo "<h3 class='rs-section-title'>Interní rezervace</h3>{$zprava}";
    $dop_int = get_option('rs_doplnujici_info_int','');
    if ($dop_int) echo "<div style='margin-bottom:16px;padding:14px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;font-size:13px'>" . wp_kses_post(nl2br($dop_int)) . "</div>";

    // Formulář nové rezervace
    $authors = get_users(['role__in' => ['author','administrator','admin_rezervacniho_systemu','spravce_rezervaci'], 'orderby' => 'display_name', 'order' => 'ASC']);
    $oddily  = get_option('rs_oddily', []);
    $dny     = ['1'=>'Pondělí','2'=>'Úterý','3'=>'Středa','4'=>'Čtvrtek','5'=>'Pátek','6'=>'Sobota','7'=>'Neděle'];

    echo "<div class='rs-card'><h4 class='rs-card-title'>➕ Nová interní rezervace</h4>";
    echo "<form method='post' id='rs-int-form'>" . wp_nonce_field('rs_interni','_wpnonce',true,false);
    echo "<input type='hidden' name='rs_int_action' value='vytvorit'>";

    // 1) Typ rezervace – hned nahoře
    echo "<div class='rs-form-group' style='margin-bottom:16px'>";
    echo "<label style='margin-right:20px'><input type='radio' name='int_mode' value='jednorazova' checked onchange='rsIntMode(\"jednorazova\")'> Jednorázová rezervace</label>";
    echo "<label><input type='radio' name='int_mode' value='opakujici' onchange='rsIntMode(\"opakujici\")'> Opakující se rezervace</label>";
    echo "</div>";

    // 2) Prostor + segmenty
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Objekt *</label><select name='int_prostor_id' id='rs-int-prostor' onchange='rsIntProstorChange(this.value)' required>";
    echo "<option value=''>– vyberte –</option>";
    foreach ($prostory as $p) echo "<option value='{$p->ID}'>" . esc_html($p->post_title) . "</option>";
    echo "</select></div>";
    echo "<div class='rs-form-group' id='rs-int-seg-wrap' style='display:none'><label>Části (nevyberte nic = celý objekt)</label><div id='rs-int-seg-list'></div></div>";
    echo "</div>";

    // 3) Název rezervace + Rezervující + Oddíl
    echo "<div class='rs-form-group'><label>Název rezervace *</label><input type='text' name='int_nazev' required placeholder='např. Schůzka, Besídka, Brigáda…'></div>";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Rezervující *</label><select name='int_rezervujici_id' required>";
    foreach ($authors as $u) {
        $sel = $u->ID === get_current_user_id() ? 'selected' : '';
        echo "<option value='{$u->ID}' {$sel}>" . esc_html($u->display_name) . "</option>";
    }
    echo "</select></div>";
    echo "<div class='rs-form-group'><label>Součást střediska</label><select name='int_oddil'>";
    echo "<option value=''>– nevybráno –</option>";
    foreach ($oddily as $o) echo "<option value='" . esc_attr($o) . "'>" . esc_html($o) . "</option>";
    echo "</select></div>";
    echo "</div>";

    // 4a) Panel: Jednorázová
    echo "<div id='rs-int-panel-jedno'>";
    echo "<div class='rs-form-group' style='margin-bottom:6px'><label style='display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400'><input type='checkbox' name='int_cely_den' onchange='rsCelyDen(this,\"int\")' style='width:auto'> Celý den</label></div>";
    echo "<div id='rs-int-cas-wrap'><div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Datum od *</label><input type='datetime-local' name='int_datum_od' step='900' onchange='var d=document.querySelector(\"[name=int_datum_do]\");if(d){d.min=this.value;if(!d.value||d.value<this.value)d.value=this.value;}'></div>";
    echo "<div class='rs-form-group'><label>Datum do *</label><input type='datetime-local' name='int_datum_do' step='900'></div>";
    echo "</div></div>";
    echo "<div id='rs-int-den-wrap' style='display:none'><div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Datum od *</label><input type='date' name='int_datum_od_den' onchange='var d=document.querySelector(\"[name=int_datum_do_den]\");if(d){d.min=this.value;if(!d.value||d.value<this.value)d.value=this.value;}'></div>";
    echo "<div class='rs-form-group'><label>Datum do *</label><input type='date' name='int_datum_do_den'></div>";
    echo "</div></div>";
    echo "</div>";

    // 4b) Panel: Opakující se (skrytý)
    echo "<div id='rs-int-panel-opak' style='display:none;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;padding:14px;margin-bottom:14px'>";
    echo "<p style='font-size:13px;color:#555;margin-top:0'>Opakující se rezervace se vytvoří jako jednotlivé záznamy na každý vybraný den.</p>";
    echo "<div class='rs-form-group'><label>Den v týdnu *</label><select name='int_den_tydne'>";
    foreach ($dny as $k => $v) echo "<option value='{$k}'>{$v}</option>";
    echo "</select></div>";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Čas od</label><input type='time' name='int_cas_od' step='900'></div>";
    echo "<div class='rs-form-group'><label>Čas do</label><input type='time' name='int_cas_do' step='900'></div>";
    echo "</div>";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Opakovat od *</label><input type='date' name='int_opakovani_od'></div>";
    echo "<div class='rs-form-group'><label>Opakovat do *</label><input type='date' name='int_opakovani_do'></div>";
    echo "</div>";
    echo "<div class='rs-form-group'><label>Výjimky – vynechat data (čárkami, RRRR-MM-DD)</label><input type='text' name='int_vyjimky' placeholder='2025-12-24, 2026-01-01'></div>";
    echo "<div class='rs-form-group'><label><input type='checkbox' name='int_vynechat_prazdniny' checked> Automaticky vynechat prázdniny</label></div>";
    echo "<div class='rs-form-group'><label><input type='checkbox' name='int_vynechat_svatky' checked> Automaticky vynechat státní svátky</label></div>";
    echo "</div>";

    // 5) Poznámka – vždy viditelná
    echo "<div class='rs-form-group'><label>Poznámka</label><textarea name='int_poznamka' rows='2'></textarea></div>";

    echo "<div class='rs-btn-row'><button type='submit' class='rs-btn rs-btn-primary'>Vytvořit rezervaci</button></div>";
    echo "</form></div>";

    // Přehled rezervací
    if ($rezervace) {
        echo "<div class='rs-card'><h4 class='rs-card-title'>Přehled interních rezervací</h4>";
        echo "<table class='rs-table'><thead><tr><th>Název</th><th>Objekt</th><th>Od</th><th>Do</th><th>Rezervující</th><th>Součást</th><th>Stav</th><th>Skupina</th><th>Akce</th></tr></thead><tbody>";

        foreach ($rezervace as $r) {
            $stav    = get_post_meta($r->ID,'rs_stav',true);
            $skupina = get_post_meta($r->ID,'rs_skupina_id',true);
            $uid     = (int)get_post_meta($r->ID,'rs_wp_user_id',true);
            $rez_uid = (int)get_post_meta($r->ID,'rs_int_rezervujici_id',true) ?: $uid;
            $rez_user = get_userdata($rez_uid);
            $oddil   = get_post_meta($r->ID,'rs_oddil',true);
            echo "<tr>";
            echo "<td><strong>" . esc_html(get_post_meta($r->ID,'rs_nazev',true) ?: '–') . "</strong></td>";
            echo "<td>" . esc_html(get_the_title((int)get_post_meta($r->ID,'rs_prostor_id',true)));
            $segs = (array)get_post_meta($r->ID,'rs_segmenty_ids',true);
            if ($segs) echo " <em style='font-size:12px;color:#777'>(" . implode(', ', array_map('get_the_title',$segs)) . ")</em>";
            echo "</td>";
            echo "<td>" . esc_html(get_post_meta($r->ID,'rs_datum_od',true)) . "</td>";
            echo "<td>" . esc_html(get_post_meta($r->ID,'rs_datum_do',true)) . "</td>";
            echo "<td>" . esc_html($rez_user ? $rez_user->display_name : '–') . "</td>";
            echo "<td>" . esc_html($oddil ?: '–') . "</td>";
            echo "<td>" . rs_stav_badge($stav) . "</td>";
            echo "<td>" . ($skupina ? "<span class='rs-badge rs-badge-info' style='font-size:11px' title='ID skupiny'>" . esc_html(substr($skupina,0,8)) . "…</span>" : '–') . "</td>";
            echo "<td>";
            if ($stav !== 'zrusena' && ($uid === get_current_user_id() || $is_spravce)) {
                echo "<form method='post' style='display:inline' onsubmit='return confirm(\"Zrušit tuto rezervaci?\")'>" . wp_nonce_field('rs_interni','_wpnonce',true,false);
                echo "<input type='hidden' name='rs_int_action' value='zrusit'><input type='hidden' name='int_rez_id' value='{$r->ID}'>";
                echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>✕</button></form>";
                if ($skupina) {
                    echo " <form method='post' style='display:inline' onsubmit='return confirm(\"Zrušit celou sérii?\")'>" . wp_nonce_field('rs_interni','_wpnonce',true,false);
                    echo "<input type='hidden' name='rs_int_action' value='zrusit_skupinu'><input type='hidden' name='int_skupina_id' value='" . esc_attr($skupina) . "'>";
                    echo "<button type='submit' class='rs-btn rs-btn-sm rs-btn-danger'>✕ série</button></form>";
                }
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
    }

    // JS
    $seg_data = [];
    foreach ($prostory as $p) {
        if (rs_ma_segmenty($p->ID)) {
            foreach (rs_get_segmenty($p->ID) as $seg) {
                $seg_data[$p->ID][] = ['id' => $seg->ID, 'nazev' => $seg->post_title];
            }
        }
    }
    ?>
    <script>
    var rsSegData = <?php echo json_encode($seg_data); ?>;
    function rsIntProstorChange(pid){
        var wrap = document.getElementById('rs-int-seg-wrap');
        var list = document.getElementById('rs-int-seg-list');
        list.innerHTML = '';
        if(rsSegData[pid]){
            rsSegData[pid].forEach(function(s){
                list.innerHTML += '<label style="display:block;margin-bottom:4px"><input type="checkbox" name="int_segmenty[]" value="'+s.id+'"> '+s.nazev+'</label>';
            });
            wrap.style.display = '';
        } else {
            wrap.style.display = 'none';
        }
    }
    function rsIntMode(mode){
        var jedno = document.getElementById('rs-int-panel-jedno');
        var opak  = document.getElementById('rs-int-panel-opak');
        jedno.style.display = mode === 'jednorazova' ? '' : 'none';
        opak.style.display  = mode === 'opakujici'   ? '' : 'none';
        document.querySelectorAll('#rs-int-panel-jedno input[type=datetime-local]').forEach(function(i){ i.required = mode === 'jednorazova'; });
    }
    </script>
    <?php
    return ob_get_clean();
}

function rs_interni_zpracuj(string $action): string {
    $uid = get_current_user_id();
    if (!$uid) return rs_alert('Nejsi přihlášen.','error');

    if ($action === 'vytvorit') {
        $prostor_id   = (int)($_POST['int_prostor_id'] ?? 0);
        $seg_ids      = array_map('intval', (array)($_POST['int_segmenty'] ?? []));
        $nazev        = sanitize_text_field($_POST['int_nazev'] ?? '');
        $poznamka     = sanitize_textarea_field($_POST['int_poznamka'] ?? '');
        $mode         = ($_POST['int_mode'] ?? 'jednorazova') === 'opakujici' ? 'opakujici' : 'jednorazova';
        $rezervujici  = (int)($_POST['int_rezervujici_id'] ?? $uid);
        $oddil        = sanitize_text_field($_POST['int_oddil'] ?? '');

        if (!$nazev) return rs_alert('Zadejte název rezervace.','error');

        if (!$prostor_id) return rs_alert('Vyberte objekt.','error');

        if ($mode === 'jednorazova') {
            $pocet = 0;
            if (!empty($_POST['int_cely_den'])) {
                $den_od = sanitize_text_field($_POST['int_datum_od_den'] ?? '');
                $den_do = sanitize_text_field($_POST['int_datum_do_den'] ?? '');
                $od  = $den_od ? $den_od . ' 00:00:00' : '';
                $do_ = $den_do ? $den_do . ' 23:59:00' : '';
            } else {
                $od  = sanitize_text_field($_POST['int_datum_od'] ?? '');
                $do_ = sanitize_text_field($_POST['int_datum_do'] ?? '');
                $od  = $od  ? str_replace('T',' ',$od)  . ':00' : '';
                $do_ = $do_ ? str_replace('T',' ',$do_) . ':00' : '';
            }
            if (!$od || !$do_) return rs_alert('Zadejte termín.','error');
            if (strtotime($od) >= strtotime($do_)) return rs_alert('Datum konce musí být po datu začátku.','error');
            if (!rs_je_volno($prostor_id,$seg_ids,$od,$do_)) return rs_alert('Zvolený termín není volný.','error');
            $stav = rs_potreba_schvaleni_interni($od) ? 'cekajici' : 'potvrzena';
            $rid  = rs_vytvor_rezervaci_post($prostor_id,$seg_ids,$od,$do_,$pocet,'interni',$stav,$uid,$poznamka,'');
            update_post_meta($rid,'rs_nazev',$nazev);
            update_post_meta($rid,'rs_int_rezervujici_id',$rezervujici);
            update_post_meta($rid,'rs_oddil',$oddil);
            return rs_alert('Rezervace vytvořena. ' . ($stav==='cekajici' ? 'Čeká na schválení (víkend/svátek/prázdniny – ale bude zdarma).' : 'Automaticky potvrzena.'));
        }

        // Opakující se
        $pocet        = 0;
        $den          = (int)($_POST['int_den_tydne'] ?? 1);
        $cas_od       = sanitize_text_field($_POST['int_cas_od'] ?? '08:00');
        $cas_do       = sanitize_text_field($_POST['int_cas_do'] ?? '10:00');
        $serie_od     = sanitize_text_field($_POST['int_opakovani_od'] ?? '');
        $serie_do     = sanitize_text_field($_POST['int_opakovani_do'] ?? '');
        if (!$serie_od || !$serie_do) return rs_alert('Zadejte rozsah opakování.','error');

        $vyjimky_raw   = sanitize_text_field($_POST['int_vyjimky'] ?? '');
        $vyjimky       = array_filter(array_map('trim', explode(',', $vyjimky_raw)));
        $vynechat_praz = isset($_POST['int_vynechat_prazdniny']);
        $vynechat_svat = isset($_POST['int_vynechat_svatky']);

        $skupina_id = rs_token();
        $created = 0; $skipped = 0;
        $current = strtotime($serie_od);
        $end     = strtotime($serie_do);

        while ($current <= $end) {
            if ((int)date('N',$current) === $den) {
                $d    = date('Y-m-d',$current);
                $skip = in_array($d,$vyjimky,true)
                     || ($vynechat_praz && rs_jsou_prazdniny($d))
                     || ($vynechat_svat && rs_je_svatek($d));
                if (!$skip) {
                    $od  = $d . ' ' . $cas_od . ':00';
                    $do_ = $d . ' ' . $cas_do . ':00';
                    if (rs_je_volno($prostor_id,$seg_ids,$od,$do_)) {
                        $stav = rs_potreba_schvaleni_interni($d) ? 'cekajici' : 'potvrzena';
                        $rid  = rs_vytvor_rezervaci_post($prostor_id,$seg_ids,$od,$do_,$pocet,'interni',$stav,$uid,$poznamka,$skupina_id);
                        update_post_meta($rid,'rs_nazev',$nazev);
                        update_post_meta($rid,'rs_int_rezervujici_id',$rezervujici);
                        update_post_meta($rid,'rs_oddil',$oddil);
                        $created++;
                    } else { $skipped++; }
                }
            }
            $current = strtotime('+1 day',$current);
        }
        return rs_alert("Série vytvořena: {$created} rezervací." . ($skipped ? " Přeskočeno {$skipped} (kolize nebo obsazeno)." : ''));
    }

    if ($action === 'zrusit') {
        $rid = (int)($_POST['int_rez_id'] ?? 0);
        if (!$rid) return '';
        $owner = (int)get_post_meta($rid,'rs_wp_user_id',true);
        if ($owner !== $uid && !rs_ma_pravo('spravce')) return rs_alert('Nemáš oprávnění.','error');
        update_post_meta($rid,'rs_stav','zrusena');
        return rs_alert('Rezervace zrušena.');
    }

    if ($action === 'zrusit_skupinu') {
        $skupina = sanitize_text_field($_POST['int_skupina_id'] ?? '');
        if (!$skupina) return '';
        $rez = get_posts(['post_type'=>'rs_rezervace','numberposts'=>-1,'fields'=>'ids','meta_query'=>[['key'=>'rs_skupina_id','value'=>$skupina]]]);
        foreach ($rez as $rid) {
            $owner = (int)get_post_meta($rid,'rs_wp_user_id',true);
            if ($owner === $uid || rs_ma_pravo('spravce')) {
                update_post_meta($rid,'rs_stav','zrusena');
            }
        }
        return rs_alert('Celá série zrušena.');
    }
    return '';
}

function rs_vytvor_rezervaci_post(int $prostor_id, array $seg_ids, string $od, string $do_, int $pocet, string $typ, string $stav, int $uid, string $poznamka, string $skupina_id, string $token = ''): int {
    $prostor_nazev = get_the_title($prostor_id);
    $rid = wp_insert_post(['post_type'=>'rs_rezervace','post_status'=>'publish','post_title'=>$prostor_nazev . ' – ' . substr($od,0,10)]);
    if (!$rid) return 0;
    update_post_meta($rid,'rs_prostor_id',$prostor_id);
    update_post_meta($rid,'rs_segmenty_ids',$seg_ids);
    update_post_meta($rid,'rs_datum_od',$od);
    update_post_meta($rid,'rs_datum_do',$do_);
    update_post_meta($rid,'rs_pocet_lidi',$pocet);
    update_post_meta($rid,'rs_typ_rezervace',$typ);
    update_post_meta($rid,'rs_stav',$stav);
    update_post_meta($rid,'rs_wp_user_id',$uid);
    update_post_meta($rid,'rs_poznamka',$poznamka);
    if ($skupina_id) update_post_meta($rid,'rs_skupina_id',$skupina_id);
    if ($token)      update_post_meta($rid,'rs_token',$token);
    return $rid;
}

// ═══ FRONTEND: KALENDÁŘ [rs_kalendar] ════════════════════════════════════════

add_shortcode('rs_kalendar','rs_kalendar_sc');
function rs_kalendar_sc(array $atts): string {
    $ob_level = ob_get_level();
    try {
    rs_css();
    $prostory = rs_get_prostory();
    if (empty($prostory)) return '<p>Žádné prostory nejsou zatím k dispozici.</p>';

    $rok   = (int)($_GET['rs_rok']   ?? date('Y'));
    $mesic = (int)($_GET['rs_mesic'] ?? date('n'));
    if ($mesic < 1) { $mesic = 12; $rok--; }
    if ($mesic > 12){ $mesic = 1;  $rok++; }

    $days_in_month = (int)date('t', mktime(0,0,0,$mesic,1,$rok));
    $mesic_od = sprintf('%04d-%02d-01 00:00:00', $rok, $mesic);
    $mesic_do = sprintf('%04d-%02d-%02d 23:59:59', $rok, $mesic, $days_in_month);

    $rezervace = get_posts(['post_type'=>'rs_rezervace','post_status'=>'publish','numberposts'=>-1,'meta_query'=>[
        'relation'=>'AND',
        ['key'=>'rs_stav','value'=>'zrusena','compare'=>'!='],
        ['key'=>'rs_datum_od','value'=>$mesic_do,'compare'=>'<='],
        ['key'=>'rs_datum_do','value'=>$mesic_od,'compare'=>'>='],
    ]]);

    $is_privileged = rs_ma_pravo('vedeni');

    // Sestavit mapu obsazenosti + detail dat pro JS
    $busy    = []; // [tid][den] = 'full'|'partial'
    $pending = []; // [tid][den] = true  (má aspoň jednu rezervaci čekající na schválení)
    $kal_data = []; // [tid][den][] = detail pole

    foreach ($rezervace as $r) {
        $pid      = (int)get_post_meta($r->ID,'rs_prostor_id',true);
        $segs     = (array)get_post_meta($r->ID,'rs_segmenty_ids',true);
        $r_od_ts  = (int)(strtotime(get_post_meta($r->ID,'rs_datum_od',true)) ?: 0);
        $r_do_ts  = (int)(strtotime(get_post_meta($r->ID,'rs_datum_do',true)) ?: 0);
        $typ      = get_post_meta($r->ID,'rs_typ_rezervace',true);
        $r_stav   = get_post_meta($r->ID,'rs_stav',true);
        $target_ids = empty($segs) ? [$pid] : $segs;

        $detail = [
            'od'   => $r_od_ts ? date('H:i', $r_od_ts) : '',
            'do'   => $r_do_ts ? date('H:i', $r_do_ts) : '',
            'typ'  => $typ,
            'stav' => $r_stav,
        ];
        if ($is_privileged) {
            $rez_uid  = (int)get_post_meta($r->ID,'rs_int_rezervujici_id',true) ?: (int)get_post_meta($r->ID,'rs_wp_user_id',true);
            $rez_user = get_userdata($rez_uid);
            $skupina  = get_post_meta($r->ID,'rs_skupina_id',true);
            $detail['nazev']       = get_post_meta($r->ID,'rs_nazev',true);
            $detail['rezervujici'] = $rez_user ? $rez_user->display_name : '';
            $detail['oddil']       = get_post_meta($r->ID,'rs_oddil',true);
            $detail['poznamka']    = get_post_meta($r->ID,'rs_poznamka',true);
            $detail['opakuje']     = !empty($skupina);
        }

        for ($d = 1; $d <= $days_in_month; $d++) {
            $day_start = mktime(0,0,0,$mesic,$d,$rok);
            $day_end   = mktime(23,59,0,$mesic,$d,$rok); // 23:59:00 – odpovídá "celý den" rezervacím
            if ($r_od_ts <= $day_end && $r_do_ts >= $day_start) {
                $coverage = ($r_od_ts <= $day_start && $r_do_ts >= $day_end) ? 'full' : 'partial';
                foreach ($target_ids as $tid) {
                    if (empty($busy[$tid][$d]) || ($busy[$tid][$d] === 'partial' && $coverage === 'full')) {
                        $busy[$tid][$d] = $coverage;
                    }
                    if ($r_stav === 'cekajici') $pending[$tid][$d] = true;
                    $kal_data[$tid][$d][] = $detail;
                }
            }
        }
    }

    $mesice_cz  = ['','Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
    $mesice_gen = ['','ledna','února','března','dubna','května','června','července','srpna','září','října','listopadu','prosince'];
    $dny_zkr    = ['','Po','Út','St','Čt','Pá','So','Ne'];

    // Sestavit data segmentů pro JS modal + foto výpis
    $rsSegData = [];
    $fmt_c = fn(float $v): string => number_format($v, 0, ',', ' ');
    foreach ($prostory as $p) {
        $is_seg_p = rs_ma_segmenty($p->ID);
        $p_items  = $is_seg_p ? rs_get_segmenty($p->ID) : [];
        if (!$is_seg_p) continue; // Segmenty nemají detail modal (info blok se zobrazí nad tabulkou)
        $p_rezim  = get_post_meta($p->ID,'rs_ceny_rezim',true) ?: 'celek';
        foreach ($p_items as $item) {
            $kap   = (int)get_post_meta($item->ID,'rs_kapacita',true);
            $roz   = (int)get_post_meta($item->ID,'rs_rozloha',true);
            $dop   = get_post_meta($item->ID,'rs_doplnujici',true);
            $popis = trim($item->post_content);
            $za_os  = (float)get_post_meta($p_rezim === 'segmenty' ? $item->ID : $p->ID,'rs_cena_za_osobu',true);
            $za_min = (float)get_post_meta($p_rezim === 'segmenty' ? $item->ID : $p->ID,'rs_cena_min',true);
            if ($za_os > 0 && $za_min > 0)  $cena_s = $fmt_c($za_os) . ' Kč/os., min. ' . $fmt_c($za_min) . ' Kč';
            elseif ($za_os > 0)              $cena_s = $fmt_c($za_os) . ' Kč/os.';
            elseif ($za_min > 0)             $cena_s = 'Paušálně ' . $fmt_c($za_min) . ' Kč';
            else                             $cena_s = '';
            $fotky_urls = [];
            foreach ((array)get_post_meta($item->ID,'rs_fotky',true) as $fid) {
                $thumb = wp_get_attachment_image_url((int)$fid,'medium');
                $full  = wp_get_attachment_url((int)$fid) ?: $thumb;
                if ($thumb) $fotky_urls[] = ['thumb' => $thumb, 'full' => $full];
            }
            $rsSegData[$item->ID] = [
                'title' => $item->post_title,
                'popis' => $popis,
                'kap'   => $kap,
                'roz'   => $roz,
                'dop'   => $dop,
                'cena'  => $cena_s,
                'fotky' => $fotky_urls,
                'ok'    => (bool)($popis || $kap || $roz || $dop || $cena_s || $fotky_urls),
            ];
        }
    }

    $prev_url = add_query_arg(['rs_rok' => $mesic === 1 ? $rok-1 : $rok, 'rs_mesic' => $mesic === 1 ? 12 : $mesic-1]) . '#rs-kalendar';
    $next_url = add_query_arg(['rs_rok' => $mesic === 12 ? $rok+1 : $rok, 'rs_mesic' => $mesic === 12 ? 1 : $mesic+1]) . '#rs-kalendar';

    ob_start();
    echo "<div class='rs-wrap' id='rs-kalendar'>";
    echo "<h3 style='margin-bottom:20px'>Obsazenost objektů</h3>";

    // URL stránky s rezervačním formulářem: option → auto-detekce → prázdné
    $form_url = get_option('rs_formular_url', '');
    if (!$form_url) {
        global $wpdb;
        $form_row = $wpdb->get_row("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%[rs_formular]%' LIMIT 1");
        $form_url = $form_row ? get_permalink($form_row->ID) : '';
    }

    // Typy prostor (pre-fetch)
    $p_typy = [];
    foreach ($prostory as $p) {
        $tid = (int)get_post_meta($p->ID,'rs_typ_id',true);
        $p_typy[$p->ID] = $tid ? get_the_title($tid) : '';
    }

    // Odfiltrovat prostory vypnuté pro ext. rezervace
    $prostory = array_values(array_filter($prostory, fn($p) => !rs_je_ext_vypnuto($p->ID)));
    if (empty($prostory)) { echo "<p>Žádné objekty nejsou momentálně k dispozici pro rezervaci.</p></div>"; return ob_get_clean(); }

    // Tab bar
    echo "<div style='display:flex;flex-wrap:wrap;gap:0;border-bottom:2px solid #1a5c2a;margin-bottom:0'>";
    foreach ($prostory as $i => $p) {
        $a   = ($i === 0);
        $typ = $p_typy[$p->ID];
        $lbl = "<span style='display:block;line-height:1.2'>" . esc_html($p->post_title) . "</span>";
        if ($typ) $lbl .= "<span style='display:block;font-size:11px;font-weight:400;opacity:" . ($a ? '.8' : '.65') . ";margin-top:2px'>" . esc_html($typ) . "</span>";
        echo "<button data-rs-tab='" . (int)$p->ID . "' onclick='rsTab(" . (int)$p->ID . ")' style='padding:10px 20px;cursor:pointer;font-size:14px;font-weight:600;border-radius:4px 4px 0 0;margin-right:3px;margin-bottom:-2px;border:1px solid #1a5c2a;border-bottom:none;background:" . ($a ? '#f4f8f4' : '#1a5c2a') . ";color:" . ($a ? '#1a5c2a' : '#fff') . ";text-align:left'>{$lbl}</button>";
    }
    echo "</div>";

    foreach ($prostory as $i => $p) {
        echo "<div id='rs-kal-panel-" . (int)$p->ID . "' data-rs-tab-panel style='padding-top:16px;margin-bottom:28px" . ($i > 0 ? ";display:none" : "") . "'>";
        echo "<h4 style='color:#1a5c2a;margin-bottom:2px'>" . esc_html($p->post_title) . "</h4>";
        if ($p_typy[$p->ID]) echo "<p style='margin:0 0 10px;font-size:13px;color:#666'>" . esc_html($p_typy[$p->ID]) . "</p>";
        $items = rs_ma_segmenty($p->ID) ? rs_get_segmenty($p->ID) : [$p];
        $items_ext = array_values(array_filter($items, fn($s) => !rs_je_ext_vypnuto($s->ID)));

        // Info blok pod názvem prostory
        $p_popis  = trim($p->post_content);
        $p_adresa = get_post_meta($p->ID,'rs_adresa',true);
        $p_gps    = get_post_meta($p->ID,'rs_gps',true);
        $p_kap    = array_sum(array_map(fn($s) => (int)get_post_meta($s->ID,'rs_kapacita',true), $items_ext));
        $p_roz    = array_sum(array_map(fn($s) => (int)get_post_meta($s->ID,'rs_rozloha',true), $items_ext));
        $p_dop    = get_post_meta($p->ID,'rs_doplnujici',true);
        $p_rezim  = get_post_meta($p->ID,'rs_ceny_rezim',true) ?: 'celek';
        $fmt      = fn(float $v): string => number_format($v, 0, ',', "\xc2\xa0");
        $p_seg_ceny       = []; // [seg_id => price_str] — vyplněno jen pokud se ceny segmentů liší
        $p_seg_ceny_chips = []; // chip strings pro info box (jen při různých cenách)
        if (!rs_ma_segmenty($p->ID) || $p_rezim === 'celek') {
            $za_os    = (float)get_post_meta($p->ID,'rs_cena_za_osobu',true);
            $za_min   = (float)get_post_meta($p->ID,'rs_cena_min',true);
            if ($za_os > 0 && $za_min > 0)  $p_cena = $fmt($za_os) . '&nbsp;Kč/os., min. ' . $fmt($za_min) . '&nbsp;Kč';
            elseif ($za_os > 0)              $p_cena = $fmt($za_os) . '&nbsp;Kč/os.';
            elseif ($za_min > 0)             $p_cena = 'Paušálně ' . $fmt($za_min) . '&nbsp;Kč';
            else                             $p_cena = '';
        } else {
            $p_cena  = '';
            $sc_map  = []; // [seg_id => ['title'=>..., 'cena'=>...]]
            foreach ($items_ext as $s) {
                $s_os  = (float)get_post_meta($s->ID,'rs_cena_za_osobu',true);
                $s_min = (float)get_post_meta($s->ID,'rs_cena_min',true);
                if ($s_os > 0 && $s_min > 0)  $sc = $fmt($s_os) . '&nbsp;Kč/os., min. ' . $fmt($s_min) . '&nbsp;Kč';
                elseif ($s_os > 0)             $sc = $fmt($s_os) . '&nbsp;Kč/os.';
                elseif ($s_min > 0)            $sc = 'Paušálně ' . $fmt($s_min) . '&nbsp;Kč';
                else                           $sc = '';
                $sc_map[$s->ID] = ['title' => $s->post_title, 'cena' => $sc];
            }
            $unique = array_unique(array_filter(array_column($sc_map, 'cena')));
            if (count($unique) <= 1) {
                $p_cena = $unique ? reset($unique) : ''; // všechny stejné → jeden chip
            } else {
                foreach ($sc_map as $sid => $d) {  // různé → chip za každý segment
                    if ($d['cena']) {
                        $p_seg_ceny[$sid]   = $d['cena'];
                        $p_seg_ceny_chips[] = '💰 <strong>' . esc_html($d['title']) . ':</strong> ' . $d['cena'];
                    }
                }
            }
        }
        if ($p_popis || $p_adresa || $p_gps || $p_kap || $p_roz || $p_cena || $p_dop) {
            echo "<div style='margin-bottom:12px;font-size:13px;color:#444;background:#f8faf8;border:1px solid #d4e8d7;border-radius:4px;padding:12px 14px'>";
            if ($p_popis) echo "<p style='margin:0 0 8px'>" . nl2br(esc_html($p_popis)) . "</p>";
            if (rs_ma_segmenty($p->ID) && count($items_ext) < count($items) && !empty($items_ext)) {
                $nazvy_arr = array_map(fn($s) => $s->post_title, $items_ext);
                $nazvy = count($nazvy_arr) > 1
                    ? implode(', ', array_slice($nazvy_arr, 0, -1)) . ' a ' . end($nazvy_arr)
                    : $nazvy_arr[0];
                $je_jsou = count($nazvy_arr) === 1 ? 'je' : 'jsou';
                echo "<p style='margin:0 0 8px;padding:6px 10px;background:#fff8e1;border:1px solid #ffe082;border-radius:3px;color:#6d4c00'>V současné době nabízíme k pronájmu jen část objektu. K dispozici {$je_jsou} jen <strong>" . esc_html($nazvy) . "</strong>.</p>";
            }
            $chips = [];
            if ($p_adresa) $chips[] = "📍 " . esc_html($p_adresa);
            if ($p_gps) {
                $gps_url = 'https://mapy.cz/zakladni?q=' . rawurlencode($p_gps);
                $chips[] = "🗺️ <a href='" . esc_url($gps_url) . "' target='_blank' rel='noopener' style='color:#1a5c2a'>" . esc_html($p_gps) . "</a>";
            }
            if ($p_kap) $chips[] = "🛏 <strong>" . $p_kap . "&nbsp;míst</strong> na spaní <span style='color:#888'>(přibližný počet)</span>";
            if ($p_roz) $chips[] = "📐 <strong>" . $p_roz . "&nbsp;m²</strong> <span style='color:#888'>(místnosti ke spaní, bez společných prostor)</span>";
            if ($p_cena) $chips[] = "💰 " . $p_cena;
            foreach ($p_seg_ceny_chips as $sc) $chips[] = $sc;
            if ($chips) {
                echo "<div style='display:flex;flex-wrap:wrap;gap:4px 20px'>";
                foreach ($chips as $chip) echo "<span>" . $chip . "</span>";
                echo "</div>";
            }
            if ($p_dop) echo "<div style='margin-top:8px;color:#555'>" . nl2br(esc_html($p_dop)) . "</div>";
            echo "</div>";
        }

        // Navigace
        echo "<div style='display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px'>";
        echo "<a href='" . esc_url($prev_url) . "' class='rs-btn rs-btn-secondary rs-btn-sm'>← Předchozí</a>";
        echo "<form method='get' action='#rs-kalendar' style='display:flex;gap:6px;align-items:center'>";
        foreach ($_GET as $k => $v)
            if ($k !== 'rs_rok' && $k !== 'rs_mesic' && is_string($v))
                echo "<input type='hidden' name='" . esc_attr($k) . "' value='" . esc_attr($v) . "'>";
        echo "<select name='rs_mesic' onchange='rsKalNav(this)' style='padding:4px 8px;border:1px solid #8c8f94;border-radius:3px;font-size:13px'>";
        for ($m = 1; $m <= 12; $m++) {
            $sel = ($m === $mesic) ? ' selected' : '';
            echo "<option value='{$m}'{$sel}>" . $mesice_cz[$m] . "</option>";
        }
        echo "</select>";
        echo "<select name='rs_rok' onchange='rsKalNav(this)' style='padding:4px 8px;border:1px solid #8c8f94;border-radius:3px;font-size:13px'>";
        for ($y = (int)date('Y') - 2; $y <= (int)date('Y') + 5; $y++) {
            $sel = ($y === $rok) ? ' selected' : '';
            echo "<option value='{$y}'{$sel}>{$y}</option>";
        }
        echo "</select>";
        echo "</form>";
        echo "<a href='" . esc_url($next_url) . "' class='rs-btn rs-btn-secondary rs-btn-sm'>Následující →</a>";
        echo "</div>";
        echo "<p style='font-weight:600;color:#1a5c2a;margin:0 0 6px'>" . esc_html($mesice_cz[$mesic]) . " " . $rok . "</p>";
        echo "<div data-rs-scroll-wrap style='position:relative'>";
        echo "<div class='rs-kal-scroll' style='overflow-x:auto'>";
        echo "<table class='rs-kal-table'><thead><tr><th>Objekt/Část</th>";
        for ($d = 1; $d <= $days_in_month; $d++) {
            $dow   = (int)date('N', mktime(0,0,0,$mesic,$d,$rok));
            $style = ($dow >= 6) ? ' style="background:#2e7d32"' : '';
            echo "<th{$style}>{$d}<br><small style='font-weight:400;font-size:10px'>" . $dny_zkr[$dow] . "</small></th>";
        }
        echo "</tr></thead><tbody>";

        foreach ($items as $item) {
            if (rs_je_ext_vypnuto($item->ID)) continue;
            $tid = $item->ID;
            $seg_link = (!empty($rsSegData[$tid]['ok']))
                ? "<a href='#' onclick='rsSegDetail(" . (int)$tid . ");return false;' style='color:#1a5c2a;text-decoration:underline dotted;cursor:pointer'>" . esc_html($item->post_title) . "</a>"
                : esc_html($item->post_title);
            echo "<tr><td>{$seg_link}</td>";
            for ($d = 1; $d <= $days_in_month; $d++) {
                $stav = $busy[$tid][$d] ?? '';
                $has_detail = !empty($kal_data[$tid][$d]);
                $click = $has_detail ? " style='cursor:pointer;position:relative' onclick='rsKalDetail(" . esc_js((string)$tid) . "," . $d . ",\"" . esc_js($item->post_title) . "\"," . $rok . "," . $mesic . ")'" : '';
                $lupa  = $has_detail ? "<span style='position:absolute;top:1px;right:2px;font-size:7px;opacity:.55;line-height:1;pointer-events:none'>🔍</span>" : '';
                $hod   = !empty($pending[$tid][$d]) ? "<span style='position:absolute;bottom:1px;right:2px;font-size:8px;line-height:1;pointer-events:none;opacity:.8'>⏳</span>" : '';
                if ($stav === 'full')         echo "<td{$click}><span class='rs-kal-busy'>✕</span>{$hod}{$lupa}</td>";
                elseif ($stav === 'partial')  echo "<td{$click}><span class='rs-kal-partial'>●</span>{$hod}{$lupa}</td>";
                else                         echo "<td><span class='rs-kal-free'>✓</span></td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>"; // .rs-kal-scroll
        $arr_btn = "background:rgba(255,255,255,.95);border:1px solid #ccc;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:20px;line-height:1;display:flex;align-items:center;justify-content:center;color:#1a5c2a;pointer-events:all;flex-shrink:0;padding:0;box-shadow:0 1px 4px rgba(0,0,0,.15)";
        echo "<div class='rs-kal-ind rs-kal-ind-r' style='position:absolute;top:0;right:0;bottom:0;width:72px;background:linear-gradient(to right,transparent,rgba(255,255,255,.85));pointer-events:none;display:flex;align-items:center;justify-content:flex-end;padding-right:8px'><button onclick='rsKalScrollDir(this,1)' style='{$arr_btn}'>&#8250;</button></div>";
        echo "<div class='rs-kal-ind rs-kal-ind-l' style='position:absolute;top:0;left:0;bottom:0;width:72px;background:linear-gradient(to left,transparent,rgba(255,255,255,.85));pointer-events:none;display:none;align-items:center;justify-content:flex-start;padding-left:8px'><button onclick='rsKalScrollDir(this,-1)' style='{$arr_btn}'>&#8249;</button></div>";
        echo "</div>"; // [data-rs-scroll-wrap]
        echo "<div style='margin:6px 0 10px;font-size:12px;color:#666;display:flex;gap:8px 18px;flex-wrap:wrap'>";
        echo "<span><span class='rs-kal-free' style='font-size:11px;width:18px;height:18px;line-height:18px'>✓</span> Volno</span>";
        echo "<span><span class='rs-kal-partial' style='font-size:13px;width:18px;height:18px;line-height:18px'>●</span> Částečně obsazeno <span style='opacity:.7'>(🔍 kliknutím detail)</span></span>";
        echo "<span><span class='rs-kal-busy' style='font-size:11px;width:18px;height:18px;line-height:18px'>✕</span> Obsazeno celý den <span style='opacity:.7'>(🔍 kliknutím detail)</span></span>";
        echo "<span>⏳ Čeká na schválení</span>";
        echo "</div>";
        $btn_href = $form_url ? esc_url($form_url) : '#';
        echo "<div style='margin:20px 0 24px;text-align:center'><a href='{$btn_href}' class='rs-btn' style='font-size:16px;padding:14px 36px;display:inline-block;box-shadow:0 2px 8px rgba(0,0,0,.18)'>📅 Rezervovat objekty</a></div>";

        // Fotky prostory a segmentů s lightbox podporou
        $fotky_p = [];
        foreach ((array)get_post_meta($p->ID,'rs_fotky',true) as $fid) {
            $thumb = wp_get_attachment_image_url((int)$fid,'medium');
            $full  = wp_get_attachment_url((int)$fid) ?: $thumb;
            if ($thumb) $fotky_p[] = ['thumb' => $thumb, 'full' => $full];
        }
        $seg_blocks = [];
        if (rs_ma_segmenty($p->ID)) {
            foreach ($items as $seg_item) {
                if (rs_je_ext_vypnuto($seg_item->ID)) continue;
                $seg_kap   = (int)get_post_meta($seg_item->ID,'rs_kapacita',true);
                $seg_roz   = (int)get_post_meta($seg_item->ID,'rs_rozloha',true);
                $seg_popis = trim($seg_item->post_content);
                $seg_dop   = get_post_meta($seg_item->ID,'rs_doplnujici',true);
                $seg_cena  = $p_seg_ceny[$seg_item->ID] ?? '';
                $seg_imgs  = [];
                foreach ((array)get_post_meta($seg_item->ID,'rs_fotky',true) as $fid) {
                    $thumb = wp_get_attachment_image_url((int)$fid,'medium');
                    $full  = wp_get_attachment_url((int)$fid) ?: $thumb;
                    if ($thumb) $seg_imgs[] = ['thumb' => $thumb, 'full' => $full];
                }
                if ($seg_kap || $seg_roz || $seg_popis || $seg_dop || $seg_cena || $seg_imgs) {
                    $seg_blocks[] = ['item' => $seg_item, 'kap' => $seg_kap, 'roz' => $seg_roz,
                        'popis' => $seg_popis, 'dop' => $seg_dop, 'cena' => $seg_cena,
                        'imgs' => $seg_imgs, 'gal' => 'gal-s-' . $seg_item->ID];
                }
            }
        }
        if ($fotky_p || $seg_blocks) {
            echo "<div style='margin-top:10px'>";
            if ($fotky_p) {
                $gal_p = 'gal-p-' . $p->ID;
                echo "<div class='rs-foto-preview'>";
                foreach ($fotky_p as $f) echo "<img src='" . esc_url($f['thumb']) . "' data-full='" . esc_attr($f['full']) . "' data-gallery='" . esc_attr($gal_p) . "' data-caption='' onclick='rsLightbox(this)' style='width:120px;height:90px;object-fit:cover;border-radius:3px;border:1px solid #ddd;cursor:pointer'>";
                echo "</div>";
            }
            foreach ($seg_blocks as $sg) {
                echo "<div style='margin-top:14px'>";
                echo "<p style='font-size:13px;font-weight:700;color:#333;margin:0 0 6px'>" . esc_html($sg['item']->post_title) . "</p>";
                $info = [];
                if ($sg['kap'])  $info[] = "🛏 <strong>" . $sg['kap'] . "&nbsp;míst</strong> na spaní";
                if ($sg['roz'])  $info[] = "📐 <strong>" . $sg['roz'] . "&nbsp;m²</strong>";
                if ($sg['cena']) $info[] = "💰 " . $sg['cena'];
                if ($info) {
                    echo "<div style='display:flex;flex-wrap:wrap;gap:4px 16px;font-size:12px;color:#444;margin-bottom:6px'>";
                    foreach ($info as $ip) echo "<span>" . $ip . "</span>";
                    echo "</div>";
                }
                if ($sg['popis']) echo "<p style='font-size:12px;color:#555;margin:0 0 6px'>" . nl2br(esc_html($sg['popis'])) . "</p>";
                if ($sg['dop'])   echo "<p style='font-size:12px;color:#666;margin:0 0 6px'>" . nl2br(esc_html($sg['dop'])) . "</p>";
                if ($sg['imgs']) {
                    echo "<div class='rs-foto-preview'>";
                    foreach ($sg['imgs'] as $f) echo "<img src='" . esc_url($f['thumb']) . "' data-full='" . esc_attr($f['full']) . "' data-gallery='" . esc_attr($sg['gal']) . "' data-caption='' onclick='rsLightbox(this)' style='width:120px;height:90px;object-fit:cover;border-radius:3px;border:1px solid #ddd;cursor:pointer'>";
                    echo "</div>";
                }
                echo "</div>";
            }
            echo "</div>";
        }
        echo "</div>";
    }

    $doplnujici = get_option('rs_doplnujici_info_ext', get_option('rs_doplnujici_info',''));
    if ($doplnujici) echo "<div style='margin-top:20px;padding:14px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;font-size:13px'>" . wp_kses_post(nl2br($doplnujici)) . "</div>";

    // Modal pro detail dne
    echo "<div id='rs-kal-modal' style='display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center'>";
    echo "<div style='background:#fff;border-radius:6px;padding:24px;max-width:500px;width:90%;max-height:80vh;overflow-y:auto;position:relative'>";
    echo "<button onclick='document.getElementById(\"rs-kal-modal\").style.display=\"none\"' style='position:absolute;top:12px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#666'>✕</button>";
    echo "<h4 id='rs-kal-modal-title' style='margin:0 0 16px;color:#1a5c2a'></h4>";
    echo "<div id='rs-kal-modal-body'></div>";
    echo "</div></div>";

    // Lightbox overlay
    echo "<div id='rs-lb' style='display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:199999;align-items:center;justify-content:center;flex-direction:column'>";
    echo "<button onclick='rsLbClose()' style='position:absolute;top:14px;right:18px;background:none;border:none;color:#fff;font-size:30px;cursor:pointer;line-height:1;padding:4px'>✕</button>";
    echo "<button id='rs-lb-prev' onclick='rsLbPrev()' style='position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:32px;cursor:pointer;padding:10px 16px;border-radius:4px;line-height:1'>&#8249;</button>";
    echo "<img id='rs-lb-img' src='' alt='' style='max-width:90vw;max-height:80vh;object-fit:contain;border-radius:3px;display:block'>";
    echo "<div id='rs-lb-cap' style='color:#eee;margin-top:10px;font-size:13px;text-align:center;max-width:80vw;display:none'></div>";
    echo "<div id='rs-lb-ctr' style='color:#aaa;margin-top:4px;font-size:12px'></div>";
    echo "<button id='rs-lb-next' onclick='rsLbNext()' style='position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:32px;cursor:pointer;padding:10px 16px;border-radius:4px;line-height:1'>&#8250;</button>";
    echo "</div>";

    // JS: detail dat + modal
    $is_priv_js = $is_privileged ? 'true' : 'false';
    $kal_json   = wp_json_encode($kal_data);
    $seg_json   = wp_json_encode($rsSegData);
    ?>
    <script>
    var rsKalData = <?php echo $kal_json; ?>;
    var rsKalPriv = <?php echo $is_priv_js; ?>;
    var rsSegData = <?php echo $seg_json; ?>;
    var rsKalMesiceGen = ['','ledna','února','března','dubna','května','června','července','srpna','září','října','listopadu','prosince'];
    function rsSegDetail(sid) {
        var seg = rsSegData[sid];
        if (!seg) return;
        document.getElementById('rs-kal-modal-title').textContent = seg.title;
        var html = '';
        if (seg.popis) html += '<p style="margin:0 0 10px">' + escHtml(seg.popis) + '</p>';
        var chips = [];
        if (seg.kap) chips.push('🛏 <strong>' + seg.kap + ' míst</strong> na spaní <span style="color:#888">(přibližně)</span>');
        if (seg.roz) chips.push('📐 <strong>' + seg.roz + ' m²</strong> <span style="color:#888">(místnosti ke spaní)</span>');
        if (seg.cena) chips.push('💰 ' + escHtml(seg.cena));
        if (chips.length) html += '<div style="display:flex;flex-wrap:wrap;gap:4px 16px;margin-bottom:10px">' + chips.map(function(c){ return '<span>' + c + '</span>'; }).join('') + '</div>';
        if (seg.dop) html += '<p style="margin:0 0 10px;color:#555">' + escHtml(seg.dop) + '</p>';
        if (seg.fotky && seg.fotky.length) {
            html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">';
            seg.fotky.forEach(function(f, i) {
                html += '<img src="' + escHtml(f.thumb) + '" data-full="' + escHtml(f.full) + '" data-gallery="seg-' + sid + '" data-caption="" onclick="rsLightbox(this)" style="width:130px;height:98px;object-fit:cover;border-radius:3px;border:1px solid #ddd;cursor:pointer">';
            });
            html += '</div>';
        }
        if (!html) html = '<p style="color:#777">Žádné detaily k zobrazení.</p>';
        document.getElementById('rs-kal-modal-body').innerHTML = html;
        var modal = document.getElementById('rs-kal-modal');
        modal.style.display = 'flex';
        modal.onclick = function(e){ if(e.target===this) this.style.display='none'; };
    }
    function rsKalDetail(tid, den, nazevProstoru, rok, mesic) {
        var items = (rsKalData[tid] && rsKalData[tid][den]) ? rsKalData[tid][den] : [];
        var title = den + '. ' + rsKalMesiceGen[mesic] + ' ' + rok + ' – ' + nazevProstoru;
        document.getElementById('rs-kal-modal-title').textContent = title;
        var html = '';
        items.forEach(function(r, i) {
            var stavText  = r.stav === 'cekajici' ? '⏳ čeká na schválení' : 'obsazeno';
            var stavColor = r.stav === 'cekajici' ? '#b06000' : '#c0392b';
            html += '<div style="border:1px solid #e0e0e0;border-radius:4px;padding:12px;margin-bottom:10px">';
            html += '<div style="font-size:14px;font-weight:600;margin-bottom:6px">🕐 ' + r.od + ' – ' + r.do + ' <span style="font-weight:normal;color:' + stavColor + '">' + stavText + '</span></div>';
            if (rsKalPriv) {
                if (r.nazev)       html += '<div style="margin-bottom:3px"><strong>Název:</strong> ' + escHtml(r.nazev) + '</div>';
                if (r.rezervujici) html += '<div style="margin-bottom:3px"><strong>Rezervující:</strong> ' + escHtml(r.rezervujici) + '</div>';
                if (r.oddil)       html += '<div style="margin-bottom:3px"><strong>Součást střediska:</strong> ' + escHtml(r.oddil) + '</div>';
                if (r.poznamka)    html += '<div style="margin-bottom:3px"><strong>Poznámka:</strong> ' + escHtml(r.poznamka) + '</div>';
                if (r.opakuje)     html += '<div style="margin-bottom:3px;color:#1a5c2a"><strong>🔁 Část opakující se série</strong></div>';
                var typLabel = r.typ === 'interni' ? 'Interní' : 'Externí';
                html += '<div style="margin-top:6px"><span style="font-size:11px;background:#e8f5e9;color:#1a5c2a;padding:2px 6px;border-radius:3px">' + escHtml(typLabel) + '</span></div>';
            }
            html += '</div>';
        });
        if (!rsKalPriv) html += '<p style="margin:8px 0 0;font-size:12px;color:#888">Pro zobrazení podrobností se přihlaste (jen pro vedení oddílů).</p>';
        if (!html) html = '<p style="color:#777">Žádné detaily k zobrazení.</p>';
        document.getElementById('rs-kal-modal-body').innerHTML = html;
        var modal = document.getElementById('rs-kal-modal');
        modal.style.display = 'flex';
        modal.onclick = function(e){ if(e.target===this) this.style.display='none'; };
    }
    function rsCelyDen(cb, prefix) {
        var casWrap = document.getElementById('rs-' + prefix + '-cas-wrap');
        var denWrap = document.getElementById('rs-' + prefix + '-den-wrap');
        if (!casWrap || !denWrap) return;
        var dtOd = casWrap.querySelector('[name=' + prefix + '_datum_od]');
        var dtDo = casWrap.querySelector('[name=' + prefix + '_datum_do]');
        var dOd  = denWrap.querySelector('[name=' + prefix + '_datum_od_den]');
        var dDo  = denWrap.querySelector('[name=' + prefix + '_datum_do_den]');
        if (cb.checked) {
            if (dtOd && dtOd.value) dOd.value = dtOd.value.split('T')[0];
            if (dtDo && dtDo.value) dDo.value = dtDo.value.split('T')[0];
            casWrap.style.display = 'none';
            denWrap.style.display = '';
            if (dtOd) dtOd.required = false;
            if (dtDo) dtDo.required = false;
            if (dOd)  dOd.required  = true;
            if (dDo)  dDo.required  = true;
        } else {
            if (dOd && dOd.value) dtOd.value = dOd.value + 'T00:00';
            if (dDo && dDo.value) dtDo.value = dDo.value + 'T00:00';
            casWrap.style.display = '';
            denWrap.style.display = 'none';
            if (dtOd) dtOd.required = true;
            if (dtDo) dtDo.required = true;
            if (dOd)  dOd.required  = false;
            if (dDo)  dDo.required  = false;
        }
    }
    function rsKalNav(sel) {
        var data = new URLSearchParams(new FormData(sel.form));
        data.set(sel.name, sel.value);
        window.location.href = window.location.pathname + '?' + data.toString() + '#rs-kalendar';
    }
    function rsTab(pid) {
        document.querySelectorAll('[data-rs-tab-panel]').forEach(function(el) { el.style.display = 'none'; });
        document.querySelectorAll('[data-rs-tab]').forEach(function(btn) {
            var active = btn.getAttribute('data-rs-tab') == pid;
            btn.style.background = active ? '#f4f8f4' : '#1a5c2a';
            btn.style.color      = active ? '#1a5c2a' : '#fff';
            btn.style.borderColor = '#1a5c2a';
        });
        var panel = document.getElementById('rs-kal-panel-' + pid);
        if (panel) panel.style.display = '';
    }
    function rsKalScrollDir(btn, dir) {
        var wrap = btn.closest('[data-rs-scroll-wrap]');
        if (!wrap) return;
        wrap.querySelector('.rs-kal-scroll').scrollBy({ left: dir * 150, behavior: 'smooth' });
    }
    function rsKalScrollUpdate(wrap) {
        var scr = wrap.querySelector('.rs-kal-scroll');
        var indR = wrap.querySelector('.rs-kal-ind-r');
        var indL = wrap.querySelector('.rs-kal-ind-l');
        var overflows = scr.scrollWidth > scr.clientWidth + 2;
        indR.style.display = (overflows && scr.scrollLeft + scr.clientWidth < scr.scrollWidth - 2) ? 'flex' : 'none';
        indL.style.display = scr.scrollLeft > 2 ? 'flex' : 'none';
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-rs-scroll-wrap]').forEach(function(wrap) {
            wrap.querySelector('.rs-kal-scroll').addEventListener('scroll', function() { rsKalScrollUpdate(wrap); });
            rsKalScrollUpdate(wrap);
        });
        window.addEventListener('resize', function() {
            document.querySelectorAll('[data-rs-scroll-wrap]').forEach(rsKalScrollUpdate);
        });
    });
    var rsLbGallery = [], rsLbIdx = 0;
    function rsLightbox(el) {
        var gallery = el.getAttribute('data-gallery');
        var imgs = document.querySelectorAll('[data-gallery="' + gallery + '"]');
        rsLbGallery = [];
        imgs.forEach(function(img) {
            rsLbGallery.push({ full: img.getAttribute('data-full'), cap: img.getAttribute('data-caption') || '' });
        });
        rsLbIdx = Array.from(imgs).indexOf(el);
        rsLbShow();
    }
    function rsLbFromArray(arr, idx) { rsLbGallery = arr; rsLbIdx = idx; rsLbShow(); }
    function rsLbShow() {
        var item = rsLbGallery[rsLbIdx];
        document.getElementById('rs-lb-img').src = item.full;
        var cap = document.getElementById('rs-lb-cap');
        cap.textContent = item.cap;
        cap.style.display = item.cap ? '' : 'none';
        document.getElementById('rs-lb-ctr').textContent = (rsLbIdx + 1) + ' / ' + rsLbGallery.length;
        document.getElementById('rs-lb-prev').style.display = rsLbGallery.length > 1 ? '' : 'none';
        document.getElementById('rs-lb-next').style.display = rsLbGallery.length > 1 ? '' : 'none';
        var lb = document.getElementById('rs-lb');
        lb.style.display = 'flex';
        lb.onclick = function(e) { if (e.target === this) rsLbClose(); };
    }
    function rsLbClose() { document.getElementById('rs-lb').style.display = 'none'; }
    function rsLbPrev() { rsLbIdx = (rsLbIdx - 1 + rsLbGallery.length) % rsLbGallery.length; rsLbShow(); }
    function rsLbNext() { rsLbIdx = (rsLbIdx + 1) % rsLbGallery.length; rsLbShow(); }
    document.addEventListener('keydown', function(e) {
        if (document.getElementById('rs-lb').style.display === 'none') return;
        if (e.key === 'Escape') rsLbClose();
        if (e.key === 'ArrowLeft') rsLbPrev();
        if (e.key === 'ArrowRight') rsLbNext();
    });
    function escHtml(s){ var d=document.createElement('div'); d.appendChild(document.createTextNode(s||'')); return d.innerHTML; }
    </script>
    <?php
    echo "</div>"; // .rs-wrap
    return ob_get_clean();
    } catch (\Throwable $e) {
        while (ob_get_level() > $ob_level) ob_end_clean();
        return '<div class="rs-wrap"><p style="background:#f8d7da;padding:12px;border-radius:4px;color:#721c24"><strong>Chyba v kalendáři:</strong> ' . esc_html($e->getMessage()) . ' (' . esc_html(basename($e->getFile())) . ':' . $e->getLine() . ')</p></div>';
    }
}

// ═══ FRONTEND: REZERVAČNÍ FORMULÁŘ [rs_formular] ═════════════════════════════

add_shortcode('rs_formular','rs_formular_sc');
function rs_formular_sc(): string {
    // Token-based management má přednost
    if (isset($_GET['rs_sprava'])) return rs_render_sprava_rezervace();

    // Přihlášení organizátoři patří do admin panelu, ne sem
    if (rs_ma_pravo('vedeni')) {
        $admin_url = rs_admin_url();
        ob_start();
        rs_css();
        echo "<div class='rs-wrap'>";
        echo "<div style='padding:20px;background:#f8f9fa;border:1px solid #ddd;border-radius:6px;text-align:center'>";
        echo "<p style='margin:0 0 12px;font-size:15px'>Tento rezervační formulář je určený pro zájemce zvenčí. Ty patříš k vedení oddílů – použij interní formulář pro členy střediska.</p>";
        echo "<a href='" . esc_url($admin_url) . "#rs-interni' class='rs-btn rs-btn-primary'>Interní formulář pro členy střediska</a>";
        echo "</div></div>";
        return ob_get_clean();
    }

    $zprava = '';
    $hotovo = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_formular_odeslat'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rs_formular')) {
            $zprava = rs_alert('Neplatný token. Zkuste znovu.','error');
        } else {
            $result = rs_zpracuj_externi_formular();
            if (is_string($result)) { $zprava = $result; }
            else { $hotovo = true; $zprava = rs_alert('Vaši žádost o rezervaci jsme dostali. Podrobnosti jsme vám zaslali na e-mail. Rezervace podléhá schválení. Až ji schválíme, přijde vám e-mail s potvrzením rezervace.'); }
        }
    }

    $old         = (!$hotovo && $zprava) ? $_POST : [];
    $old_typ     = $old['rez_typ']          ?? 'fyzicka';
    $old_prostor = (int)($old['ext_prostor_id'] ?? 0);
    $old_segs    = array_map('intval', (array)($old['ext_segmenty'] ?? []));
    $old_cd      = !empty($old['ext_cely_den']);
    $ov          = fn(string $k, string $d = '') => ' value="' . esc_attr($old[$k] ?? $d) . '"';

    $prostory = rs_get_prostory();
    ob_start();
    rs_css();
    echo "<div class='rs-wrap'>";

    if ($hotovo) {
        echo $zprava;
        echo "</div>";
        return ob_get_clean();
    }

    echo "<h3 style='margin-bottom:16px'>Žádost o rezervaci objektu</h3>{$zprava}";

    echo "<form method='post' id='rs-ext-form'>" . wp_nonce_field('rs_formular','_wpnonce',true,false);
    echo "<input type='hidden' name='rs_formular_odeslat' value='1'>";
    // Honeypot: boti toto pole vyplní, lidé ne
    echo "<div style='position:absolute;left:-9999px;height:0;overflow:hidden' aria-hidden='true'><label>Nevyplňujte toto pole<input type='text' name='rs_hp_email' tabindex='-1' autocomplete='off'></label></div>";
    // Časová kontrola: podepsaný timestamp pro detekci okamžitého odeslání
    $rs_form_ts = time();
    $rs_form_sig = hash_hmac('sha256', (string)$rs_form_ts, wp_salt('auth'));
    echo "<input type='hidden' name='rs_form_ts' value='" . esc_attr($rs_form_ts) . "'>";
    echo "<input type='hidden' name='rs_form_sig' value='" . esc_attr($rs_form_sig) . "'>";

    // Typ rezervujícího
    echo "<div class='rs-card'><h4 class='rs-card-title'>Kdo rezervuje?</h4>";
    echo "<div class='rs-form-group'>";
    echo "<label style='font-weight:normal;margin-right:16px'><input type='radio' name='rez_typ' value='fyzicka'" . ($old_typ !== 'pravnicka' ? ' checked' : '') . " onchange='rsRezTypChange(this.value)'> Fyzická osoba</label>";
    echo "<label style='font-weight:normal'><input type='radio' name='rez_typ' value='pravnicka'" . ($old_typ === 'pravnicka' ? ' checked' : '') . " onchange='rsRezTypChange(this.value)'> Právnická osoba</label>";
    echo "</div>";

    // Fyzická osoba
    echo "<div id='rs-ext-fyzicka'" . ($old_typ === 'pravnicka' ? " style='display:none'" : '') . ">";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Jméno *</label><input type='text' name='fyzicka_jmeno'" . $ov('fyzicka_jmeno') . " required minlength='2' data-rs-cond></div>";
    echo "<div class='rs-form-group'><label>Příjmení *</label><input type='text' name='fyzicka_prijmeni'" . $ov('fyzicka_prijmeni') . " required minlength='2' data-rs-cond></div>";
    echo "</div>";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Datum narození *</label><input type='date' name='fyzicka_datum_nar'" . $ov('fyzicka_datum_nar') . " required data-rs-cond></div>";
    echo "</div>";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group' style='flex:3'><label>Ulice *</label><input type='text' name='fyzicka_ulice'" . $ov('fyzicka_ulice') . " required data-rs-cond placeholder='Náměstí míru'></div>";
    echo "<div class='rs-form-group' style='flex:1'><label>Č. popisné *</label><input type='text' name='fyzicka_cp'" . $ov('fyzicka_cp') . " required data-rs-cond placeholder='12'></div>";
    echo "</div>";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group' style='flex:1'><label>PSČ *</label><input type='text' name='fyzicka_psc'" . $ov('fyzicka_psc') . " required minlength='3' data-rs-cond placeholder='503 51'></div>";
    echo "<div class='rs-form-group' style='flex:3'><label>Obec *</label><input type='text' name='fyzicka_obec'" . $ov('fyzicka_obec') . " required data-rs-cond placeholder='Hradec Králové'></div>";
    echo "</div>";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'>";
    echo "<label>Mobil * <span style='font-weight:normal;font-size:12px;color:#666'>(9 číslic bez předvolby)</span></label>";
    echo "<div style='display:flex;align-items:center;gap:4px'>";
    echo "<div style='display:flex;align-items:center;border:1px solid #ccc;border-radius:3px;padding:0 4px 0 8px;background:#f5f5f5'><span style='color:#555;font-size:13px'>+</span><input type='text' name='fyzicka_predvolba'" . $ov('fyzicka_predvolba','420') . " pattern='[0-9]{1,4}' maxlength='4' style='border:none;outline:none;background:transparent;width:38px;padding:7px 2px;font-size:14px' required data-rs-cond title='Mezinárodní předvolba (např. 420 pro ČR)'></div>";
    echo "<input type='tel' name='fyzicka_mobil'" . $ov('fyzicka_mobil') . " pattern='[0-9]{9}' maxlength='9' minlength='9' placeholder='123456789' required data-rs-cond style='flex:1' title='9 číslic bez předvolby'>";
    echo "</div></div>";
    echo "<div class='rs-form-group'><label>E-mail *</label><input type='email' name='fyzicka_email'" . $ov('fyzicka_email') . " required data-rs-cond></div>";
    echo "</div>";
    echo "</div>"; // fyzicka

    // Právnická osoba
    echo "<div id='rs-ext-pravnicka'" . ($old_typ !== 'pravnicka' ? " style='display:none'" : '') . ">";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>IČO *</label><div style='display:flex;gap:8px;align-items:center'><input type='text' name='pravnicka_ico' id='rs-ico' maxlength='8' style='width:130px'" . $ov('pravnicka_ico') . "><button type='button' class='rs-btn rs-btn-secondary rs-btn-sm' onclick='rsAresLoad()'>🔍 Načíst z ARES</button></div></div>";
    echo "</div>";
    echo "<div class='rs-form-group'><label>Název organizace *</label><input type='text' name='pravnicka_nazev' id='rs-nazev'" . $ov('pravnicka_nazev') . " data-rs-cond></div>";
    echo "<div class='rs-form-group'><label>Sídlo *</label><input type='text' name='pravnicka_sidlo' id='rs-sidlo'" . $ov('pravnicka_sidlo') . " data-rs-cond></div>";
    echo "<div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Kontaktní osoba *</label><input type='text' name='pravnicka_kontakt'" . $ov('pravnicka_kontakt') . " minlength='2' data-rs-cond></div>";
    echo "<div class='rs-form-group'>";
    echo "<label>Mobil * <span style='font-weight:normal;font-size:12px;color:#666'>(9 číslic bez předvolby)</span></label>";
    echo "<div style='display:flex;align-items:center;gap:4px'>";
    echo "<div style='display:flex;align-items:center;border:1px solid #ccc;border-radius:3px;padding:0 4px 0 8px;background:#f5f5f5'><span style='color:#555;font-size:13px'>+</span><input type='text' name='pravnicka_predvolba'" . $ov('pravnicka_predvolba','420') . " pattern='[0-9]{1,4}' maxlength='4' style='border:none;outline:none;background:transparent;width:38px;padding:7px 2px;font-size:14px' data-rs-cond title='Mezinárodní předvolba'></div>";
    echo "<input type='tel' name='pravnicka_mobil'" . $ov('pravnicka_mobil') . " pattern='[0-9]{9}' maxlength='9' minlength='9' placeholder='123456789' data-rs-cond style='flex:1' title='9 číslic bez předvolby'>";
    echo "</div></div>";
    echo "<div class='rs-form-group'><label>E-mail *</label><input type='email' name='pravnicka_email'" . $ov('pravnicka_email') . " data-rs-cond></div>";
    echo "</div>";
    echo "</div>"; // pravnicka
    echo "</div>"; // card

    // Prostor + termín
    echo "<div class='rs-card'><h4 class='rs-card-title'>Objekt a termín</h4>";
    echo "<div class='rs-form-group'><label>Objekt *</label><select name='ext_prostor_id' id='rs-ext-prostor' onchange='rsExtProstorChange(this.value)' required>";
    echo "<option value=''>– vyberte –</option>";
    foreach ($prostory as $p) {
        if (rs_je_ext_vypnuto($p->ID)) continue;
        $sel = $old_prostor === $p->ID ? ' selected' : '';
        echo "<option value='{$p->ID}'{$sel}>" . esc_html($p->post_title) . "</option>";
    }
    echo "</select></div>";

    echo "<div id='rs-ext-seg-wrap' style='display:none' class='rs-form-group'><label>Části (nevyberte nic = celý objekt)</label><div id='rs-ext-seg-list'></div></div>";

    $cas_style = $old_cd ? " style='display:none'" : '';
    $den_style = $old_cd ? '' : " style='display:none'";
    echo "<div class='rs-form-group' style='margin-bottom:6px'><label style='display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400'><input type='checkbox' name='ext_cely_den'" . ($old_cd ? ' checked' : '') . " onchange='rsCelyDen(this,\"ext\")' style='width:auto'> Celý den</label></div>";
    echo "<div id='rs-ext-cas-wrap'{$cas_style}><div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Datum a čas od *</label><input type='datetime-local' name='ext_datum_od' step='900'" . ($old_cd ? '' : ' required') . $ov('ext_datum_od') . " onchange='var d=document.querySelector(\"[name=ext_datum_do]\");if(d){d.min=this.value;if(!d.value||d.value<this.value)d.value=this.value;}rsCheckVolno();'></div>";
    echo "<div class='rs-form-group'><label>Datum a čas do *</label><input type='datetime-local' name='ext_datum_do' step='900'" . ($old_cd ? '' : ' required') . $ov('ext_datum_do') . " onchange='rsCheckVolno();'></div>";
    echo "</div></div>";
    echo "<div id='rs-ext-den-wrap'{$den_style}><div class='rs-form-row'>";
    echo "<div class='rs-form-group'><label>Datum od *</label><input type='date' name='ext_datum_od_den'" . ($old_cd ? ' required' : '') . $ov('ext_datum_od_den') . " onchange='var d=document.querySelector(\"[name=ext_datum_do_den]\");if(d){d.min=this.value;if(!d.value||d.value<this.value)d.value=this.value;}rsCheckVolno();'></div>";
    echo "<div class='rs-form-group'><label>Datum do *</label><input type='date' name='ext_datum_do_den'" . ($old_cd ? ' required' : '') . $ov('ext_datum_do_den') . " onchange='rsCheckVolno();'></div>";
    echo "</div></div>";
    echo "<div id='rs-ext-avail' style='font-size:13px;margin-top:2px;margin-bottom:10px'></div>";
    echo "<div class='rs-form-group'><label>Počet osob *</label><input type='number' name='ext_pocet'" . $ov('ext_pocet', '1') . " min='1' max='500' required style='width:100px'></div>";

    if (get_option('rs_vzdusne_aktivni') === '1') {
        echo "<div class='rs-form-group' style='background:#fff8e1;border:1px solid #ffc107;border-radius:3px;padding:10px'>";
        echo "<p style='margin:0;font-size:13px'><strong>Informace o ubytovacím poplatku:</strong> " . wp_kses_post(get_option('rs_vzdusne_info','')) . " Po potvrzení rezervace budete vyzváni k vyplnění jmen ubytovaných osob.</p>";
        echo "</div>";
    }

    $old_poznamka = esc_textarea($old['ext_poznamka'] ?? '');
    echo "<div class='rs-form-group'><label>Poznámka / dotaz</label><textarea name='ext_poznamka' rows='3'>{$old_poznamka}</textarea></div>";
    echo "</div>"; // card

    echo "<div class='rs-btn-row'><button type='submit' class='rs-btn rs-btn-primary'>Odeslat žádost o rezervaci</button></div>";
    echo "</form>";

    $doplnujici = get_option('rs_doplnujici_info_ext', get_option('rs_doplnujici_info',''));
    if ($doplnujici) echo "<div style='margin-top:24px;padding:14px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;font-size:13px'>" . wp_kses_post(nl2br($doplnujici)) . "</div>";

    echo "</div>"; // .rs-wrap
    ?>
    <script>
    function rsRezTypChange(val){
        document.getElementById('rs-ext-fyzicka').style.display  = val==='fyzicka'   ? '' : 'none';
        document.getElementById('rs-ext-pravnicka').style.display = val==='pravnicka' ? '' : 'none';
        document.querySelectorAll('#rs-ext-fyzicka [data-rs-cond]').forEach(function(el){el.required = val==='fyzicka';});
        document.querySelectorAll('#rs-ext-pravnicka [data-rs-cond]').forEach(function(el){el.required = val==='pravnicka';});
    }
    var rsExtSegData = <?php
        $sd = [];
        foreach ($prostory as $p) {
            if (rs_ma_segmenty($p->ID))
                foreach (rs_get_segmenty($p->ID) as $s) {
                    if (rs_je_ext_vypnuto($s->ID)) continue;
                    $sd[$p->ID][] = ['id'=>$s->ID,'nazev'=>$s->post_title];
                }
        }
        echo json_encode($sd);
    ?>;
    function rsExtProstorChange(pid){
        var wrap=document.getElementById('rs-ext-seg-wrap');
        var list=document.getElementById('rs-ext-seg-list');
        list.innerHTML='';
        if(rsExtSegData[pid]){
            rsExtSegData[pid].forEach(function(s){list.innerHTML+='<label style="display:block;margin-bottom:4px"><input type="checkbox" name="ext_segmenty[]" value="'+s.id+'" onchange="rsCheckVolno()"> '+s.nazev+'</label>';});
            wrap.style.display='';
        } else { wrap.style.display='none'; }
        rsCheckVolno();
    }
    var rsCheckVolnoNonce = '<?php echo wp_create_nonce('rs_public'); ?>';
    var rsAjaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
    var rsCheckVolnoTimer = null;
    function rsCheckVolno(){
        clearTimeout(rsCheckVolnoTimer);
        rsCheckVolnoTimer = setTimeout(function(){
            var pid = document.getElementById('rs-ext-prostor').value;
            var el  = document.getElementById('rs-ext-avail');
            if(!pid){ el.innerHTML=''; return; }
            var cd  = document.querySelector('[name=ext_cely_den]').checked;
            var od  = cd ? (document.querySelector('[name=ext_datum_od_den]')||{}).value : (document.querySelector('[name=ext_datum_od]')||{}).value;
            var do_ = cd ? (document.querySelector('[name=ext_datum_do_den]')||{}).value : (document.querySelector('[name=ext_datum_do]')||{}).value;
            if(!od||!do_){ el.innerHTML=''; return; }
            el.innerHTML='<span style="color:#888">Kontroluji dostupnost…</span>';
            var segs = Array.from(document.querySelectorAll('[name="ext_segmenty[]"]:checked')).map(function(c){return '&seg_ids[]='+c.value;}).join('');
            fetch(rsAjaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'action=rs_check_volno&nonce='+rsCheckVolnoNonce+'&prostor_id='+encodeURIComponent(pid)+'&datum_od='+encodeURIComponent(od)+'&datum_do='+encodeURIComponent(do_)+'&cely_den='+(cd?'1':'')+segs})
            .then(function(r){return r.json();})
            .then(function(d){
                if(!d.success||d.data===null){el.innerHTML='';return;}
                if(d.data.volno){
                    el.innerHTML='<span style="color:#2e7d32;font-weight:500">✓ Termín je volný</span>';
                    document.querySelectorAll('.rs-alert').forEach(function(a){a.style.display='none';});
                } else {
                    el.innerHTML='<span style="color:#c62828;font-weight:500">✗ Termín není volný – vyberte jiný</span>';
                }
            }).catch(function(){el.innerHTML='';});
        }, 400);
    }
    document.addEventListener('DOMContentLoaded', function(){
        rsRezTypChange('<?php echo esc_js($old_typ); ?>');
        <?php if ($old_prostor): ?>
        rsExtProstorChange(<?php echo $old_prostor; ?>);
        var oldSegs = <?php echo json_encode($old_segs); ?>;
        setTimeout(function(){
            oldSegs.forEach(function(sid){
                var cb = document.querySelector('[name="ext_segmenty[]"][value="'+sid+'"]');
                if(cb) cb.checked = true;
            });
            rsCheckVolno();
        }, 100);
        <?php endif; ?>
    });
    function rsAresLoad(){
        var ico=document.getElementById('rs-ico').value.replace(/\D/g,'');
        if(ico.length<7){alert('Zadejte IČO');return;}
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=rs_ares&ico='+encodeURIComponent(ico)+'&nonce=<?php echo wp_create_nonce('rs_public'); ?>'})
        .then(r=>r.json()).then(function(d){
            if(d.success){document.getElementById('rs-nazev').value=d.data.nazev;document.getElementById('rs-sidlo').value=d.data.sidlo;}
            else{alert('ARES: '+d.data);}
        });
    }
    function rsCelyDen(cb, prefix) {
        var casWrap = document.getElementById('rs-' + prefix + '-cas-wrap');
        var denWrap = document.getElementById('rs-' + prefix + '-den-wrap');
        if (!casWrap || !denWrap) return;
        var dtOd = casWrap.querySelector('[name=' + prefix + '_datum_od]');
        var dtDo = casWrap.querySelector('[name=' + prefix + '_datum_do]');
        var dOd  = denWrap.querySelector('[name=' + prefix + '_datum_od_den]');
        var dDo  = denWrap.querySelector('[name=' + prefix + '_datum_do_den]');
        if (cb.checked) {
            if (dtOd && dtOd.value) dOd.value = dtOd.value.split('T')[0];
            if (dtDo && dtDo.value) dDo.value = dtDo.value.split('T')[0];
            casWrap.style.display = 'none';
            denWrap.style.display = '';
            if (dtOd) dtOd.required = false;
            if (dtDo) dtDo.required = false;
            if (dOd)  dOd.required  = true;
            if (dDo)  dDo.required  = true;
        } else {
            if (dOd && dOd.value) dtOd.value = dOd.value + 'T00:00';
            if (dDo && dDo.value) dtDo.value = dDo.value + 'T00:00';
            casWrap.style.display = '';
            denWrap.style.display = 'none';
            if (dtOd) dtOd.required = true;
            if (dtDo) dtDo.required = true;
            if (dOd)  dOd.required  = false;
            if (dDo)  dDo.required  = false;
        }
    }
    </script>
    <?php
    return ob_get_clean();
}

function rs_zpracuj_externi_formular() {
    // Honeypot: pokud je pole vyplněné, jde o bota
    if (!empty($_POST['rs_hp_email'])) return rs_alert('Formulář se nepodařilo odeslat.','error');
    // Časová kontrola: formulář musí být odeslán nejdříve 3 sekundy po načtení
    $ts  = (int)($_POST['rs_form_ts'] ?? 0);
    $sig = sanitize_text_field($_POST['rs_form_sig'] ?? '');
    $expected = hash_hmac('sha256', (string)$ts, wp_salt('auth'));
    if (!$ts || !hash_equals($expected, $sig) || (time() - $ts) < 3 || (time() - $ts) > 3600) {
        return rs_alert('Formulář se nepodařilo odeslat. Zkuste stránku obnovit a odeslat znovu.','error');
    }

    $rez_typ    = sanitize_key($_POST['rez_typ'] ?? 'fyzicka');
    $prostor_id = (int)($_POST['ext_prostor_id'] ?? 0);
    $seg_ids    = array_map('intval',(array)($_POST['ext_segmenty'] ?? []));
    $pocet      = max(1,(int)($_POST['ext_pocet'] ?? 1));
    $poznamka   = sanitize_textarea_field($_POST['ext_poznamka'] ?? '');
    if (!empty($_POST['ext_cely_den'])) {
        $den_od   = sanitize_text_field($_POST['ext_datum_od_den'] ?? '');
        $den_do   = sanitize_text_field($_POST['ext_datum_do_den'] ?? '');
        $datum_od = $den_od ? $den_od . ' 00:00:00' : '';
        $datum_do = $den_do ? $den_do . ' 23:59:00' : '';
    } else {
        $raw_od   = sanitize_text_field($_POST['ext_datum_od'] ?? '');
        $raw_do   = sanitize_text_field($_POST['ext_datum_do'] ?? '');
        $datum_od = $raw_od ? str_replace('T',' ',$raw_od) . ':00' : '';
        $datum_do = $raw_do ? str_replace('T',' ',$raw_do) . ':00' : '';
    }
    if (!$prostor_id || !$datum_od || !$datum_do) return rs_alert('Vyplňte prosím všechna povinná pole.','error');
    if (strtotime($datum_od) >= strtotime($datum_do)) return rs_alert('Datum konce musí být po datu začátku.','error');

    // Kontaktní validace před vytvořením rezervace
    if ($rez_typ === 'fyzicka') {
        $f_jmeno    = sanitize_text_field($_POST['fyzicka_jmeno']??'');
        $f_prijmeni = sanitize_text_field($_POST['fyzicka_prijmeni']??'');
        $f_datum_nar = sanitize_text_field($_POST['fyzicka_datum_nar']??'');
        $f_ulice    = sanitize_text_field($_POST['fyzicka_ulice']??'');
        $f_cp       = sanitize_text_field($_POST['fyzicka_cp']??'');
        $f_psc      = sanitize_text_field($_POST['fyzicka_psc']??'');
        $f_obec     = sanitize_text_field($_POST['fyzicka_obec']??'');
        $f_predv    = preg_replace('/[^0-9]/', '', $_POST['fyzicka_predvolba']??'420') ?: '420';
        $f_mobil    = preg_replace('/\s+/', '', sanitize_text_field($_POST['fyzicka_mobil']??''));
        $f_email    = sanitize_email($_POST['fyzicka_email']??'');
        if (mb_strlen($f_jmeno) < 2 || mb_strlen($f_prijmeni) < 2)
            return rs_alert('Jméno a příjmení musí mít každé aspoň 2 znaky.','error');
        if (!$f_ulice || !$f_cp || !$f_psc || !$f_obec)
            return rs_alert('Vyplňte prosím všechna adresní pole (ulice, č. popisné, PSČ, obec).','error');
        if (!preg_match('/^[0-9]{9}$/', $f_mobil))
            return rs_alert('Telefonní číslo musí mít přesně 9 číslic (bez předvolby).','error');
        if (!is_email($f_email))
            return rs_alert('Zadejte platnou e-mailovou adresu.','error');
    } else {
        $p_nazev    = sanitize_text_field($_POST['pravnicka_nazev']??'');
        $p_ico      = sanitize_text_field($_POST['pravnicka_ico']??'');
        $p_sidlo    = sanitize_text_field($_POST['pravnicka_sidlo']??'');
        $p_kontakt  = sanitize_text_field($_POST['pravnicka_kontakt']??'');
        $p_predv    = preg_replace('/[^0-9]/', '', $_POST['pravnicka_predvolba']??'420') ?: '420';
        $p_mobil    = preg_replace('/\s+/', '', sanitize_text_field($_POST['pravnicka_mobil']??''));
        $p_email    = sanitize_email($_POST['pravnicka_email']??'');
        if (!preg_match('/^[0-9]{9}$/', $p_mobil))
            return rs_alert('Telefonní číslo musí mít přesně 9 číslic (bez předvolby).','error');
        if (!is_email($p_email))
            return rs_alert('Zadejte platnou e-mailovou adresu.','error');
    }

    if (!rs_je_volno($prostor_id,$seg_ids,$datum_od,$datum_do)) return rs_alert('Zvolený termín bohužel není volný. Vyberte prosím jiný termín.','error');

    $token = rs_token();
    $rid   = rs_vytvor_rezervaci_post($prostor_id,$seg_ids,$datum_od,$datum_do,$pocet,'externi','cekajici',0,$poznamka,'', $token);
    if (!$rid) return rs_alert('Chyba při vytváření rezervace. Zkuste to prosím znovu.','error');

    update_post_meta($rid,'rs_rez_typ',$rez_typ);
    if ($rez_typ === 'fyzicka') {
        update_post_meta($rid,'rs_jmeno',          $f_jmeno);
        update_post_meta($rid,'rs_prijmeni',        $f_prijmeni);
        update_post_meta($rid,'rs_datum_narozeni',  $f_datum_nar);
        update_post_meta($rid,'rs_ulice',           $f_ulice);
        update_post_meta($rid,'rs_cp',              $f_cp);
        update_post_meta($rid,'rs_psc',             $f_psc);
        update_post_meta($rid,'rs_obec',            $f_obec);
        update_post_meta($rid,'rs_tel_predvolba',   $f_predv);
        update_post_meta($rid,'rs_mobil',           $f_mobil);
        update_post_meta($rid,'rs_email',           $f_email);
    } else {
        update_post_meta($rid,'rs_nazev',           $p_nazev);
        update_post_meta($rid,'rs_ico',             $p_ico);
        update_post_meta($rid,'rs_sidlo',           $p_sidlo);
        update_post_meta($rid,'rs_kontakt_jmeno',   $p_kontakt);
        update_post_meta($rid,'rs_tel_predvolba',   $p_predv);
        update_post_meta($rid,'rs_mobil',           $p_mobil);
        update_post_meta($rid,'rs_email',           $p_email);
    }
    rs_notifikuj_nova($rid);
    return true;
}

// ═══ SPRÁVA REZERVACE PŘES TOKEN ═════════════════════════════════════════════

function rs_render_sprava_rezervace(): string {
    $token = sanitize_text_field($_GET['rs_sprava'] ?? '');
    if (!$token) return rs_alert('Neplatný odkaz.','error');

    $found = get_posts(['post_type'=>'rs_rezervace','post_status'=>'publish','numberposts'=>1,'meta_query'=>[['key'=>'rs_token','value'=>$token,'compare'=>'=']]]);
    if (!$found) return rs_alert('Rezervace nenalezena nebo odkaz vypršel.','error');
    $rid  = $found[0]->ID;
    $stav = get_post_meta($rid,'rs_stav',true);

    $zprava = '';

    // Zpracování POST akcí
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rs_sprava_action'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rs_sprava_' . $token)) {
            $zprava = rs_alert('Neplatný token.','error');
        } else {
            $action = sanitize_key($_POST['rs_sprava_action']);
            if ($action === 'zrusit' && $stav !== 'zrusena') {
                update_post_meta($rid,'rs_stav','zrusena');
                rs_notifikuj_zruseni($rid);
                $stav   = 'zrusena';
                $zprava = rs_alert('Rezervace byla zrušena.');
            } elseif ($action === 'upravit_kontakt' && $stav !== 'zrusena') {
                $r_typ = get_post_meta($rid,'rs_rez_typ',true);
                update_post_meta($rid,'rs_pocet_lidi',(int)($_POST['pocet_lidi'] ?? 1));
                $up_predv = preg_replace('/[^0-9]/', '', $_POST['predvolba'] ?? '420') ?: '420';
                $up_mobil = preg_replace('/\s+/', '', sanitize_text_field($_POST['mobil'] ?? ''));
                update_post_meta($rid,'rs_tel_predvolba', $up_predv);
                update_post_meta($rid,'rs_mobil', $up_mobil);
                update_post_meta($rid,'rs_email',sanitize_email($_POST['email'] ?? ''));
                if ($r_typ === 'pravnicka') {
                    update_post_meta($rid,'rs_kontakt_jmeno',sanitize_text_field($_POST['kontakt_jmeno'] ?? ''));
                } else {
                    update_post_meta($rid,'rs_jmeno',sanitize_text_field($_POST['jmeno'] ?? ''));
                    update_post_meta($rid,'rs_prijmeni',sanitize_text_field($_POST['prijmeni'] ?? ''));
                    update_post_meta($rid,'rs_datum_narozeni',sanitize_text_field($_POST['datum_narozeni'] ?? ''));
                    update_post_meta($rid,'rs_ulice',sanitize_text_field($_POST['ulice'] ?? ''));
                    update_post_meta($rid,'rs_cp',sanitize_text_field($_POST['cp'] ?? ''));
                    update_post_meta($rid,'rs_psc',sanitize_text_field($_POST['psc'] ?? ''));
                    update_post_meta($rid,'rs_obec',sanitize_text_field($_POST['obec'] ?? ''));
                }
                $zprava = rs_alert('Údaje byly uloženy.');
            } elseif ($action === 'ulozit_ucastniky') {
                $ucast = rs_uloz_ucastniky($rid);
                if (is_array($ucast) && !empty($ucast)) {
                    rs_notifikuj_ucastnici($rid,$ucast);
                    $zprava = rs_alert('Seznam ubytovaných byl uložen a odeslán správci.');
                } else {
                    $zprava = rs_alert('Zadejte alespoň jednoho účastníka.','error');
                }
            }
        }
    }

    $pid     = (int)get_post_meta($rid,'rs_prostor_id',true);
    $segs    = (array)get_post_meta($rid,'rs_segmenty_ids',true);
    $od      = get_post_meta($rid,'rs_datum_od',true);
    $do_     = get_post_meta($rid,'rs_datum_do',true);
    $pocet   = (int)get_post_meta($rid,'rs_pocet_lidi',true);
    $cena    = (float)get_post_meta($rid,'rs_cena_celkem',true);
    $ucast   = get_post_meta($rid,'rs_ucastnici',true);

    ob_start();
    rs_css();
    echo "<div class='rs-wrap'>";
    echo "<h3>Správa rezervace</h3>{$zprava}";

    echo "<div class='rs-card'>";
    echo "<h4 class='rs-card-title'>" . esc_html(get_the_title($pid)) . " – " . rs_stav_badge($stav) . "</h4>";
    $r_typ = get_post_meta($rid,'rs_rez_typ',true);
    echo "<table class='rs-table' style='max-width:500px'>";
    echo "<tr><th style='width:140px'>Objekt</th><td>" . esc_html(rs_prostor_label($pid, array_filter($segs))) . "</td></tr>";
    echo "<tr><th>Termín</th><td>" . esc_html(rs_format_datum($od)) . " – " . esc_html(rs_format_datum($do_)) . "</td></tr>";
    echo "<tr><th>Počet osob</th><td>" . $pocet . "</td></tr>";
    echo "<tr><th>Stav</th><td>" . rs_stav_badge($stav) . "</td></tr>";
    if ($cena > 0) echo "<tr><th>Cena</th><td>" . number_format($cena,0,'.',' ') . " Kč</td></tr>";
    echo "</table>";

    // Zrušit rezervaci
    if ($stav === 'cekajici') {
        echo "<form method='post' style='margin-top:16px' onsubmit='return confirm(\"Opravdu zrušit rezervaci?\")'>" . wp_nonce_field('rs_sprava_' . $token,'_wpnonce',true,false);
        echo "<input type='hidden' name='rs_sprava_action' value='zrusit'>";
        echo "<button type='submit' class='rs-btn rs-btn-danger'>Zrušit rezervaci</button></form>";
    }
    echo "</div>";

    // Upravit rezervaci
    if ($stav !== 'zrusena') {
        echo "<div class='rs-card'><h4 class='rs-card-title'>Upravit rezervaci</h4>";
        echo "<form method='post'>" . wp_nonce_field('rs_sprava_' . $token,'_wpnonce',true,false);
        echo "<input type='hidden' name='rs_sprava_action' value='upravit_kontakt'>";
        echo "<div class='rs-form-row'>";
        $sp_predv = esc_attr(get_post_meta($rid,'rs_tel_predvolba',true) ?: '420');
        $sp_mobil = esc_attr(get_post_meta($rid,'rs_mobil',true));
        $sp_email = esc_attr(get_post_meta($rid,'rs_email',true));
        if ($r_typ === 'pravnicka') {
            echo "<div class='rs-form-group'><label>Kontaktní osoba *</label><input type='text' name='kontakt_jmeno' value='" . esc_attr(get_post_meta($rid,'rs_kontakt_jmeno',true)) . "' required minlength='2'></div>";
        } else {
            echo "<div class='rs-form-group'><label>Jméno *</label><input type='text' name='jmeno' value='" . esc_attr(get_post_meta($rid,'rs_jmeno',true)) . "' required minlength='2'></div>";
            echo "<div class='rs-form-group'><label>Příjmení *</label><input type='text' name='prijmeni' value='" . esc_attr(get_post_meta($rid,'rs_prijmeni',true)) . "' required minlength='2'></div>";
            echo "<div class='rs-form-group'><label>Datum narození</label><input type='date' name='datum_narozeni' value='" . esc_attr(get_post_meta($rid,'rs_datum_narozeni',true)) . "'></div>";
            $sp_ulice = esc_attr(get_post_meta($rid,'rs_ulice',true));
            $sp_cp    = esc_attr(get_post_meta($rid,'rs_cp',true));
            $sp_psc   = esc_attr(get_post_meta($rid,'rs_psc',true));
            $sp_obec  = esc_attr(get_post_meta($rid,'rs_obec',true));
            $sp_old_b = get_post_meta($rid,'rs_bydliste',true);
            if (!$sp_ulice && $sp_old_b) echo "<p style='font-size:12px;color:#888;margin:0 0 6px'>Původní adresa: <em>" . esc_html($sp_old_b) . "</em></p>";
            echo "<div class='rs-form-row'>";
            echo "<div class='rs-form-group' style='flex:3'><label>Ulice</label><input type='text' name='ulice' value='{$sp_ulice}'></div>";
            echo "<div class='rs-form-group' style='flex:1'><label>Č. popisné</label><input type='text' name='cp' value='{$sp_cp}'></div>";
            echo "</div><div class='rs-form-row'>";
            echo "<div class='rs-form-group' style='flex:1'><label>PSČ</label><input type='text' name='psc' value='{$sp_psc}'></div>";
            echo "<div class='rs-form-group' style='flex:3'><label>Obec</label><input type='text' name='obec' value='{$sp_obec}'></div>";
            echo "</div>";
        }
        echo "<div class='rs-form-group'>";
        echo "<label>Mobil * <span style='font-weight:normal;font-size:12px;color:#666'>(9 číslic bez předvolby)</span></label>";
        echo "<div style='display:flex;align-items:center;gap:4px'>";
        echo "<div style='display:flex;align-items:center;border:1px solid #ccc;border-radius:3px;padding:0 4px 0 8px;background:#f5f5f5'><span style='color:#555;font-size:13px'>+</span><input type='text' name='predvolba' value='{$sp_predv}' pattern='[0-9]{1,4}' maxlength='4' style='border:none;outline:none;background:transparent;width:38px;padding:7px 2px;font-size:14px' required></div>";
        echo "<input type='tel' name='mobil' value='{$sp_mobil}' pattern='[0-9]{9}' maxlength='9' minlength='9' placeholder='123456789' required style='flex:1' title='9 číslic bez předvolby'>";
        echo "</div></div>";
        echo "<div class='rs-form-group'><label>E-mail *</label><input type='email' name='email' value='{$sp_email}' required></div>";
        echo "<div class='rs-form-group'><label>Počet osob *</label><input type='number' name='pocet_lidi' min='1' value='" . esc_attr((string)$pocet) . "' required style='max-width:100px'></div>";
        echo "</div>";
        echo "<button type='submit' class='rs-btn rs-btn-primary'>💾 Uložit změny</button>";
        echo "</form></div>";
    }

    // Formulář pro seznam ubytovaných (vzdušné)
    if (get_option('rs_vzdusne_aktivni') === '1' && $stav === 'potvrzena') {
        echo "<div class='rs-card'><h4 class='rs-card-title'>Seznam ubytovaných osob</h4>";
        echo "<p style='font-size:13px'>Nejpozději v den zahájení pobytu vyplňte jména a údaje ubytovaných osob pro potřeby ubytovacího poplatku.</p>";
        echo "<form method='post'>" . wp_nonce_field('rs_sprava_' . $token,'_wpnonce',true,false);
        echo "<input type='hidden' name='rs_sprava_action' value='ulozit_ucastniky'>";
        $saved = is_array($ucast) ? $ucast : [[]];
        echo "<div id='rs-ucast-list'>";
        foreach ($saved as $i => $u) {
            echo rs_ucastnik_row($i, $u);
        }
        echo "</div>";
        echo "<button type='button' class='rs-btn rs-btn-secondary rs-btn-sm' onclick='rsAddUcast()' style='margin-bottom:12px'>➕ Přidat osobu</button><br>";
        echo "<button type='submit' class='rs-btn rs-btn-primary'>💾 Uložit seznam</button>";
        echo "</form></div>";
    }

    echo "</div>"; // .rs-wrap
    ?>
    <script>
    var rsUcastIdx = <?php echo max(count(is_array($ucast) ? $ucast : [[]]), 1); ?>;
    var rsUcastRowTpl = <?php echo json_encode(rs_ucastnik_row('__I__', [])); ?>;
    function rsAddUcast(){
        var list = document.getElementById('rs-ucast-list');
        var div = document.createElement('div');
        div.innerHTML = rsUcastRowTpl.replace(/__I__/g, rsUcastIdx++);
        list.appendChild(div);
    }
    </script>
    <?php
    return ob_get_clean();
}

function rs_ucastnik_row(int|string $i, array $u): string {
    $j  = esc_attr($u['jmeno'] ?? '');
    $p  = esc_attr($u['prijmeni'] ?? '');
    $dn = esc_attr($u['datum_narozeni'] ?? '');
    $a  = esc_attr($u['adresa'] ?? '');
    $nc = !empty($u['neplati']) ? 'checked' : '';
    return "<div class='rs-segment-box' style='margin-bottom:8px'>"
        . "<div class='rs-form-row'>"
        . "<div class='rs-form-group'><label>Jméno</label><input type='text' name='ucast_jmeno[]' value='{$j}'></div>"
        . "<div class='rs-form-group'><label>Příjmení</label><input type='text' name='ucast_prijmeni[]' value='{$p}'></div>"
        . "<div class='rs-form-group'><label>Datum narození</label><input type='date' name='ucast_datum_nar[]' value='{$dn}'></div>"
        . "<div class='rs-form-group'><label>Adresa pobytu</label><input type='text' name='ucast_adresa[]' value='{$a}'></div>"
        . "<div class='rs-form-group' style='align-self:flex-end'><label><input type='checkbox' name='ucast_neplati[{$i}]' {$nc}> Neplatí poplatek</label></div>"
        . "</div></div>";
}

// ═══ SEKCE: POPIS APLIKACE ═══════════════════════════════════════════════════

function rs_sekce_napoveda(): string {
    ob_start(); ?>
<div style="max-width:820px;line-height:1.7;font-size:15px">

<p style="font-size:17px;font-weight:600;margin-top:0">Středisková aplikace pro rezervaci prostor</p>
<p>Tato aplikace slouží k rezervaci objektů (zatím skautský dům a Smetánka) a jejich částí (místnosti, chatky). Umožňuje přijímat žádosti od veřejnosti i plánovat vlastní interní akce. Všichni tedy na jednom místě uvidíme, kdy kde je nebo není v našich prostorách místo.</p>

<hr style="margin:24px 0">

<p style="font-weight:600;font-size:15px;margin-bottom:6px">Co vidí veřejnost (nepřihlášení návštěvníci)</p>
<ul>
  <li><strong>Kalendář obsazenosti</strong> – přehledný měsíční kalendář zobrazující, kdy jsou objekty zcela nebo částečně obsazené a kdy volné. Veřejnost však nevidí žádné detaily rezervací. Odlišeny jsou potvrzené a čekající rezervace.</li>
  <li><strong>Formulář pro poptávku prostor</strong> – návštěvník vyplní své kontaktní údaje, zvolí požadovaný termín (aplikace automaticky kontroluje dostupnost) a objekt, uvede počet osob a odešle žádost. Ta pak čeká na schválení správcem.
    <ul style="margin-top:6px">
      <li>Po odeslání žadatel obdrží e-mail s potvrzením přijetí žádosti a <strong>jedinečným odkazem pro správu své rezervace</strong>. Přes tento odkaz může sledovat stav rezervace (čeká / potvrzena / zrušena) a v případě potvrzení i vyplnit seznam ubytovaných osob. Žádost ani termín přes tento odkaz měnit nelze – změny řeší správce.</li>
      <li>Jakmile správce rezervaci potvrdí nebo zamítne, přijde žadateli automatický e-mail s výsledkem a orientační cenou.</li>
      <li>Je-li zapnuta funkce <strong>ubytovacího poplatku (vzdušného)</strong>, je žadatel po potvrzení rezervace vyzván k vyplnění jmen a dat narození všech ubytovaných osob. Upomínkový e-mail se automaticky odešle 7 dní a znovu 1 den před začátkem pobytu, pokud seznam dosud nebyl vyplněn.</li>
    </ul>
  </li>
</ul>

<hr style="margin:24px 0">

<p style="font-weight:600;font-size:15px;margin-bottom:6px">Vedení oddílů</p>
<p>Vedoucí mají přístup k záložce <strong>Interní rezervace</strong>, kde mohou:</p>
<ul>
  <li>Zadat interní akci oddílu (výprava, oddílová rada, přespávačka apod.) přímo bez schvalovacího procesu.</li>
  <li>Nastavit opakující se rezervaci – například týdenní schůzky na celou sezónu – jedním kliknutím. Systém automaticky přeskočí státní svátky a prázdniny (lze volitelně vypnout), takže není třeba výjimky zadávat ručně. Všechny termíny série se v přehledu zobrazí sbalené pod jedno označení, aby tabulku nepřeplňovaly.</li>
  <li>Zrušit jednotlivé termíny nebo celou sérii najednou – každý vedoucí ale může rušit jen rezervace, které sám vytvořil, ne rezervace ostatních vedoucích.</li>
</ul>

<hr style="margin:24px 0">

<p style="font-weight:600;font-size:15px;margin-bottom:6px">Správce rezervací (Vráťa)</p>
<p>Správce má navíc záložku <strong>Správa rezervací</strong>, kde:</p>
<ul>
  <li>Vidí všechny rezervace rozdělené na <strong>Externí</strong> (žádosti od veřejnosti) a <strong>Interní</strong> (akce oddílu).</li>
  <li>Může rezervaci <strong>potvrdit</strong> nebo <strong>zamítnout</strong> – žadatel je o změně informován automatickým e-mailem.</li>
  <li>U externích rezervací vidí kontaktní údaje žadatele, termín, počet osob a stav rezervace.</li>
  <li>Cena je automaticky vypočtena podle ceníku a zobrazena u každé rezervace. Systém přitom respektuje nastavenou minimální cenu – pokud by výsledná cena za počet osob a nocí byla nižší, naúčtuje se minimální cena. Správce ji může v případě potřeby individuálně upravit přímo v detailu rezervace.</li>
  <li>Může kdykoli zobrazit detail rezervace, upravit ji nebo ji zrušit.</li>
</ul>

<hr style="margin:24px 0">

<p style="font-weight:600;font-size:15px;margin-bottom:6px">Administrátor systému</p>
<p>Administrátor nastavuje:</p>
<ul>
  <li><strong>Typy objektů</strong> – definuje kategorie (např. skautský dům, příměstský areál, tábořiště atp.).</li>
  <li><strong>Objekty</strong> – spravuje konkrétní pronajímatelné prostory, jejich kapacitu, popis (rozloha, kapacita osob, adresa a GPS souřadnice – ty se zobrazují jako přímý proklik do mapy na mapy.cz), fotografie a přiřazení k typu. Každý objekt nebo jeho část lze dočasně vypnout z nabídky pro veřejnost – buď na konkrétní časové rozmezí, nebo do odvolání.</li>
  <li><strong>Ceník pronájmu objektů</strong> – definuje ceny za nocleh a pobyt. Je-li zapnuta funkce vzdušného (ubytovacího poplatku), nastaví se zde sazby podle věkových kategorií ubytovaných.</li>
  <li><strong>Nastavení</strong> – další obecná konfigurace systému (parametry notifikačních e-mailů atp.).</li>
</ul>

</div>
<?php return ob_get_clean();
}
