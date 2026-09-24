<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Lecture des mails (IMAP OVH) via la librairie Webklex fournie avec Dolibarr
 */

dol_include_once('/claudeverif/class/claudeverifpdf.class.php');
dol_include_once('/claudeverif/class/claudeverifdoc.class.php');

/**
 * Accès IMAP en lecture seule (les mails ne sont jamais marqués comme lus)
 */
class ClaudeVerifMail
{
	const MAX_BODY = 20000;       // caractères max du corps renvoyé
	const MAX_ATTACH_TEXT = 20000; // caractères max du texte extrait d'une PJ
	const MIN_IMAGE_SIZE = 15000;  // images plus petites ignorées (logos de signature)

	/** @var \Webklex\PHPIMAP\Client|null */
	private $client = null;
	/** @var mixed */
	private $folder = null;

	/**
	 * Connexion à la boîte configurée
	 *
	 * @return void
	 * @throws Exception
	 */
	public function connect()
	{
		$autoload = DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';
		if (!file_exists($autoload)) {
			throw new Exception('Librairie IMAP Webklex introuvable ('.$autoload.')');
		}
		require_once $autoload;

		$login = getDolGlobalString('CLAUDEVERIF_IMAP_LOGIN');
		$pass = getDolGlobalString('CLAUDEVERIF_IMAP_PASSWORD');
		if ($login === '' || $pass === '') {
			throw new Exception('Messagerie IMAP non configurée (identifiant ou mot de passe manquant)');
		}

		$cm = new \Webklex\PHPIMAP\ClientManager();
		$this->client = $cm->make(array(
			'host' => getDolGlobalString('CLAUDEVERIF_IMAP_HOST', 'ssl0.ovh.net'),
			'port' => getDolGlobalInt('CLAUDEVERIF_IMAP_PORT', 993),
			'encryption' => 'ssl',
			'validate_cert' => true,
			'username' => $login,
			'password' => dolDecrypt($pass),
			'protocol' => 'imap',
		));
		$this->client->connect();

		$path = getDolGlobalString('CLAUDEVERIF_IMAP_FOLDER', 'INBOX');
		$this->folder = method_exists($this->client, 'getFolderByPath') ? $this->client->getFolderByPath($path) : $this->client->getFolder($path);
		if (!$this->folder) {
			throw new Exception('Dossier IMAP introuvable : '.$path);
		}
	}

	/**
	 * @return void
	 */
	public function disconnect()
	{
		if ($this->client) {
			try {
				$this->client->disconnect();
			} catch (Throwable $e) {
				// sans importance
			}
		}
		$this->client = null;
		$this->folder = null;
	}

	/**
	 * Requête de base : lecture seule, plus récents d'abord
	 *
	 * @return mixed WhereQuery
	 */
	private function query()
	{
		$q = $this->folder->query();
		if (method_exists($q, 'leaveUnread')) {
			$q->leaveUnread();
		}
		if (method_exists($q, 'setFetchOrder')) {
			$q->setFetchOrder('desc');
		} elseif (method_exists($q, 'fetchOrderDesc')) {
			$q->fetchOrderDesc();
		}
		return $q;
	}

	/**
	 * Recherche de mails
	 *
	 * @param array $args texte, expediteur, objet, depuis_jours, limit
	 * @return array
	 * @throws Exception
	 */
	public function search($args)
	{
		$days = max(1, min(120, (int) ($args['depuis_jours'] ?? 15)));
		$limit = max(1, min(30, (int) ($args['limit'] ?? 15)));

		$this->connect();
		try {
			$q = $this->query();
			$since = class_exists('\Carbon\Carbon') ? \Carbon\Carbon::now()->subDays($days) : date('d.m.Y', strtotime('-'.$days.' days'));
			$q->since($since);
			if (trim((string) ($args['texte'] ?? '')) !== '') {
				$q->text(trim((string) $args['texte']));
			}
			if (trim((string) ($args['expediteur'] ?? '')) !== '') {
				$q->from(trim((string) $args['expediteur']));
			}
			if (trim((string) ($args['objet'] ?? '')) !== '') {
				$q->subject(trim((string) $args['objet']));
			}
			if (method_exists($q, 'setFetchBody')) {
				$q->setFetchBody(false); // en-têtes seulement : rapide
			}
			$messages = $q->limit($limit)->get();

			$out = array();
			foreach ($messages as $m) {
				$out[] = array(
					'uid' => (int) $m->getUid(),
					'date' => $this->msgDate($m),
					'de' => $this->addresses($m->getFrom()),
					'a' => $this->addresses($m->getTo()),
					'objet' => $this->decodeHeader((string) $m->getSubject()),
				);
			}
		} finally {
			$this->disconnect();
		}

		return array(
			'periode' => $days.' derniers jours',
			'nombre' => count($out),
			'mails' => $out,
			'aide' => $out ? "Utiliser get_mail avec l'uid pour lire le contenu et les pièces jointes."
				: "Aucun résultat : élargir la période (depuis_jours) ou essayer un autre terme (réf. client, n° de commande client, nom du chantier, adresse de l'expéditeur).",
		);
	}

