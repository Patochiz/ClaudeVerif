# ClaudeVerif 0.6.0

Connecteur MCP entre Claude (claude.ai) et Dolibarr v20, pour vérifier les commandes clients et fournisseurs.
Commandes clients, PDF des commandes (AR), commandes fournisseurs et transports, mails (IMAP) et pièces jointes (PDF texte ou scanné, .doc, .docx, images) en lecture.
Deux écritures limitées, désactivées par défaut : liaison transport → commandes fournisseurs, et prix de revient des produits. Inclut smalot/pdfparser (LGPL-3.0) dans lib/.

## Installation
1. Envoyer le dossier `claudeverif/` dans `htdocs/custom/` par FTP.
2. Accueil → Configuration → Modules → activer **ClaudeVerif**.
3. Créer un utilisateur Dolibarr dédié (ex. `claude`), sans droits d'admin, avec uniquement :
   Commandes clients → Lire ; Factures clients → Lire ; Expéditions → Lire ; Tiers → Lire ; Produits → Lire ;
   Commandes fournisseurs → Lire. Droits d'écriture : voir « Commandes fournisseurs et transports ».
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
- `get_pdf_commande` : relit le PDF de l'AR tel que généré par Dolibarr, avant envoi au client.
  Fichiers lus : `documents/commande/REF/REF*.pdf` (ex. `2610_047_AR.pdf`, `2610_047-FP.pdf`). Par défaut l'AR (`REF_AR.pdf`, sinon `REF.pdf`, sinon le plus récent) ;
  un autre PDF de la commande se lit avec le paramètre `fichier`.
  Paramètres : `ref` ou `id`, `fichier` (optionnel), `mode` (`auto` | `texte` | `images` | `ocr`, défaut `auto`), `pages` (ex. `"1-3"`).
  Renvoie le nom du fichier, sa date, un avertissement si la commande a été modifiée après la génération du PDF, le nombre de pages,
  le contenu (texte, images des pages par 5 au plus, ou OCR Mistral) et la liste des autres PDF de la commande (sans les lire).
  Ne génère jamais le PDF : s'il est absent, l'outil le signale.
  Le mode `images` rend les pages avec l'extension PHP Imagick (et Ghostscript) ; si elle n'est pas disponible sur l'hébergement, le texte du PDF est renvoyé avec une note.
- `search_mails`, `get_mail`, `get_piece_jointe` : boîte mail des commandes et pièces jointes.
- `search_factures`, `get_facture`, `controle_facture` : factures clients et contrôle commandé / livré / facturé.
- `get_stock` : stock physique, reste à livrer, reste à recevoir et stock virtuel.
- Commandes fournisseurs et transports : voir la section suivante.

## Commandes fournisseurs et transports

### Outils
Lecture :
- `search_commandes_fournisseur` : critères `fournisseur`, `ref`, `ref_fournisseur`, `date_from`, `date_to`, `statut` (liste), `contient_produit` (ex. `ADRIT_METALSCREEN`), `limit`.
  Renvoie aussi le nombre de commandes fournisseurs liées.
- `get_commande_fournisseur` : en-tête, contacts, lignes (avec quantité déjà réceptionnée) et commandes fournisseurs liées.
  Pour un transport (ligne `ADRIT_…`) : fournisseur d'enlèvement, date d'enlèvement et de livraison (texte de la ligne `PrixTrps`), colisage et poids (texte de la ligne `ADRIT_…`).
- `rapprocher_transports` : pour un fournisseur (ex. `Metalscreen`), transports `ADRIT_<FOURNISSEUR>` non annulés et commandes candidates,
  avec score, motif et classement sûr / probable / non résolu. Jetons tirés de la réf. fournisseur du transport (`MS (ARRIGHI2A + RIEMA145 + STOCK)`) :
  chantier, département (2 chiffres ; nombre de 3 chiffres qui commence par le département = correspondance faible) ou Stock.
  Fenêtre de dates : commande datée avant l'enlèvement, livraison prévue entre −30 et +15 jours de l'enlèvement (`jours_avant`, `jours_apres`).
  Les transports déjà liés ne sont affichés qu'avec `inclure_lies = true` (avec la concordance entre liens existants et proposition). N'écrit rien.
- `calculer_prix_rendu` : prix rendu Brest par produit (voir ci-dessous), comparé au prix de revient, au PMP et au prix fournisseur actuels. N'écrit rien.

