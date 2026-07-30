-- Schvaľujúca osoba on the client company (change request 2.3 / 2.1).
--
-- Both new document types print an approver next to the technician's
-- signature: "Schválil" on the Pokyn — žatevné práce, and "Za spoločnosť
-- prevzal na vedomie" on the Vyraďovací protokol. The spec asks for one field
-- carrying name *and* role ("meno a funkcia, napr. konateľ"), reused across
-- documents — so it lives on the company, not on either document.
--
-- Nullable: existing companies have no approver recorded, and the document
-- forms let the technician type one in for that document alone.

ALTER TABLE companies
    ADD COLUMN approver VARCHAR(191) NULL DEFAULT NULL AFTER contact;
