<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Outils factures : recherche, détail, contrôle commandé / livré / facturé
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

/**
 * Outils factures (lecture seule)
 * Dolibarr ne conserve pas le lien ligne de facture -> ligne de commande :
 * le rapprochement se fait par produit (ou par libellé pour les lignes libres).
 */
class ClaudeVerifInvoice extends ClaudeVerifTools
{
	const EPS = 0.001;

	/** @var string[] */
	private static $types = array(0 => 'standard', 1 => 'remplacement', 2 => 'avoir', 3 => 'acompte', 5 => 'situation');
	/** @var string[] */
	private static $shipStatus = array(0 => 'brouillon', 1 => 'validée', 2 => 'clôturée');

	/**
	 * @return array
	 */
	public static function definitions()
	{
		return array(
			array(
				'name' => 'search_factures',
				'description' => "Recherche des factures clients. Critères optionnels et combinables, plus récentes d'abord.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => array('type' => 'string', 'description' => 'Référence facture, ou partie'),
						'ref_client' => array('type' => 'string', 'description' => 'Référence client, ou partie'),
						'tiers' => array('type' => 'string', 'description' => 'Nom du client, ou partie'),
						'date_from' => array('type' => 'string', 'description' => 'Date de facture minimum, AAAA-MM-JJ'),
						'date_to' => array('type' => 'string', 'description' => 'Date de facture maximum, AAAA-MM-JJ'),
						'brouillons' => array('type' => 'boolean', 'description' => 'Uniquement les factures brouillon'),
						'limit' => array('type' => 'integer', 'description' => '1 à 50, défaut 20'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'get_facture',
				'description' => "Détail d'une facture client : en-tête, type, statut, lignes (avec % de situation), commandes, expéditions et factures liées.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => array('type' => 'string', 'description' => 'Référence exacte (brouillon : (PROVxx))'),
						'id' => array('type' => 'integer', 'description' => 'Identifiant interne (alternative à ref)'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'controle_facture',
				'description' => "Contrôle calculé d'une facture par rapport à ses commandes liées : pour chaque produit, quantités commandées, "
					."livrées (expéditions validées), déjà facturées (autres factures, avoirs déduits, situations au prorata), "
					."facturées sur cette facture et cumul, avec alertes (facturé > livré, facturé > commandé, prix différent, "
					."produit absent des commandes, livré non facturé). Fonctionne aussi sur une facture brouillon, avant validation. "
					."Les calculs sont exacts : les interpréter (services non expédiés, reliquats, lignes de port ou d'écotaxe) sans les recalculer.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => array('type' => 'string', 'description' => 'Référence de la facture'),
						'id' => array('type' => 'integer', 'description' => 'Identifiant interne (alternative à ref)'),
					),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * @param array $args Arguments
	 * @return array
	 * @throws Exception
	 */
	public function searchFactures($args)
	{
		$this->requireRight('facture', 'lire');
		$limit = max(1, min(50, (int) ($args['limit'] ?? 20)));

		$sql = "SELECT f.rowid, f.ref, f.ref_client, f.type, f.fk_statut, f.paye, f.datef, f.total_ht, s.nom as tiers";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').")";
		foreach (array('ref' => 'f.ref', 'ref_client' => 'f.ref_client', 'tiers' => 's.nom') as $key => $field) {
			$val = trim((string) ($args[$key] ?? ''));
			if ($val !== '') {
				$sql .= " AND ".$field." LIKE '%".$this->db->escape($this->db->escapeforlike($val))."%'";
			}
		}
		foreach (array('date_from' => '>=', 'date_to' => '<=') as $key => $op) {
			$val = trim((string) ($args[$key] ?? ''));
			if ($val !== '') {
				if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
					throw new Exception($key.' doit être au format AAAA-MM-JJ');
				}
				$sql .= " AND f.datef ".$op." '".$this->db->escape($val)."'";
			}
		}
		if (!empty($args['brouillons'])) {
			$sql .= " AND f.fk_statut = 0";
		}
		$sql .= " ORDER BY f.datef DESC, f.rowid DESC".$this->db->plimit($limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Erreur SQL : '.$this->db->lasterror());
		}
		$static = new Facture($this->db);
		$out = array();
		while ($o = $this->db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $o->rowid,
				'ref' => $o->ref,
				'ref_client' => $o->ref_client,
				'tiers' => $o->tiers,
				'type' => self::$types[(int) $o->type] ?? (string) $o->type,
				'statut' => $this->clean($static->LibStatut((int) $o->paye, (int) $o->fk_statut, 0, -1, (int) $o->type)),
				'date' => $this->date($this->db->jdate($o->datef)),
				'total_ht' => (float) $o->total_ht,
			);
		}
		$this->db->free($resql);
		return array('nombre' => count($out), 'factures' => $out);
	}

	/**
	 * @param array $args Arguments
	 * @return array
	 * @throws Exception
	 */
	public function getFacture($args)
	{
		$this->requireRight('facture', 'lire');
		$fac = $this->fetchFacture($args);
		$fac->fetch_thirdparty();

		$lines = array();
		foreach ((array) $fac->lines as $l) {
			$lines[] = array(
				'rang' => (int) $l->rang,
				'type' => ((int) $l->product_type === 1 ? 'service' : 'produit'),
				'product_ref' => (string) ($l->product_ref ?? $l->ref ?? ''),
				'product_label' => (string) ($l->product_label ?? ''),
				'description' => $this->clean($l->desc),
				'qty' => (float) $l->qty,
				'unite' => method_exists($l, 'getLabelOfUnit') ? $this->clean($l->getLabelOfUnit('short')) : '',
				'pu_ht' => (float) $l->subprice,
				'remise_percent' => (float) $l->remise_percent,
				'total_ht' => (float) $l->total_ht,
				'situation_percent' => ((int) $fac->type === Facture::TYPE_SITUATION) ? (float) $l->situation_percent : null,
				'extrafields' => $this->formatExtrafields('facturedet', $l->array_options),
			);
		}

		$orders = $this->linkedIds('facture', $fac->id, 'commande');
		$ships = $this->linkedIds('facture', $fac->id, 'shipping');

		return array(
			'id' => (int) $fac->id,
			'ref' => $fac->ref,
			'ref_client' => $fac->ref_client,
			'type' => self::$types[(int) $fac->type] ?? (string) $fac->type,
			'statut' => $this->clean($fac->getLibStatut(0)),
			'date' => $this->date($fac->date),
			'client' => array('id' => (int) $fac->thirdparty->id, 'nom' => $fac->thirdparty->name),
			'situation' => ((int) $fac->type === Facture::TYPE_SITUATION) ? array('numero' => (int) $fac->situation_counter, 'finale' => (bool) $fac->situation_final) : null,
			'facture_source' => $fac->fk_facture_source ? $this->refOf('facture', (int) $fac->fk_facture_source) : null,
			'totaux' => array('ht' => (float) $fac->total_ht, 'tva' => (float) $fac->total_tva, 'ttc' => (float) $fac->total_ttc),
			'note_publique' => $this->clean($fac->note_public),
			'note_privee' => $this->clean($fac->note_private),
			'extrafields' => $this->formatExtrafields('facture', $fac->array_options),
			'commandes_liees' => array_map(function ($id) {
				return $this->refOf('commande', $id);
			}, $orders),
			'expeditions_liees' => array_map(function ($id) {
				return $this->refOf('expedition', $id);
			}, $ships),
			'lignes' => $lines,
			'url' => dol_buildpath('/compta/facture/card.php', 2).'?id='.((int) $fac->id),
		);
	}

	/**
	 * Contrôle commandé / livré / facturé
	 *
	 * @param array $args Arguments
	 * @return array
	 * @throws Exception
	 */
	public function controleFacture($args)
	{
		$this->requireRight('facture', 'lire');
		$this->requireRight('commande', 'lire');
		$fac = $this->fetchFacture($args);
		$facId = (int) $fac->id;

		if ((int) $fac->type === Facture::TYPE_DEPOSIT) {
			return array('facture' => $fac->ref, 'resultat' => "Facture d'acompte : montant forfaitaire, pas de contrôle de quantités.");
		}

		// Commandes liées (pour un avoir : celles de la facture d'origine)
		$orderIds = $this->linkedIds('facture', $facId, 'commande');
		if (!$orderIds && $fac->fk_facture_source) {
			$orderIds = $this->linkedIds('facture', (int) $fac->fk_facture_source, 'commande');
		}
		if (!$orderIds) {
			return array('facture' => $fac->ref, 'resultat' => "Aucune commande liée à cette facture : contrôle impossible. Vérifier le lien dans l'onglet Objets liés.");
		}
		$in = implode(',', array_map('intval', $orderIds));

		$rows = array();

		// 1. Commandé
		$sql = "SELECT cd.fk_product, cd.product_type, cd.qty, cd.subprice, cd.remise_percent, cd.description, cd.label, p.ref as pref, p.label as plabel";
		$sql .= " FROM ".MAIN_DB_PREFIX."commandedet as cd";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = cd.fk_commande";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = cd.fk_product";
		$sql .= " WHERE cd.fk_commande IN (".$in.") AND c.fk_statut <> -1 AND cd.product_type IN (0, 1)";
		foreach ($this->rowsOf($sql) as $o) {
			$k = $this->key($o);
			$this->initRow($rows, $k, $o);
			$rows[$k]['commande'] += (float) $o->qty;
			$rows[$k]['_pu_cmd'][] = $this->net($o->subprice, $o->remise_percent);
		}

		// 2. Livré (expéditions validées ou clôturées)
		$sql = "SELECT cd.fk_product, cd.product_type, cd.description, cd.label, p.ref as pref, p.label as plabel, SUM(ed.qty) as qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet as ed";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."expedition as e ON e.rowid = ed.fk_expedition";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commandedet as cd ON cd.rowid = ed.fk_elementdet";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = cd.fk_product";
		$sql .= " WHERE cd.fk_commande IN (".$in.") AND e.fk_statut IN (1, 2)";
		$sql .= " GROUP BY cd.rowid, cd.fk_product, cd.product_type, cd.description, cd.label, p.ref, p.label";
		foreach ($this->rowsOf($sql) as $o) {
			$k = $this->key($o);
			$this->initRow($rows, $k, $o);
			$rows[$k]['livre'] += (float) $o->qty;
		}

		// 3. Factures concernées : liées aux commandes + cette facture + avoirs sur ces factures
		$invIds = array($facId => true);
		foreach ($orderIds as $oid) {
			foreach ($this->linkedIds('commande', $oid, 'facture') as $fid) {
				$invIds[$fid] = true;
			}
		}
		$list = implode(',', array_map('intval', array_keys($invIds)));
		foreach ($this->rowsOf("SELECT rowid FROM ".MAIN_DB_PREFIX."facture WHERE type = 2 AND fk_facture_source IN (".$list.")") as $o) {
			$invIds[(int) $o->rowid] = true;
		}
		$list = implode(',', array_map('intval', array_keys($invIds)));
		$proforma = $this->proformaIds(array_keys($invIds));

		$others = array();
		$ignored = array();
		$sql = "SELECT rowid, ref, type, fk_statut, paye, datef FROM ".MAIN_DB_PREFIX."facture WHERE rowid IN (".$list.") ORDER BY datef, rowid";
		$counted = array();
		$static = new Facture($this->db);
		foreach ($this->rowsOf($sql) as $o) {
			$info = array(
				'ref' => $o->ref,
				'type' => self::$types[(int) $o->type] ?? (string) $o->type,
				'statut' => $this->clean($static->LibStatut((int) $o->paye, (int) $o->fk_statut, 0, -1, (int) $o->type)),
				'date' => $this->date($this->db->jdate($o->datef)),
			);
			if ((int) $o->rowid === $facId) {
				$counted[(int) $o->rowid] = (int) $o->type;
				continue;
			}
			if ((int) $o->fk_statut === 0 || (int) $o->fk_statut === 3 || (int) $o->type === 3) {
				if ((int) $o->type === 3) {
					$info['non_comptee'] = 'acompte';
				} elseif ((int) $o->fk_statut === 0) {
					$info['non_comptee'] = !empty($proforma[(int) $o->rowid]) ? 'pro forma' : 'brouillon (à valider)';
				} else {
					$info['non_comptee'] = 'abandonnée';
				}
				$ignored[] = $info;
				continue;
			}
			$counted[(int) $o->rowid] = (int) $o->type;
			$others[] = $info;
		}

		// 4. Facturé (contributions : avoirs en négatif, situations au prorata de l'avancement)
		if ($counted) {
			$sql = "SELECT fd.rowid, fd.fk_facture, fd.fk_product, fd.product_type, fd.qty, fd.subprice, fd.remise_percent, fd.description, fd.label,";
			$sql .= " fd.situation_percent, prev.situation_percent as prev_percent, p.ref as pref, p.label as plabel";
			$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as fd";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facturedet as prev ON prev.rowid = fd.fk_prev_id";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = fd.fk_product";
			$sql .= " WHERE fd.fk_facture IN (".implode(',', array_keys($counted)).") AND fd.product_type IN (0, 1)";
			foreach ($this->rowsOf($sql) as $o) {
				$type = $counted[(int) $o->fk_facture];
				$qty = (float) $o->qty;
				if ($type === Facture::TYPE_SITUATION) {
					$qty = $qty * ((float) $o->situation_percent - (float) ($o->prev_percent ?? 0)) / 100;
				}
				if ($type === Facture::TYPE_CREDIT_NOTE) {
					$qty = -abs($qty);
				}
				$k = $this->key($o);
				$this->initRow($rows, $k, $o);
				if ((int) $o->fk_facture === $facId) {
					$rows[$k]['cette_facture'] += $qty;
					if ($type !== Facture::TYPE_CREDIT_NOTE) {
						$rows[$k]['_pu_fac'][] = $this->net(abs((float) $o->subprice), $o->remise_percent);
					}
				} else {
					$rows[$k]['facture_avant'] += $qty;
				}
			}
		}

		// 5. Alertes
		$out = array();
		$nbAlerts = 0;
		foreach ($rows as $r) {
			$isProduct = ($r['type'] === 'produit');
			$cumul = $r['facture_avant'] + $r['cette_facture'];
			$alerts = array();
			$onThis = abs($r['cette_facture']) > self::EPS;

			if ($r['commande'] <= self::EPS && $onThis) {
				$alerts[] = 'ABSENT DES COMMANDES';
			}
			if ($r['commande'] > self::EPS && $cumul > $r['commande'] + self::EPS) {
				$alerts[] = 'FACTURÉ > COMMANDÉ';
			}
			if ($isProduct && $cumul > $r['livre'] + self::EPS && ($onThis || $r['facture_avant'] > $r['livre'] + self::EPS)) {
				$alerts[] = 'FACTURÉ > LIVRÉ';
			}
			if ($cumul < -self::EPS) {
				$alerts[] = 'CUMUL NÉGATIF';
			}
			if ($isProduct && $r['livre'] > $cumul + self::EPS) {
				$alerts[] = 'LIVRÉ NON FACTURÉ';
			}
			if (!$isProduct && $r['commande'] > self::EPS && $cumul < $r['commande'] - self::EPS) {
				$alerts[] = 'SERVICE PAS ENTIÈREMENT FACTURÉ';
			}
			$puCmd = array_values(array_unique(array_map(function ($v) {
				return round($v, 4);
			}, $r['_pu_cmd'])));
			$puFac = array_values(array_unique(array_map(function ($v) {
				return round($v, 4);
			}, $r['_pu_fac'])));
			if ($puCmd && $puFac) {
				foreach ($puFac as $pf) {
					$match = false;
					foreach ($puCmd as $pc) {
						if (abs($pf - $pc) < 0.005) {
							$match = true;
						}
					}
					if (!$match) {
						$alerts[] = 'PRIX DIFFÉRENT';
						break;
					}
				}
			}
			$nbAlerts += count(array_diff($alerts, array('LIVRÉ NON FACTURÉ')));

			$out[] = array(
				'ref' => $r['ref'],
				'libelle' => $r['libelle'],
				'type' => $r['type'],
				'commande' => round($r['commande'], 4),
				'livre' => $isProduct ? round($r['livre'], 4) : null,
				'deja_facture' => round($r['facture_avant'], 4),
				'cette_facture' => round($r['cette_facture'], 4),
				'cumul_facture' => round($cumul, 4),
				'reste_livre_a_facturer' => $isProduct ? round($r['livre'] - $cumul, 4) : null,
				'pu_net_commande' => $puCmd,
				'pu_net_facture' => $puFac,
				'alertes' => $alerts,
			);
		}

		// Expéditions des commandes
		$ships = array();
		$sql = "SELECT DISTINCT e.ref, e.fk_statut, e.date_expedition, e.date_delivery FROM ".MAIN_DB_PREFIX."expedition as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."expeditiondet as ed ON ed.fk_expedition = e.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commandedet as cd ON cd.rowid = ed.fk_elementdet";
		$sql .= " WHERE cd.fk_commande IN (".$in.") ORDER BY e.ref";
		foreach ($this->rowsOf($sql) as $o) {
			$ships[] = array(
				'ref' => $o->ref,
				'statut' => self::$shipStatus[(int) $o->fk_statut] ?? (string) $o->fk_statut,
				'date' => $this->date($this->db->jdate($o->date_expedition ?: $o->date_delivery)),
				'comptee' => in_array((int) $o->fk_statut, array(1, 2), true),
			);
		}

		return array(
			'facture' => array(
				'ref' => $fac->ref,
				'type' => self::$types[(int) $fac->type] ?? (string) $fac->type,
				'statut' => $this->clean($fac->getLibStatut(0)),
				'total_ht' => (float) $fac->total_ht,
			),
			'commandes' => array_map(function ($id) {
				return $this->refOf('commande', $id);
			}, $orderIds),
			'expeditions' => $ships,
			'autres_factures_comptees' => $others,
			'factures_non_comptees' => $ignored,
			'nb_alertes' => $nbAlerts,
			'lignes' => $out,
			'regles' => "Rapprochement par produit (ou libellé pour les lignes libres), toutes commandes liées confondues. "
				."Livré = expéditions validées ou clôturées. Déjà facturé = autres factures validées liées aux commandes, avoirs déduits, "
				."situations au prorata de l'avancement ; pro forma, brouillons, abandonnées et acomptes exclus. "
				."Une pro forma (brouillon avec la case Pro forma cochée) est normale et sans risque. "
				."Un simple brouillon lié à une commande déjà facturée est un risque de double facturation. "
				."LIVRÉ NON FACTURÉ est informatif (reliquat à facturer).",
		);
	}

	/**
	 * @param array $args ref|id
	 * @return Facture
	 * @throws Exception
	 */
	private function fetchFacture($args)
	{
		$id = (int) ($args['id'] ?? 0);
		$ref = trim((string) ($args['ref'] ?? ''));
		if (!$id && $ref === '') {
			throw new Exception("Indiquer 'ref' ou 'id'");
		}
		$fac = new Facture($this->db);
		if ($fac->fetch($id, $ref) <= 0) {
			throw new Exception('Facture introuvable : '.($ref !== '' ? $ref : $id));
		}
		return $fac;
	}

	/**
	 * Identifiants liés dans les deux sens (llx_element_element)
	 *
	 * @param string $type      Type de l'objet
	 * @param int    $id        Id de l'objet
	 * @param string $otherType Type cherché (commande, facture, shipping)
	 * @return int[]
	 */
	private function linkedIds($type, $id, $otherType)
	{
		$t = $this->db->escape($type);
		$o = $this->db->escape($otherType);
		$id = (int) $id;
		$sql = "SELECT fk_target as oid FROM ".MAIN_DB_PREFIX."element_element WHERE sourcetype = '".$t."' AND fk_source = ".$id." AND targettype = '".$o."'";
		$sql .= " UNION SELECT fk_source as oid FROM ".MAIN_DB_PREFIX."element_element WHERE targettype = '".$t."' AND fk_target = ".$id." AND sourcetype = '".$o."'";
		$ids = array();
		foreach ($this->rowsOf($sql) as $r) {
			$ids[] = (int) $r->oid;
		}
		return array_values(array_unique($ids));
	}

	/**
	 * @param string $table commande|facture|expedition
	 * @param int    $id    Id
	 * @return string
	 */
	private function refOf($table, $id)
	{
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX.$this->db->escape($table)." WHERE rowid = ".((int) $id);
		$r = $this->rowsOf($sql);
		return $r ? (string) $r[0]->ref : '#'.$id;
	}

	/**
	 * @param string $sql Requête
	 * @return array
	 * @throws Exception
	 */
	private function rowsOf($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Erreur SQL : '.$this->db->lasterror());
		}
		$out = array();
		while ($o = $this->db->fetch_object($resql)) {
			$out[] = $o;
		}
		$this->db->free($resql);
		return $out;
	}

	/**
	 * Clé de rapprochement : produit, sinon libellé normalisé
	 *
	 * @param object $o Ligne
	 * @return string
	 */
	private function key($o)
	{
		if ((int) $o->fk_product > 0) {
			return 'p'.(int) $o->fk_product;
		}
		$label = trim((string) ($o->label ?? ''));
		if ($label === '') {
			$label = mb_substr(trim(preg_replace('/\s+/', ' ', dol_string_nohtmltag((string) $o->description))), 0, 80);
		}
		return 'l'.md5(mb_strtolower($label));
	}

	/**
	 * @param array  $rows Lignes (par référence)
	 * @param string $k    Clé
	 * @param object $o    Ligne SQL
	 * @return void
	 */
	private function initRow(&$rows, $k, $o)
	{
		if (isset($rows[$k])) {
			return;
		}
		$label = (string) ($o->plabel ?? '');
		if ($label === '') {
			$label = trim((string) ($o->label ?? ''));
		}
		if ($label === '') {
			$label = mb_substr(trim(preg_replace('/\s+/', ' ', dol_string_nohtmltag((string) $o->description))), 0, 80);
		}
		$rows[$k] = array(
			'ref' => (string) ($o->pref ?? ''),
			'libelle' => $label,
			'type' => ((int) $o->product_type === 1 ? 'service' : 'produit'),
			'commande' => 0.0,
			'livre' => 0.0,
			'facture_avant' => 0.0,
			'cette_facture' => 0.0,
			'_pu_cmd' => array(),
			'_pu_fac' => array(),
		);
	}

	/**
	 * @param mixed $pu     Prix unitaire
	 * @param mixed $remise Remise %
	 * @return float
	 */
	private function net($pu, $remise)
	{
		return (float) $pu * (1 - (float) $remise / 100);
	}
}
