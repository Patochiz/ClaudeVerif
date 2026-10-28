<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Lecture des PDF : couche texte, images des pages scannées, OCR Mistral en secours
 */

/**
 * Analyse de PDF en PHP pur (smalot/pdfparser livré dans lib/)
 */
class ClaudeVerifPdf
{
	const MAX_TEXT = 20000;       // caractères max de texte renvoyé
	const MIN_CHARS_PER_PAGE = 40; // en dessous : page considérée comme scannée
	const IMAGE_MAX_EDGE = 1568;  // taille optimale pour la vision de Claude
	const IMAGE_QUALITY = 82;
	const MAX_PAGES_IMAGES = 5;   // pages max renvoyées en images par appel

	/** @var bool */
	private static $autoload = false;

	/**
	 * Autoloader PSR-4 de smalot/pdfparser (sans Composer)
	 *
	 * @return void
	 */
	private static function loadLib()
	{
		if (self::$autoload) {
			return;
		}
		$base = dol_buildpath('/claudeverif/lib/pdfparser/src/Smalot/PdfParser/', 0);
		spl_autoload_register(function ($class) use ($base) {
			$prefix = 'Smalot\\PdfParser\\';
			if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
				return;
			}
			$file = $base.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
			if (file_exists($file)) {
				require_once $file;
			}
		});
		self::$autoload = true;
	}

	/**
	 * @param string $content Contenu binaire du PDF
	 * @return \Smalot\PdfParser\Document
	 * @throws Exception
	 */
	private function parse($content)
	{
		self::loadLib();
		$config = new \Smalot\PdfParser\Config();
		$config->setRetainImageContent(true);
		if (method_exists($config, 'setIgnoreEncryption')) {
			$config->setIgnoreEncryption(true);
		}
		$parser = new \Smalot\PdfParser\Parser(array(), $config);
		return $parser->parseContent($content);
	}

	/**
	 * Texte du PDF et détection des scans
	 *
	 * @param string $content Contenu binaire
	 * @return array nb_pages, scanne, pages_scannees, texte
	 */
	public function analyze($content)
	{
		try {
			$pdf = $this->parse($content);
			$pages = $pdf->getPages();
		} catch (Throwable $e) {
			return array('erreur' => 'PDF illisible : '.$e->getMessage());
		}

		$texts = array();
		$scanned = array();
		$n = 0;
		foreach ($pages as $page) {
			$n++;
			try {
				$t = $this->normalize((string) $page->getText());
			} catch (Throwable $e) {
				$t = '';
			}
			if (preg_match_all('/[\p{L}\p{N}]/u', $t) < self::MIN_CHARS_PER_PAGE) {
				$scanned[] = $n;
			}
			if ($t !== '') {
				$texts[] = '--- Page '.$n.' ---'."\n".$t;
			}
		}

		$text = implode("\n\n", $texts);
		if (mb_strlen($text) > self::MAX_TEXT) {
			$text = mb_substr($text, 0, self::MAX_TEXT)."\n[… tronqué]";
		}

		return array(
			'nb_pages' => $n,
			'scanne' => ($n > 0 && count($scanned) === $n),
			'pages_scannees' => $scanned,
			'texte' => $text,
		);
	}

	/**
	 * Images JPEG des pages (pour les scans)
	 *
	 * @param string $content     Contenu binaire
	 * @param array  $wanted      Numéros de pages voulus (vide = toutes, dans la limite)
	 * @param array  $renderPages Pages à rendre en entier (PDF généré, avec texte) au lieu d'en extraire l'image principale
	 * @return array ['images' => [[page, data(base64)]], 'ignorees' => [[page, raison]], 'nb_pages' => n]
	 * @throws Exception
	 */
	public function pageImages($content, $wanted = array(), $renderPages = array())
	{
		if (!function_exists('imagecreatefromstring')) {
			throw new Exception('Extension GD absente : conversion des pages en images impossible');
		}
		$pdf = $this->parse($content);
		$pages = $pdf->getPages();

		$images = array();
		$skipped = array();
		$n = 0;
		foreach ($pages as $page) {
			$n++;
			if ($wanted && !in_array($n, $wanted, true)) {
				continue;
			}
			if (count($images) >= self::MAX_PAGES_IMAGES) {
				$skipped[] = array('page' => $n, 'raison' => 'limite de '.self::MAX_PAGES_IMAGES.' pages par appel : redemander avec le paramètre pages');
				continue;
			}

			// Page avec texte (document généré) : rendu complet de la page
			if (in_array($n, $renderPages, true)) {
				try {
					$images[] = array('page' => $n, 'data' => base64_encode($this->renderPage($content, $n)));
				} catch (Throwable $e) {
					$skipped[] = array('page' => $n, 'raison' => $e->getMessage());
				}
				continue;
			}

			// Image la plus grande de la page = le scan
			$best = null;
			$bestArea = 0;
			try {
				foreach ((array) $page->getXObjects() as $xo) {
					if (!($xo instanceof \Smalot\PdfParser\XObject\Image)) {
						continue;
					}
					$d = $xo->getDetails();
					$area = (int) ($d['Width'] ?? 0) * (int) ($d['Height'] ?? 0);
					if ($area > $bestArea) {
						$best = $xo;
						$bestArea = $area;
					}
				}
			} catch (Throwable $e) {
				$best = null;
			}
			if (!$best) {
				$skipped[] = array('page' => $n, 'raison' => 'aucune image sur la page');
				continue;
			}

			try {
				$gd = $this->toGd($best);
				$images[] = array('page' => $n, 'data' => base64_encode($this->toJpeg($gd)));
				imagedestroy($gd);
			} catch (Throwable $e) {
				$skipped[] = array('page' => $n, 'raison' => $e->getMessage());
			}
		}

		$this->cleanRender();
		return array('images' => $images, 'ignorees' => $skipped, 'nb_pages' => $n);
	}

	/** @var string|null Fichier temporaire du PDF en cours de rendu */
	private $renderFile = null;

	/**
	 * Rendu complet d'une page en JPEG (Imagick + Ghostscript)
	 *
	 * @param string $content Contenu binaire du PDF
	 * @param int    $n       Numéro de page (1 = première)
	 * @return string JPEG
	 * @throws Exception
	 */
	private function renderPage($content, $n)
	{
		if (!class_exists('Imagick')) {
			throw new Exception("rendu en image impossible (extension Imagick absente sur le serveur) : utiliser le mode texte");
		}
		if ($this->renderFile === null) {
			// Copie temporaire dans documents/claudeverif/temp, supprimée après le rendu
			$this->renderFile = $this->tempFile('pdf');
			file_put_contents($this->renderFile, $content);
		}
		try {
			$im = new Imagick();
			$im->setResolution(150, 150);
			$im->readImage($this->renderFile.'['.((int) $n - 1).']');
			$im->setImageBackgroundColor('white');
			$flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
			$flat->setImageFormat('png');
			$blob = $flat->getImageBlob();
			$flat->clear();
			$im->clear();
		} catch (Throwable $e) {
			throw new Exception('rendu en image impossible ('.$e->getMessage().') : utiliser le mode texte');
		}
		$gd = @imagecreatefromstring($blob);
		if (!$gd) {
			throw new Exception('rendu en image illisible');
		}
		$jpeg = $this->toJpeg($gd);
		imagedestroy($gd);
		return $jpeg;
	}

	/**
	 * Supprime le fichier temporaire de rendu
	 *
	 * @return void
	 */
	private function cleanRender()
	{
		if ($this->renderFile !== null) {
			@unlink($this->renderFile);
			$this->renderFile = null;
		}
	}

	/**
	 * Lecture d'un PDF selon le mode (partagé par get_piece_jointe et get_pdf_commande)
	 *
	 * @param string $content Contenu binaire du PDF
	 * @param string $label   Libellé du document (en tête de la réponse en images)
	 * @param string $mode    auto | texte | images | ocr
	 * @param int[]  $pages   Pages voulues (vide = défaut du mode)
	 * @param array  $meta    Informations ajoutées à la réponse (nom du fichier, dates…)
	 * @param bool   $render  true pour un PDF généré : pages avec texte rendues en entier (Imagick) en mode images
	 * @return array Résultat (clé _mcp_content si images)
	 * @throws Exception
	 */
	public function read($content, $label, $mode, $pages = array(), $meta = array(), $render = false)
	{
		if (!in_array($mode, array('auto', 'texte', 'images', 'ocr'), true)) {
			throw new Exception("Mode inconnu : ".$mode." (auto, texte, images ou ocr)");
		}
		if ($mode === 'ocr') {
			return $meta + array('source' => 'OCR Mistral', 'texte' => $this->ocrMistral($content));
		}

		$analysis = $this->analyze($content);
		if ($mode === 'texte') {
			return $meta + $this->filterPages($analysis, $pages);
		}

		// auto / images
		if ($mode === 'auto' && empty($analysis['erreur']) && empty($analysis['pages_scannees'])) {
			return $meta + array('info' => 'PDF avec texte, pas de page scannée') + $this->filterPages($analysis, $pages);
		}
		$text = $this->filterPages($analysis, $pages)['texte'] ?? '';
		if ($mode === 'auto' && !$pages && !empty($analysis['pages_scannees'])) {
			$pages = $analysis['pages_scannees'];
		}

		$renderPages = array();
		if ($render && empty($analysis['erreur'])) {
			for ($i = 1; $i <= (int) $analysis['nb_pages']; $i++) {
				if (!in_array($i, (array) $analysis['pages_scannees'], true)) {
					$renderPages[] = $i;
				}
			}
		}

		try {
			$res = $this->pageImages($content, $pages, $renderPages);
		} finally {
			$this->cleanRender();
		}
		if (!$res['images']) {
			$reasons = array();
			foreach ($res['ignorees'] as $ig) {
				$reasons[] = 'page '.$ig['page'].' : '.$ig['raison'];
			}
			if (getDolGlobalString('CLAUDEVERIF_MISTRAL_KEY') !== '') {
				return $meta + array('source' => 'OCR Mistral (pages non convertibles en images)', 'texte' => $this->ocrMistral($content));
			}
			if ($text !== '') {
				return $meta + array('note' => 'Pages non convertibles en images ('.implode(' ; ', $reasons).') : texte du PDF renvoyé à la place')
					+ $this->filterPages($analysis, $pages);
			}
			throw new Exception('Aucune page convertible en image ('.($reasons ? implode(' ; ', $reasons) : 'pages demandées absentes').'). OCR Mistral non configuré : utiliser le mode texte ou vérifier ce document manuellement.');
		}

		$summary = $label.' — '.$res['nb_pages'].' page(s), images des pages '.implode(', ', array_column($res['images'], 'page')).'.';
		$extra = $meta;
		unset($extra['piece_jointe']);
		if ($extra) {
			$summary .= "\n".json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
		}
		if ($text !== '') {
			$summary .= "\n\nTexte présent dans le PDF :\n".$text;
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
	 * Ne garde que le texte des pages voulues
	 *
	 * @param array $analysis Résultat de analyze()
	 * @param int[] $pages    Pages voulues (vide = toutes)
	 * @return array
	 */
	private function filterPages($analysis, $pages)
	{
		if (!$pages || !empty($analysis['erreur']) || ($analysis['texte'] ?? '') === '') {
			return $analysis;
		}
		$keep = array();
		foreach (preg_split('/\n\n(?=--- Page \d+ ---\n)/', $analysis['texte']) as $block) {
			if (preg_match('/^--- Page (\d+) ---/', $block, $m) && in_array((int) $m[1], $pages, true)) {
				$keep[] = $block;
			}
		}
		$analysis['texte'] = implode("\n\n", $keep);
		$analysis['pages_demandees'] = $pages;
		return $analysis;
	}

	/**
	 * "1-3,5" -> [1,2,3,5]
	 *
	 * @param string $s Pages
	 * @return int[]
	 */
	public static function parsePages($s)
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
	 * Conversion d'une image PDF en ressource GD
	 *
	 * @param \Smalot\PdfParser\XObject\Image $img Image
	 * @return GdImage|resource
	 * @throws Exception
	 */
	private function toGd($img)
	{
		$d = $img->getDetails();
		$filters = $d['Filter'] ?? array();
		$filters = is_array($filters) ? array_values($filters) : array($filters);
		$filters = array_map('strval', $filters);
		$data = (string) $img->getContent();
		// Filtres de transport restants (la librairie laisse le flux brut si un décodage échoue)
		foreach ($filters as $f) {
			if (in_array($f, array('DCTDecode', 'CCITTFaxDecode', 'JBIG2Decode', 'JPXDecode'), true)) {
				break;
			}
			$data = $this->decodeTransport($f, $data);
		}
		$w = (int) ($d['Width'] ?? 0);
		$h = (int) ($d['Height'] ?? 0);

		// JPEG : le flux est un fichier JPEG complet
		if (in_array('DCTDecode', $filters, true)) {
			$gd = @imagecreatefromstring($data);
			if (!$gd) {
				throw new Exception('JPEG illisible (peut-être CMJN)');
			}
			return $gd;
		}

		foreach (array('CCITTFaxDecode' => 'CCITT (fax)', 'JBIG2Decode' => 'JBIG2', 'JPXDecode' => 'JPEG 2000') as $f => $label) {
			if (in_array($f, $filters, true)) {
				throw new Exception('format de scan '.$label.' non convertible en image sur le serveur');
			}
		}

		// Bitmap brut (Flate décodé par la librairie)
		$bpc = (int) ($d['BitsPerComponent'] ?? 8);
		$cs = $d['ColorSpace'] ?? '';
		$cs = is_array($cs) ? (string) reset($cs) : (string) $cs;
		$predictor = (int) ($d['DecodeParms']['Predictor'] ?? 1);
		if ($w <= 0 || $h <= 0 || $predictor > 1) {
			throw new Exception('format d\'image non pris en charge');
		}

		if ($bpc === 1 || !empty($d['ImageMask'])) {
			$rowIn = (int) ceil($w / 8);
			$decode = $d['Decode'] ?? array();
			$invert = (is_array($decode) && isset($decode[0]) && (int) $decode[0] === 1);
			if (!empty($d['ImageMask'])) {
				$invert = !$invert; // masque : 0 = peint (noir)... inversé par rapport au gris
			}
			return $this->bmpToGd($data, $w, $h, $rowIn, 1, $invert);
		}
		if ($bpc === 8 && stripos($cs, 'Gray') !== false) {
			return $this->bmpToGd($data, $w, $h, $w, 8);
		}
		throw new Exception('format d\'image non pris en charge ('.$cs.', '.$bpc.' bits)');
	}

	/**
	 * Décodage tolérant : si les données sont déjà décodées, elles sont renvoyées telles quelles
	 *
	 * @param string $filter Filtre PDF
	 * @param string $data   Données
	 * @return string
	 */
	private function decodeTransport($filter, $data)
	{
		switch ($filter) {
			case 'ASCII85Decode':
				$out = $this->ascii85($data);
				return $out === null ? $data : $out;
			case 'ASCIIHexDecode':
				$hex = preg_replace('/[^0-9A-Fa-f]/', '', str_replace('>', '', $data));
				if (strlen($hex) % 2) {
					$hex .= '0';
				}
				return (strlen($data) > 0 && preg_match('/^[0-9A-Fa-f\s>]+$/', $data)) ? (string) hex2bin($hex) : $data;
			case 'FlateDecode':
				$out = @gzuncompress($data);
				return $out === false ? $data : $out;
		}
		return $data;
	}

	/**
	 * @param string $data Données ASCII85
	 * @return string|null null si ce n'est pas de l'ASCII85
	 */
	private function ascii85($data)
	{
		$data = preg_replace('/\s+/', '', $data);
		if (substr($data, 0, 2) === '<~') {
			$data = substr($data, 2);
		}
		$end = strpos($data, '~>');
		if ($end !== false) {
			$data = substr($data, 0, $end);
		}
		if ($data === '' || preg_match('/[^!-uz]/', $data)) {
			return null;
		}
		$out = '';
		$group = array();
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$c = $data[$i];
			if ($c === 'z' && !$group) {
				$out .= "\0\0\0\0";
				continue;
			}
			$group[] = ord($c) - 33;
			if (count($group) === 5) {
				$v = (((($group[0] * 85 + $group[1]) * 85 + $group[2]) * 85 + $group[3]) * 85 + $group[4]);
				$out .= pack('N', $v & 0xFFFFFFFF);
				$group = array();
			}
		}
		if ($group) {
			$n = count($group);
			for ($k = $n; $k < 5; $k++) {
				$group[] = 84;
			}
			$v = (((($group[0] * 85 + $group[1]) * 85 + $group[2]) * 85 + $group[3]) * 85 + $group[4]);
			$out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $n - 1);
		}
		return $out;
	}

	/**
	 * Bitmap brut (1 ou 8 bits, gris) -> GD via un BMP en mémoire (rapide, sans boucle par pixel)
	 *
	 * @param string $data  Pixels bruts, lignes de haut en bas
	 * @param int    $w     Largeur
	 * @param int    $h     Hauteur
	 * @param int    $rowIn Octets par ligne dans le PDF
	 * @param int    $bits  1 ou 8
	 * @param bool   $invert Palette inversée (1 bit)
	 * @return GdImage|resource
	 * @throws Exception
	 */
	private function bmpToGd($data, $w, $h, $rowIn, $bits, $invert = false)
	{
		if (!function_exists('imagecreatefrombmp')) {
			throw new Exception('imagecreatefrombmp indisponible');
		}
		if (strlen($data) < $rowIn * $h) {
			throw new Exception('données d\'image incomplètes');
		}
		$rowOut = (int) (ceil($rowIn / 4) * 4);
		$pad = str_repeat("\0", $rowOut - $rowIn);
		$rows = str_split(substr($data, 0, $rowIn * $h), $rowIn);
		$pixels = implode($pad, array_reverse($rows)).$pad; // BMP : de bas en haut

		$ncolors = ($bits === 1) ? 2 : 256;
		$palette = '';
		for ($i = 0; $i < $ncolors; $i++) {
			$v = ($bits === 1) ? (($i xor $invert) ? 255 : 0) : $i;
			$palette .= chr($v).chr($v).chr($v)."\0";
		}
		$offset = 14 + 40 + strlen($palette);
		$bmp = 'BM'.pack('VvvV', $offset + strlen($pixels), 0, 0, $offset)
			.pack('VVVvvVVVVVV', 40, $w, $h, 1, $bits, 0, strlen($pixels), 2835, 2835, $ncolors, 0)
			.$palette.$pixels;

		$tmp = $this->tempFile('bmp');
		file_put_contents($tmp, $bmp);
		$gd = @imagecreatefrombmp($tmp);
		@unlink($tmp);
		if (!$gd) {
			throw new Exception('conversion bitmap échouée');
		}
		return $gd;
	}

	/**
	 * Redimensionne et encode en JPEG
	 *
	 * @param GdImage|resource $gd Image
	 * @return string JPEG
	 */
	private function toJpeg($gd)
	{
		$w = imagesx($gd);
		$h = imagesy($gd);
		$scale = min(1, self::IMAGE_MAX_EDGE / max($w, $h));
		if ($scale < 1) {
			$nw = max(1, (int) round($w * $scale));
			$nh = max(1, (int) round($h * $scale));
			$dst = imagecreatetruecolor($nw, $nh);
			imagecopyresampled($dst, $gd, 0, 0, 0, 0, $nw, $nh, $w, $h);
		} else {
			$dst = imagecreatetruecolor($w, $h);
			imagecopy($dst, $gd, 0, 0, 0, 0, $w, $h);
		}
		ob_start();
		imagejpeg($dst, null, self::IMAGE_QUALITY);
		$jpeg = ob_get_clean();
		imagedestroy($dst);
		return $jpeg;
	}

	/**
	 * Image quelconque (JPEG, PNG…) -> JPEG redimensionné en base64
	 *
	 * @param string $content Contenu binaire
	 * @return string
	 * @throws Exception
	 */
	public function imageToJpegBase64($content)
	{
		if (!function_exists('imagecreatefromstring')) {
			throw new Exception('Extension GD absente');
		}
		$gd = @imagecreatefromstring($content);
		if (!$gd) {
			throw new Exception('Image illisible');
		}
		$jpeg = $this->toJpeg($gd);
		imagedestroy($gd);
		return base64_encode($jpeg);
	}

	/**
	 * OCR Mistral (secours pour les scans non convertibles)
	 *
	 * @param string $content Contenu binaire du PDF
	 * @return string Texte (markdown)
	 * @throws Exception
	 */
	public function ocrMistral($content)
	{
		$key = getDolGlobalString('CLAUDEVERIF_MISTRAL_KEY');
		if ($key === '') {
			throw new Exception('OCR Mistral non configuré (clé API absente)');
		}
		$payload = json_encode(array(
			'model' => 'mistral-ocr-latest',
			'document' => array('type' => 'document_url', 'document_url' => 'data:application/pdf;base64,'.base64_encode($content)),
		));
		$ch = curl_init('https://api.mistral.ai/v1/ocr');
		curl_setopt_array($ch, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer '.dolDecrypt($key)),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 90,
		));
		$resp = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($resp === false || $code !== 200) {
			throw new Exception('OCR Mistral en échec (HTTP '.$code.($err ? ', '.$err : '').')');
		}
		$json = json_decode($resp, true);
		$out = array();
		foreach ((array) ($json['pages'] ?? array()) as $i => $p) {
			$out[] = '--- Page '.((int) ($p['index'] ?? $i) + 1).' ---'."\n".trim((string) ($p['markdown'] ?? ''));
		}
		$text = $this->normalize(implode("\n\n", $out));
		return mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT)."\n[… tronqué]" : $text;
	}

	/**
	 * @param string $ext Extension
	 * @return string Chemin
	 */
	private function tempFile($ext)
	{
		global $conf;
		$dir = $conf->claudeverif->dir_temp ?? (DOL_DATA_ROOT.'/claudeverif/temp');
		dol_mkdir($dir);
		return $dir.'/cv_'.bin2hex(random_bytes(6)).'.'.$ext;
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
}