	/**
	 * Lecture complète d'un mail
	 *
	 * @param array $args uid
	 * @return array
	 * @throws Exception
	 */
	public function get($args)
	{
		$uid = (int) ($args['uid'] ?? 0);
		if ($uid <= 0) {
			throw new Exception("Indiquer 'uid' (obtenu avec search_mails)");
		}

		$this->connect();
		try {
			$m = $this->fetchMessage($uid);

			// Corps : texte brut de préférence, sinon HTML converti
			$body = '';
			if (method_exists($m, 'hasTextBody') && $m->hasTextBody()) {
				$body = (string) $m->getTextBody();
			}
			if (trim($body) === '' && method_exists($m, 'hasHTMLBody') && $m->hasHTMLBody()) {
				$body = $this->htmlToText((string) $m->getHTMLBody());
			}
			$body = $this->normalize($body);
			$truncated = mb_strlen($body) > self::MAX_BODY;
			if ($truncated) {
				$body = mb_substr($body, 0, self::MAX_BODY);
			}

			$attachments = array();
			$index = 0;
			foreach ($m->getAttachments() as $att) {
				$index++;
				$name = $this->decodeHeader((string) ($att->getName() ?? $att->name ?? ''));
				$mime = strtolower((string) ($att->getMimeType() ?? $att->content_type ?? ''));
				$size = (int) ($att->getSize() ?? $att->size ?? 0);

				if (strpos($mime, 'image/') === 0 && $size < self::MIN_IMAGE_SIZE) {
					continue; // logo de signature
				}

				$info = array('index' => $index, 'nom' => $name, 'type' => $mime, 'taille_ko' => (int) round($size / 1024));
				$info = array_merge($info, $this->describeAttachment($att, $name, $mime));
				$attachments[] = $info;
			}

			$result = array(
				'uid' => $uid,
				'date' => $this->msgDate($m),
				'de' => $this->addresses($m->getFrom()),
				'a' => $this->addresses($m->getTo()),
				'cc' => $this->addresses($m->getCc()),
				'objet' => $this->decodeHeader((string) $m->getSubject()),
				'corps' => $body,
				'corps_tronque' => $truncated,
				'pieces_jointes' => $attachments,
			);
		} finally {
			$this->disconnect();
		}

		return $result;
	}

	/**
	 * Contenu d'une pièce jointe : texte, ou images des pages pour un scan
	 *
	 * @param array $args uid, index, pages, mode
	 * @return array Résultat (clé _mcp_content si images)
	 * @throws Exception
	 */
	public function attachment($args)
	{
		$uid = (int) ($args['uid'] ?? 0);
		$index = (int) ($args['index'] ?? 0);
		$mode = (string) ($args['mode'] ?? 'auto');
		$pages = $this->parsePages((string) ($args['pages'] ?? ''));
		if ($uid <= 0 || $index <= 0) {
			throw new Exception("Indiquer 'uid' et 'index' (obtenus avec get_mail)");
		}

		$this->connect();
		try {
			$m = $this->fetchMessage($uid);
			$att = null;
			$i = 0;
			foreach ($m->getAttachments() as $a) {
				if (++$i === $index) {
					$att = $a;
					break;
				}
			}
			if (!$att) {
				throw new Exception('Pièce jointe introuvable : index '.$index);
			}
			$name = $this->decodeHeader((string) ($att->getName() ?? ''));
			$mime = strtolower((string) ($att->getMimeType() ?? ''));
			$content = (string) $att->getContent();
		} finally {
			$this->disconnect();
		}

		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$head = 'Pièce jointe '.$index.' : '.$name;

		// Image jointe (photo de bon de commande…)
		if (strpos($mime, 'image/') === 0 || in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'))) {
			$pdf = new ClaudeVerifPdf();
			return array('_mcp_content' => array(
				array('type' => 'text', 'text' => $head),
				array('type' => 'image', 'data' => $pdf->imageToJpegBase64($content), 'mimeType' => 'image/jpeg'),
			));
		}

		if ($ext !== 'pdf' && $mime !== 'application/pdf') {
			$d = $this->describeAttachment($att, $name, $mime);
			return array('piece_jointe' => $name, 'contenu' => $d['texte'] ?? ($d['note'] ?? 'Type de fichier non lisible'));
		}

		$pdf = new ClaudeVerifPdf();
		if ($mode === 'texte') {
			return array('piece_jointe' => $name) + $pdf->analyze($content);
		}
		if ($mode === 'ocr') {
			return array('piece_jointe' => $name, 'source' => 'OCR Mistral', 'texte' => $pdf->ocrMistral($content));
		}

		// auto / images
		$analysis = $pdf->analyze($content);
		if ($mode === 'auto' && empty($analysis['erreur']) && empty($analysis['pages_scannees'])) {
			return array('piece_jointe' => $name, 'info' => 'PDF avec texte, pas de page scannée') + $analysis;
		}
		if ($mode === 'auto' && !$pages && !empty($analysis['pages_scannees'])) {
			$pages = $analysis['pages_scannees'];
		}

		$res = $pdf->pageImages($content, $pages);
		if (!$res['images']) {
			$reasons = array();
			foreach ($res['ignorees'] as $ig) {
				$reasons[] = 'page '.$ig['page'].' : '.$ig['raison'];
			}
			if (getDolGlobalString('CLAUDEVERIF_MISTRAL_KEY') !== '') {
				return array('piece_jointe' => $name, 'source' => 'OCR Mistral (pages non convertibles en images)', 'texte' => $pdf->ocrMistral($content));
			}
			throw new Exception('Aucune page convertible en image ('.implode(' ; ', $reasons).'). OCR Mistral non configuré : vérifier ce document manuellement.');
		}

		$summary = $head.' — '.$res['nb_pages'].' page(s), images des pages '.implode(', ', array_column($res['images'], 'page')).'.';
		if (!empty($analysis['texte'])) {
			$summary .= "\n\nTexte présent dans le PDF :\n".$analysis['texte'];
		}
		foreach ($res['ignorees'] as $ig) {
			$summary .= "\nPage ".$ig['page'].' non incluse : '.$ig['raison'];
		}
		$out = array(array('type' => 'text', 'text' => $summary));
		foreach ($res['images'] as $img) {
			$out[] = array('type' => 'text', 'text' => 'Page '.$img['page'].' :');
			$out[] = array('type' => 'image', 'data' => $img['data'], 'mimeType' => 'image/jpeg');
		}
		return array('_mcp_content' => $out);
	}

