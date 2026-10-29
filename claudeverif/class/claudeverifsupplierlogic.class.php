<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Règles métier commandes fournisseurs (sans accès base) :
 * lecture des commandes de transport, rapprochement transport / commandes, prix rendu Brest.
 */

/**
 * Fonctions pures : elles travaillent sur des tableaux préparés par ClaudeVerifSupplier
 *
 * Ligne de commande attendue : id, rang, fk_product, product_ref, product_type (0 produit, 1 service),
 * fk_unit (unité de la ligne, sinon celle du produit), unite (libellé), qty, subprice, total_ht, special_code, label, description
 */
class ClaudeVerifSupplierLogic
{
	const WEIGHT_STRONG = 70;
	const WEIGHT_WEAK = 40;
	const BONUS_DATES_OK = 30;
	const BONUS_DATES_UNKNOWN = 10;

	/**
	 * Lecture d'une commande de transport : ligne d'enlèvement (préfixe ADRIT_) et ligne forfait transport
	 *
	 * @param array  $lines         Lignes
	 * @param string $pickupPrefix  Préfixe des produits adresse d'enlèvement (ADRIT_)
	 * @param string $transportRef  Réf. du produit forfait transport (PrixTrps)
	 * @return array|null Infos de transport, null si la commande n'est pas un transport
	 */
	public static function parseTransport(array $lines, string $pickupPrefix, string $transportRef)
	{
		$pickup = null;
		$forfait = null;
		foreach ($lines as $l) {
			$ref = (string) ($l['product_ref'] ?? '');
			if ($pickup === null && $pickupPrefix !== '' && stripos($ref, $pickupPrefix) === 0) {
				$pickup = $l;
			}
			if ($forfait === null && $transportRef !== '' && strcasecmp($ref, $transportRef) === 0) {
				$forfait = $l;
			}
		}
		if ($pickup === null) {
			return null;
		}

		$pickupText = self::lineText($pickup);
		$datesText = $forfait !== null ? self::lineText($forfait) : '';
		if ($datesText === '') {
			// Pas de ligne forfait : chercher les dates dans toutes les lignes
			$datesText = implode("\n", array_map(array(__CLASS__, 'lineText'), $lines));
		}

		$poids = null;
		if (preg_match_all('/(\d[\d\s.]*(?:,\d+)?)\s*kg\b/iu', $pickupText, $m)) {
			// Plus grand poids indiqué (total plutôt que poids par colis)
			foreach ($m[1] as $raw) {
				$v = (float) str_replace(array(' ', "\xC2\xA0", ','), array('', '', '.'), preg_replace('/\.(?=\d{3}\b)/', '', trim($raw)));
				if ($poids === null || $v > $poids) {
					$poids = $v;
				}
			}
		}
		$colisage = array();
		foreach (preg_split('/\n/', $pickupText) as $row) {
			if (preg_match('/colis|palette|carton|caisse|fardeau|botte|paquet|\bpal\b/iu', $row)) {
				$colisage[] = trim($row);
			}
		}

		return array(
			'ligne_enlevement' => (int) $pickup['id'],
			'produit_enlevement' => (string) $pickup['product_ref'],
			'fournisseur_enlevement' => (string) substr((string) $pickup['product_ref'], strlen($pickupPrefix)),
			'adresse_enlevement' => $pickupText,
			'date_enlevement' => self::findDate($datesText, '/enl[eè]vement/iu'),
			'date_livraison' => self::findDate($datesText, '/livr/iu'),
			'colisage' => $colisage ? implode("\n", $colisage) : null,
			'poids_kg' => $poids,
		);
	}

	/**
	 * Texte complet d'une ligne (libellé + description), sans HTML
	 *
	 * @param array $l Ligne
	 * @return string
	 */
	public static function lineText($l)
	{
		$t = trim(((string) ($l['label'] ?? ''))."\n".((string) ($l['description'] ?? '')));
		$t = preg_replace('/<br\s*\/?>/i', "\n", $t);
		$t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$t = str_replace(array("\r\n", "\r"), "\n", $t);
		return trim(preg_replace("/\n{2,}/", "\n", $t));
	}

