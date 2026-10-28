# ClaudeVerif 0.5.0

Connecteur MCP entre Claude (claude.ai) et Dolibarr v20, pour vérifier les commandes clients.
Commandes, PDF des commandes (AR), mails (IMAP) et pièces jointes (PDF texte ou scanné, .doc, .docx, images) en lecture seule. Inclut smalot/pdfparser (LGPL-3.0) dans lib/.

## Installation
1. Envoyer le dossier `claudeverif/` dans `htdocs/custom/` par FTP.
2. Accueil → Configuration → Modules → activer **ClaudeVerif**.
3. Créer un utilisateur Dolibarr dédié (ex. `claude`), sans droits d'admin, avec uniquement :
   Commandes clients → Lire ; Factures clients → Lire ; Expéditions → Lire ; Tiers → Lire ; Produits → Lire.
4. Configuration du module : choisir cet utilisateur, cocher la journalisation, enregistrer, puis **Générer le jeton**.

## Test (avant claude.ai)
```
curl -s -X POST "https://VOTRE-ERP/custom/claudeverif/mcp.php?key=JETON" -H "Content-Type: application/json" -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}"

curl -s -X POST "https://VOTRE-ERP/custom/claudeverif/mcp.php?key=JETON" -H "Content-Type: application/json" -d "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"get_commande\",\"arguments\":{\"ref\":\"CO2609-0142\"}}}"

curl -s -X POST "https://VOTRE-ERP/custom/claudeverif/mcp.php?key=JETON" -H "Content-Type: application/json" -d "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"get_pdf_commande\",\"arguments\":{\"ref\":\"2610_049\",\"mode\":\"texte\"}}}"
```
Réponses attendues : JSON. Si HTML ou 401 : voir Dépannage.

## Outils
- `search_commandes`, `get_commande` : recherche et détail des commandes clients.
- `get_pdf_commande` : relit le PDF de l'AR tel que généré par Dolibarr (`documents/commande/REF/REF.pdf`), avant envoi au client.
  Paramètres : `ref` ou `id`, `mode` (`auto` | `texte` | `images` | `ocr`, défaut `auto`), `pages` (ex. `"1-3"`).
  Renvoie le nom du fichier, sa date, un avertissement si la commande a été modifiée après la génération du PDF, le nombre de pages,
  le contenu (texte, images des pages par 5 au plus, ou OCR Mistral) et la liste des autres PDF du dossier (sans les lire).
  Ne génère jamais le PDF : s'il est absent, l'outil le signale.
  Le mode `images` rend les pages avec l'extension PHP Imagick (et Ghostscript) ; si elle n'est pas disponible sur l'hébergement, le texte du PDF est renvoyé avec une note.
- `search_mails`, `get_mail`, `get_piece_jointe` : boîte mail des commandes et pièces jointes.
- `search_factures`, `get_facture`, `controle_facture` : factures clients et contrôle commandé / livré / facturé.

## Ajout dans claude.ai
Paramètres → Connecteurs → Ajouter un connecteur personnalisé → nom `ClaudeVerif`, URL = celle affichée dans la configuration du module (sans OAuth).

## Dépannage
- 401 : jeton absent/incorrect dans l'URL.
- 500 « Utilisateur de service » : utilisateur non choisi ou désactivé.
- Réponse HTML : erreur PHP/Dolibarr → consulter dolibarr.log ou les logs OVH.
- « n'a pas le droit » : compléter les droits de l'utilisateur de service.

## Sécurité
- L'URL contient le jeton : ne pas la partager ; la régénérer en cas de doute.
- Aucune écriture dans Dolibarr à ce stade.
- Journal : `documents/claudeverif/mcp.log`.
