<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Outil stock : stock physique et stock virtuel d'un produit
 */

/**
 * Outil stock (lecture seule)
 * Stock virtuel = stock physique - reste à livrer sur commandes clients validées / en cours
 *                 + reste à recevoir sur commandes fournisseurs commandées / reçues partiellement.
 */
class ClaudeVerifStock extends ClaudeVerifTools
{
	/**
	 * @return array
	 */
	public static function definitions()
	{
		return array(
			array(
				'name' => 'get_stock',
				'description' => "Vérification de stock d'un ou plusieurs produits : stock physique, reste à livrer sur les commandes clients "
					."(validées ou en cours, moins les expéditions validées), reste à recevoir sur les commandes fournisseurs "
					."(commandées ou reçues partiellement, moins les réceptions), stock virtuel et date du dernier mouvement. "
					."Les calculs sont exacts : les interpréter sans les recalculer.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => array('type' => 'string', 'description' => 'Référence ou libellé du produit, ou partie'),
						'id' => array('type' => 'integer', 'description' => 'Identifiant interne du produit (alternative à ref)'),
						'limit' => array('type' => 'integer', 'description' => '1 à 50, défaut 20'),
					),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * @param array $args ref|id, limit
	 * @return array
	 * @throws Exception
	 */
	public function getStock($args)
	{
		$this->requireRight('produit', 'lire');

		$id = (int) ($args['id'] ?? 0);
		$ref = trim((string) ($args['ref'] ?? ''));
		if (!$id && $ref === '') {
			throw new Exception("Indiquer 'ref' ou 'id'");
		}
		$limit = max(1, min(50, (int) ($args['limit'] ?? 20)));

		// Reste à livrer : commandes clients validées (1) ou en cours (2), moins les expéditions validées ou clôturées
		$cdesClient = "COALESCE((SELECT SUM(cd.qty) FROM ".MAIN_DB_PREFIX."commandedet cd"
			." INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande"
			." WHERE cd.fk_product = p.rowid AND c.fk_statut IN (1, 2)), 0)"
			." - COALESCE((SELECT SUM(ed.qty) FROM ".MAIN_DB_PREFIX."expeditiondet ed"
			." INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition"
			." INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.rowid = ed.fk_elementdet"
			." INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande"
			." WHERE cd.fk_product = p.rowid AND c.fk_statut IN (1, 2) AND e.fk_statut >= 1), 0)";
		// Reste à recevoir : commandes fournisseurs commandées (3) ou reçues partiellement (4), moins les réceptions
		$cdesFourn = "COALESCE((SELECT SUM(cfd.qty - COALESCE((SELECT SUM(rdb.qty) FROM ".MAIN_DB_PREFIX."receptiondet_batch rdb"
			." INNER JOIN ".MAIN_DB_PREFIX."reception r ON r.rowid = rdb.fk_reception"
			." WHERE rdb.fk_elementdet = cfd.rowid), 0))"
			." FROM ".MAIN_DB_PREFIX."commande_fournisseurdet cfd"
			." INNER JOIN ".MAIN_DB_PREFIX."commande_fournisseur cf ON cf.rowid = cfd.fk_commande"
			." WHERE cfd.fk_product = p.rowid AND cf.fk_statut IN (3, 4)), 0)";

		$sql = "SELECT p.rowid, p.ref, p.label, COALESCE(p.stock, 0) as stock_physique,";
		$sql .= " (".$cdesClient.") as cdes_client,";
		$sql .= " (".$cdesFourn.") as cdes_fournisseur,";
		$sql .= " (SELECT MAX(sm.datem) FROM ".MAIN_DB_PREFIX."stock_mouvement sm WHERE sm.fk_product = p.rowid) as dernier_mouvement";
		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= " WHERE p.entity IN (".getEntity('product').")";
		if ($id) {
			$sql .= " AND p.rowid = ".$id;
		} else {
			$like = $this->db->escape($this->db->escapeforlike($ref));
			$sql .= " AND (p.ref LIKE '%".$like."%' OR p.label LIKE '%".$like."%')";
		}
		$sql .= " ORDER BY p.ref";
		$sql .= $this->db->plimit($limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Erreur SQL : '.$this->db->lasterror());
		}

		$out = array();
		while ($o = $this->db->fetch_object($resql)) {
			$physique = (float) $o->stock_physique;
			$client = (float) $o->cdes_client;
			$fourn = (float) $o->cdes_fournisseur;
			$out[] = array(
				'id' => (int) $o->rowid,
				'ref' => $o->ref,
				'libelle' => $o->label,
				'stock_physique' => round($physique, 2),
				'cdes_client' => round($client, 2),
				'cdes_fournisseur' => round($fourn, 2),
				'stock_virtuel' => round($physique - $client + $fourn, 2),
				'dernier_mouvement' => $this->date($this->db->jdate($o->dernier_mouvement)),
			);
		}
		$this->db->free($resql);

		if (!$out) {
			throw new Exception('Produit introuvable : '.($id ? $id : $ref));
		}

		return array(
			'nombre' => count($out),
			'produits' => $out,
			'regles' => "Stock virtuel = stock physique - cdes client + cdes fournisseur. "
				."Cdes client = reste à livrer sur les commandes clients validées ou en cours (quantités commandées moins expéditions validées ou clôturées). "
				."Cdes fournisseur = reste à recevoir sur les commandes fournisseurs commandées ou reçues partiellement (quantités commandées moins réceptions). "
				."Un stock virtuel négatif signale un besoin d'approvisionnement.",
		);
	}
}
