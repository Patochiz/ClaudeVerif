<?php
/* Copyright (C) 2026 DIAMANT INDUSTRIE
 *
 * ClaudeVerif — Texte des fichiers Word 97-2003 (.doc), en PHP pur
 * Lecture du conteneur OLE (Compound File) puis de la table des pièces (CLX) du document.
 */

/**
 * Extraction du texte principal d'un .doc
 */
class ClaudeVerifDoc
{
	/** @var string */
	private $data;
	/** @var int */
	private $sectorSize;
	/** @var int */
	private $miniSectorSize;
	/** @var int */
	private $miniCutoff;
	/** @var int[] */
	private $fat = array();
	/** @var int[] */
	private $miniFat = array();
	/** @var array */
	private $dir = array();
	/** @var string */
	private $miniStream = '';

	/**
	 * @param string $content Contenu binaire du .doc
	 * @return string Texte
	 * @throws Exception
	 */
	public function extract($content)
	{
		$this->data = $content;
		if (substr($content, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
			throw new Exception('pas un fichier Word 97-2003 (conteneur OLE absent)');
		}
		$this->readCfb();

		$word = $this->stream('WordDocument');
		if ($word === null || strlen($word) < 0x1AA) {
			throw new Exception('flux WordDocument absent');
		}
		if ($this->u16($word, 0) !== 0xA5EC) {
			throw new Exception('en-tête Word invalide');
		}
		$flags = $this->u16($word, 0x0A);
		if ($flags & 0x0100) {
			throw new Exception('document protégé par mot de passe');
		}
		$table = $this->stream(($flags & 0x0200) ? '1Table' : '0Table');
		if ($table === null) {
			throw new Exception('flux Table absent');
		}

		$ccpText = $this->u32($word, 0x4C);
		$fcClx = $this->u32($word, 0x1A2);
		$lcbClx = $this->u32($word, 0x1A6);
		$clx = substr($table, $fcClx, $lcbClx);

		// CLX : ignorer les Prc (0x01), trouver le Pcdt (0x02)
		$pos = 0;
		$len = strlen($clx);
		while ($pos < $len && ord($clx[$pos]) === 0x01) {
			$pos += 3 + $this->u16($clx, $pos + 1);
		}
		if ($pos >= $len || ord($clx[$pos]) !== 0x02) {
			throw new Exception('table des pièces introuvable');
		}
		$lcb = $this->u32($clx, $pos + 1);
		$plc = substr($clx, $pos + 5, $lcb);
		$n = (int) (($lcb - 4) / 12);

		$text = '';
		$done = 0;
		for ($i = 0; $i < $n && $done < $ccpText; $i++) {
			$cpStart = $this->u32($plc, $i * 4);
			$cpEnd = $this->u32($plc, ($i + 1) * 4);
			$count = min($cpEnd - $cpStart, $ccpText - $done);
			if ($count <= 0) {
				continue;
			}
			$fc = $this->u32($plc, ($n + 1) * 4 + $i * 8 + 2);
			if ($fc & 0x40000000) {
				// 8 bits (Windows-1252)
				$off = (int) (($fc & 0x3FFFFFFF) / 2);
				$text .= mb_convert_encoding(substr($word, $off, $count), 'UTF-8', 'Windows-1252');
			} else {
				// UTF-16LE
				$text .= mb_convert_encoding(substr($word, $fc, $count * 2), 'UTF-8', 'UTF-16LE');
			}
			$done += $count;
		}

		return $this->cleanText($text);
	}

	/**
	 * Caractères de contrôle Word -> texte lisible
	 *
	 * @param string $t Texte brut
	 * @return string
	 */
	private function cleanText($t)
	{
		// Champs : \x13 instruction \x14 résultat \x15 -> garder le résultat
		$t = preg_replace('/\x13[^\x13\x14\x15]*\x14/u', '', $t);
		$t = preg_replace('/\x13[^\x13\x14\x15]*\x15/u', '', $t);
		$t = str_replace("\x15", '', $t);
		$t = str_replace("\x07\x07", "\n", $t); // dernière cellule + marque de fin de ligne de tableau
		$t = str_replace(array("\r", "\x0B", "\x0C", "\x07", "\x1E", "\x1F", "\xC2\xA0"), array("\n", "\n", "\n", ' | ', '-', '', ' '), $t);
		$t = preg_replace('/[\x00-\x08\x0E-\x1D]/u', '', $t);
		$t = preg_replace('/ \| \n/', "\n", $t);
		$t = preg_replace("/[ \t]+\n/", "\n", $t);
		$t = preg_replace("/\n{3,}/", "\n\n", $t);
		return trim($t);
	}

	/**
	 * Lecture de la structure OLE
	 *
	 * @return void
	 * @throws Exception
	 */
	private function readCfb()
	{
		$d = $this->data;
		$this->sectorSize = 1 << $this->u16($d, 0x1E);
		$this->miniSectorSize = 1 << $this->u16($d, 0x20);
		$numFat = $this->u32($d, 0x2C);
		$firstDir = $this->u32($d, 0x30);
		$this->miniCutoff = $this->u32($d, 0x38);
		$firstMiniFat = $this->u32($d, 0x3C);
		$firstDifat = $this->u32($d, 0x44);
		$numDifat = $this->u32($d, 0x48);

		// Secteurs de la FAT (DIFAT : 109 dans l'en-tête, puis chaîne DIFAT)
		$fatSectors = array();
		for ($i = 0; $i < 109 && count($fatSectors) < $numFat; $i++) {
			$fatSectors[] = $this->u32($d, 0x4C + $i * 4);
		}
		$sec = $firstDifat;
		$per = $this->sectorSize / 4 - 1;
		for ($k = 0; $k < $numDifat && $sec < 0xFFFFFFFA; $k++) {
			$s = $this->sector($sec);
			for ($i = 0; $i < $per && count($fatSectors) < $numFat; $i++) {
				$fatSectors[] = $this->u32($s, $i * 4);
			}
			$sec = $this->u32($s, $per * 4);
		}
		foreach ($fatSectors as $fs) {
			$this->fat = array_merge($this->fat, array_values(unpack('V*', $this->sector($fs))));
		}

		// Répertoire
		$dirData = $this->chain($firstDir);
		for ($off = 0; $off + 128 <= strlen($dirData); $off += 128) {
			$nameLen = $this->u16($dirData, $off + 64);
			$name = $nameLen > 2 ? mb_convert_encoding(substr($dirData, $off, $nameLen - 2), 'UTF-8', 'UTF-16LE') : '';
			$this->dir[] = array(
				'name' => $name,
				'type' => ord($dirData[$off + 66]),
				'start' => $this->u32($dirData, $off + 116),
				'size' => $this->u32($dirData, $off + 120),
			);
		}

		// Mini-FAT et mini-flux (porté par l'entrée racine)
		if ($firstMiniFat < 0xFFFFFFFA) {
			$this->miniFat = array_values(unpack('V*', $this->chain($firstMiniFat)));
		}
		if (!empty($this->dir[0]) && $this->dir[0]['start'] < 0xFFFFFFFA) {
			$this->miniStream = $this->chain($this->dir[0]['start']);
		}
	}

	/**
	 * @param string $name Nom du flux
	 * @return string|null
	 */
	private function stream($name)
	{
		foreach ($this->dir as $i => $e) {
			if ($i === 0 || $e['type'] !== 2 || $e['name'] !== $name) {
				continue;
			}
			if ($e['size'] < $this->miniCutoff) {
				$out = '';
				$sec = $e['start'];
				$guard = 0;
				while ($sec < 0xFFFFFFFA && isset($this->miniFat[$sec]) && $guard++ < 1000000) {
					$out .= substr($this->miniStream, $sec * $this->miniSectorSize, $this->miniSectorSize);
					$sec = $this->miniFat[$sec];
				}
				return substr($out, 0, $e['size']);
			}
			return substr($this->chain($e['start']), 0, $e['size']);
		}
		return null;
	}

	/**
	 * @param int $start Premier secteur
	 * @return string
	 */
	private function chain($start)
	{
		$out = '';
		$sec = $start;
		$guard = 0;
		while ($sec < 0xFFFFFFFA && $guard++ < 1000000) {
			$out .= $this->sector($sec);
			if (!isset($this->fat[$sec])) {
				break;
			}
			$sec = $this->fat[$sec];
		}
		return $out;
	}

	/**
	 * @param int $n Numéro de secteur
	 * @return string
	 */
	private function sector($n)
	{
		return (string) substr($this->data, ($n + 1) * $this->sectorSize, $this->sectorSize);
	}

	/**
	 * @param string $s   Données
	 * @param int    $off Position
	 * @return int
	 */
	private function u16($s, $off)
	{
		$v = @unpack('v', substr($s, $off, 2));
		return $v ? (int) $v[1] : 0;
	}

	/**
	 * @param string $s   Données
	 * @param int    $off Position
	 * @return int
	 */
	private function u32($s, $off)
	{
		$v = @unpack('V', substr($s, $off, 4));
		return $v ? (int) $v[1] : 0;
	}
}