	/**
	 * Texte ou note pour une pièce jointe (utilisé par get_mail)
	 *
	 * @param mixed  $att  Attachment
	 * @param string $name Nom
	 * @param string $mime Type
	 * @return array texte et/ou note, nb_pages
	 */
	private function describeAttachment($att, $name, $mime)
	{
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$out = array();
		try {
			if ($ext === 'docx') {
				$out['texte'] = $this->docxToText((string) $att->getContent());
			} elseif ($ext === 'doc') {
				$doc = new ClaudeVerifDoc();
				$t = $doc->extract((string) $att->getContent());
				$out['texte'] = $t === '' ? '(document vide)' : $this->cut($t);
			} elseif (in_array($ext, array('txt', 'csv')) || strpos($mime, 'text/plain') === 0) {
				$out['texte'] = $this->cut($this->normalize((string) $att->getContent()));
			} elseif ($ext === 'pdf' || $mime === 'application/pdf') {
				$pdf = new ClaudeVerifPdf();
				$a = $pdf->analyze((string) $att->getContent());
				if (!empty($a['erreur'])) {
					$out['note'] = $a['erreur'].' — essayer get_piece_jointe (mode images ou ocr)';
				} else {
					$out['nb_pages'] = $a['nb_pages'];
					if ($a['texte'] !== '') {
						$out['texte'] = $a['texte'];
					}
					if ($a['scanne']) {
						$out['note'] = 'PDF scanné (pas de texte) : appeler get_piece_jointe avec uid et index pour voir les pages en images';
					} elseif ($a['pages_scannees']) {
						$out['note'] = 'Pages sans texte : '.implode(', ', $a['pages_scannees']).' — get_piece_jointe pour les voir en images';
					}
				}
			} elseif (strpos($mime, 'image/') === 0) {
				$out['note'] = 'Image : get_piece_jointe pour la voir';
			}
		} catch (Throwable $e) {
			$out['note'] = 'Extraction impossible : '.$e->getMessage();
		}
		return $out;
	}

	/**
	 * @param int $uid UID IMAP
	 * @return mixed Message
	 * @throws Exception
	 */
	private function fetchMessage($uid)
	{
		$q = $this->query();
		if (method_exists($q, 'getMessageByUid')) {
			$m = $q->getMessageByUid($uid);
		} else {
			$m = $q->whereUid($uid)->get()->first();
		}
		if (!$m) {
			throw new Exception('Mail introuvable : uid '.$uid);
		}
		return $m;
	}