	/**
	 * Première date JJ/MM/AAAA qui suit un mot-clé, sur la même phrase
	 *
	 * @param string $text    Texte
	 * @param string $keyword Expression du mot-clé
	 * @return string|null AAAA-MM-JJ
	 */
	public static function findDate($text, $keyword)
	{
		foreach (preg_split('/\n/', (string) $text) as $row) {
			if (!preg_match($keyword, $row, $k, PREG_OFFSET_CAPTURE)) {
				continue;
			}
			$after = substr($row, $k[0][1]);
			if (preg_match('/(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})/', $after, $d)) {
				$y = (int) $d[3];
				if ($y < 100) {
					$y += 2000;
				}
				if (checkdate((int) $d[2], (int) $d[1], $y)) {
					return sprintf('%04d-%02d-%02d', $y, (int) $d[2], (int) $d[1]);
				}
			}
		}
		return null;
	}

	/**
	 * Jetons de la réf. fournisseur d'un transport : « MS (ARRIGHI2A + RIEMA145 + STOCK) »
	 *
	 * @param string $refSupplier Réf. fournisseur du transport
	 * @return array [[jeton, type (chantier|departement|stock), norm]]
	 */
	public static function tokenize($refSupplier)
	{
		$s = trim((string) $refSupplier);
		// Préfixe fournisseur (MS…) : 1 à 4 lettres suivies d'une parenthèse ou d'un autre mot
		$s = preg_replace('/^[A-Za-z]{1,4}(?=\s*\(|\s+\S)/', '', $s);
		$out = array();
		foreach (preg_split('/[()+,;\/&]|-/', $s) as $part) {
			$part = strtoupper(trim(preg_replace('/\s+/', ' ', $part)));
			if ($part === '') {
				continue;
			}
			$norm = self::norm($part);
			if ($norm === '' || isset($out[$norm])) {
				continue;
			}
			if (preg_match('/^(\d{2}|2[AB])$/', $norm)) {
				$type = 'departement';
			} elseif (strpos($norm, 'STOCK') !== false) {
				$type = 'stock';
			} else {
				$type = 'chantier';
			}
			$out[$norm] = array('jeton' => $part, 'type' => $type, 'norm' => $norm);
		}
		return array_values($out);
	}

	/**
	 * Majuscules, sans espaces ni ponctuation, sans accents
	 *
	 * @param string $s Texte
	 * @return string
	 */
	public static function norm($s)
	{
		$s = (string) $s;
		if (function_exists('iconv')) {
			$t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if ($t !== false) {
				$s = $t;
			}
		}
		return preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
	}

	/**
	 * Correspondance d'un jeton avec la réf. fournisseur d'une commande
	 *
	 * @param array  $token Jeton (tokenize)
	 * @param string $ref   Réf. fournisseur de la commande candidate
	 * @return array|null [force (forte|faible), motif]
	 */
	public static function matchToken(array $token, $ref)
	{
		$ref = (string) $ref;
		$normRef = self::norm($ref);
		if ($normRef === '') {
			return null;
		}
		switch ($token['type']) {
			case 'stock':
				return stripos($ref, 'stock') !== false ? array('force' => 'forte', 'motif' => '« stock » dans « '.$ref.' »') : null;

			case 'departement':
				$d = $token['norm'];
				if (!ctype_digit($d)) {
					// Corse 2A / 2B
					return strpos($normRef, $d) !== false ? array('force' => 'faible', 'motif' => $d.' trouvé dans « '.$ref.' » (moins fiable)') : null;
				}
				preg_match_all('/\d+/', $normRef, $m);
				$weak = null;
				foreach ($m[0] as $num) {
					if ($num === $d) {
						return array('force' => 'forte', 'motif' => 'département '.$d.' dans « '.$ref.' »');
					}
					if ($weak === null && strlen($num) === 3 && strpos($num, $d) === 0) {
						$weak = array('force' => 'faible', 'motif' => 'nombre '.$num.' commençant par '.$d.' dans « '.$ref.' » (moins fiable)');
					}
				}
				return $weak;

			default:
				if (strpos($normRef, $token['norm']) !== false) {
					return array('force' => 'forte', 'motif' => 'chantier '.$token['jeton'].' dans « '.$ref.' »');
				}
				// Correspondance partielle : un mot d'au moins 4 lettres du jeton
				if (preg_match_all('/[A-Z]{4,}/', $token['norm'], $w)) {
					foreach ($w[0] as $word) {
						if (strpos($normRef, $word) !== false) {
							return array('force' => 'faible', 'motif' => 'nom partiel '.$word.' dans « '.$ref.' » (moins fiable)');
						}
					}
				}
				return null;
		}
	}

