<?php

/**
 * TreeMDown demo page
 *
 * @author h.woltersdorf
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use hollodotme\TreeMDown\TreeMDown;

// Create instance
$tmd = new TreeMDown( __DIR__ . '/../../vendor/hollodotme/treemdown/doc/TreeMDown' );
$tmd->setDefaultFile( '01-What-Is-TreeMDown.md' );
$tmd->hideEmptyFolders();
$tmd->enablePrettyNames();
$tmd->hideFilenameSuffix();
$tmd->enableGithubRibbon();
$tmd->display();