	/**
	 * "1-3,5" -> [1,2,3,5]
	 *
	 * @param string $s Pages
	 * @return int[]
	 */
	private function parsePages($s)
	{
		$out = array();
		foreach (preg_split('/[,;\s]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) as $part) {
			if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
				for ($i = (int) $m[1]; $i <= (int) $m[2] && $i - (int) $m[1] < 50; $i++) {
					$out[] = $i;
				}
			} elseif (ctype_digit($part)) {
				$out[] = (int) $part;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Test de connexion (page de configuration)
	 *
	 * @return string Message
	 * @throws Exception
	 */
	public function test()
	{
		$this->connect();
		try {
			$q = $this->query();
			$q->since(class_exists('\Carbon\Carbon') ? \Carbon\Carbon::now()->subDays(7) : date('d.m.Y', strtotime('-7 days')));
			if (method_exists($q, 'setFetchBody')) {
				$q->setFetchBody(false);
			}
			$n = $q->count();
		} finally {
			$this->disconnect();
		}
		return 'Connexion IMAP OK — '.$n.' mail(s) reçu(s) ces 7 derniers jours';
	}

	/**
	 * Texte d'un .docx (sans librairie : lecture de word/document.xml)
	 *
	 * @param string $content Contenu binaire
	 * @return string
	 */
	private function docxToText($content)
	{
		global $conf;

		if (!class_exists('ZipArchive')) {
			return '(extraction impossible : ZipArchive absent)';
		}
		$dir = $conf->claudeverif->dir_temp ?? (DOL_DATA_ROOT.'/claudeverif/temp');
		dol_mkdir($dir);
		$tmp = $dir.'/docx_'.bin2hex(random_bytes(6)).'.docx';
		file_put_contents($tmp, $content);

		$text = '';
		$zip = new ZipArchive();
		if ($zip->open($tmp) === true) {
			$xml = (string) $zip->getFromName('word/document.xml');
			$zip->close();
			$xml = preg_replace('~</w:p>\s*</w:tc>~', '</w:tc>', $xml); // tableaux : une ligne par rangée
			$xml = preg_replace('~</w:tc>~', ' | ', $xml);
			$xml = preg_replace('~</w:tr>~', "\n", $xml);
			$xml = preg_replace('~</w:p>~', "\n", $xml);
			$xml = preg_replace('~<w:tab[^>]*/>~', "\t", $xml);
			$xml = preg_replace('~<w:br[^>]*/>~', "\n", $xml);
			$text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
		}
		@unlink($tmp);

		$text = $this->normalize($text);
		return $text === '' ? '(document vide ou illisible)' : $this->cut($text);
	}

	/**
	 * @param string $html HTML
	 * @return string
	 */
	private function htmlToText($html)
	{
		$html = preg_replace('~<(style|script|head)[^>]*>.*?</\1>~is', '', $html);
		$html = preg_replace('~<br\s*/?>~i', "\n", $html);
		$html = preg_replace('~</(p|div|tr|li|h[1-6]|table|blockquote)>~i', "\n", $html);
		$html = preg_replace('~</t[dh]>~i', "\t", $html);
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * @param string $s Texte
	 * @return string
	 */
	private function normalize($s)
	{
		if (!mb_check_encoding($s, 'UTF-8')) {
			$s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
		}
		$s = str_replace(array("\r\n", "\r", "\xC2\xA0"), array("\n", "\n", ' '), $s);
		$s = preg_replace("/[ \t]+\n/", "\n", $s);
		$s = preg_replace("/\n{3,}/", "\n\n", $s);
		return trim($s);
	}

	/**
	 * @param string $s Texte
	 * @return string
	 */
	private function cut($s)
	{
		return mb_strlen($s) > self::MAX_ATTACH_TEXT ? mb_substr($s, 0, self::MAX_ATTACH_TEXT)."\n[… tronqué]" : $s;
	}

	/**
	 * @param string $s En-tête éventuellement encodé MIME
	 * @return string
	 */
	private function decodeHeader($s)
	{
		if (strpos($s, '=?') !== false && function_exists('iconv_mime_decode')) {
			$d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
			if ($d !== false) {
				$s = $d;
			}
		}
		return trim($s);
	}

	/**
	 * @param mixed $m Message
	 * @return string|null
	 */
	private function msgDate($m)
	{
		try {
			$d = $m->getDate();
			if (is_object($d) && method_exists($d, 'toDate')) {
				return $d->toDate()->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
			}
			return (string) $d;
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * @param mixed $attr Attribute d'adresses
	 * @return array
	 */
	private function addresses($attr)
	{
		$out = array();
		if (!$attr) {
			return $out;
		}
		$list = method_exists($attr, 'toArray') ? $attr->toArray() : (array) $attr;
		foreach ($list as $a) {
			if (is_object($a)) {
				$mail = (string) ($a->mail ?? '');
				$name = $this->decodeHeader((string) ($a->personal ?? ''));
				$out[] = ($name !== '' && $name !== $mail) ? $name.' <'.$mail.'>' : $mail;
			} elseif (is_string($a)) {
				$out[] = $a;
			}
		}
		return $out;
	}
}
