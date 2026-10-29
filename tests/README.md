# Tests hors Dolibarr

Ne pas déposer ce dossier sur le serveur : il sert seulement à vérifier le code avant envoi par FTP.
PHP 8.1+ en ligne de commande, avec l'extension pdo_sqlite pour `test_tools.php`.

```
php tests/test_prix_rendu_po2610_1029.php   # calcul du prix rendu : 12,23 / 13,44 / 15,44
php tests/test_logic.php                    # jetons, dates, poids, classement des lignes
php tests/test_tools.php                    # outils complets sur une base SQLite (fixtures.sql) avec Dolibarr simulé
```

`env.php` simule les fonctions et classes Dolibarr utilisées. `fixtures.sql` reprend des cas inspirés des données réelles
(PO2610-1029 et son transport PO2610-1043, transport à plusieurs départements, transport annulé, Stock hors période…).
Ces tests ne remplacent pas les essais sur doli2 : les textes réels des lignes de transport et des réf. fournisseurs peuvent différer.
