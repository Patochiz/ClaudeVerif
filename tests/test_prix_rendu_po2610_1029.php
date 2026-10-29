<?php
require __DIR__.'/stubs.php';
require dirname(__DIR__).'/claudeverif/class/claudeverifsupplierlogic.class.php';
$M2 = 3; $U = 1;
$lines = array(
	array('id' => 11, 'rang' => 1, 'fk_product' => 501, 'product_ref' => 'OUESSANT_600_H00_T24_PRE_BLANC_NP', 'product_type' => 0, 'fk_unit' => $M2, 'unite' => 'm²', 'qty' => 288, 'subprice' => 9.40, 'total_ht' => 2707.20, 'special_code' => 0, 'label' => 'Ouessant'),
	array('id' => 12, 'rang' => 2, 'fk_product' => 502, 'product_ref' => 'Boxes', 'product_type' => 0, 'fk_unit' => $U, 'unite' => 'u', 'qty' => 32, 'subprice' => 3.50, 'total_ht' => 112.00, 'special_code' => 0, 'label' => 'Boxes'),
	array('id' => 13, 'rang' => 3, 'fk_product' => 503, 'product_ref' => 'Horizon_D85a_PRE_RAL_9003_NP', 'product_type' => 0, 'fk_unit' => $M2, 'unite' => 'm²', 'qty' => 18, 'subprice' => 11.00, 'total_ht' => 198.00, 'special_code' => 0, 'label' => 'D85a'),
	array('id' => 14, 'rang' => 4, 'fk_product' => 504, 'product_ref' => 'Horizon_LO_80_PRE_RAL_9003_NP', 'product_type' => 0, 'fk_unit' => $M2, 'unite' => 'm²', 'qty' => 6.60, 'subprice' => 13.00, 'total_ht' => 85.80, 'special_code' => 0, 'label' => 'LO80'),
	array('id' => 15, 'rang' => 5, 'fk_product' => 0, 'product_ref' => '', 'product_type' => 0, 'fk_unit' => null, 'unite' => '', 'qty' => 1, 'subprice' => 100.00, 'total_ht' => 100.00, 'special_code' => 0, 'label' => 'Packing charges'),
);
$cls = ClaudeVerifSupplierLogic::classify($lines);
$r = ClaudeVerifSupplierLogic::compute($lines, $cls, 'quantite', 662.28, 0);
$expected = array('OUESSANT_600_H00_T24_PRE_BLANC_NP' => 12.23, 'Horizon_D85a_PRE_RAL_9003_NP' => 13.44, 'Horizon_LO_80_PRE_RAL_9003_NP' => 15.44);
$ok = true;
foreach ($r['lignes'] as $l) {
	$e = $expected[$l['produit']];
	printf("%-36s spec %7.2f  communs %7.2f  prix %.5f -> %.2f  attendu %.2f %s\n", $l['produit'], $l['frais_specifiques'], $l['frais_communs_alloues'], $l['prix_rendu_calcul'], $l['prix_rendu'], $e, abs($l['prix_rendu'] - $e) < 0.001 ? 'OK' : 'ÉCHEC');
	$ok = $ok && abs($l['prix_rendu'] - $e) < 0.001;
}
echo "frais communs ".$r['frais_communs']." / base ".$r['base_repartition']."\n";
echo json_encode(array('specifiques' => $cls['specifiques'], 'communs' => $cls['communs'], 'alertes' => $cls['alertes']))."\n";
echo $ok ? "TEST PO2610-1029 : OK\n" : "TEST PO2610-1029 : ÉCHEC\n";
exit($ok ? 0 : 1);
