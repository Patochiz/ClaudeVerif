<?php
require __DIR__.'/env.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
	global $fails;
	echo ($cond ? '  OK    ' : '  ÉCHEC ').$label.($cond || $detail === '' ? '' : ' — '.$detail)."\n";
	if (!$cond) $fails++;
}
function names($defs) { return array_column($defs, 'name'); }
function call($tools, $name, $args)
{
	try { return $tools->call($name, $args); } catch (Throwable $e) { return array('_erreur' => $e->getMessage()); }
}

$db = testdb();
$u = new TestUser();
$u->rights = array('fournisseur/commande/lire', 'produit/lire');
$tools = new ClaudeVerifTools($db, $u);

echo "1. Constantes à 0\n";
$n = names($tools->getDefinitions());
check('outils lecture exposés', !array_diff(array('search_commandes_fournisseur', 'get_commande_fournisseur', 'rapprocher_transports', 'calculer_prix_rendu'), $n));
check('lier_commandes_fournisseur absent', !in_array('lier_commandes_fournisseur', $n, true));
check('maj_prix_revient absent', !in_array('maj_prix_revient', $n, true));
$r = call($tools, 'lier_commandes_fournisseur', array('transport' => 'PO2610-1043', 'commandes' => array('PO2610-1029')));
check('lier refuse', strpos($r['_erreur'] ?? '', 'désactivée') !== false, json_encode($r));
$r = call($tools, 'maj_prix_revient', array('produits' => array(array('produit' => 'PRODUIT_TEST', 'cost_price' => 11)), 'commande_source' => 'X'));
check('maj refuse', strpos($r['_erreur'] ?? '', 'désactivée') !== false, json_encode($r));
$GLOBALS['CONST'] = array('CLAUDEVERIF_ALLOW_LINK' => 1, 'CLAUDEVERIF_ALLOW_COSTPRICE' => 1);
$n = names($tools->getDefinitions());
check('outils écriture exposés si constantes à 1', in_array('lier_commandes_fournisseur', $n, true) && in_array('maj_prix_revient', $n, true));
$GLOBALS['CONST'] = array();
$u0 = new TestUser();
$r = call(new ClaudeVerifTools($db, $u0), 'search_commandes_fournisseur', array());
check('sans droit lecture : refus', strpos($r['_erreur'] ?? '', 'Commandes fournisseurs') !== false, json_encode($r));

echo "2. search_commandes_fournisseur contient_produit=ADRIT_METALSCREEN\n";
$r = call($tools, 'search_commandes_fournisseur', array('contient_produit' => 'ADRIT_METALSCREEN', 'limit' => 50));
$refs = array_column($r['commandes'] ?? array(), 'ref');
check('transports trouvés (dont annulé PO2604-0822)', count($refs) === 6 && in_array('PO2604-0822', $refs, true), json_encode($r));
$c1043 = array_values(array_filter($r['commandes'], function ($c) { return $c['ref'] === 'PO2610-1043'; }))[0];
check('nb liés PO2610-1043 = 1, statut libellé', $c1043['nb_commandes_fournisseur_liees'] === 1 && $c1043['statut'] === 'Commandée', json_encode($c1043));
$r = call($tools, 'search_commandes_fournisseur', array('fournisseur' => 'metal', 'ref_fournisseur' => 'stock', 'statut' => array(3)));
check('critères combinés', array_column($r['commandes'], 'ref') === array('PO2610-1103'), json_encode($r));
$r = call($tools, 'search_commandes_fournisseur', array('date_from' => '2026-13'));
check('date invalide refusée', isset($r['_erreur']));

