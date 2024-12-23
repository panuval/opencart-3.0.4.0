<?php

/**
*  File for path replacements in xml paths. Examples:
*
*  $replaces[] = array('~^admin\b~', 'admin123'); // This replaces the admin folder name for use when admin folder's renamed
*  $replaces[] = array('~\btheme/default\b~', 'theme/my-theme-name'); // Theme name replace to apply mods to your theme
*
*  Place your replaces between the START and END lines below
**/


// VQMODDED START REPLACES //
if (defined('DIR_CATALOG')) { $replaces[] = array('~^admin\b~', basename(DIR_APPLICATION)); }

// END REPLACES //