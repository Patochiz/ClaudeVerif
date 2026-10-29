CREATE TABLE llx_societe (rowid INTEGER PRIMARY KEY, nom TEXT, fournisseur INT, entity INT DEFAULT 1);
CREATE TABLE llx_c_units (rowid INTEGER PRIMARY KEY, code TEXT, short_label TEXT);
CREATE TABLE llx_product (rowid INTEGER PRIMARY KEY, ref TEXT, label TEXT, fk_unit INT, cost_price REAL, pmp REAL DEFAULT 0, price REAL DEFAULT 0, fk_user_modif INT, entity INT DEFAULT 1);
CREATE TABLE llx_product_fournisseur_price (rowid INTEGER PRIMARY KEY, fk_product INT, fk_soc INT, ref_fourn TEXT, quantity REAL, unitprice REAL, remise_percent REAL DEFAULT 0, entity INT DEFAULT 1);
CREATE TABLE llx_commande_fournisseur (rowid INTEGER PRIMARY KEY, ref TEXT, ref_supplier TEXT, fk_soc INT, entity INT DEFAULT 1, fk_statut INT, billed INT DEFAULT 0, total_ht REAL, date_commande TEXT, date_livraison TEXT);
CREATE TABLE llx_commande_fournisseurdet (rowid INTEGER PRIMARY KEY, fk_commande INT, fk_product INT, product_type INT DEFAULT 0, label TEXT, description TEXT, ref TEXT, qty REAL, subprice REAL, remise_percent REAL DEFAULT 0, total_ht REAL, special_code INT DEFAULT 0, rang INT, fk_unit INT);
CREATE TABLE llx_element_element (rowid INTEGER PRIMARY KEY, fk_source INT, sourcetype TEXT, fk_target INT, targettype TEXT);
CREATE TABLE llx_receptiondet_batch (rowid INTEGER PRIMARY KEY, fk_element INT, fk_elementdet INT, qty REAL);

INSERT INTO llx_societe VALUES (10, 'METALSCREEN', 1, 1), (20, 'SAS Transports GELIN', 1, 1), (21, 'KUEHNE & NAGEL', 1, 1);
INSERT INTO llx_c_units VALUES (1, 'P', 'u'), (3, 'M2', 'm²');
INSERT INTO llx_product (rowid, ref, label, fk_unit, cost_price, pmp) VALUES
 (228, 'ADRIT_METALSCREEN', 'Adresse enlèvement Metalscreen', NULL, NULL, 0),
 (229, 'ADRIT_AUTRE', 'Adresse enlèvement autre', NULL, NULL, 0),
 (300, 'PrixTrps', 'Forfait transport', NULL, NULL, 0),
 (301, 'ADRLIV_BREST', 'Livraison Brest', NULL, NULL, 0),
 (501, 'OUESSANT_600_H00_T24_PRE_BLANC_NP', 'Ouessant 600', 3, 11.90, 11.75),
 (502, 'Boxes', 'Boxes', 1, 3.50, 3.50),
 (503, 'Horizon_D85a_PRE_RAL_9003_NP', 'Horizon D85a', 3, 13.20, 13.10),
 (504, 'Horizon_LO_80_PRE_RAL_9003_NP', 'Horizon LO 80', 3, 9.00, 8.80),
 (505, 'PRODUIT_TEST', 'Produit test', 3, 10.00, 10.00);
INSERT INTO llx_product_fournisseur_price (fk_product, fk_soc, ref_fourn, quantity, unitprice) VALUES (501, 10, 'OUE600', 1, 9.60), (501, 10, 'OUE600', 200, 9.40), (503, 10, 'D85A', 1, 11.00);

-- Commande de référence et son transport
INSERT INTO llx_commande_fournisseur VALUES (1029, 'PO2610-1029', 'LN 352', 10, 1, 3, 0, 3203.00, '2026-10-12', '2026-10-27');
INSERT INTO llx_commande_fournisseurdet (rowid, fk_commande, fk_product, product_type, label, description, qty, subprice, total_ht, rang, fk_unit) VALUES
 (11, 1029, 501, 0, NULL, 'Dalle Ouessant 600x600', 288, 9.40, 2707.20, 1, 3),
 (12, 1029, 502, 0, NULL, 'Boxes pour Ouessant', 32, 3.50, 112.00, 2, 1),
 (13, 1029, 503, 0, NULL, '', 18, 11.00, 198.00, 3, 3),
 (14, 1029, 504, 0, NULL, '', 6.60, 13.00, 85.80, 4, 3),
 (15, 1029, NULL, 0, NULL, 'Packing charges', 1, 100.00, 100.00, 5, NULL);
INSERT INTO llx_receptiondet_batch (fk_element, fk_elementdet, qty) VALUES (1029, 11, 144), (1029, 11, 144);
INSERT INTO llx_commande_fournisseur VALUES (1043, 'PO2610-1043', 'MS LN 352', 20, 1, 3, 0, 662.28, '2026-10-22', '2026-11-02');
INSERT INTO llx_commande_fournisseurdet (rowid, fk_commande, fk_product, product_type, label, description, qty, subprice, total_ht, rang) VALUES
 (21, 1043, 300, 1, NULL, 'Enlèvement sur le dépôt de notre fournisseur dès le 28/10/2026<br>A livrer sur notre dépôt le 02/11/2026', 1, 662.28, 662.28, 1),
 (22, 1043, 228, 0, NULL, 'METALSCREEN<br>Via Roma 12, 20090 Milano<br>4 palettes 120x80x110<br>Poids total : 1128 kg', 1, 0, 0, 2),
 (23, 1043, 301, 0, NULL, 'DIAMANT INDUSTRIE, Brest', 1, 0, 0, 3);
