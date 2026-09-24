<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Page de configuration
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array('admin', 'claudeverif@claudeverif'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$entity = $conf->entity;

if ($action == 'save') {
	$error = 0;
	$db->begin();

	$error += (dolibarr_set_const($db, 'CLAUDEVERIF_USER_ID', (int) GETPOST('CLAUDEVERIF_USER_ID', 'int'), 'chaine', 0, '', $entity) < 0);
	$error += (dolibarr_set_const($db, 'CLAUDEVERIF_LOG', GETPOST('CLAUDEVERIF_LOG', 'int') ? 1 : 0, 'chaine', 0, '', $entity) < 0);
	$error += (dolibarr_set_const($db, 'CLAUDEVERIF_IMAP_HOST', GETPOST('CLAUDEVERIF_IMAP_HOST', 'alphanohtml'), 'chaine', 0, '', $entity) < 0);
	$error += (dolibarr_set_const($db, 'CLAUDEVERIF_IMAP_PORT', (int) GETPOST('CLAUDEVERIF_IMAP_PORT', 'int'), 'chaine', 0, '', $entity) < 0);
	$error += (dolibarr_set_const($db, 'CLAUDEVERIF_IMAP_LOGIN', GETPOST('CLAUDEVERIF_IMAP_LOGIN', 'alphanohtml'), 'chaine', 0, '', $entity) < 0);
	$error += (dolibarr_set_const($db, 'CLAUDEVERIF_IMAP_FOLDER', GETPOST('CLAUDEVERIF_IMAP_FOLDER', 'alphanohtml'), 'chaine', 0, '', $entity) < 0);

	// Mot de passe : modifié uniquement s'il est saisi, stocké chiffré
	$pass = GETPOST('CLAUDEVERIF_IMAP_PASSWORD', 'none');
	if ($pass !== '') {
		$error += (dolibarr_set_const($db, 'CLAUDEVERIF_IMAP_PASSWORD', dolEncrypt($pass), 'chaine', 0, '', $entity) < 0);
	}

	$mkey = GETPOST('CLAUDEVERIF_MISTRAL_KEY', 'none');
	if ($mkey !== '') {
		$error += (dolibarr_set_const($db, 'CLAUDEVERIF_MISTRAL_KEY', ($mkey === '-' ? '' : dolEncrypt($mkey)), 'chaine', 0, '', $entity) < 0);
	}

	if (!$error) {
		$db->commit();
		setEventMessages('Configuration enregistrée', null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages('Erreur à l\'enregistrement', null, 'errors');
	}
}

if ($action == 'gentoken') {
	if (dolibarr_set_const($db, 'CLAUDEVERIF_TOKEN', bin2hex(random_bytes(32)), 'chaine', 0, '', $entity) > 0) {
		setEventMessages('Nouveau jeton généré : mettez à jour l\'URL du connecteur dans claude.ai', null, 'warnings');
	}
}

if ($action == 'testimap') {
	dol_include_once('/claudeverif/class/claudeverifmail.class.php');
	try {
		$mail = new ClaudeVerifMail();
		setEventMessages($mail->test(), null, 'mesgs');
	} catch (Throwable $e) {
		setEventMessages('Échec IMAP : '.$e->getMessage(), null, 'errors');
	}
}

/*
 * Vue
 */
$form = new Form($db);

llxHeader('', $langs->trans('ClaudeVerifSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('ClaudeVerifSetup'), $linkback, 'title_setup');

// --- Connecteur ---
$token = getDolGlobalString('CLAUDEVERIF_TOKEN');
print load_fiche_titre('Connecteur Claude', '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="gentoken">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Paramètre</td><td>Valeur</td></tr>';
if ($token) {
	$url = dol_buildpath('/claudeverif/mcp.php', 2).'?key='.$token;
	print '<tr class="oddeven"><td class="titlefield">URL du connecteur</td><td>';
	print '<input type="text" class="quatrevingtpercent" readonly value="'.dol_escape_htmltag($url).'" onclick="this.select()">';
	print '<br><span class="opacitymedium">À coller dans claude.ai → Paramètres → Connecteurs → Ajouter un connecteur personnalisé. Cette URL donne accès aux données : ne la partagez pas.</span>';
	print '</td></tr>';
} else {
	print '<tr class="oddeven"><td class="titlefield">URL du connecteur</td><td><span class="warning">Aucun jeton : générez-en un.</span></td></tr>';
}
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.($token ? 'Régénérer le jeton' : 'Générer le jeton').'"'
	.($token ? ' onclick="return confirm(\'L\\\'ancienne URL cessera de fonctionner. Continuer ?\');"' : '').'></div>';
print '</form><br>';

// --- Paramètres ---
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Paramètre</td><td>Valeur</td></tr>';

print '<tr class="oddeven"><td class="titlefield">Utilisateur de service</td><td>';
print $form->select_dolusers(getDolGlobalInt('CLAUDEVERIF_USER_ID'), 'CLAUDEVERIF_USER_ID', 1);
print '<br><span class="opacitymedium">Claude lit les données avec les droits de cet utilisateur. Créez un utilisateur dédié en lecture seule (commandes, tiers, produits).</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>Journaliser les appels</td><td>';
print '<input type="checkbox" name="CLAUDEVERIF_LOG" value="1"'.(getDolGlobalInt('CLAUDEVERIF_LOG') ? ' checked' : '').'>';
print ' <span class="opacitymedium">documents/claudeverif/mcp.log</span></td></tr>';

print '<tr class="liste_titre"><td colspan="2">Messagerie IMAP (OVH MX Plan)</td></tr>';
print '<tr class="oddeven"><td>Serveur</td><td><input type="text" name="CLAUDEVERIF_IMAP_HOST" value="'.dol_escape_htmltag(getDolGlobalString('CLAUDEVERIF_IMAP_HOST', 'ssl0.ovh.net')).'"></td></tr>';
print '<tr class="oddeven"><td>Port (SSL)</td><td><input type="number" name="CLAUDEVERIF_IMAP_PORT" value="'.getDolGlobalInt('CLAUDEVERIF_IMAP_PORT', 993).'"></td></tr>';
print '<tr class="oddeven"><td>Identifiant (adresse mail)</td><td><input type="text" class="minwidth300" name="CLAUDEVERIF_IMAP_LOGIN" value="'.dol_escape_htmltag(getDolGlobalString('CLAUDEVERIF_IMAP_LOGIN')).'"></td></tr>';
print '<tr class="oddeven"><td>Mot de passe</td><td><input type="password" autocomplete="new-password" name="CLAUDEVERIF_IMAP_PASSWORD" value="" placeholder="'.(getDolGlobalString('CLAUDEVERIF_IMAP_PASSWORD') ? '(enregistré — laisser vide pour conserver)' : '').'"></td></tr>';
print '<tr class="oddeven"><td>Dossier</td><td><input type="text" name="CLAUDEVERIF_IMAP_FOLDER" value="'.dol_escape_htmltag(getDolGlobalString('CLAUDEVERIF_IMAP_FOLDER', 'INBOX')).'"></td></tr>';

print '<tr class="liste_titre"><td colspan="2">OCR (optionnel)</td></tr>';
print '<tr class="oddeven"><td>Clé API Mistral</td><td><input type="password" autocomplete="new-password" class="minwidth300" name="CLAUDEVERIF_MISTRAL_KEY" value="" placeholder="'.(getDolGlobalString('CLAUDEVERIF_MISTRAL_KEY') ? '(enregistrée — laisser vide pour conserver, « - » pour supprimer)' : '').'">';
print '<br><span class="opacitymedium">Utilisée seulement si un scan ne peut pas être converti en images (CCITT, JBIG2…) ou sur demande (mode ocr). Le document est alors envoyé à Mistral.</span></td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testimap">';
print '<div class="center"><input type="submit" class="button" value="Tester la connexion IMAP"></div>';
print '</form>';

llxFooter();
$db->close();
