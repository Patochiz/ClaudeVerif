<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Connecteur MCP pour Claude : vérification des commandes clients
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descripteur du module ClaudeVerif
 */
class modClaudeVerif extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 500900; // Vérifier l'unicité parmi les modules custom installés
		$this->rights_class = 'claudeverif';
		$this->family = 'other';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Connecteur MCP pour Claude : vérification des commandes clients (Dolibarr + mails IMAP)';
		$this->descriptionlong = $this->description;
		$this->editor_name = 'DIAMANT INDUSTRIE';
		$this->version = '0.4.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';

		// Format plat (convention v20)
		$this->module_parts = array();

		$this->dirs = array('/claudeverif/temp');
		$this->config_page_url = array('setup.php@claudeverif');

		$this->depends = array('modCommande', 'modSociete');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('claudeverif@claudeverif');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(20, 0);

		$this->const = array();
		$this->tabs = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * @param string $options Options
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	/**
	 * @param string $options Options
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
