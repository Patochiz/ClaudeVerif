<?php
// Environnement de test hors Dolibarr : base SQLite + fonctions et classes Dolibarr simulées
define('DOL_DOCUMENT_ROOT', sys_get_temp_dir().'/claudeverif-test-root');
define('MAIN_DB_PREFIX', 'llx_');
foreach (array('commande/class/commande.class.php', 'contact/class/contact.class.php', 'core/class/extrafields.class.php',
	'core/lib/files.lib.php', 'compta/facture/class/facture.class.php', 'fourn/class/fournisseur.commande.class.php', 'product/class/product.class.php') as $f) {
	@mkdir(dirname(DOL_DOCUMENT_ROOT.'/'.$f), 0777, true);
	file_put_contents(DOL_DOCUMENT_ROOT.'/'.$f, '<?php');
}

$GLOBALS['CONST'] = array();
$GLOBALS['SYSLOG'] = array();
function getDolGlobalInt($k, $d = 0) { return (int) ($GLOBALS['CONST'][$k] ?? $d); }
function getDolGlobalString($k, $d = '') { return (string) ($GLOBALS['CONST'][$k] ?? $d); }
function isModEnabled($m) { return true; }
function getEntity($e) { return '1'; }
function dol_syslog($m, $l = 0) { $GLOBALS['SYSLOG'][] = $m; }
function dol_include_once($p) { require_once dirname(__DIR__).$p; }
function dol_buildpath($p, $t = 0) { return 'https://erp'.$p; }
function dol_string_nohtmltag($s, $x = 0) { return strip_tags(preg_replace('/<br\s*\/?>/i', "\n", (string) $s)); }
function dol_print_date($t, $f = '') { return $t ? date('Y-m-d', $t) : ''; }
function price2num($amount, $rounding = '', $option = 0)
{
	$amount = (float) str_replace(',', '.', (string) $amount);
	if ($rounding === 'MU') return (string) round($amount, 5);
	if ($rounding === 'MT') return (string) round($amount, 2);
	return (string) $amount;
}
class Translate { public function transnoentitiesnoconv($k) { return $k === 'unitM2Short' ? 'm²' : $k; } public function load($x) {} }
$GLOBALS['langs'] = new Translate();

class TestDB
{
	public $pdo;
	public function __construct() { $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT); }
	public function query($sql) { $r = $this->pdo->query($sql); if (!$r) { $this->err = implode(' ', $this->pdo->errorInfo()).' — '.$sql; } return $r; }
	public function fetch_object($r) { return $r->fetch(PDO::FETCH_OBJ) ?: null; }
	public function free($r) {}
	public function escape($s) { return str_replace("'", "''", (string) $s); }
	public function escapeforlike($s) { return (string) $s; } // SQLite : pas d'échappement par antislash (MySQL : \_ = _)
	public function plimit($n) { return ' LIMIT '.((int) $n); }
	public function jdate($s) { return $s ? strtotime($s) : ''; }
	public function lasterror() { return $this->err ?? ''; }
	public $err;
}

class CommonStub
{
	public $db; public $id; public $error = '';
	public function __construct($db) { $this->db = $db; }
}
class CommandeFournisseur extends CommonStub
{
	public $element = 'order_supplier';
	public $ref; public $ref_supplier; public $socid; public $entity; public $status; public $statut; public $billed;
	public $total_ht; public $total_tva; public $total_ttc; public $note_public; public $note_private; public $date_commande; public $delivery_date;
	public $thirdparty; public $array_options = array(); public $linkedObjects = array();
	public function fetch($id, $ref = '')
	{
		$w = $id ? 'rowid = '.((int) $id) : "ref = '".$this->db->escape($ref)."'";
		$o = $this->db->fetch_object($this->db->query('SELECT * FROM llx_commande_fournisseur WHERE '.$w));
		if (!$o) return 0;
		$this->id = (int) $o->rowid; $this->ref = $o->ref; $this->ref_supplier = $o->ref_supplier; $this->socid = (int) $o->fk_soc;
		$this->entity = (int) $o->entity; $this->status = $this->statut = (int) $o->fk_statut; $this->billed = (int) $o->billed;
		$this->total_ht = (float) $o->total_ht; $this->total_tva = 0; $this->total_ttc = (float) $o->total_ht * 1.2;
		$this->date_commande = $this->db->jdate($o->date_commande); $this->delivery_date = $this->db->jdate($o->date_livraison);
		return 1;
	}
	public function fetch_thirdparty()
	{
		$o = $this->db->fetch_object($this->db->query('SELECT * FROM llx_societe WHERE rowid = '.$this->socid));
		$this->thirdparty = (object) array('id' => (int) $o->rowid, 'name' => $o->nom);
	}
	public function liste_contact($a = -1, $b = 'external') { return array(); }
	public function fetchObjectLinked() { $this->linkedObjects = array(); return 1; }
	public function LibStatut($s, $mode = 0, $billed = 0)
	{
		$l = array(0 => 'Brouillon', 1 => 'Validée', 2 => 'Approuvée', 3 => 'Commandée', 4 => 'Reçue partiellement', 5 => 'Reçue', 6 => 'Annulée', 7 => 'Annulée', 9 => 'Refusée');
		return $l[$s] ?? (string) $s;
	}
	public function add_object_linked($origin, $origin_id, $user = null, $notrigger = 0)
	{
		$ok = $this->db->query("INSERT INTO llx_element_element (fk_source, sourcetype, fk_target, targettype) VALUES (".((int) $origin_id).", '".$origin."', ".((int) $this->id).", 'order_supplier')");
		return $ok ? 1 : 0;
	}
}
class Product extends CommonStub
{
	public $ref; public $cost_price; public $table_element = 'product';
	public function fetch($id = 0, $ref = '')
	{
		$w = $id ? 'rowid = '.((int) $id) : "ref = '".$this->db->escape($ref)."'";
		$o = $this->db->fetch_object($this->db->query('SELECT * FROM llx_product WHERE '.$w));
		if (!$o) return 0;
		$this->id = (int) $o->rowid; $this->ref = $o->ref; $this->cost_price = $o->cost_price;
		return 1;
	}
	public function setValueFrom($field, $value, $table = '', $id = null, $format = '', $id_field = '', $fuser = null, $trigkey = '')
	{
		// Même requête que CommonObject::setValueFrom() (format text)
		$sql = "UPDATE llx_".$this->table_element." SET ".$field." = '".$this->db->escape($value)."', fk_user_modif = ".((int) $fuser->id)." WHERE rowid = ".((int) $this->id);
		return $this->db->query($sql) ? 1 : -1;
	}
}
class TestUser
{
	public $id = 99; public $login = 'claude'; public $rights = array();
	public function hasRight($module, $perm, $sub = '') { return in_array($module.'/'.$perm.($sub ? '/'.$sub : ''), $this->rights, true); }
}

require dirname(__DIR__).'/claudeverif/class/claudeveriftools.class.php';

/** Base de test */
function testdb()
{
	$db = new TestDB();
	$db->pdo->exec(file_get_contents(__DIR__.'/fixtures.sql'));
	return $db;
}
