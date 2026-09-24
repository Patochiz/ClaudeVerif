<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Serveur MCP minimal (JSON-RPC 2.0)
 */

dol_include_once('/claudeverif/class/claudeveriftools.class.php');

/**
 * Gestion du protocole MCP : initialize, ping, tools/list, tools/call
 */
class ClaudeVerifMcpServer
{
	const SERVER_NAME = 'claudeverif';
	const SERVER_VERSION = '0.4.0';
	const SUPPORTED_VERSIONS = array('2025-06-18', '2025-03-26', '2024-11-05');

	/** @var DoliDB */
	public $db;
	/** @var User */
	public $user;
	/** @var ClaudeVerifTools */
	public $tools;

	/**
	 * @param DoliDB $db   Database handler
	 * @param User   $user Utilisateur de service
	 */
	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
		$this->tools = new ClaudeVerifTools($db, $user);
	}

	/**
	 * @param string $raw Corps brut de la requête
	 * @return array|null Réponse JSON-RPC, ou null si rien à renvoyer
	 */
	public function handleRaw($raw)
	{
		$msg = json_decode((string) $raw, true);
		if (!is_array($msg)) {
			return $this->error(null, -32700, 'Parse error');
		}
		// Lot de messages (anciennes versions du protocole)
		if (array_is_list($msg)) {
			$out = array();
			foreach ($msg as $m) {
				$r = is_array($m) ? $this->handle($m) : $this->error(null, -32600, 'Invalid request');
				if ($r !== null) {
					$out[] = $r;
				}
			}
			return $out ? $out : null;
		}
		return $this->handle($msg);
	}

	/**
	 * @param array $msg Message JSON-RPC
	 * @return array|null
	 */
	public function handle($msg)
	{
		// Notification (pas d'id) : notifications/initialized, cancelled… rien à répondre
		if (!array_key_exists('id', $msg)) {
			return null;
		}
		$id = $msg['id'];
		$method = (string) ($msg['method'] ?? '');
		$params = (isset($msg['params']) && is_array($msg['params'])) ? $msg['params'] : array();

		switch ($method) {
			case 'initialize':
				return $this->result($id, $this->initialize($params));
			case 'ping':
				return $this->result($id, new stdClass());
			case 'tools/list':
				return $this->result($id, array('tools' => $this->tools->getDefinitions()));
			case 'tools/call':
				return $this->result($id, $this->callTool($params));
			default:
				return $this->error($id, -32601, 'Method not found: '.$method);
		}
	}

	/**
	 * @param array $params Paramètres initialize
	 * @return array
	 */
	private function initialize($params)
	{
		$requested = (string) ($params['protocolVersion'] ?? '');
		$version = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0];

		return array(
			'protocolVersion' => $version,
			'capabilities' => array('tools' => array('listChanged' => false)),
			'serverInfo' => array('name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION),
			'instructions' => "Connecteur de l'ERP Dolibarr de DIAMANT INDUSTRIE (plafonds métalliques suspendus). "
				."Accès en lecture seule aux commandes et factures clients et à la boîte mail où arrivent les commandes, "
				."pour vérifier une commande saisie par rapport aux mails reçus du client. "
				."Démarche : get_commande, puis search_mails avec des termes tirés de la commande (réf. client, n° de commande client, chantier, contacts), puis get_mail. "
				."Les pièces jointes .docx, .doc et PDF avec texte sont lues par get_mail ; pour un PDF scanné ou une image, utiliser get_piece_jointe. "
				."Factures : controle_facture calcule commandé / livré / facturé par produit ; en interpréter les alertes sans refaire les calculs. "
				."Montants en euros HT sauf mention contraire.",
		);
	}

	/**
	 * @param array $params Paramètres tools/call
	 * @return array Résultat MCP (content + isError)
	 */
	private function callTool($params)
	{
		$name = (string) ($params['name'] ?? '');
		$args = (isset($params['arguments']) && is_array($params['arguments'])) ? $params['arguments'] : array();
		$t0 = microtime(true);

		try {
			$data = $this->tools->call($name, $args);
			$ok = true;
			if (is_array($data) && isset($data['_mcp_content'])) {
				// Contenu riche (images de pages)
				return $this->logAndReturn($name, $args, $t0, array('content' => $data['_mcp_content'], 'isError' => false));
			}
			$res = array(
				'content' => array(array(
					'type' => 'text',
					'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE),
				)),
				'isError' => false,
			);
		} catch (Throwable $e) {
			$ok = false;
			$res = array(
				'content' => array(array('type' => 'text', 'text' => 'Erreur : '.$e->getMessage())),
				'isError' => true,
			);
		}

		$this->log($name, $args, $ok, (int) round((microtime(true) - $t0) * 1000));
		return $res;
	}

	/**
	 * @param string $name Outil
	 * @param array  $args Arguments
	 * @param float  $t0   Début
	 * @param array  $res  Résultat
	 * @return array
	 */
	private function logAndReturn($name, $args, $t0, $res)
	{
		$this->log($name, $args, true, (int) round((microtime(true) - $t0) * 1000));
		return $res;
	}

	/**
	 * Journal des appels (documents/claudeverif/mcp.log)
	 *
	 * @param string $name Outil
	 * @param array  $args Arguments
	 * @param bool   $ok   Succès
	 * @param int    $ms   Durée
	 * @return void
	 */
	private function log($name, $args, $ok, $ms)
	{
		global $conf;

		dol_syslog('ClaudeVerif: '.$name.' '.($ok ? 'OK' : 'ERR').' '.$ms.'ms', LOG_INFO);
		if (!getDolGlobalInt('CLAUDEVERIF_LOG') || empty($conf->claudeverif->dir_output)) {
			return;
		}
		$dir = $conf->claudeverif->dir_output;
		dol_mkdir($dir);
		$line = dol_print_date(dol_now(), 'standard')."\t".$name."\t"
			.json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\t"
			.($ok ? 'OK' : 'ERR')."\t".$ms."ms\n";
		@file_put_contents($dir.'/mcp.log', $line, FILE_APPEND | LOCK_EX);
	}

	/**
	 * @param mixed $id     Id JSON-RPC
	 * @param mixed $result Résultat
	 * @return array
	 */
	private function result($id, $result)
	{
		return array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result);
	}

	/**
	 * @param mixed  $id      Id JSON-RPC
	 * @param int    $code    Code d'erreur
	 * @param string $message Message
	 * @return array
	 */
	private function error($id, $code, $message)
	{
		return array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message));
	}
}