	/**
	 * Cohérence des dates d'une commande candidate avec la date d'enlèvement
	 *
	 * @param string|null $pickup     Date d'enlèvement AAAA-MM-JJ
	 * @param string|null $orderDate  Date de commande AAAA-MM-JJ
	 * @param string|null $delivery   Livraison prévue AAAA-MM-JJ
	 * @param int         $daysBefore Jours avant l'enlèvement (30)
	 * @param int         $daysAfter  Jours après l'enlèvement (15)
	 * @return array [etat (ok|inconnu|hors), motif]
	 */
	public static function checkDates($pickup, $orderDate, $delivery, $daysBefore, $daysAfter)
	{
		if (!$pickup) {
			return array('etat' => 'inconnu', 'motif' => "date d'enlèvement inconnue");
		}
		$p = strtotime($pickup.' 12:00:00');
		if ($orderDate && strtotime($orderDate.' 12:00:00') > $p) {
			return array('etat' => 'hors', 'motif' => 'commandée le '.$orderDate.', après l\'enlèvement du '.$pickup);
		}
		if (!$delivery) {
			return array('etat' => 'inconnu', 'motif' => 'livraison prévue non renseignée');
		}
		$diff = (int) round((strtotime($delivery.' 12:00:00') - $p) / 86400);
		if ($diff < -$daysBefore || $diff > $daysAfter) {
			return array('etat' => 'hors', 'motif' => 'livraison prévue le '.$delivery.' ('.($diff >= 0 ? '+' : '').$diff.' j par rapport à l\'enlèvement)');
		}
		return array('etat' => 'ok', 'motif' => 'livraison prévue le '.$delivery.' ('.($diff >= 0 ? '+' : '').$diff.' j)');
	}

	/**
	 * Rapprochement d'un transport avec les commandes du fournisseur
	 *
	 * @param array $transport  ref_supplier, date_enlevement
	 * @param array $candidates Commandes : id, ref, ref_supplier, date_commande, date_livraison
	 * @param int   $daysBefore Fenêtre avant
	 * @param int   $daysAfter  Fenêtre après
	 * @return array statut (sûr|probable|non résolu), jetons, proposition
	 */
	public static function matchTransport(array $transport, array $candidates, $daysBefore = 30, $daysAfter = 15)
	{
		$tokens = self::tokenize((string) $transport['ref_supplier']);
		$res = array();
		$proposal = array();
		$states = array();
		foreach ($tokens as $tok) {
			$found = array();
			foreach ($candidates as $c) {
				$m = self::matchToken($tok, $c['ref_supplier']);
				if ($m === null) {
					continue;
				}
				$dates = self::checkDates($transport['date_enlevement'] ?? null, $c['date_commande'] ?? null, $c['date_livraison'] ?? null, $daysBefore, $daysAfter);
				if ($dates['etat'] === 'hors') {
					continue;
				}
				$score = ($m['force'] === 'forte' ? self::WEIGHT_STRONG : self::WEIGHT_WEAK)
					+ ($dates['etat'] === 'ok' ? self::BONUS_DATES_OK : self::BONUS_DATES_UNKNOWN);
				$found[] = array(
					'id' => (int) $c['id'],
					'ref' => $c['ref'],
					'ref_fournisseur' => $c['ref_supplier'],
					'date_commande' => $c['date_commande'] ?? null,
					'date_livraison' => $c['date_livraison'] ?? null,
					'force' => $m['force'],
					'score' => $score,
					'motif' => $m['motif'].' ; '.$dates['motif'],
				);
			}
			// Les correspondances fortes écartent les faibles
			$strong = array_values(array_filter($found, function ($f) {
				return $f['force'] === 'forte';
			}));
			if ($strong) {
				$found = $strong;
			}
			usort($found, function ($a, $b) {
				return $b['score'] - $a['score'];
			});

			if (!$found) {
				$st = 'non résolu';
			} elseif (count($found) === 1 && $found[0]['score'] === self::WEIGHT_STRONG + self::BONUS_DATES_OK) {
				$st = 'sûr';
			} else {
				$st = 'probable';
			}
			$states[] = $st;
			if ($found) {
				$proposal[$found[0]['id']] = $found[0]['ref'];
			}
			$res[] = array('jeton' => $tok['jeton'], 'type' => $tok['type'], 'statut' => $st, 'candidats' => $found);
		}

		if (!$tokens || in_array('non résolu', $states, true)) {
			$global = 'non résolu';
		} elseif (in_array('probable', $states, true)) {
			$global = 'probable';
		} else {
			$global = 'sûr';
		}
		return array('statut' => $global, 'jetons' => $res, 'proposition' => $proposal);
	}

