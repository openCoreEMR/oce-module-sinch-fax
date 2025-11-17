-- This file is executed when the module is uninstalled via the OpenEMR interface

-- Drop tables in reverse dependency order
DROP TABLE IF EXISTS `oce_sinch_cover_pages`;
DROP TABLE IF EXISTS `oce_sinch_faxes`;
DROP TABLE IF EXISTS `oce_sinch_services`;
