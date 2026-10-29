<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Outils commandes fournisseurs : recherche, détail, rapprochement des transports,
 * liaison transport / commandes, prix rendu Brest et mise à jour du prix de revient.
 */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/claudeverif/class/claudeverifsupplierlogic.class.php');

/**
 * Outils commandes fournisseurs.
 * Lecture : search_commandes_fournisseur, get_commande_fournisseur, rapprocher_transports, calculer_prix_rendu.
 * Écriture (désactivée par défaut) : lier_commandes_fournisseur (CLAUDEVERIF_ALLOW_LINK), maj_prix_revient (CLAUDEVERIF_ALLOW_COSTPRICE).
 */
class ClaudeVerifSupplier extends ClaudeVerifTools
{
	const ELEMENT = 'order_supplier';
	/** Commandes annulées ou refusées : exclues des rapprochements */
	const STATUS_EXCLUDED = array(6, 7, 9);
	const MAX_COST_GAP = 0.30;

	/**
	 * Outils exposés (les outils d'écriture seulement si leur constante est à 1)
	 *
	 * @return array
	 */
	public static function definitions()
	{
		if (!isModEnabled('fournisseur') && !isModEnabled('supplier_order')) {
			return array();
		}
		$defs = array(
			array(
				'name' => 'search_commandes_fournisseur',
				'description' => "Recherche des commandes fournisseurs (y compris les commandes de transport passées aux transporteurs). "
					."Critères optionnels et combinables (recherche partielle sur les textes), plus récentes d'abord. "
					."Statuts : 0 brouillon, 1 validée, 2 approuvée, 3 commandée, 4 reçue partiellement, 5 reçue, 6 et 7 annulée, 9 refusée.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'fournisseur' => array('type' => 'string', 'description' => 'Nom du fournisseur ou du transporteur, ou partie'),
						'ref' => array('type' => 'string', 'description' => 'Référence Dolibarr ou partie (ex. PO2610-1029)'),
						'ref_fournisseur' => array('type' => 'string', 'description' => 'Réf. fournisseur, ou partie (chantier, ex. LN 352)'),
						'date_from' => array('type' => 'string', 'description' => 'Date de commande minimum, AAAA-MM-JJ'),
						'date_to' => array('type' => 'string', 'description' => 'Date de commande maximum, AAAA-MM-JJ'),
						'statut' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'Statuts voulus (ex. [3, 4])'),
						'contient_produit' => array('type' => 'string', 'description' => 'Réf. produit présente sur une ligne, ou partie (ex. ADRIT_METALSCREEN)'),
						'limit' => array('type' => 'integer', 'description' => '1 à 50, défaut 20'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'get_commande_fournisseur',
				'description' => "Détail d'une commande fournisseur : en-tête (réf. fournisseur, tiers, dates, statut, montants, notes, contacts), "
					."lignes (produit, description complète, type, unité, quantité, P.U. HT, remise, total HT, quantité déjà réceptionnée) "
					."et commandes fournisseurs liées. Pour une commande de transport (ligne ADRIT_…) : fournisseur d'enlèvement, "
					."date d'enlèvement, date de livraison, colisage et poids, tirés du texte des lignes.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => array('type' => 'string', 'description' => 'Référence exacte (ex. PO2610-1043)'),
						'id' => array('type' => 'integer', 'description' => 'Identifiant interne (alternative à ref)'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'rapprocher_transports',
				'description' => "Rapproche les commandes de transport (enlèvement chez un fournisseur, ligne ADRIT_<FOURNISSEUR>) des commandes "
					."du fournisseur qu'elles transportent, d'après la réf. fournisseur du transport (chantiers, départements, Stock) et les dates. "
					."Pour chaque transport : candidats avec score et motif, classement sûr / probable / non résolu. N'écrit rien : "
					."présenter le tableau à l'utilisateur et obtenir sa validation avant lier_commandes_fournisseur.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'fournisseur' => array('type' => 'string', 'description' => 'Fournisseur chez qui a lieu l\'enlèvement (ex. Metalscreen)'),
						'produit_enlevement' => array('type' => 'string', 'description' => 'Réf. exacte du produit adresse d\'enlèvement si elle ne se déduit pas du nom (ex. ADRIT_METALSCREEN)'),
						'inclure_lies' => array('type' => 'boolean', 'description' => 'Inclure les transports déjà liés (pour contrôle). Défaut : false'),
						'date_from' => array('type' => 'string', 'description' => 'Transports commandés à partir de cette date, AAAA-MM-JJ'),
						'jours_avant' => array('type' => 'integer', 'description' => 'Livraison prévue de la commande au plus N jours avant l\'enlèvement (défaut 30)'),
						'jours_apres' => array('type' => 'integer', 'description' => 'Livraison prévue de la commande au plus N jours après l\'enlèvement (défaut 15)'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'calculer_prix_rendu',
				'description' => "Calcule le prix rendu Brest (frais d'approche inclus) de chaque produit d'une commande fournisseur : "
					."P.U. d'achat + frais spécifiques (accessoire placé juste après un produit, ex. Boxes) + part des frais communs "
					."(lignes libres et services, transports liés, frais supplémentaires) répartie à la quantité ou à la valeur. "
					."Compare au prix de revient, au PMP et au prix fournisseur actuels. Les calculs sont exacts : les interpréter sans les recalculer. N'écrit rien.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'commande' => array('type' => 'string', 'description' => 'Réf. (ex. PO2610-1029) ou id de la commande fournisseur (pas du transport)'),
						'cle_repartition' => array('type' => 'string', 'enum' => array('quantite', 'valeur'), 'description' => 'Répartition des frais communs. Défaut : quantite'),
						'affectations' => array(
							'type' => 'array',
							'description' => 'Frais à rattacher à un seul produit : [{ligne_frais: id, ligne_produit: id}] (id de ligne donnés par get_commande_fournisseur)',
							'items' => array(
								'type' => 'object',
								'properties' => array(
									'ligne_frais' => array('type' => 'integer'),
									'ligne_produit' => array('type' => 'integer'),
								),
								'required' => array('ligne_frais', 'ligne_produit'),
								'additionalProperties' => false,
							),
						),
						'frais_supplementaires' => array('type' => 'number', 'description' => 'Frais communs HT non saisis (ex. transport non commandé), en euros'),
					),
					'required' => array('commande'),
					'additionalProperties' => false,
				),
			),
		);

		if (getDolGlobalInt('CLAUDEVERIF_ALLOW_LINK')) {
			$defs[] = array(
				'name' => 'lier_commandes_fournisseur',
				'description' => "ÉCRITURE — n'appeler qu'après validation explicite de l'utilisateur. Lie une commande de transport (ligne ADRIT_…) "
					."aux commandes fournisseurs qu'elle transporte (lien Dolibarr visible des deux côtés). Ne crée que les liens demandés, "
					."ne supprime rien ; un lien existant est signalé « déjà lié ».",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'transport' => array('type' => 'string', 'description' => 'Réf. ou id de la commande de transport'),
						'commandes' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Réf. ou id des commandes transportées'),
					),
					'required' => array('transport', 'commandes'),
					'additionalProperties' => false,
				),
			);
		}
		if (getDolGlobalInt('CLAUDEVERIF_ALLOW_COSTPRICE')) {
			$defs[] = array(
				'name' => 'maj_prix_revient',
				'description' => "ÉCRITURE — n'appeler qu'après validation explicite de l'utilisateur, sur le tableau ancien → nouveau prix proposé. "
					."Met à jour uniquement le prix de revient (cost_price) des produits indiqués ; ne touche ni au PMP, ni aux prix fournisseurs, "
					."ni aux prix de vente. Un écart de plus de 30 % avec le prix actuel est refusé sauf forcer = true.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'produits' => array(
							'type' => 'array',
							'items' => array(
								'type' => 'object',
								'properties' => array(
									'produit' => array('type' => 'string', 'description' => 'Réf. ou id du produit'),
									'cost_price' => array('type' => 'number', 'description' => 'Nouveau prix de revient HT'),
								),
								'required' => array('produit', 'cost_price'),
								'additionalProperties' => false,
							),
						),
						'commande_source' => array('type' => 'string', 'description' => 'Réf. de la commande fournisseur d\'où vient le calcul (trace)'),
						'forcer' => array('type' => 'boolean', 'description' => 'Accepter un écart de plus de 30 %. Défaut : false'),
					),
					'required' => array('produits', 'commande_source'),
					'additionalProperties' => false,
				),
			);
		}
		return $defs;
	}

	/*
	 * Lecture
	 */

	/**
	 * @param array $args Critères
	 * @return array
	 * @throws Exception
	 */
	public function searchCommandesFournisseur($args)
	{
		$this->requireSupplierRight('lire');

		$limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
		$sql = "SELECT cf.rowid, cf.ref, cf.ref_supplier, cf.date_commande, cf.date_livraison, cf.fk_statut, cf.billed, cf.total_ht, s.nom as tiers,";
		$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."element_element ee WHERE ee.sourcetype = '".self::ELEMENT."' AND ee.targettype = '".self::ELEMENT."'";
		$sql .= " AND (ee.fk_source = cf.rowid OR ee.fk_target = cf.rowid)) as nb_lies";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande_fournisseur as cf";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = cf.fk_soc";
		$sql .= " WHERE cf.entity IN (".getEntity('supplier_order').")";
		foreach (array('ref' => 'cf.ref', 'ref_fournisseur' => 'cf.ref_supplier', 'fournisseur' => 's.nom') as $key => $field) {
			$val = trim((string) ($args[$key] ?? ''));
			if ($val !== '') {
				$sql .= " AND ".$field." LIKE '%".$this->db->escape($this->db->escapeforlike($val))."%'";
			}
		}
		$sql .= $this->dateFilter($args, 'cf.date_commande');
		$statuts = array_values(array_filter((array) ($args['statut'] ?? array()), 'is_numeric'));
		if ($statuts) {
			$sql .= " AND cf.fk_statut IN (".implode(',', array_map('intval', $statuts)).")";
		}
		$prod = trim((string) ($args['contient_produit'] ?? ''));
		if ($prod !== '') {
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."commande_fournisseurdet d";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = d.fk_product";
			$sql .= " WHERE d.fk_commande = cf.rowid AND p.ref LIKE '%".$this->db->escape($this->db->escapeforlike($prod))."%')";
		}
		$sql .= " ORDER BY cf.date_commande DESC, cf.rowid DESC";
		$sql .= $this->db->plimit($limit);

		$out = array();
		foreach ($this->rows($sql) as $o) {
			$out[] = array(
				'id' => (int) $o->rowid,
				'ref' => $o->ref,
				'ref_fournisseur' => (string) $o->ref_supplier,
				'tiers' => $o->tiers,
				'date_commande' => $this->date($this->db->jdate($o->date_commande)),
				'date_livraison' => $this->date($this->db->jdate($o->date_livraison)),
				'total_ht' => (float) $o->total_ht,
				'statut' => $this->statusLabel((int) $o->fk_statut, (int) $o->billed),
				'nb_commandes_fournisseur_liees' => (int) $o->nb_lies,
			);
		}
		return array('nombre' => count($out), 'commandes' => $out);
	}

	/**
	 * @param array $args ref | id
	 * @return array
	 * @throws Exception
	 */
	public function getCommandeFournisseur($args)
	{
		$this->requireSupplierRight('lire');

		$cf = $this->fetchOrder($args['id'] ?? ($args['ref'] ?? ''));
		$lines = $this->loadLines((int) $cf->id);

		$contacts = array();
		foreach ((array) $cf->liste_contact(-1, 'external') as $c) {
			$contacts[] = array(
				'type' => (string) $c['code'],
				'libelle_type' => (string) ($c['libelle'] ?? ''),
				'nom' => trim(($c['firstname'] ?? '').' '.($c['lastname'] ?? '')),
			);
		}
		foreach ((array) $cf->liste_contact(-1, 'internal') as $c) {
			$contacts[] = array(
				'type' => (string) $c['code'],
				'libelle_type' => (string) ($c['libelle'] ?? '').' (interne)',
				'nom' => trim(($c['firstname'] ?? '').' '.($c['lastname'] ?? '')),
			);
		}

		$outLines = array();
		foreach ($lines as $l) {
			$outLines[] = array(
				'id' => (int) $l['id'],
				'rang' => (int) $l['rang'],
				'type' => $this->lineType($l),
				'produit_ref' => (string) $l['product_ref'],
				'libelle' => (string) $l['label'],
				'description' => $this->clean($l['description']),
				'ref_fournisseur' => (string) $l['ref_supplier'],
				'unite' => (string) $l['unite'],
				'qty' => (float) $l['qty'],
				'pu_ht' => (float) $l['subprice'],
				'remise_percent' => (float) $l['remise_percent'],
				'total_ht' => (float) $l['total_ht'],
				'qty_receptionnee' => (float) $l['qty_recue'],
				'special_code' => (int) $l['special_code'],
			);
		}

		$linked = $this->linkedSummaries((int) $cf->id);
		$others = array();
		$cf->fetchObjectLinked();
		foreach ((array) $cf->linkedObjects as $type => $objs) {
			if ($type === self::ELEMENT) {
				continue;
			}
			foreach ((array) $objs as $o) {
				$others[] = array('type' => $type, 'id' => (int) $o->id, 'ref' => (string) ($o->ref ?? ''));
			}
		}

		$transport = ClaudeVerifSupplierLogic::parseTransport($lines, $this->pickupPrefix(), $this->transportProduct());

		$out = array(
			'id' => (int) $cf->id,
			'ref' => $cf->ref,
			'ref_fournisseur' => (string) $cf->ref_supplier,
			'tiers' => array('id' => (int) $cf->thirdparty->id, 'nom' => $cf->thirdparty->name),
			'statut' => $this->statusLabel((int) ($cf->status ?? $cf->statut), (int) $cf->billed),
			'facturee' => (bool) $cf->billed,
			'date_creation' => $this->date($cf->date_creation ?? null),
			'date_commande' => $this->date($cf->date_commande ?? null),
			'date_livraison' => $this->date($cf->delivery_date ?? null),
			'totaux' => array('ht' => (float) $cf->total_ht, 'tva' => (float) $cf->total_tva, 'ttc' => (float) $cf->total_ttc),
			'note_publique' => $this->clean($cf->note_public),
			'note_privee' => $this->clean($cf->note_private),
			'contacts' => $contacts,
			'extrafields' => $this->formatExtrafields('commande_fournisseur', $cf->array_options),
			'est_transport' => $transport !== null,
		);
		if ($transport !== null) {
			unset($transport['ligne_enlevement']);
			$out['transport'] = $transport;
		}
		$out['commandes_fournisseur_liees'] = $linked;
		$out['autres_objets_lies'] = $others;
		$out['nb_lignes'] = count($outLines);
		$out['lignes'] = $outLines;
		$out['url'] = dol_buildpath('/fourn/commande/card.php', 2).'?id='.((int) $cf->id);
		if ($transport !== null) {
			$out['regles'] = "Infos de transport tirées du texte des lignes : date d'enlèvement et de livraison dans la ligne ".$this->transportProduct()
				.", colisage et poids dans la ligne ".$this->pickupPrefix()."… (poids = plus grand nombre suivi de kg). Les vérifier dans la description si un champ est vide.";
		}
		return $out;
	}

	/**
	 * Rapprochement des transports d'un fournisseur avec ses commandes (lecture seule)
	 *
	 * @param array $args fournisseur, produit_enlevement, inclure_lies, date_from, jours_avant, jours_apres
	 * @return array
	 * @throws Exception
	 */
	public function rapprocherTransports($args)
	{
		$this->requireSupplierRight('lire');

		$name = trim((string) ($args['fournisseur'] ?? ''));
		$pickupRef = trim((string) ($args['produit_enlevement'] ?? ''));
		$withLinked = !empty($args['inclure_lies']);
		$before = max(0, min(365, (int) ($args['jours_avant'] ?? 30)));
		$after = max(0, min(365, (int) ($args['jours_apres'] ?? 15)));
		if ($name === '' && $pickupRef === '') {
			throw new Exception("Indiquer 'fournisseur' (ex. Metalscreen) ou 'produit_enlevement'");
		}
		$dateFrom = trim((string) ($args['date_from'] ?? ''));
		if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
			throw new Exception('date_from doit être au format AAAA-MM-JJ');
		}

		// Produit adresse d'enlèvement : ADRIT_<FOURNISSEUR>
		$prefix = $this->pickupPrefix();
		$sql = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."product WHERE entity IN (".getEntity('product').")";
		$sql .= " AND ref LIKE '".$this->db->escape($this->db->escapeforlike($prefix))."%'";
		$pickups = array();
		$all = array();
		foreach ($this->rows($sql) as $o) {
			$all[] = $o->ref;
			$suffix = ClaudeVerifSupplierLogic::norm(substr($o->ref, strlen($prefix)));
			if (($pickupRef !== '' && strcasecmp($o->ref, $pickupRef) === 0)
				|| ($pickupRef === '' && $suffix !== '' && $suffix === ClaudeVerifSupplierLogic::norm($name))) {
				$pickups[(int) $o->rowid] = $o->ref;
			}
		}
		if (!$pickups) {
			throw new Exception('Aucun produit d\'enlèvement '.($pickupRef !== '' ? $pickupRef : $prefix.strtoupper(ClaudeVerifSupplierLogic::norm($name)))
				.'. Produits '.$prefix.'… existants : '.($all ? implode(', ', $all) : 'aucun').' (paramètre produit_enlevement)');
		}
		$pickupLabel = implode(', ', $pickups);
		if ($name === '') {
			$name = substr(reset($pickups), strlen($prefix));
		}

		// Fournisseur(s) dont le nom correspond
		$sql = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".getEntity('societe').") AND fournisseur = 1";
		$sql .= " AND nom LIKE '%".$this->db->escape($this->db->escapeforlike($name))."%'";
		$socs = array();
		foreach ($this->rows($sql) as $o) {
			$socs[(int) $o->rowid] = $o->nom;
		}
		if (!$socs) {
			// Nom saisi sans espace (METALSCREEN pour « Metal Screen ») : comparaison normalisée
			foreach ($this->rows("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".getEntity('societe').") AND fournisseur = 1") as $o) {
				if (strpos(ClaudeVerifSupplierLogic::norm($o->nom), ClaudeVerifSupplierLogic::norm($name)) !== false) {
					$socs[(int) $o->rowid] = $o->nom;
				}
			}
		}
		if (!$socs) {
			throw new Exception('Fournisseur introuvable : '.$name);
		}

		// Toutes les commandes de transport (toute ligne d'enlèvement, quel que soit le fournisseur) : à exclure des candidats
		$transportIds = $this->transportIds();

		// Transports de ce fournisseur
		$sql = "SELECT cf.rowid, cf.ref, cf.ref_supplier, cf.date_commande, cf.date_livraison, cf.fk_statut, cf.billed, cf.total_ht, s.nom as tiers";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande_fournisseur cf INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = cf.fk_soc";
		$sql .= " WHERE cf.entity IN (".getEntity('supplier_order').")";
		$sql .= " AND cf.fk_statut NOT IN (".implode(',', self::STATUS_EXCLUDED).")";
		$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."commande_fournisseurdet d WHERE d.fk_commande = cf.rowid AND d.fk_product IN (".implode(',', array_keys($pickups))."))";
		if ($dateFrom !== '') {
			$sql .= " AND cf.date_commande >= '".$this->db->escape($dateFrom)."'";
		}
		$sql .= " ORDER BY cf.date_commande DESC, cf.rowid DESC";
		$transports = $this->rows($sql);

		// Commandes candidates du fournisseur (hors transports, hors annulées)
		$sql = "SELECT cf.rowid, cf.ref, cf.ref_supplier, cf.date_commande, cf.date_livraison";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande_fournisseur cf";
		$sql .= " WHERE cf.entity IN (".getEntity('supplier_order').")";
		$sql .= " AND cf.fk_soc IN (".implode(',', array_keys($socs)).")";
		$sql .= " AND cf.fk_statut NOT IN (".implode(',', self::STATUS_EXCLUDED).")";
		if ($dateFrom !== '') {
			$sql .= " AND (cf.date_commande IS NULL OR cf.date_commande >= '".$this->db->escape(date('Y-m-d', strtotime($dateFrom.' -120 days')))."')";
		}
		$candidates = array();
		foreach ($this->rows($sql) as $o) {
			if (isset($transportIds[(int) $o->rowid])) {
				continue;
			}
			$candidates[(int) $o->rowid] = array(
				'id' => (int) $o->rowid,
				'ref' => $o->ref,
				'ref_supplier' => (string) $o->ref_supplier,
				'date_commande' => $this->date($this->db->jdate($o->date_commande)),
				'date_livraison' => $this->date($this->db->jdate($o->date_livraison)),
			);
		}

		// Liens existants commande <-> transport
		$links = $this->linkPairs(array_keys($candidates), array_keys($transportIds));

		$results = array();
		$skipped = array();
		$counts = array('sûr' => 0, 'probable' => 0, 'non résolu' => 0);
		foreach ($transports as $t) {
			$tid = (int) $t->rowid;
			$linkedHere = array();
			foreach ($links as $pair) {
				if ($pair[1] === $tid && isset($candidates[$pair[0]])) {
					$linkedHere[$pair[0]] = $candidates[$pair[0]]['ref'];
				}
			}
			$linkedAny = $this->linkedIds($tid);
			if (!$withLinked && $linkedAny) {
				$skipped[] = $t->ref;
				continue;
			}

			$info = ClaudeVerifSupplierLogic::parseTransport($this->loadLines($tid), $prefix, $this->transportProduct());
			$pool = array();
			foreach ($candidates as $cid => $c) {
				$otherTransport = false;
				foreach ($links as $pair) {
					if ($pair[0] === $cid && $pair[1] !== $tid) {
						$otherTransport = true;
					}
				}
				if ($otherTransport && !$withLinked) {
					continue; // déjà transportée par un autre transport
				}
				$pool[] = $c;
			}

			$m = ClaudeVerifSupplierLogic::matchTransport(
				array('ref_supplier' => (string) $t->ref_supplier, 'date_enlevement' => $info['date_enlevement'] ?? null),
				$pool,
				$before,
				$after
			);
			$counts[$m['statut']]++;
			$row = array(
				'transport' => array(
					'id' => $tid,
					'ref' => $t->ref,
					'ref_fournisseur' => (string) $t->ref_supplier,
					'transporteur' => $t->tiers,
					'total_ht' => (float) $t->total_ht,
					'statut' => $this->statusLabel((int) $t->fk_statut, (int) $t->billed),
					'date_commande' => $this->date($this->db->jdate($t->date_commande)),
					'date_enlevement' => $info['date_enlevement'] ?? null,
					'date_livraison' => $info['date_livraison'] ?? null,
				),
				'statut' => $m['statut'],
				'proposition' => array_values($m['proposition']),
				'jetons' => $m['jetons'],
				'deja_lies' => array_values($linkedHere),
			);
			if ($withLinked && $linkedHere) {
				$prop = array_values($m['proposition']);
				$cur = array_values($linkedHere);
				sort($prop);
				sort($cur);
				$row['concordance'] = ($prop === $cur) ? 'identique' : 'différent';
				$row['a_lier'] = array_values(array_diff($prop, $cur));
				$row['lies_non_proposes'] = array_values(array_diff($cur, $prop));
			} else {
				$row['a_lier'] = array_values(array_diff(array_values($m['proposition']), array_values($linkedHere)));
			}
			$results[] = $row;
		}

		return array(
			'fournisseur' => array_values($socs),
			'produit_enlevement' => $pickupLabel,
			'fenetre_jours' => array('avant' => -$before, 'apres' => $after),
			'nb_transports' => count($results),
			'resume' => $counts,
			'transports_deja_lies_non_affiches' => $skipped,
			'transports' => $results,
			'regles' => "Aucune écriture n'a été faite. Jetons tirés de la réf. fournisseur du transport (sans le préfixe fournisseur) : chantier, département (2 chiffres) ou Stock. "
				."Chaque jeton est cherché dans la réf. fournisseur des commandes du fournisseur, hors annulées et hors commandes déjà liées à un autre transport"
				.($withLinked ? ' (incluses ici car inclure_lies = true)' : '').". "
				."Une commande doit être datée avant l'enlèvement et sa livraison prévue comprise entre ".(-$before)." et +".$after." jours de l'enlèvement. "
				."Score : correspondance forte ".ClaudeVerifSupplierLogic::WEIGHT_STRONG." (faible ".ClaudeVerifSupplierLogic::WEIGHT_WEAK.") + dates cohérentes "
				.ClaudeVerifSupplierLogic::BONUS_DATES_OK." (livraison prévue inconnue ".ClaudeVerifSupplierLogic::BONUS_DATES_UNKNOWN."). "
				."« sûr » : chaque jeton a une seule commande, forte, aux dates cohérentes ; « probable » : plusieurs candidats ou correspondance faible ; "
				."« non résolu » : un jeton sans candidat. "
				."Présenter à l'utilisateur un tableau transport → commandes proposées (avec les motifs pour les cas probables) et obtenir sa validation explicite "
				."avant d'appeler lier_commandes_fournisseur, transport par transport, avec seulement les commandes validées.",
		);
	}

	/**
	 * Prix rendu Brest des produits d'une commande (lecture seule)
	 *
	 * @param array $args commande, cle_repartition, affectations, frais_supplementaires
	 * @return array
	 * @throws Exception
	 */
	public function calculerPrixRendu($args)
	{
		$this->requireSupplierRight('lire');

		$key = (string) ($args['cle_repartition'] ?? 'quantite');
		if ($key === '') {
			$key = 'quantite';
		}
		if (!in_array($key, array('quantite', 'valeur'), true)) {
			throw new Exception('cle_repartition : quantite ou valeur');
		}
		$extra = (float) price2num($args['frais_supplementaires'] ?? 0, 'MT');
		if ($extra < 0) {
			throw new Exception('frais_supplementaires doit être positif');
		}
		$affect = is_array($args['affectations'] ?? null) ? $args['affectations'] : array();

		$cf = $this->fetchOrder($args['commande'] ?? '');
		$lines = $this->loadLines((int) $cf->id);
		if (ClaudeVerifSupplierLogic::parseTransport($lines, $this->pickupPrefix(), $this->transportProduct()) !== null) {
			throw new Exception($cf->ref.' est une commande de transport : indiquer la commande fournisseur transportée');
		}
		$cls = ClaudeVerifSupplierLogic::classify($lines, $affect);
		$alerts = $cls['alertes'];
		$myBase = ClaudeVerifSupplierLogic::keyTotal($lines, $cls, $key);

		// Transports liés et part retenue pour cette commande
		$transportsOut = array();
		$transportShare = 0.0;
		foreach ($this->linkedIds((int) $cf->id) as $tid) {
			$tLines = $this->loadLines($tid);
			if (ClaudeVerifSupplierLogic::parseTransport($tLines, $this->pickupPrefix(), $this->transportProduct()) === null) {
				continue; // commande fournisseur liée qui n'est pas un transport
			}
			$t = $this->orderSummary($tid);
			if ($t === null || in_array((int) $t['fk_statut'], self::STATUS_EXCLUDED, true)) {
				if ($t !== null) {
					$alerts[] = 'Transport lié '.$t['ref'].' annulé : non pris en compte';
				}
				continue;
			}

			// Commandes transportées par ce transport (hors transports) et leur base de répartition
			$bases = array();
			foreach ($this->linkedIds($tid) as $oid) {
				if ($oid === (int) $cf->id) {
					$bases[$oid] = array('ref' => $cf->ref, 'ref_supplier' => (string) $cf->ref_supplier, 'base' => $myBase);
					continue;
				}
				$oLines = $this->loadLines($oid);
				if (ClaudeVerifSupplierLogic::parseTransport($oLines, $this->pickupPrefix(), $this->transportProduct()) !== null) {
					continue;
				}
				$o = $this->orderSummary($oid);
				$oCls = ClaudeVerifSupplierLogic::classify($oLines);
				$bases[$oid] = array('ref' => $o ? $o['ref'] : (string) $oid, 'ref_supplier' => $o ? $o['ref_supplier'] : '',
					'base' => ClaudeVerifSupplierLogic::keyTotal($oLines, $oCls, $key));
			}
			$sum = 0.0;
			foreach ($bases as $oid => $b) {
				$sum += $b['base'];
				if ($b['base'] <= 0 && $oid !== (int) $cf->id) {
					$alerts[] = 'Transport '.$t['ref'].' : la commande liée '.$b['ref'].' n\'a pas de produit principal, elle ne prend aucune part du transport';
				}
			}
			// Éléments de la réf. du transport sans commande liée correspondante : liens peut-être manquants, part surestimée
			$missing = array();
			foreach (ClaudeVerifSupplierLogic::tokenize($t['ref_supplier']) as $tok) {
				$hit = false;
				foreach ($bases as $b) {
					if (ClaudeVerifSupplierLogic::matchToken($tok, $b['ref_supplier']) !== null) {
						$hit = true;
					}
				}
				if (!$hit) {
					$missing[] = $tok['jeton'];
				}
			}
			if ($missing) {
				$alerts[] = 'Transport '.$t['ref'].' : « '.implode(' », « ', $missing).' » cité(s) dans sa réf. « '.$t['ref_supplier']
					.' » sans commande liée correspondante : vérifier qu\'il ne manque pas de liens (rapprocher_transports), sinon la part retenue est surestimée';
			}
			$ratio = $sum > 0 ? $myBase / $sum : 1.0;
			$share = (float) price2num($t['total_ht'] * $ratio, 'MT');
			$transportShare += $share;

			$billed = (int) $t['billed'] === 1 || $this->hasSupplierInvoice($tid);
			if ((float) $t['total_ht'] == 0) {
				$alerts[] = 'Transport '.$t['ref'].' à 0 € HT : prix rendu sans transport';
			}
			if (!$billed) {
				$alerts[] = 'Transport '.$t['ref'].' pas encore facturé : montant de la commande ('.price2num($t['total_ht'], 'MT').' € HT), qui peut changer';
			}
			$transportsOut[] = array(
				'ref' => $t['ref'],
				'transporteur' => $t['tiers'],
				'ref_fournisseur' => $t['ref_supplier'],
				'total_ht' => (float) $t['total_ht'],
				'facture' => $billed,
				'commandes_transportees' => array_values(array_map(function ($b) use ($key) {
					return array('ref' => $b['ref'], ($key === 'valeur' ? 'valeur_produits_ht' : 'quantite_produits') => round($b['base'], 4));
				}, $bases)),
				'part_retenue_pourcent' => round($ratio * 100, 2),
				'part_retenue_ht' => $share,
			);
		}
		if (!$transportsOut) {
			$alerts[] = 'Aucune commande de transport liée : prix rendu sans transport'.($extra > 0 ? ' (hors frais_supplementaires)' : '')
				.' — lier le transport (rapprocher_transports) ou passer frais_supplementaires';
		}

		$calc = ClaudeVerifSupplierLogic::compute($lines, $cls, $key, $transportShare, $extra);

		// Comparaison : prix de revient, PMP, prix fournisseur actuels
		$proposal = array();
		foreach ($calc['lignes'] as &$row) {
			$p = $this->productPrices((int) $row['produit_id'], (int) $cf->socid, (float) $row['qty']);
			$row['cost_price_actuel'] = $p['cost_price'];
			$row['pmp_actuel'] = $p['pmp'];
			$row['prix_fournisseur_actuel'] = $p['prix_fournisseur'];
			$row['ecart_cost_price_pourcent'] = ($p['cost_price'] > 0 && $row['prix_rendu'] !== null)
				? round(($row['prix_rendu'] - $p['cost_price']) / $p['cost_price'] * 100, 1) : null;
			$proposal[] = array(
				'produit' => $row['produit'],
				'cost_price_actuel' => $p['cost_price'],
				'cost_price_propose' => $row['prix_rendu'],
			);
		}
		unset($row);

		// Lignes de frais et lignes écartées
		$byId = array();
		foreach ($lines as $l) {
			$byId[(int) $l['id']] = $l;
		}
		$fees = array();
		foreach ($cls['specifiques'] as $f => $p) {
			$fees[] = array('ligne' => $f, 'libelle' => $this->lineName($byId[$f]), 'total_ht' => (float) $byId[$f]['total_ht'],
				'classement' => 'frais spécifique', 'rattache_a' => $byId[$p]['product_ref']);
		}
		foreach ($cls['communs'] as $f) {
			$fees[] = array('ligne' => $f, 'libelle' => $this->lineName($byId[$f]), 'total_ht' => (float) $byId[$f]['total_ht'], 'classement' => 'frais commun');
		}
		$excluded = array();
		foreach ($cls['ignorees'] + $cls['non_classees'] as $f => $reason) {
			$excluded[] = array('ligne' => $f, 'libelle' => $this->lineName($byId[$f]), 'total_ht' => (float) $byId[$f]['total_ht'], 'raison' => $reason);
		}

		return array(
			'commande' => $cf->ref,
			'ref_fournisseur' => (string) $cf->ref_supplier,
			'fournisseur' => $cf->thirdparty->name,
			'cle_repartition' => $key,
			'unite_principale' => $this->unitLabel($cls['unite']),
			'base_repartition' => $calc['base_repartition'],
			'frais_communs' => array(
				'lignes_commande' => $calc['frais_communs_lignes'],
				'transports' => (float) price2num($transportShare, 'MT'),
				'frais_supplementaires' => $extra,
				'total' => $calc['frais_communs'],
				'par_unite' => $calc['base_repartition'] > 0 ? (float) price2num($calc['frais_communs'] / $calc['base_repartition'], 'MU') : null,
			),
			'produits' => $calc['lignes'],
			'lignes_frais' => $fees,
			'lignes_exclues' => $excluded,
			'transports' => $transportsOut,
			'alertes' => array_values(array_unique($alerts)),
			'proposition_maj_prix_revient' => $proposal,
			'regles' => "Prix rendu = (total HT de la ligne + frais spécifiques + part des frais communs) / quantité, arrondi à 2 décimales. "
				."Produits principaux : lignes produit de l'unité majoritaire. Frais spécifiques : produit d'une autre unité placé juste après un produit principal "
				."(ou désigné dans affectations), ajouté à ce seul produit. Frais communs : lignes libres et services, transports liés, frais supplémentaires, "
				."répartis au prorata de la ".($key === 'valeur' ? 'valeur HT' : 'quantité')." des produits principaux. Un transport lié à plusieurs commandes "
				."est réparti entre elles selon la même clé (part retenue indiquée). "
				."Proposer ensuite à l'utilisateur la mise à jour du prix de revient sous forme de tableau produit / ancien → nouveau prix (proposition_maj_prix_revient), "
				."en signalant les alertes ; n'appeler maj_prix_revient qu'après son accord explicite"
				.(getDolGlobalInt('CLAUDEVERIF_ALLOW_COSTPRICE') ? '' : ' (outil désactivé dans la configuration du module : la mise à jour se fait alors à la main dans Dolibarr)').".",
		);
	}

	/*
	 * Écriture
	 */

	/**
	 * Liaison transport -> commandes transportées
	 *
	 * @param array $args transport, commandes
	 * @return array
	 * @throws Exception
	 */
	public function lierCommandesFournisseur($args)
	{
		if (!getDolGlobalInt('CLAUDEVERIF_ALLOW_LINK')) {
			throw new Exception('Liaison désactivée : activer « Autoriser Claude à lier des commandes fournisseurs entre elles » dans la configuration du module ClaudeVerif');
		}
		$this->requireSupplierRight('lire');
		$this->requireSupplierRight('creer');

		$transport = $this->fetchOrder($args['transport'] ?? '');
		$tLines = $this->loadLines((int) $transport->id);
		if (ClaudeVerifSupplierLogic::parseTransport($tLines, $this->pickupPrefix(), $this->transportProduct()) === null) {
			throw new Exception($transport->ref.' n\'est pas une commande de transport (aucune ligne '.$this->pickupPrefix().'…)');
		}
		$items = array_values((array) ($args['commandes'] ?? array()));
		if (!$items) {
			throw new Exception("Indiquer 'commandes' (réf. ou id des commandes transportées)");
		}
		if (count($items) > 50) {
			throw new Exception('50 commandes au plus par appel');
		}

		$results = array();
		foreach ($items as $item) {
			$label = is_scalar($item) ? (string) $item : json_encode($item);
			try {
				$cmd = $this->fetchOrder($item);
				if ((int) $cmd->id === (int) $transport->id) {
					throw new Exception('la commande est le transport lui-même');
				}
				if ($this->isLinked((int) $transport->id, (int) $cmd->id)) {
					$results[] = array('commande' => $cmd->ref, 'resultat' => 'déjà lié');
					continue;
				}
				$transport->error = '';
				if ($transport->add_object_linked(self::ELEMENT, (int) $cmd->id, $this->user) <= 0) {
					throw new Exception('échec de la liaison'.($transport->error ? ' : '.$transport->error : ''));
				}
				dol_syslog('ClaudeVerif: lier_commandes_fournisseur utilisateur='.$this->user->login.' transport='.$transport->ref.' commande='.$cmd->ref, LOG_INFO);
				$results[] = array('commande' => $cmd->ref, 'resultat' => 'créé');
			} catch (Throwable $e) {
				$results[] = array('commande' => $label, 'resultat' => 'erreur', 'motif' => $e->getMessage());
			}
		}

		return array(
			'transport' => $transport->ref,
			'resultats' => $results,
			'commandes_fournisseur_liees' => $this->linkedSummaries((int) $transport->id),
		);
	}

	/**
	 * Mise à jour du prix de revient (llx_product.cost_price uniquement)
	 *
	 * @param array $args produits, commande_source, forcer
	 * @return array
	 * @throws Exception
	 */
	public function majPrixRevient($args)
	{
		if (!getDolGlobalInt('CLAUDEVERIF_ALLOW_COSTPRICE')) {
			throw new Exception('Mise à jour désactivée : activer « Autoriser Claude à mettre à jour le prix de revient des produits » dans la configuration du module ClaudeVerif');
		}
		$this->requireRight('produit', 'lire');
		$this->requireRight('produit', 'creer');

		$source = trim((string) ($args['commande_source'] ?? ''));
		if ($source === '') {
			throw new Exception("Indiquer 'commande_source' (réf. de la commande d'où vient le calcul)");
		}
		$force = !empty($args['forcer']);
		$items = array_values((array) ($args['produits'] ?? array()));
		if (!$items) {
			throw new Exception("Indiquer 'produits' : [{produit, cost_price}]");
		}
		if (count($items) > 50) {
			throw new Exception('50 produits au plus par appel');
		}

		$results = array();
		foreach ($items as $it) {
			$label = is_array($it) ? (string) ($it['produit'] ?? '') : (string) $it;
			try {
				if (!is_array($it) || !isset($it['produit'], $it['cost_price']) || !is_numeric($it['cost_price'])) {
					throw new Exception('élément attendu : {produit, cost_price}');
				}
				$new = (float) price2num($it['cost_price'], 'MU');
				if ($new <= 0) {
					throw new Exception('le prix de revient doit être supérieur à 0');
				}
				$prod = new Product($this->db);
				$pid = (is_int($it['produit']) || ctype_digit((string) $it['produit'])) ? (int) $it['produit'] : 0;
				if ($prod->fetch($pid, $pid ? '' : (string) $it['produit']) <= 0) {
					throw new Exception('produit introuvable');
				}
				$old = ($prod->cost_price === null || $prod->cost_price === '') ? null : (float) $prod->cost_price;
				$gap = ($old !== null && $old > 0) ? abs($new - $old) / $old : null;
				if ($gap !== null && $gap > self::MAX_COST_GAP && !$force) {
					$results[] = array('produit' => $prod->ref, 'resultat' => 'refusé', 'ancien' => $old, 'demande' => $new,
						'motif' => 'écart de '.round($gap * 100, 1).' % (> 30 %) : confirmer avec l\'utilisateur puis rappeler avec forcer = true');
					continue;
				}
				if ($old !== null && abs($new - $old) < 0.000001) {
					$results[] = array('produit' => $prod->ref, 'resultat' => 'inchangé', 'ancien' => $old, 'nouveau' => $new);
					continue;
				}
				// Mise à jour d'un seul champ (pas de Product::update(), qui réécrit toute la fiche), sans trigger
				if ($prod->setValueFrom('cost_price', price2num($new, 'MU'), '', null, 'text', '', $this->user) <= 0) {
					throw new Exception('échec de la mise à jour'.($prod->error ? ' : '.$prod->error : ''));
				}
				dol_syslog('ClaudeVerif: maj_prix_revient utilisateur='.$this->user->login.' produit='.$prod->ref
					.' ancien='.($old === null ? 'vide' : $old).' nouveau='.$new.' source='.$source.($force ? ' forcé' : ''), LOG_INFO);
				$results[] = array('produit' => $prod->ref, 'resultat' => 'mis à jour', 'ancien' => $old, 'nouveau' => $new,
					'ecart_pourcent' => $gap === null ? null : round($gap * 100, 1));
			} catch (Throwable $e) {
				$results[] = array('produit' => $label, 'resultat' => 'erreur', 'motif' => $e->getMessage());
			}
		}
		return array('commande_source' => $source, 'resultats' => $results,
			'regles' => 'Seul le prix de revient (cost_price) a été modifié : PMP, prix fournisseurs et prix de vente inchangés.');
	}

	/*
	 * Outils internes
	 */

	/**
	 * @param string $perm lire | creer
	 * @return void
	 * @throws Exception
	 */
	private function requireSupplierRight($perm)
	{
		if ($this->user->hasRight('fournisseur', 'commande', $perm) || $this->user->hasRight('supplier_order', $perm)) {
			return;
		}
		throw new Exception("L'utilisateur du connecteur n'a pas le droit Commandes fournisseurs -> ".($perm === 'lire' ? 'Lire' : 'Créer/modifier'));
	}

	/**
	 * @return string Préfixe des produits adresse d'enlèvement
	 */
	private function pickupPrefix()
	{
		return getDolGlobalString('CLAUDEVERIF_TRANSPORT_PICKUP_PREFIX', 'ADRIT_');
	}

	/**
	 * @return string Réf. du produit forfait transport
	 */
	private function transportProduct()
	{
		return getDolGlobalString('CLAUDEVERIF_TRANSPORT_PRODUCT', 'PrixTrps');
	}

	/**
	 * Commande fournisseur par id (entier) ou réf.
	 *
	 * @param mixed $idOrRef Id ou réf.
	 * @return CommandeFournisseur
	 * @throws Exception
	 */
	private function fetchOrder($idOrRef)
	{
		$s = is_scalar($idOrRef) ? trim((string) $idOrRef) : '';
		if ($s === '') {
			throw new Exception('Indiquer la référence ou l\'id de la commande fournisseur');
		}
		$id = ctype_digit($s) ? (int) $s : 0;
		$cf = new CommandeFournisseur($this->db);
		if ($cf->fetch($id, $id ? '' : $s) <= 0) {
			throw new Exception('Commande fournisseur introuvable : '.$s);
		}
		if (!in_array((int) $cf->entity, array_map('intval', explode(',', getEntity('supplier_order'))), true)) {
			throw new Exception('Commande fournisseur introuvable : '.$s);
		}
		$cf->fetch_thirdparty();
		return $cf;
	}

	/**
	 * Lignes d'une commande fournisseur (unité de la ligne, sinon celle du produit ; quantité réceptionnée)
	 *
	 * @param int $id Id de la commande
	 * @return array
	 * @throws Exception
	 */
	private function loadLines($id)
	{
		$sql = "SELECT l.rowid, l.rang, l.fk_product, l.product_type, l.label, l.description, l.ref as ref_supplier, l.qty, l.subprice,";
		$sql .= " l.remise_percent, l.total_ht, l.special_code, COALESCE(l.fk_unit, p.fk_unit) as fk_unit,";
		$sql .= " p.ref as product_ref, p.label as product_label, u.short_label as unit_short, u.code as unit_code,";
		$sql .= " (SELECT SUM(rb.qty) FROM ".MAIN_DB_PREFIX."receptiondet_batch rb WHERE rb.fk_element = l.fk_commande AND rb.fk_elementdet = l.rowid) as qty_recue";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande_fournisseurdet l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = l.fk_product";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_units u ON u.rowid = COALESCE(l.fk_unit, p.fk_unit)";
		$sql .= " WHERE l.fk_commande = ".((int) $id);
		$sql .= " ORDER BY l.rang, l.rowid";
		$out = array();
		foreach ($this->rows($sql) as $o) {
			$out[] = array(
				'id' => (int) $o->rowid,
				'rang' => (int) $o->rang,
				'fk_product' => (int) $o->fk_product,
				'product_ref' => (string) $o->product_ref,
				'product_type' => (int) $o->product_type,
				'label' => (string) ($o->label !== null && $o->label !== '' ? $o->label : $o->product_label),
				'description' => (string) $o->description,
				'ref_supplier' => (string) $o->ref_supplier,
				'fk_unit' => $o->fk_unit === null ? null : (int) $o->fk_unit,
				'unite' => $this->unitText($o->unit_short, $o->unit_code),
				'qty' => (float) $o->qty,
				'subprice' => (float) $o->subprice,
				'remise_percent' => (float) $o->remise_percent,
				'total_ht' => (float) $o->total_ht,
				'special_code' => (int) $o->special_code,
				'qty_recue' => (float) $o->qty_recue,
			);
		}
		return $out;
	}

	/**
	 * @param string|null $short Libellé court
	 * @param string|null $code  Code
	 * @return string
	 */
	private function unitText($short, $code)
	{
		global $langs;
		if ($short === null && $code === null) {
			return '';
		}
		$t = $langs->transnoentitiesnoconv('unit'.$code.'Short');
		return ($t !== '' && $t !== 'unit'.$code.'Short') ? $t : (string) ($short ?: $code);
	}

	/**
	 * @param string|null $fkUnit Id d'unité
	 * @return string|null
	 */
	private function unitLabel($fkUnit)
	{
		if ($fkUnit === null || $fkUnit === '') {
			return null;
		}
		foreach ($this->rows("SELECT short_label, code FROM ".MAIN_DB_PREFIX."c_units WHERE rowid = ".((int) $fkUnit)) as $o) {
			return $this->unitText($o->short_label, $o->code);
		}
		return (string) $fkUnit;
	}

	/**
	 * @param array $l Ligne
	 * @return string produit | service | ligne libre
	 */
	private function lineType($l)
	{
		if ((int) $l['fk_product'] <= 0) {
			return 'ligne libre';
		}
		return (int) $l['product_type'] === 1 ? 'service' : 'produit';
	}

	/**
	 * @param array $l Ligne
	 * @return string
	 */
	private function lineName($l)
	{
		if ($l['product_ref'] !== '') {
			return $l['product_ref'];
		}
		$t = trim(strtok(ClaudeVerifSupplierLogic::lineText($l), "\n"));
		return $t !== '' ? $t : 'ligne '.$l['id'];
	}

	/**
	 * Ids des commandes fournisseurs liées (dans un sens ou dans l'autre)
	 *
	 * @param int $id Id
	 * @return int[]
	 */
	private function linkedIds($id)
	{
		$sql = "SELECT fk_source, fk_target FROM ".MAIN_DB_PREFIX."element_element";
		$sql .= " WHERE sourcetype = '".self::ELEMENT."' AND targettype = '".self::ELEMENT."'";
		$sql .= " AND (fk_source = ".((int) $id)." OR fk_target = ".((int) $id).")";
		$out = array();
		foreach ($this->rows($sql) as $o) {
			$other = ((int) $o->fk_source === (int) $id) ? (int) $o->fk_target : (int) $o->fk_source;
			if ($other !== (int) $id) {
				$out[$other] = $other;
			}
		}
		return array_values($out);
	}

	/**
	 * @param int $a Id
	 * @param int $b Id
	 * @return bool Lien existant dans un sens ou dans l'autre
	 */
	private function isLinked($a, $b)
	{
		return in_array((int) $b, $this->linkedIds((int) $a), true);
	}

	/**
	 * Liens commande -> transport
	 *
	 * @param int[] $orders     Ids de commandes
	 * @param int[] $transports Ids de transports
	 * @return array [[id commande, id transport]]
	 */
	private function linkPairs(array $orders, array $transports)
	{
		if (!$orders || !$transports) {
			return array();
		}
		$o = implode(',', array_map('intval', $orders));
		$t = implode(',', array_map('intval', $transports));
		$sql = "SELECT fk_source, fk_target FROM ".MAIN_DB_PREFIX."element_element";
		$sql .= " WHERE sourcetype = '".self::ELEMENT."' AND targettype = '".self::ELEMENT."'";
		$sql .= " AND ((fk_source IN (".$o.") AND fk_target IN (".$t.")) OR (fk_target IN (".$o.") AND fk_source IN (".$t.")))";
		$out = array();
		$isOrder = array_flip(array_map('intval', $orders));
		foreach ($this->rows($sql) as $r) {
			$out[] = isset($isOrder[(int) $r->fk_source]) ? array((int) $r->fk_source, (int) $r->fk_target) : array((int) $r->fk_target, (int) $r->fk_source);
		}
		return $out;
	}

	/**
	 * Ids des commandes contenant une ligne d'enlèvement (préfixe ADRIT_)
	 *
	 * @return array [id => true]
	 */
	private function transportIds()
	{
		$sql = "SELECT DISTINCT d.fk_commande FROM ".MAIN_DB_PREFIX."commande_fournisseurdet d";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = d.fk_product";
		$sql .= " WHERE p.ref LIKE '".$this->db->escape($this->db->escapeforlike($this->pickupPrefix()))."%'";
		$out = array();
		foreach ($this->rows($sql) as $o) {
			$out[(int) $o->fk_commande] = true;
		}
		return $out;
	}

	/**
	 * @param int $id Id
	 * @return array|null ref, ref_supplier, tiers, total_ht, fk_statut, billed
	 */
	private function orderSummary($id)
	{
		$sql = "SELECT cf.rowid, cf.ref, cf.ref_supplier, cf.total_ht, cf.fk_statut, cf.billed, s.nom as tiers";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande_fournisseur cf INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = cf.fk_soc";
		$sql .= " WHERE cf.rowid = ".((int) $id)." AND cf.entity IN (".getEntity('supplier_order').")";
		foreach ($this->rows($sql) as $o) {
			return array('id' => (int) $o->rowid, 'ref' => $o->ref, 'ref_supplier' => (string) $o->ref_supplier, 'tiers' => $o->tiers,
				'total_ht' => (float) $o->total_ht, 'fk_statut' => (int) $o->fk_statut, 'billed' => (int) $o->billed);
		}
		return null;
	}

	/**
	 * Commandes fournisseurs liées : ref, type, tiers, montant HT, statut
	 *
	 * @param int $id Id
	 * @return array
	 */
	private function linkedSummaries($id)
	{
		$out = array();
		foreach ($this->linkedIds($id) as $oid) {
			$o = $this->orderSummary($oid);
			if ($o === null) {
				continue;
			}
			$isTransport = ClaudeVerifSupplierLogic::parseTransport($this->loadLines($oid), $this->pickupPrefix(), $this->transportProduct()) !== null;
			$out[] = array(
				'id' => $o['id'],
				'ref' => $o['ref'],
				'type' => $isTransport ? 'commande de transport' : 'commande fournisseur',
				'ref_fournisseur' => $o['ref_supplier'],
				'tiers' => $o['tiers'],
				'total_ht' => $o['total_ht'],
				'statut' => $this->statusLabel($o['fk_statut'], $o['billed']),
			);
		}
		return $out;
	}

	/**
	 * @param int $id Id de commande fournisseur
	 * @return bool Facture fournisseur liée
	 */
	private function hasSupplierInvoice($id)
	{
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."element_element WHERE";
		$sql .= " (fk_source = ".((int) $id)." AND sourcetype = '".self::ELEMENT."' AND targettype = 'invoice_supplier')";
		$sql .= " OR (fk_target = ".((int) $id)." AND targettype = '".self::ELEMENT."' AND sourcetype = 'invoice_supplier')";
		foreach ($this->rows($sql) as $o) {
			return (int) $o->nb > 0;
		}
		return false;
	}

	/**
	 * Prix actuels d'un produit : prix de revient, PMP, prix chez le fournisseur de la commande
	 *
	 * @param int   $productId Id produit
	 * @param int   $socid     Fournisseur
	 * @param float $qty       Quantité commandée (palier de prix)
	 * @return array
	 */
	private function productPrices($productId, $socid, $qty)
	{
		$out = array('cost_price' => null, 'pmp' => null, 'prix_fournisseur' => null);
		foreach ($this->rows("SELECT cost_price, pmp FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $productId)) as $o) {
			$out['cost_price'] = $o->cost_price === null ? null : (float) $o->cost_price;
			$out['pmp'] = (float) $o->pmp;
		}
		$sql = "SELECT ref_fourn, quantity, unitprice, remise_percent FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
		$sql .= " WHERE fk_product = ".((int) $productId)." AND fk_soc = ".((int) $socid);
		$sql .= " AND entity IN (".getEntity('product_fournisseur_price').")";
		$sql .= " ORDER BY quantity ASC";
		$best = null;
		foreach ($this->rows($sql) as $o) {
			if ($best === null || (float) $o->quantity <= $qty) {
				$best = $o; // plus grand palier <= quantité commandée, sinon le premier palier
			}
		}
		if ($best !== null) {
			$out['prix_fournisseur'] = array('ref_fournisseur' => (string) $best->ref_fourn, 'quantite_min' => (float) $best->quantity,
				'pu_ht' => (float) $best->unitprice, 'remise_percent' => (float) $best->remise_percent);
		}
		return $out;
	}

	/**
	 * @param int $status Statut
	 * @param int $billed Facturée
	 * @return string
	 */
	private function statusLabel($status, $billed)
	{
		static $static = null;
		if ($static === null) {
			$static = new CommandeFournisseur($this->db);
		}
		return $this->clean($static->LibStatut($status, 1, $billed));
	}

	/**
	 * @param array  $args  date_from, date_to
	 * @param string $field Champ date
	 * @return string SQL
	 * @throws Exception
	 */
	private function dateFilter($args, $field)
	{
		$sql = '';
		foreach (array('date_from' => '>=', 'date_to' => '<=') as $key => $op) {
			$val = trim((string) ($args[$key] ?? ''));
			if ($val !== '') {
				if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
					throw new Exception($key.' doit être au format AAAA-MM-JJ');
				}
				$sql .= " AND ".$field." ".$op." '".$this->db->escape($val)."'";
			}
		}
		return $sql;
	}

	/**
	 * @param string $sql Requête
	 * @return array Objets
	 * @throws Exception
	 */
	private function rows($sql)
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
}
