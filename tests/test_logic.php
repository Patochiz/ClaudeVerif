<?php
require __DIR__.'/stubs.php';
require dirname(__DIR__).'/claudeverif/class/claudeverifsupplierlogic.class.php';
$fails = 0;
function check($label, $cond, $detail = '') { global $fails; echo ($cond ? '  OK    ' : '  ÉCHEC ').$label.($cond ? '' : ' — '.$detail)."\n"; if (!$cond) $fails++; }
$L = 'ClaudeVerifSupplierLogic';

echo "Jetons\n";
$tok = function ($s) use ($L) { return array_map(function ($t) { return $t['jeton'].':'.$t['type']; }, $L::tokenize($s)); };
check('MS (ARRIGHI2A + RIEMA145 + STOCK)', $tok('MS (ARRIGHI2A + RIEMA145 + STOCK)') === array('ARRIGHI2A:chantier', 'RIEMA145:chantier', 'STOCK:stock'), json_encode($tok('MS (ARRIGHI2A + RIEMA145 + STOCK)')));
check('MS (42+69+67+31)', $tok('MS (42+69+67+31)') === array('42:departement', '69:departement', '67:departement', '31:departement'));
check('MS YOMGHY 763', $tok('MS YOMGHY 763') === array('YOMGHY 763:chantier'), json_encode($tok('MS YOMGHY 763')));
check('MS (Stock)', $tok('MS (Stock)') === array('STOCK:stock'));
check('doublon supprimé', $tok('MS (42 + 42)') === array('42:departement'));

echo "Correspondances\n";
$m = function ($tok, $ref) use ($L) { $t = $L::tokenize('MS ('.$tok.')')[0]; $r = $L::matchToken($t, $ref); return $r ? $r['force'] : null; };
check('42 dans RESO 42', $m('42', 'RESO 42') === 'forte');
check('69 dans BIGMAT69', $m('69', 'BIGMAT69 (MAINRO 69)') === 'forte');
check('56 dans STELLECRY 56', $m('56', 'STELLECRY 56') === 'forte');
check('14 → MAUNY 141 faible', $m('14', 'MAUNY 141') === 'faible');
check('14 ne prend pas 2014', $m('14', 'CHANTIER 2014') === null);
check('42 ne prend pas 420', $m('42', 'LOT 4201') === null);
check('YOMGHY 763 forte', $m('YOMGHY 763', 'yomghy  763') === 'forte');
check('YOMGHY 763 → YOMGHY 800 faible', $m('YOMGHY 763', 'YOMGHY 800') === 'faible');
check('STOCK → Stock DIAMANT', $m('STOCK', 'RINEMA 338 + Stock DIAMANT') === 'forte');
check('chantier absent', $m('ARRIGHI2A', 'LN 352') === null);

echo "Dates\n";
check('dès le 28/10/2026', $L::findDate('Enlèvement sur le dépôt de notre fournisseur dès le 28/10/2026', '/enl[eè]vement/iu') === '2026-10-28');
check('ENLEVEMENT 3/11/26', $L::findDate('ENLEVEMENT LE 3/11/26', '/enl[eè]vement/iu') === '2026-11-03');
check('livrer, pas la date d\'enlèvement', $L::findDate("Enlèvement dès le 28/10/2026\nA livrer sur notre dépôt le 02/11/2026", '/livr/iu') === '2026-11-02');
check('date invalide ignorée', $L::findDate('Enlèvement le 31/02/2026', '/enl[eè]vement/iu') === null);
check('fenêtre : livraison -31 j hors', $L::checkDates('2026-10-28', '2026-09-01', '2026-09-27', 30, 15)['etat'] === 'hors');
check('fenêtre : commandée après enlèvement hors', $L::checkDates('2026-10-28', '2026-10-29', '2026-10-28', 30, 15)['etat'] === 'hors');
check('fenêtre : livraison inconnue', $L::checkDates('2026-10-28', '2026-10-01', null, 30, 15)['etat'] === 'inconnu');

echo "Transport\n";
$lines = array(
	array('id' => 1, 'product_ref' => 'PrixTrps', 'label' => '', 'description' => 'Enlèvement ... dès le 28/10/2026<br />A livrer le 02/11/2026'),
	array('id' => 2, 'product_ref' => 'adrit_metalscreen', 'label' => '', 'description' => "METALSCREEN<br>2 palettes de 300 kg<br>Poids total 1.128 kg"),
);
$t = $L::parseTransport($lines, 'ADRIT_', 'PrixTrps');
check('préfixe insensible à la casse, poids 1.128 kg = 1128', $t !== null && $t['poids_kg'] == 1128 && $t['date_livraison'] === '2026-11-02', json_encode($t));
check('pas de ligne ADRIT → null', $L::parseTransport(array($lines[0]), 'ADRIT_', 'PrixTrps') === null);

echo "Classement\n";
$p = function ($id, $ref, $unit, $qty, $tot, $extra = array()) { return $extra + array('id' => $id, 'rang' => $id, 'fk_product' => $id + 100, 'product_ref' => $ref, 'product_type' => 0, 'fk_unit' => $unit, 'qty' => $qty, 'subprice' => $qty ? $tot / $qty : 0, 'total_ht' => $tot, 'special_code' => 0, 'label' => $ref); };
$lines = array(
	$p(1, 'VIS', 1, 10, 20),                                        // accessoire en tête : non classé
	$p(2, 'DALLE', 3, 100, 1000),
	$p(3, 'BOX', 1, 5, 50), $p(4, 'CLIP', 1, 5, 25),                // accessoires en chaîne
	$p(5, 'TITRE', null, 0, 0, array('fk_product' => 0, 'special_code' => 104777)),
	$p(6, 'PORT', null, 1, 80, array('product_type' => 1)),         // service : commun
	$p(7, 'RAIL', 3, 50, 500),
);
$c = $L::classify($lines);
check('principaux DALLE et RAIL', $c['principaux'] === array(2, 7), json_encode($c));
check('BOX et CLIP rattachés à DALLE', $c['specifiques'] === array(3 => 2, 4 => 2));
check('service en frais commun, titre ignoré', $c['communs'] === array(6) && isset($c['ignorees'][5]));
check('VIS non classée + alerte', isset($c['non_classees'][1]) && (bool) preg_grep('/non classée/', $c['alertes']));
$c = $L::classify(array($p(1, 'A', 3, 10, 10), $p(2, 'B', 1, 10, 10)));
check('égalité d\'unités : alerte hétérogène', (bool) preg_grep('/hétérogènes/', $c['alertes']) && $c['principaux'] === array(1));
$c = $L::classify(array($p(1, 'A', null, 10, 10), $p(2, 'B', null, 10, 10)));
check('unités non renseignées : alerte', (bool) preg_grep('/non renseignée/', $c['alertes']) && $c['principaux'] === array(1, 2));
try { $L::classify($lines, array(array('ligne_frais' => 6, 'ligne_produit' => 3))); $e = ''; } catch (Exception $x) { $e = $x->getMessage(); }
check('affectation vers un non-principal refusée', strpos($e, "n'est pas un produit principal") !== false);

echo $fails ? "\n$fails ÉCHEC(S)\n" : "\nTOUS LES TESTS PASSENT\n";
exit($fails ? 1 : 0);
