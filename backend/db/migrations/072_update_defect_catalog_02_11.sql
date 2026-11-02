-- POapp 02. 11. 2026 — update global seeded rows in defect_catalog for items 2, 14, 18, 19
-- matching 7_Ciselnik_nedostatkov_strojovo.md verbatim.

UPDATE defect_catalog
SET title = 'Požiarne dvere podložené zarážkou'
WHERE id = 2 AND account_id IS NULL;

UPDATE defect_catalog
SET title = 'Zatarasený prístup k uzáveru energie, plynu alebo vody'
WHERE id = 14 AND account_id IS NULL;

UPDATE defect_catalog
SET justification = 'Prestupy rozvodov a inštalácií cez požiarne deliace konštrukcie musia byť utesnené tak, aby zabránili rozšíreniu požiaru do iného požiarneho úseku. Utesnený prestup musí spĺňať požiadavku na požiarnu odolnosť konštrukcie, ktorou prestupuje, najviac však EI 90.'
WHERE id = 18 AND account_id IS NULL;

UPDATE defect_catalog
SET description = 'Tesnenie prestupu cez požiarne deliacu konštrukciu nebolo označené predpísaným štítkom.',
    justification = 'Tesnenie prestupu s plochou otvoru viac ako 0,04 m2 sa označuje štítkom na utesnenom prvku alebo v jeho tesnej blízkosti, aspoň na jednej strane konštrukcie, viditeľným, čitateľným, prístupným a ťažko odstrániteľným.',
    measure = 'Doplniť štítok s nápisom PRESTUP, symbolmi a hodnotou požiarnej odolnosti, názvom systému tesnenia, mesiacom a rokom zhotovenia a názvom a adresou zhotoviteľa.'
WHERE id = 19 AND account_id IS NULL;