echo "3. get_commande_fournisseur PO2610-1043\n";
$r = call($tools, 'get_commande_fournisseur', array('ref' => 'PO2610-1043'));
$t = $r['transport'] ?? array();
check('est un transport', !empty($r['est_transport']), json_encode($r));
check('enlèvement 2026-10-28', ($t['date_enlevement'] ?? '') === '2026-10-28', json_encode($t));
check('livraison 2026-11-02', ($t['date_livraison'] ?? '') === '2026-11-02');
check('poids 1128 kg', ($t['poids_kg'] ?? 0) == 1128);
check('colisage', strpos((string) ($t['colisage'] ?? ''), '4 palettes') !== false);
check('fournisseur enlèvement METALSCREEN', ($t['fournisseur_enlevement'] ?? '') === 'METALSCREEN');
check('lien vers PO2610-1029', array_column($r['commandes_fournisseur_liees'], 'ref') === array('PO2610-1029'));
$r = call($tools, 'get_commande_fournisseur', array('id' => 1029));
$l11 = $r['lignes'][0];
check('ligne : unité, qté reçue, type', $l11['unite'] === 'm²' && $l11['qty_receptionnee'] == 288 && $l11['type'] === 'produit' && $r['lignes'][4]['type'] === 'ligne libre', json_encode($l11));
check('commande 1029 liée au transport', ($r['commandes_fournisseur_liees'][0]['type'] ?? '') === 'commande de transport');
$r = call($tools, 'get_commande_fournisseur', array('ref' => 'PO0000'));
check('ref inexistante', strpos($r['_erreur'] ?? '', 'introuvable') !== false);
$r = call($tools, 'get_commande_fournisseur', array('ref' => "x' OR '1'='1"));
check('injection : introuvable', strpos($r['_erreur'] ?? '', 'introuvable') !== false);

echo "4. rapprocher_transports Metalscreen\n";
$r = call($tools, 'rapprocher_transports', array('fournisseur' => 'Metalscreen', 'inclure_lies' => true));
if (isset($r['_erreur'])) { check('appel', false, $r['_erreur']); }
$by = array();
foreach ($r['transports'] ?? array() as $tr) { $by[$tr['transport']['ref']] = $tr; }
check('annulé PO2604-0822 exclu', !isset($by['PO2604-0822']));
check('PO2610-1043 sûr → PO2610-1029, identique', ($by['PO2610-1043']['statut'] ?? '') === 'sûr' && $by['PO2610-1043']['proposition'] === array('PO2610-1029') && $by['PO2610-1043']['concordance'] === 'identique', json_encode($by['PO2610-1043'] ?? null));
$p = $by['PO2609-0981']['proposition'] ?? array(); sort($p);
check('PO2609-0981 sûr → 0972, 0934, 0970, identique', ($by['PO2609-0981']['statut'] ?? '') === 'sûr' && $p === array('PO2606-0934', 'PO2607-0970', 'PO2608-0972') && $by['PO2609-0981']['concordance'] === 'identique', json_encode($by['PO2609-0981'] ?? null, JSON_UNESCAPED_UNICODE));
$p = $by['PO2610-1110']['proposition'] ?? array(); sort($p);
check('PO2610-1110 sûr → 1101, 1102, 1103 (annulée 1106 et Stock d\'avril écartés)', ($by['PO2610-1110']['statut'] ?? '') === 'sûr' && $p === array('PO2610-1101', 'PO2610-1102', 'PO2610-1103'), json_encode($by['PO2610-1110'] ?? null, JSON_UNESCAPED_UNICODE));
check('PO2610-1111 (14 → MAUNY 141) probable, faible', ($by['PO2610-1111']['statut'] ?? '') === 'probable' && ($by['PO2610-1111']['jetons'][0]['candidats'][0]['force'] ?? '') === 'faible', json_encode($by['PO2610-1111'] ?? null, JSON_UNESCAPED_UNICODE));
check('regles présentes', strpos($r['regles'] ?? '', 'validation explicite') !== false);
$r2 = call($tools, 'rapprocher_transports', array('fournisseur' => 'Metalscreen'));
$refs = array_map(function ($x) { return $x['transport']['ref']; }, $r2['transports']);
check('sans inclure_lies : transports liés masqués', !in_array('PO2610-1043', $refs, true) && in_array('PO2610-1110', $refs, true) && in_array('PO2610-1043', $r2['transports_deja_lies_non_affiches'], true), json_encode($refs));
$r3 = call($tools, 'rapprocher_transports', array('fournisseur' => 'Inconnu'));
check('fournisseur sans produit ADRIT : erreur claire', strpos($r3['_erreur'] ?? '', 'ADRIT_METALSCREEN') !== false || strpos($r3['_erreur'] ?? '', 'existants') !== false, json_encode($r3, JSON_UNESCAPED_UNICODE));