	/**
	 * Classement des lignes d'une commande pour le prix rendu
	 *
	 * @param array $lines        Lignes triées par rang
	 * @param array $affectations [[ligne_frais, ligne_produit]] : frais spécifiques forcés
	 * @return array unite, principaux [id], specifiques [id frais => id produit], communs [id], ignorees [id => raison], non_classees [id => raison], alertes
	 * @throws Exception
	 */
	public static function classify(array $lines, array $affectations = array())
	{
		$alerts = array();
		$ignored = array();
		$byId = array();
		$products = array();
		foreach ($lines as $l) {
			$id = (int) $l['id'];
			$byId[$id] = $l;
			if ((int) ($l['special_code'] ?? 0) !== 0) {
				$ignored[$id] = 'ligne spéciale (titre, sous-total…)';
				continue;
			}
			if ((float) $l['qty'] == 0 && (float) $l['total_ht'] == 0) {
				$ignored[$id] = 'ligne sans quantité ni montant';
				continue;
			}
			if ((int) ($l['fk_product'] ?? 0) > 0 && (int) ($l['product_type'] ?? 0) === 0) {
				$products[] = $id;
			}
		}

		// Unité majoritaire des lignes produit, en montant HT (quelques accessoires à l'unité ne l'emportent pas
		// sur les m² même s'ils ont plus de lignes) ; à égalité : celle de la première ligne produit
		$weight = array();
		foreach ($products as $id) {
			$u = (string) ($byId[$id]['fk_unit'] ?? '');
			$weight[$u] = round(($weight[$u] ?? 0) + abs((float) $byId[$id]['total_ht']), 4);
		}
		$unit = null;
		if ($weight) {
			$max = max($weight);
			$first = (string) ($byId[$products[0]]['fk_unit'] ?? '');
			$unit = ($weight[$first] === $max) ? $first : (string) array_search($max, $weight, true);
			if (count(array_keys($weight, $max, true)) > 1) {
				$alerts[] = 'Unités hétérogènes : plusieurs unités à égalité parmi les produits, unité retenue celle de la première ligne produit';
			}
			if ($unit === '') {
				$alerts[] = 'Unités hétérogènes : unité non renseignée sur les produits principaux (vérifier les unités des lignes)';
			}
		}

		$principal = array();
		foreach ($products as $id) {
			if ((string) ($byId[$id]['fk_unit'] ?? '') === $unit) {
				$principal[$id] = true;
			}
		}

		// Affectations forcées
		$forced = array();
		foreach ($affectations as $a) {
			$f = (int) ($a['ligne_frais'] ?? 0);
			$p = (int) ($a['ligne_produit'] ?? 0);
			if (!isset($byId[$f])) {
				throw new Exception('Affectation : ligne de frais '.$f.' absente de la commande');
			}
			if (!isset($principal[$p])) {
				throw new Exception('Affectation : la ligne '.$p.' n\'est pas un produit principal de la commande');
			}
			if (isset($principal[$f])) {
				throw new Exception('Affectation : la ligne '.$f.' est un produit principal, pas un frais');
			}
			$forced[$f] = $p;
		}

		$specific = array();
		$common = array();
		$unclassified = array();
		$prevOwner = null; // produit principal auquel rattacher un accessoire qui suit
		foreach ($lines as $l) {
			$id = (int) $l['id'];
			if (isset($ignored[$id])) {
				continue;
			}
			if (isset($forced[$id])) {
				$specific[$id] = $forced[$id];
				unset($ignored[$id]);
				$prevOwner = $forced[$id];
				continue;
			}
			if (isset($principal[$id])) {
				$prevOwner = $id;
				continue;
			}
			$isProduct = (int) ($l['fk_product'] ?? 0) > 0 && (int) ($l['product_type'] ?? 0) === 0;
			if ($isProduct) {
				if ($prevOwner !== null) {
					$specific[$id] = $prevOwner; // accessoire placé juste après un produit principal (Boxes)
				} else {
					$unclassified[$id] = 'produit d\'une autre unité qui ne suit pas un produit principal : exclu du calcul (utiliser affectations)';
				}
				continue;
			}
			$common[] = $id; // ligne libre ou service : frais communs
			$prevOwner = null;
		}

		if ($unclassified) {
			$alerts[] = 'Ligne(s) non classée(s) : '.implode(', ', array_map(function ($id) use ($byId) {
				return ($byId[$id]['product_ref'] ?: $id);
			}, array_keys($unclassified)));
		}

		return array(
			'unite' => $unit,
			'principaux' => array_keys($principal),
			'specifiques' => $specific,
			'communs' => $common,
			'ignorees' => $ignored,
			'non_classees' => $unclassified,
			'alertes' => $alerts,
		);
	}

