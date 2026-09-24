# ClaudeVerif 0.4.0

Connecteur MCP entre Claude (claude.ai) et Dolibarr v20, pour vérifier les commandes clients.
Commandes, mails (IMAP) et pièces jointes (PDF texte ou scanné, .doc, .docx, images) en lecture seule. Inclut smalot/pdfparser (LGPL-3.0) dans lib/.

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
```
Réponses attendues : JSON. Si HTML ou 401 : voir Dépannage.

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
