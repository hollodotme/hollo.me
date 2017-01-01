<?php
/**
 * TreeMDown-Multi demo page
 * @author hwoltersdorf
 */

// include composer autoloading
require_once __DIR__ . '/../../vendor/autoload.php';

use hollodotme\TreeMDown\Misc\Opt;
use hollodotme\TreeMDownMulti\TreeMDown;
use hollodotme\TreeMDownMulti\TreeMDownMulti;

// Create instance
$multi_view = new TreeMDownMulti();

$tree1 = new TreeMDown( __DIR__ . '/../../vendor/hollodotme/treemdown-multi/doc/TreeMDown-Multi' );
$tree1->setDefaultFile( '01-What-is-TreeMDown-Multi.md' );
$tree1->setProjectName( 'TreeMDown-Multi' );
$tree1->enablePrettyNames();
$tree1->hideFilenameSuffix();
$tree1->enableGithubRibbon();
$tree1->getOptions()->set( Opt::GITHUB_RIBBON_URL, 'https://github.com/hollodotme/TreeMDown-Multi' );

// Configure your markdown primary dir
$tree2 = new TreeMDown( __DIR__ . '/../../vendor/hollodotme/treemdown/doc/TreeMDown' );
$tree2->setDefaultFile( '01-What-Is-TreeMDown.md' );
$tree2->hideEmptyFolders();
$tree2->enablePrettyNames();
$tree2->hideFilenameSuffix();
$tree2->enableGithubRibbon();
$tree2->getOptions()->set( Opt::GITHUB_RIBBON_URL, 'https://github.com/hollodotme/TreeMDown' );

// Configure other dir
$tree3 = new TreeMDown( __DIR__ . '/../../vendor/hollodotme/treemdown/doc/TreeMDown' );
$tree3->setDefaultFile( '01-What-Is-TreeMDown.md' );
$tree3->enableGithubRibbon();
$tree3->getOptions()->set( Opt::GITHUB_RIBBON_URL, 'https://github.com/hollodotme/TreeMDown' );

// Make "TreeMDown-Multi documentation" default (3rd parameter)
$multi_view->addTreeMDown( $tree1, 'TreeMDown-Multi documentation (default)', true );
$multi_view->addTreeMDown( $tree2, 'TreeMDown documentation (prettyfied)' );
$multi_view->addTreeMDown( $tree3, 'TreeMDown documentation (no options)' );

// Display
$multi_view->display();