	/**
	 * Base de répartition d'une commande : quantité ou valeur totale de ses produits principaux
	 *
	 * @param array  $lines Lignes
	 * @param array  $cls   Résultat de classify()
	 * @param string $key   quantite | valeur
	 * @return float
	 */
	public static function keyTotal(array $lines, array $cls, $key)
	{
		$sum = 0.0;
		$byId = array();
		foreach ($lines as $l) {
			$byId[(int) $l['id']] = $l;
		}
		foreach ($cls['principaux'] as $id) {
			$sum += ($key === 'valeur') ? (float) $byId[$id]['total_ht'] : (float) $byId[$id]['qty'];
		}
		return $sum;
	}

	/**
	 * Prix rendu par produit principal
	 *
	 * @param array  $lines      Lignes
	 * @param array  $cls        Résultat de classify()
	 * @param string $key        quantite | valeur
	 * @param float  $transports Part des transports liés retenue pour cette commande
	 * @param float  $extra      Frais supplémentaires HT
	 * @return array lignes, frais_communs, base_repartition
	 * @throws Exception
	 */
	public static function compute(array $lines, array $cls, $key, $transports, $extra)
	{
		$byId = array();
		foreach ($lines as $l) {
			$byId[(int) $l['id']] = $l;
		}
		$commonLines = 0.0;
		foreach ($cls['communs'] as $id) {
			$commonLines += (float) $byId[$id]['total_ht'];
		}
		$common = (float) price2num($commonLines + (float) $transports + (float) $extra, 'MT');
		$base = self::keyTotal($lines, $cls, $key);
		if ($base <= 0) {
			throw new Exception('Base de répartition nulle : aucun produit principal avec une '.($key === 'valeur' ? 'valeur' : 'quantité').' positive');
		}

		$specSum = array();
		$specDetail = array();
		foreach ($cls['specifiques'] as $f => $p) {
			$specSum[$p] = ($specSum[$p] ?? 0) + (float) $byId[$f]['total_ht'];
			$specDetail[$p][] = ($byId[$f]['product_ref'] ?: ($byId[$f]['label'] ?: 'ligne '.$f)).' '.price2num($byId[$f]['total_ht'], 'MT');
		}

		$out = array();
		foreach ($cls['principaux'] as $id) {
			$l = $byId[$id];
			$qty = (float) $l['qty'];
			$k = ($key === 'valeur') ? (float) $l['total_ht'] : $qty;
			$spec = (float) ($specSum[$id] ?? 0);
			$share = (float) price2num($common * $k / $base, 'MU');
			$total = (float) $l['total_ht'] + $spec + $share;
			$unitPrice = $qty != 0 ? (float) price2num($total / $qty, 'MU') : null;
			$out[] = array(
				'ligne' => $id,
				'produit_id' => (int) $l['fk_product'],
				'produit' => (string) $l['product_ref'],
				'libelle' => (string) $l['label'],
				'unite' => (string) ($l['unite'] ?? ''),
				'qty' => $qty,
				'pu_achat' => (float) $l['subprice'],
				'remise_percent' => (float) ($l['remise_percent'] ?? 0),
				'total_ht_ligne' => (float) price2num($l['total_ht'], 'MT'),
				'frais_specifiques' => (float) price2num($spec, 'MT'),
				'frais_specifiques_detail' => $specDetail[$id] ?? array(),
				'frais_communs_alloues' => (float) price2num($share, 'MT'),
				'prix_rendu_calcul' => $unitPrice,
				'prix_rendu' => $unitPrice === null ? null : round($unitPrice, 2),
			);
		}
		return array('lignes' => $out, 'frais_communs' => $common, 'frais_communs_lignes' => (float) price2num($commonLines, 'MT'), 'base_repartition' => $base);
	}
}
