<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Outils exposés à Claude (lecture seule)
 */

require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
dol_include_once('/claudeverif/class/claudeverifmail.class.php');

/**
 * Outils MCP
 */
class ClaudeVerifTools
{
	/** @var DoliDB */
	public $db;
	/** @var User */
	public $user;
	/** @var ExtraFields|null */
	private $extrafields = null;
	/** @var array */
	private $extrafieldsLoaded = array();

	/**
	 * @param DoliDB $db   Database handler
	 * @param User   $user Utilisateur de service
	 */
	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
	}

	/**
	 * Définitions renvoyées par tools/list
	 *
	 * @return array
	 */
	public function getDefinitions()
	{
		$defs = array(
			array(
				'name' => 'search_commandes',
				'description' => "Recherche des commandes clients dans Dolibarr. Tous les critères sont optionnels et combinables "
					."(recherche partielle sur les textes). Renvoie les plus récentes d'abord.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => array('type' => 'string', 'description' => 'Référence Dolibarr ou partie (ex. CO2609-0142)'),
						'ref_client' => array('type' => 'string', 'description' => 'Référence de commande du client, ou partie'),
						'tiers' => array('type' => 'string', 'description' => 'Nom du client, ou partie'),
						'date_from' => array('type' => 'string', 'description' => 'Date de commande minimum, AAAA-MM-JJ'),
						'date_to' => array('type' => 'string', 'description' => 'Date de commande maximum, AAAA-MM-JJ'),
						'limit' => array('type' => 'integer', 'description' => 'Nombre maximum de résultats (1 à 50, défaut 20)'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'get_commande',
				'description' => "Détail complet d'une commande client : en-tête, client (avec adresse), contacts liés "
					."(livraison, facturation, suivi… avec adresse et téléphone), conditions, totaux, notes, "
					."champs complémentaires, objets liés (devis…) et toutes les lignes avec leurs champs complémentaires.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'ref' => array('type' => 'string', 'description' => 'Référence Dolibarr exacte (ex. CO2609-0142)'),
						'id' => array('type' => 'integer', 'description' => 'Identifiant interne (alternative à ref)'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'search_mails',
				'description' => "Recherche dans la boîte mail des commandes (IMAP OVH, lecture seule). Renvoie date, expéditeur, destinataires et objet, "
					."les plus récents d'abord. Les commandes arrivent en général quelques jours avant leur saisie dans Dolibarr. "
					."Chercher avec des termes distinctifs : n° de commande client, nom du chantier, réf. client, adresse de l'expéditeur. "
					."Si rien n'est trouvé, essayer un autre terme ou augmenter depuis_jours.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'texte' => array('type' => 'string', 'description' => 'Texte cherché dans les en-têtes et le corps (ex. 100057269, HAUCOURT)'),
						'expediteur' => array('type' => 'string', 'description' => "Adresse ou domaine de l'expéditeur (ex. litt.fr)"),
						'objet' => array('type' => 'string', 'description' => "Texte cherché dans l'objet uniquement"),
						'depuis_jours' => array('type' => 'integer', 'description' => 'Période de recherche en jours (1 à 120, défaut 15)'),
						'limit' => array('type' => 'integer', 'description' => 'Nombre maximum de résultats (1 à 30, défaut 15)'),
					),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'get_mail',
				'description' => "Contenu complet d'un mail : en-têtes, corps en texte, liste des pièces jointes avec leur index. "
					."Le texte des pièces jointes .docx, .doc, .txt et des PDF avec texte est inclus. "
					."Pour un PDF scanné ou une image, utiliser get_piece_jointe. Le mail n'est pas marqué comme lu.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'uid' => array('type' => 'integer', 'description' => 'Identifiant du mail renvoyé par search_mails'),
					),
					'required' => array('uid'),
					'additionalProperties' => false,
				),
			),
			array(
				'name' => 'get_piece_jointe',
				'description' => "Contenu d'une pièce jointe. Pour un PDF scanné ou une image : renvoie les pages en images à lire visuellement "
					."(5 pages max par appel, utiliser 'pages' pour la suite). Mode 'ocr' : texte via OCR Mistral si configuré. "
					."Mode 'texte' : couche texte du PDF uniquement.",
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'uid' => array('type' => 'integer', 'description' => 'Identifiant du mail'),
						'index' => array('type' => 'integer', 'description' => 'Index de la pièce jointe (donné par get_mail)'),
						'pages' => array('type' => 'string', 'description' => 'Pages voulues, ex. "1-3" ou "2,4" (défaut : pages scannées)'),
						'mode' => array('type' => 'string', 'enum' => array('auto', 'images', 'texte', 'ocr'), 'description' => 'Défaut : auto'),
					),
					'required' => array('uid', 'index'),
					'additionalProperties' => false,
				),
			),
		);
		return array_merge($defs, ClaudeVerifInvoice::definitions());
	}

	/**
	 * @param string $name Nom de l'outil
	 * @param array  $args Arguments
	 * @return array
	 * @throws Exception
	 */
	public function call($name, $args)
	{
		switch ($name) {
			case 'search_commandes':
				return $this->searchCommandes($args);
			case 'get_commande':
				return $this->getCommande($args);
			case 'search_mails':
				$mail = new ClaudeVerifMail();
				return $mail->search($args);
			case 'get_mail':
				$mail = new ClaudeVerifMail();
				return $mail->get($args);
			case 'get_piece_jointe':
				$mail = new ClaudeVerifMail();
				return $mail->attachment($args);
			case 'search_factures':
				$inv = new ClaudeVerifInvoice($this->db, $this->user);
				return $inv->searchFactures($args);
			case 'get_facture':
				$inv = new ClaudeVerifInvoice($this->db, $this->user);
				return $inv->getFacture($args);
			case 'controle_facture':
				$inv = new ClaudeVerifInvoice($this->db, $this->user);
				return $inv->controleFacture($args);
		}
		throw new Exception('Outil inconnu : '.$name);
	}

	/**
	 * @param array $args Arguments
	 * @return array
	 * @throws Exception
	 */
	private function searchCommandes($args)
	{
		$this->requireRight('commande', 'lire');

		$limit = max(1, min(50, (int) ($args['limit'] ?? 20)));

		$sql = "SELECT c.rowid, c.ref, c.ref_client, c.date_commande, c.date_livraison, c.fk_statut, c.facture, c.total_ht, s.nom as tiers";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande as c";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = c.fk_soc";
		$sql .= " WHERE c.entity IN (".getEntity('commande').")";
		foreach (array('ref' => 'c.ref', 'ref_client' => 'c.ref_client', 'tiers' => 's.nom') as $key => $field) {
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
				$sql .= " AND c.date_commande ".$op." '".$this->db->escape($val)."'";
			}
		}
		$sql .= " ORDER BY c.date_commande DESC, c.rowid DESC";
		$sql .= $this->db->plimit($limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Erreur SQL : '.$this->db->lasterror());
		}

		$static = new Commande($this->db);
		$out = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $obj->rowid,
				'ref' => $obj->ref,
				'ref_client' => $obj->ref_client,
				'tiers' => $obj->tiers,
				'date_commande' => $this->date($this->db->jdate($obj->date_commande)),
				'date_livraison' => $this->date($this->db->jdate($obj->date_livraison)),
				'statut' => $this->clean($static->LibStatut((int) $obj->fk_statut, (int) $obj->facture, 0)),
				'total_ht' => (float) $obj->total_ht,
			);
		}
		$this->db->free($resql);

		return array('nombre' => count($out), 'commandes' => $out);
	}

	/**
	 * @param array $args Arguments
	 * @return array
	 * @throws Exception
	 */
	private function getCommande($args)
	{
		$this->requireRight('commande', 'lire');

		$id = (int) ($args['id'] ?? 0);
		$ref = trim((string) ($args['ref'] ?? ''));
		if (!$id && $ref === '') {
			throw new Exception("Indiquer 'ref' ou 'id'");
		}

		$cmd = new Commande($this->db);
		if ($cmd->fetch($id, $ref) <= 0) {
			throw new Exception('Commande introuvable : '.($ref !== '' ? $ref : $id));
		}
		$cmd->fetch_thirdparty();
		$soc = $cmd->thirdparty;

		// Objets liés (devis, factures, expéditions…)
		$linked = array();
		$cmd->fetchObjectLinked();
		foreach ((array) $cmd->linkedObjects as $type => $objs) {
			foreach ((array) $objs as $o) {
				$linked[] = array('type' => $type, 'id' => (int) $o->id, 'ref' => (string) ($o->ref ?? ''));
			}
		}

		// Contacts externes liés (livraison, facturation, suivi…)
		$contacts = array();
		foreach ((array) $cmd->liste_contact(-1, 'external') as $c) {
			$contact = new Contact($this->db);
			$found = ((int) $c['id'] > 0 && $contact->fetch((int) $c['id']) > 0);
			$contacts[] = array(
				'type' => (string) $c['code'],
				'libelle_type' => (string) ($c['libelle'] ?? ''),
				'nom' => trim(($c['firstname'] ?? '').' '.($c['lastname'] ?? '')),
				'adresse' => $found ? (string) $contact->address : '',
				'cp' => $found ? (string) $contact->zip : '',
				'ville' => $found ? (string) $contact->town : '',
				'telephone' => $found ? (string) ($contact->phone_pro ?: ($contact->phone_mobile ?: $contact->phone_perso)) : '',
			);
		}

		$lines = array();
		foreach ((array) $cmd->lines as $l) {
			$lines[] = array(
				'rang' => (int) $l->rang,
				'type' => ((int) $l->product_type === 1 ? 'service' : 'produit'),
				'product_ref' => (string) ($l->product_ref ?? ''),
				'product_label' => (string) ($l->product_label ?? ''),
				'description' => $this->clean($l->desc),
				'qty' => (float) $l->qty,
				'unite' => method_exists($l, 'getLabelOfUnit') ? $this->clean($l->getLabelOfUnit('short')) : '',
				'pu_ht' => (float) $l->subprice,
				'remise_percent' => (float) $l->remise_percent,
				'total_ht' => (float) $l->total_ht,
				'tva_tx' => (float) $l->tva_tx,
				'special_code' => (int) $l->special_code,
				'extrafields' => $this->formatExtrafields('commandedet', $l->array_options),
			);
		}

		return array(
			'id' => (int) $cmd->id,
			'ref' => $cmd->ref,
			'ref_client' => $cmd->ref_client,
			'statut' => $this->clean($cmd->getLibStatut(0)),
			'date_commande' => $this->date($cmd->date_commande ?: $cmd->date),
			'date_livraison' => $this->date($cmd->delivery_date ?? null),
			'client' => array(
				'id' => (int) $soc->id,
				'nom' => $soc->name,
				'code_client' => $soc->code_client,
				'adresse' => (string) $soc->address,
				'cp' => (string) $soc->zip,
				'ville' => $soc->town,
				'pays' => (string) ($soc->country ?: $soc->country_code),
				'remise_percent_defaut' => (float) $soc->remise_percent,
			),
			'contacts' => $contacts,
			'conditions_reglement' => $this->transOr('PaymentCondition'.($cmd->cond_reglement_code ?? ''), (string) ($cmd->cond_reglement_doc ?? ($cmd->cond_reglement_code ?? ''))),
			'mode_reglement' => $this->transOr('PaymentType'.($cmd->mode_reglement_code ?? ''), (string) ($cmd->mode_reglement ?? '')),
			'totaux' => array(
				'ht' => (float) $cmd->total_ht,
				'tva' => (float) $cmd->total_tva,
				'ttc' => (float) $cmd->total_ttc,
			),
			'note_publique' => $this->clean($cmd->note_public),
			'note_privee' => $this->clean($cmd->note_private),
			'extrafields' => $this->formatExtrafields('commande', $cmd->array_options),
			'objets_lies' => $linked,
			'nb_lignes' => count($lines),
			'lignes' => $lines,
			'url' => dol_buildpath('/commande/card.php', 2).'?id='.((int) $cmd->id),
		);
	}

	/**
	 * Champs complémentaires : libellé => valeur affichée
	 *
	 * @param string     $elementtype  commande | commandedet
	 * @param array|null $arrayOptions Tableau options_xxx
	 * @return array|stdClass
	 */
	protected function formatExtrafields($elementtype, $arrayOptions)
	{
		if (empty($arrayOptions) || !is_array($arrayOptions)) {
			return new stdClass();
		}
		if ($this->extrafields === null) {
			$this->extrafields = new ExtraFields($this->db);
		}
		if (empty($this->extrafieldsLoaded[$elementtype])) {
			$this->extrafields->fetch_name_optionals_label($elementtype);
			$this->extrafieldsLoaded[$elementtype] = true;
		}

		$out = array();
		foreach ($arrayOptions as $key => $value) {
			if ($value === null || $value === '') {
				continue;
			}
			$code = preg_replace('/^options_/', '', $key);
			$label = $this->extrafields->attributes[$elementtype]['label'][$code] ?? $code;
			if (($this->extrafields->attributes[$elementtype]['type'][$code] ?? '') === 'boolean') {
				$out[$label] = !empty($value) ? 'Oui' : 'Non';
				continue;
			}
			$display = $this->clean($this->extrafields->showOutputField($code, $value, '', $elementtype));
			if ($display === '') {
				continue;
			}
			$out[$label] = $display;
		}
		return $out ? $out : new stdClass();
	}

	/**
	 * Code de l'extrafield booléen « Pro forma » des factures
	 *
	 * @return string Code du champ, '' si absent
	 */
	protected function proformaExtrafieldCode(): string
	{
		if ($this->extrafields === null) {
			$this->extrafields = new ExtraFields($this->db);
		}
		if (empty($this->extrafieldsLoaded['facture'])) {
			$this->extrafields->fetch_name_optionals_label('facture');
			$this->extrafieldsLoaded['facture'] = true;
		}
		$attrs = $this->extrafields->attributes['facture'] ?? array();
		foreach (($attrs['type'] ?? array()) as $code => $type) {
			if ($type !== 'boolean') {
				continue;
			}
			$label = (string) ($attrs['label'][$code] ?? '');
			if (preg_match('/pro\s*-?\s*forma/i', $label) || preg_match('/pro\s*-?\s*forma/i', (string) $code)) {
				return (string) $code;
			}
		}
		return '';
	}

	/**
	 * Factures dont la case « Pro forma » est cochée
	 *
	 * @param array $ids Ids de factures
	 * @return array [id => true]
	 */
	protected function proformaIds(array $ids): array
	{
		$code = $this->proformaExtrafieldCode();
		if ($code === '' || empty($ids)) {
			return array();
		}
		$sql = "SELECT fk_object FROM ".MAIN_DB_PREFIX."facture_extrafields";
		$sql .= " WHERE fk_object IN (".implode(',', array_map('intval', $ids)).")";
		$sql .= " AND ".$this->db->escape($code)." = 1";
		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$out[(int) $o->fk_object] = true;
			}
			$this->db->free($resql);
		}
		return $out;
	}

	/**
	 * @param string $module Module
	 * @param string $perm   Permission
	 * @return void
	 * @throws Exception
	 */
	protected function requireRight($module, $perm)
	{
		if (!$this->user->hasRight($module, $perm)) {
			throw new Exception("L'utilisateur du connecteur n'a pas le droit ".$module.'->'.$perm);
		}
	}

	/**
	 * HTML -> texte brut en conservant les retours à la ligne
	 *
	 * @param mixed $s Texte
	 * @return string
	 */
	protected function clean($s)
	{
		$s = dol_string_nohtmltag((string) $s, 0);
		$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$s = str_replace(array("\r\n", "\r"), "\n", $s);
		$s = preg_replace("/[ \t]+\n/", "\n", $s);
		$s = preg_replace("/\n{3,}/", "\n\n", $s);
		return trim($s);
	}

	/**
	 * Traduction Dolibarr, ou valeur de repli si la clé n'est pas traduite
	 *
	 * @param string $key      Clé de traduction
	 * @param string $fallback Valeur de repli
	 * @return string
	 */
	protected function transOr($key, $fallback)
	{
		global $langs;
		$t = $langs->transnoentitiesnoconv($key);
		return ($t !== '' && $t !== $key) ? $t : $fallback;
	}

	/**
	 * @param int|string|null $ts Timestamp
	 * @return string|null AAAA-MM-JJ
	 */
	protected function date($ts)
	{
		return empty($ts) ? null : dol_print_date($ts, 'dayrfc');
	}
}

// Outils factures (sous-classe : chargée après la classe mère)
dol_include_once('/claudeverif/class/claudeverifinvoice.class.php');