Écriture (outils absents de la liste tant que leur option est décochée) :
- `lier_commandes_fournisseur` : `transport` + `commandes`. Vérifie que le transport a une ligne `ADRIT_…`, que les commandes existent et ne sont pas le transport.
  Crée le lien Dolibarr (`add_object_linked('order_supplier', id)`), visible des deux côtés ; un lien existant (dans un sens ou dans l'autre) est signalé « déjà lié ».
  Ne supprime jamais de lien.
- `maj_prix_revient` : `produits` [{`produit`, `cost_price`}], `commande_source`, `forcer`. Met à jour uniquement `llx_product.cost_price`,
  par `setValueFrom()` (un seul champ, sans trigger) : `Product::update()` réécrit toute la fiche produit et peut modifier la référence.
  PMP, prix fournisseurs et prix de vente ne sont jamais modifiés. Prix ≤ 0 refusé ; écart de plus de 30 % avec le prix actuel refusé sans `forcer`.

Claude présente toujours ses propositions (tableau des liaisons, tableau ancien → nouveau prix de revient) et attend la validation explicite avant un outil d'écriture.
Chaque écriture est tracée dans le journal Dolibarr (`dol_syslog`, niveau INFO) : utilisateur, transport et commande, ou produit, ancien et nouveau prix, commande source.

### Configuration (page du module)
| Constante | Libellé | Défaut |
|---|---|---|
| `CLAUDEVERIF_ALLOW_LINK` | Autoriser Claude à lier des commandes fournisseurs entre elles | 0 |
| `CLAUDEVERIF_ALLOW_COSTPRICE` | Autoriser Claude à mettre à jour le prix de revient des produits | 0 |
| `CLAUDEVERIF_TRANSPORT_PICKUP_PREFIX` | Préfixe des produits « adresse d'enlèvement » | `ADRIT_` |
| `CLAUDEVERIF_TRANSPORT_PRODUCT` | Réf. du produit forfait transport | `PrixTrps` |

### Droits de l'utilisateur de service
- Lecture : Commandes fournisseurs → Lire ; Produits → Lire.
- `lier_commandes_fournisseur` : en plus, Commandes fournisseurs → Créer/modifier.
- `maj_prix_revient` : en plus, Produits → Créer/modifier.

### Prix rendu Brest : règles
- Produits principaux : lignes produit de l'unité majoritaire en montant HT (m² chez Metalscreen).
- Frais spécifiques : ligne produit d'une autre unité placée juste après un produit principal (ex. `Boxes` après OUESSANT), ou désignée par `affectations`
  ([{`ligne_frais`, `ligne_produit`}], id de ligne de `get_commande_fournisseur`) ; ajoutés à ce seul produit.
- Frais communs : lignes libres et services (`Packing charges`…), commandes de transport liées, `frais_supplementaires` ; répartis au prorata de la quantité
  (défaut) ou de la valeur (`cle_repartition = valeur`) des produits principaux. Un transport lié à plusieurs commandes est réparti entre elles avec la même clé.
- Prix rendu = (total HT de la ligne + frais spécifiques + part des frais communs) / quantité ; calcul en `price2num(…, 'MU')`, affichage à 2 décimales.
- Alertes : transport non facturé ou à 0 €, aucun transport lié, élément cité dans la réf. du transport sans commande liée, unités hétérogènes, ligne non classée.

Exemple PO2610-1029 (Metalscreen, transport lié PO2610-1043 à 662,28 € HT) :

| Ligne | Qté | P.U. HT | Classement |
|---|---|---|---|
| OUESSANT_600_H00_T24_PRE_BLANC_NP | 288 m² | 9,40 | produit principal |
| Boxes | 32 u | 3,50 | frais spécifique d'OUESSANT : 112 / 288 = 0,3889 €/m² |
| Horizon_D85a_PRE_RAL_9003_NP | 18 m² | 11,00 | produit principal |
| Horizon_LO_80_PRE_RAL_9003_NP | 6,60 m² | 13,00 | produit principal |
| Packing charges | 1 | 100,00 | frais commun |

Frais communs : 100 + 662,28 = 762,28 € sur 312,60 m², soit 2,4385 €/m².
Prix rendu : OUESSANT **12,23** ; Horizon_D85a **13,44** ; Horizon_LO_80 **15,44**.

Le futur trigger diamantutils (prix de revient mis à jour depuis le prix de réception) n'entre pas en conflit avec `maj_prix_revient` :
si le prix rendu est saisi à la réception, les deux écrivent la même valeur.

## Ajout dans claude.ai
Paramètres → Connecteurs → Ajouter un connecteur personnalisé → nom `ClaudeVerif`, URL = celle affichée dans la configuration du module (sans OAuth).

## Dépannage
- 401 : jeton absent/incorrect dans l'URL.
- 500 « Utilisateur de service » : utilisateur non choisi ou désactivé.
- Réponse HTML : erreur PHP/Dolibarr → consulter dolibarr.log ou les logs OVH.
- « n'a pas le droit » : compléter les droits de l'utilisateur de service.

## Sécurité
- L'URL contient le jeton : ne pas la partager ; la régénérer en cas de doute.
- Écritures possibles uniquement si les options sont cochées : liens entre commandes fournisseurs (jamais de suppression) et prix de revient des produits.
- Journal : `documents/claudeverif/mcp.log`.
