<?php declare(strict_types=1);

namespace Deployer;

require 'recipe/common.php';

// Project name
set( 'application', 'hollo.me' );
// Project repository
set( 'repository', 'git@github.com:hollodotme/hollo.me.git' );
// Shared files/dirs between deploys
set(
	'shared_files',
	[]
);
set(
	'shared_dirs',
	[]
);
// Writable dirs by web server
set( 'writable_dirs', [] );
set( 'allow_anonymous_stats', false );
// Hosts
host( 'hollo.me' )
	->user( 'root' )
	->multiplexing( true )
	->forwardAgent( false )
	->addSshOption( 'UserKnownHostsFile', '/dev/null' )
	->addSshOption( 'StrictHostKeyChecking', 'no' )
	->set( 'deploy_path', '/var/www/hollo.me' );
// Tasks
desc( 'Deploy your project' );
task(
	'deploy',
	[
		'deploy:info',
		'deploy:lock',
		'git:fetch-pull',
		'dc:pull',
		'dc:restart',
		'spg:generate',
		'deploy:unlock',
		'success',
	]
);

desc( 'GIT Pull' );
task(
	'git:fetch-pull',
	static function ()
	{
		run( 'cd {{deploy_path}} && git checkout -- public/* && git fetch --all && git checkout master && git pull' );
	}
);

desc('Docker compose pull');
task(
	'dc:pull',
	static function ()
	{
		run( 'cd {{deploy_path}} && docker-compose -f docker-compose.dist.yml pull' );
	}
);

desc('Docker compose restart');
task(
	'dc:restart',
	static function ()
	{
		run( 'cd {{deploy_path}} && docker-compose -f docker-compose.dist.yml up -d --force-recreate' );
	}
);

desc( 'Generate static pages' );
task(
	'spg:generate',
	static function ()
	{
		run( 'cd {{deploy_path}} && docker-compose -f docker-compose.dist.yml run --rm spg php vendor/bin/spg.phar check:links -g -b https://hollo.me' );
		run( 'cd {{deploy_path}} && docker-compose -f docker-compose.dist.yml run --rm spg php vendor/bin/spg.phar generate:sitemap -b https://hollo.me' );
		run( 'cd {{deploy_path}} && docker-compose -f docker-compose.dist.yml run --rm spg php vendor/bin/spg.phar generate:search-index -b https://hollo.me' );
	}
);

after( 'deploy:failed', 'deploy:unlock' );