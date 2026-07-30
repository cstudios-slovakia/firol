-- Contact e-mail on the client company (change request 2.5.4).
--
-- The calendar's "Oznámiť klientovi e-mailom" button opens the technician's
-- own mail client with a pre-filled message addressed to the client. That
-- needs one machine-readable address — the existing free-text `contact` field
-- mixes a name, a phone number and sometimes an e-mail, so it cannot be used
-- as a mailto: recipient.
--
-- Nullable: the spec makes the field optional, and with no address recorded
-- the button still opens the message with an empty recipient.

ALTER TABLE companies
    ADD COLUMN contact_email VARCHAR(191) NULL DEFAULT NULL AFTER contact;
