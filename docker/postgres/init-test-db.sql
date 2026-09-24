-- Runs once, on first initialisation of the data volume.
-- Tests run against their own database so a test run never touches dev data.
CREATE DATABASE invoice_test OWNER invoice;
