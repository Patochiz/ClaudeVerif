<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Endpoint MCP (transport Streamable HTTP, réponses JSON simples)
 * URL à déclarer dans claude.ai : https://<erp>/custom/claudeverif/mcp.php?key=<jeton>
 */

if (!defined('NOLOGIN')) define('NOLOGIN', 1);
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', 1);
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', 1);
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', 1);
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', 1);
if (!defined('NOIPCHECK')) define('NOIPCHECK', 1);
if (!defined('NOBROWSERNOTIF')) define('NOBROWSERNOTIF', 1);

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) {
	http_response_code(500);
	exit;
}

dol_include_once('/claudeverif/class/claudeverifmcpserver.class.php');

// PDF volumineux : marge mémoire et temps (plafonnés par l'hébergement)
@ini_set('memory_limit', '512M');
@set_time_limit(120);

/**
 * Envoie la réponse et termine
 *
 * @param int   $code    Code HTTP
 * @param mixed $payload Données JSON (null = corps vide)
 * @return void
 */
function claudeverif_out($code, $payload = null)
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	if ($payload !== null) {
		echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}
	exit;
}

if (!isModEnabled('claudeverif')) {
	claudeverif_out(404, array('error' => 'Module ClaudeVerif désactivé'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Allow: POST');
	claudeverif_out(405, array('error' => 'Method not allowed'));
}

// Authentification : en-tête Bearer (si OVH le transmet) ou paramètre ?key=
$expected = getDolGlobalString('CLAUDEVERIF_TOKEN');
$given = '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
	$given = $m[1];
}
if ($given === '') {
	$given = (string) GETPOST('key', 'aZ09');
}
if (strlen($expected) < 32 || !hash_equals($expected, $given)) {
	dol_syslog('ClaudeVerif: accès refusé depuis '.getUserRemoteIP(), LOG_WARNING);
	claudeverif_out(401, array('error' => 'Unauthorized'));
}

// Utilisateur de service : toutes les lectures se font avec SES droits
$user = new User($db);
$userId = getDolGlobalInt('CLAUDEVERIF_USER_ID');
if ($userId <= 0 || $user->fetch($userId) <= 0 || (int) ($user->status ?? $user->statut) !== 1) {
	claudeverif_out(500, array('error' => 'Utilisateur de service non configuré ou désactivé'));
}
if (method_exists($user, 'loadRights')) {
	$user->loadRights();
} else {
	$user->getrights();
}

// Langue forcée en français (sans session, Dolibarr se base sur le navigateur appelant)
$langs = new Translate('', $conf);
$langs->setDefaultLang('fr_FR');
$langs->loadLangs(array('main', 'orders', 'companies', 'bills', 'products', 'other'));

$server = new ClaudeVerifMcpServer($db, $user);
$response = $server->handleRaw(file_get_contents('php://input'));

if ($response === null) {
	claudeverif_out(202); // notification : pas de corps
}
claudeverif_out(200, $response);