echo "5. lier_commandes_fournisseur\n";
$GLOBALS['CONST'] = array('CLAUDEVERIF_ALLOW_LINK' => 1);
$r = call($tools, 'lier_commandes_fournisseur', array('transport' => 'PO2610-1043', 'commandes' => array('PO2610-1029')));
check('sans droit créer : refus', strpos($r['_erreur'] ?? '', 'Créer') !== false, json_encode($r));
$u->rights[] = 'fournisseur/commande/creer';
$count = function () use ($db) { return (int) $db->fetch_object($db->query('SELECT COUNT(*) n FROM llx_element_element'))->n; };
$before = $count();
$r = call($tools, 'lier_commandes_fournisseur', array('transport' => 'PO2610-1043', 'commandes' => array('PO2610-1029')));
check('lien existant → déjà lié, pas de doublon', ($r['resultats'][0]['resultat'] ?? '') === 'déjà lié' && $count() === $before, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = call($tools, 'lier_commandes_fournisseur', array('transport' => '1110', 'commandes' => array('PO2610-1101', 1102, 'PO2610-1110', 'PO9999')));
$res = array_column($r['resultats'], 'resultat');
check('créé, créé, erreur (lui-même), erreur (inexistante)', $res === array('créé', 'créé', 'erreur', 'erreur') && $count() === $before + 2, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = call($tools, 'get_commande_fournisseur', array('ref' => 'PO2610-1101'));
check('visible des deux côtés', array_column($r['commandes_fournisseur_liees'], 'ref') === array('PO2610-1110'));
check('trace syslog', (bool) preg_grep('/lier_commandes_fournisseur utilisateur=claude transport=PO2610-1110 commande=PO2610-1101/', $GLOBALS['SYSLOG']));
$r = call($tools, 'lier_commandes_fournisseur', array('transport' => 'PO2610-1029', 'commandes' => array('PO2610-1101')));
check('transport sans ligne ADRIT refusé', strpos($r['_erreur'] ?? '', "n'est pas une commande de transport") !== false, json_encode($r, JSON_UNESCAPED_UNICODE));

echo "6. calculer_prix_rendu PO2610-1029\n";
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2610-1029'));
if (isset($r['_erreur'])) { check('appel', false, $r['_erreur']); }
$px = array_column($r['produits'] ?? array(), 'prix_rendu', 'produit');
check('12,23 / 13,44 / 15,44', $px === array('OUESSANT_600_H00_T24_PRE_BLANC_NP' => 12.23, 'Horizon_D85a_PRE_RAL_9003_NP' => 13.44, 'Horizon_LO_80_PRE_RAL_9003_NP' => 15.44), json_encode($px));
check('frais communs 762,28 / 312,6 m²', $r['frais_communs']['total'] == 762.28 && $r['base_repartition'] == 312.6, json_encode($r['frais_communs']));
check('transport à 100 %', ($r['transports'][0]['part_retenue_pourcent'] ?? 0) == 100);
check('alerte transport non facturé', (bool) preg_grep('/PO2610-1043 pas encore facturé/', $r['alertes']), json_encode($r['alertes'], JSON_UNESCAPED_UNICODE));
check('comparaison prix', $r['produits'][0]['cost_price_actuel'] == 11.90 && $r['produits'][0]['pmp_actuel'] == 11.75 && $r['produits'][0]['prix_fournisseur_actuel']['pu_ht'] == 9.40, json_encode($r['produits'][0], JSON_UNESCAPED_UNICODE));
check('unité principale m²', $r['unite_principale'] === 'm²');
check('regles : proposer, accord explicite', strpos($r['regles'], 'accord explicite') !== false);
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2610-1043'));
check('refus sur un transport', strpos($r['_erreur'] ?? '', 'transport') !== false);
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2611-1201'));
check('transport partagé : 100/(100+300) = 25 % de 400 = 100', ($r['transports'][0]['part_retenue_ht'] ?? 0) == 100 && $r['produits'][0]['prix_rendu'] == 11.00, json_encode($r['transports'] ?? $r, JSON_UNESCAPED_UNICODE));
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2611-1201', 'cle_repartition' => 'valeur'));
check('clé valeur : 1000/(1000+3000) = 25 %', ($r['transports'][0]['part_retenue_pourcent'] ?? 0) == 25);
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2610-1103', 'frais_supplementaires' => 50));
check('aucun transport lié : alerte, frais supp. pris en compte', (bool) preg_grep('/Aucune commande de transport/', $r['alertes'] ?? array()) && $r['produits'][0]['prix_rendu'] == 11.25, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2610-1101'));
check('transport incomplètement lié : alertes liens manquants et commande sans produit', (bool) preg_grep('/« STOCK » cité\(s\) dans sa réf/u', $r['alertes']) && (bool) preg_grep('/PO2610-1102 n\'a pas de produit principal/', $r['alertes']), json_encode($r['alertes'], JSON_UNESCAPED_UNICODE));
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2610-1029'));
$r9 = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2608-0972'));
check('PO2608-0972 (42+69+67+31, 3 commandes) : pas d\'alerte de liens manquants, part 100/180', !preg_grep('/cité/u', $r9['alertes']) && abs($r9['transports'][0]['part_retenue_pourcent'] - 55.56) < 0.01, json_encode($r9['alertes'] + array('t' => $r9['transports']), JSON_UNESCAPED_UNICODE));
check('PO2610-1029 : pas d\'alerte de liens manquants', !preg_grep('/cité/u', $r['alertes']), json_encode($r['alertes'], JSON_UNESCAPED_UNICODE));
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2610-1102'));
check('commande sans produit : erreur claire', strpos($r['_erreur'] ?? '', 'Base de répartition nulle') !== false);
$r = call($tools, 'calculer_prix_rendu', array('commande' => 'PO2610-1029', 'affectations' => array(array('ligne_frais' => 15, 'ligne_produit' => 11))));
$px = array_column($r['produits'], 'prix_rendu', 'produit');
check('affectation : packing sur OUESSANT seul', $px['Horizon_D85a_PRE_RAL_9003_NP'] == round(11 + 662.28 / 312.6, 2), json_encode($px));

echo "7. maj_prix_revient\n";
$GLOBALS['CONST'] = array('CLAUDEVERIF_ALLOW_COSTPRICE' => 1);
$r = call($tools, 'maj_prix_revient', array('produits' => array(array('produit' => 'PRODUIT_TEST', 'cost_price' => 11)), 'commande_source' => 'PO2610-1029'));
check('sans droit produit créer : refus', strpos($r['_erreur'] ?? '', 'produit->creer') !== false, json_encode($r));
$u->rights[] = 'produit/creer';
$snap = function () use ($db) { return $db->fetch_object($db->query("SELECT cost_price, pmp, price FROM llx_product WHERE ref = 'PRODUIT_TEST'")); };
$fp = function () use ($db) { return $db->fetch_object($db->query("SELECT COUNT(*) n, SUM(unitprice) s FROM llx_product_fournisseur_price")); };
$fpBefore = $fp();
$r = call($tools, 'maj_prix_revient', array('produits' => array(array('produit' => 'PRODUIT_TEST', 'cost_price' => 11.2274)), 'commande_source' => 'PO2610-1029'));
$s = $snap();
check('cost_price modifié 10 → 11.2274', ($r['resultats'][0]['resultat'] ?? '') === 'mis à jour' && (float) $s->cost_price == 11.2274, json_encode($r, JSON_UNESCAPED_UNICODE));
check('PMP, prix de vente, prix fournisseurs inchangés', (float) $s->pmp == 10 && (float) $s->price == 0 && $fp() == $fpBefore);
check('trace syslog', (bool) preg_grep('/maj_prix_revient utilisateur=claude produit=PRODUIT_TEST ancien=10 nouveau=11.2274 source=PO2610-1029/', $GLOBALS['SYSLOG']), json_encode($GLOBALS['SYSLOG']));
$r = call($tools, 'maj_prix_revient', array('produits' => array(array('produit' => 'PRODUIT_TEST', 'cost_price' => 20)), 'commande_source' => 'PO2610-1029'));
check('écart > 30 % sans forcer : refusé, prix inchangé', ($r['resultats'][0]['resultat'] ?? '') === 'refusé' && (float) $snap()->cost_price == 11.2274, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = call($tools, 'maj_prix_revient', array('produits' => array(array('produit' => 'PRODUIT_TEST', 'cost_price' => 20)), 'commande_source' => 'PO2610-1029', 'forcer' => true));
check('avec forcer : mis à jour', ($r['resultats'][0]['resultat'] ?? '') === 'mis à jour' && (float) $snap()->cost_price == 20);
$r = call($tools, 'maj_prix_revient', array('produits' => array(array('produit' => 'PRODUIT_TEST', 'cost_price' => 0), array('produit' => 'NEXISTEPAS', 'cost_price' => 5), array('produit' => 505, 'cost_price' => 21)), 'commande_source' => 'X'));
check('prix 0 refusé, produit inexistant, id numérique accepté', array_column($r['resultats'], 'resultat') === array('erreur', 'erreur', 'mis à jour'), json_encode($r, JSON_UNESCAPED_UNICODE));

echo $fails ? "\n$fails ÉCHEC(S)\n" : "\nTOUS LES TESTS PASSENT\n";
exit($fails ? 1 : 0);