INSERT INTO llx_element_element (fk_source, sourcetype, fk_target, targettype) VALUES (1029, 'order_supplier', 1043, 'order_supplier');

-- Transport déjà lié à 3 commandes (départements)
INSERT INTO llx_commande_fournisseur VALUES
 (972, 'PO2608-0972', 'RESO 42', 10, 1, 5, 1, 1500, '2026-08-20', '2026-09-15'),
 (934, 'PO2606-0934', 'BIGMAT69 (MAINRO 69)', 10, 1, 5, 1, 900, '2026-08-25', '2026-09-18'),
 (970, 'PO2607-0970', 'SOPREMA 67 + CHANTIER 31', 10, 1, 5, 1, 700, '2026-08-28', '2026-09-20'),
 (981, 'PO2609-0981', 'MS (42+69+67+31)', 21, 1, 5, 1, 980, '2026-09-10', '2026-09-26');
INSERT INTO llx_commande_fournisseurdet (rowid, fk_commande, fk_product, product_type, description, qty, subprice, total_ht, rang, fk_unit) VALUES
 (31, 972, 501, 0, '', 100, 9.40, 940, 1, 3), (32, 934, 503, 0, '', 50, 11, 550, 1, 3), (33, 970, 504, 0, '', 30, 13, 390, 1, 3),
 (34, 981, 300, 1, 'Enlèvement sur le dépôt de notre fournisseur dès le 22/09/2026. A livrer sur notre dépôt le 26/09/2026', 1, 980, 980, 1, NULL),
 (35, 981, 228, 0, 'METALSCREEN 6 palettes 2 400 kg', 1, 0, 0, 2, NULL);
INSERT INTO llx_element_element (fk_source, sourcetype, fk_target, targettype) VALUES
 (981, 'order_supplier', 972, 'order_supplier'), (934, 'order_supplier', 981, 'order_supplier'), (981, 'order_supplier', 970, 'order_supplier');

-- Transport non lié : chantier, chantier avec numéro, stock
INSERT INTO llx_commande_fournisseur VALUES
 (1101, 'PO2610-1101', 'ARRIGHI2A', 10, 1, 3, 0, 500, '2026-10-01', '2026-10-20'),
 (1102, 'PO2610-1102', 'RIEMA 145', 10, 1, 3, 0, 600, '2026-10-02', '2026-10-22'),
 (1103, 'PO2610-1103', 'Stock DIAMANT', 10, 1, 3, 0, 800, '2026-10-03', '2026-10-21'),
 (1104, 'PO2604-1104', 'Stock DIAMANT', 10, 1, 5, 1, 800, '2026-04-03', '2026-04-21'),
 (1105, 'PO2610-1105', 'MAUNY 141', 10, 1, 3, 0, 300, '2026-10-05', '2026-10-21'),
 (1106, 'PO2610-1106', 'ARRIGHI2A annulée', 10, 1, 6, 0, 500, '2026-10-01', '2026-10-20'),
 (1110, 'PO2610-1110', 'MS (ARRIGHI2A + RIEMA145 + STOCK)', 20, 1, 3, 0, 750, '2026-10-15', '2026-10-28'),
 (1111, 'PO2610-1111', 'MS (14)', 20, 1, 3, 0, 300, '2026-10-15', '2026-10-28'),
 (822, 'PO2604-0822', 'MS (Stock)', 20, 1, 6, 0, 400, '2026-04-01', '2026-04-10');
INSERT INTO llx_commande_fournisseurdet (rowid, fk_commande, fk_product, product_type, description, qty, subprice, total_ht, rang, fk_unit) VALUES
 (41, 1110, 300, 1, 'Enlèvement sur le dépôt de notre fournisseur dès le 24/10/2026<br>A livrer sur notre dépôt le 28/10/2026', 1, 750, 750, 1, NULL),
 (42, 1110, 228, 0, 'METALSCREEN 3 palettes 900 kg', 1, 0, 0, 2, NULL),
 (43, 1111, 300, 1, 'Enlèvement sur le dépôt de notre fournisseur dès le 24/10/2026', 1, 300, 300, 1, NULL),
 (44, 1111, 228, 0, 'METALSCREEN', 1, 0, 0, 2, NULL),
 (45, 822, 228, 0, 'METALSCREEN', 1, 0, 0, 1, NULL),
 (46, 1101, 501, 0, '', 50, 9.4, 470, 1, 3),
 (47, 1103, 503, 0, '', 40, 10, 400, 1, 3);

-- Commande partageant un transport avec une autre (répartition)
INSERT INTO llx_commande_fournisseur VALUES
 (1201, 'PO2611-1201', 'A 11', 10, 1, 3, 0, 1000, '2026-11-01', '2026-11-10'),
 (1202, 'PO2611-1202', 'B 12', 10, 1, 3, 0, 3000, '2026-11-01', '2026-11-10'),
 (1210, 'PO2611-1210', 'MS (A11+B12)', 20, 1, 3, 1, 400, '2026-11-05', '2026-11-15');
INSERT INTO llx_commande_fournisseurdet (rowid, fk_commande, fk_product, product_type, description, qty, subprice, total_ht, rang, fk_unit) VALUES
 (51, 1201, 501, 0, '', 100, 10, 1000, 1, 3),
 (52, 1202, 503, 0, '', 300, 10, 3000, 1, 3),
 (53, 1210, 300, 1, 'Enlèvement ... dès le 12/11/2026', 1, 400, 400, 1, NULL),
 (54, 1210, 228, 0, 'METALSCREEN', 1, 0, 0, 2, NULL);
INSERT INTO llx_element_element (fk_source, sourcetype, fk_target, targettype) VALUES
 (1201, 'order_supplier', 1210, 'order_supplier'), (1210, 'order_supplier', 1202, 'order_supplier');
