<?php
/**
 * Create an environment
 *
 * @package wpassure
 */

namespace WPAssure;

use Docker\API\Model\NetworkCreate;
use Docker\API\Model\NetworksCreatePostBody;
use Docker\API\Model\ContainersCreatePostBody;
use Docker\API\Model\ContainersCreatePostBodyNetworkingConfig;
use Docker\API\Model\ContainersIdExecPostBody;
use Docker\API\Model\ExecIdStartPostBody;
use Docker\API\Model\HostConfig;
use Docker\API\Model\PortBinding;
use Docker\API\Model\BuildInfo;
use Docker\API\Endpoint\ContainerCreate;
use Docker\Context\Context;
use Docker\Docker;
use GrantLucas\PortFinder;
use WPAssure\Utils as Utils;
use WPSnapshots\Snapshot;

/**
 * Create and manage the docker environment
 */
class Environment {

	/**
	 * Array of Docker containers
	 *
	 * @var array
	 */
	protected $containers = [];

	/**
	 * Environment ID
	 *
	 * @var string
	 */
	protected $environment_id;

	/**
	 * Docker instance
	 *
	 * @var Docker\Docker
	 */
	protected $docker;

	/**
	 * WordPress port
	 *
	 * @var int
	 */
	protected $wordpress_port;

	/**
	 * Gateway IP
	 *
	 * @var string
	 */
	protected $gateway_ip;

	/**
	 * Selenium port
	 *
	 * @var int
	 */
	protected $selenium_port;

	/**
	 * MySQL port
	 *
	 * @var int
	 */
	protected $mysql_port;

	/**
	 * Suite config
	 *
	 * @var Config
	 */
	protected $suite_config;

	/**
	 * Preserve containers or not
	 *
	 * @var boolean
	 */
	protected $cache_environment = false;

	/**
	 * Snapshot instance
	 *
	 * @var \WPSnapshots\Snapshot
	 */
	protected $snapshot;

	/**
	 * MySQL client instance
	 *
	 * @var MySQL
	 */
	protected $mysql_client;

	/**
	 * Current MySQL db to use
	 *
	 * @var string
	 */
	protected $current_mysql_db = 'wordpress_clean';

	/**
	 * Path to wpassure.json in snapshot
	 *
	 * @var string
	 */
	protected $snapshot_wpassure_path;

	/**
	 * Path to repo in snapshot
	 *
	 * @var string
	 */
	protected $snapshot_repo_path;

	/**
	 * Whether we are in gitlab or not
	 *
	 * @var bool
	 */
	public $gitab = false;

	/**
	 * Environment constructor
	 *
	 * @param  Config  $suite_config Config array
	 * @param  boolean $cache_environment Cache environment for later or not
	 */
	public function __construct( $suite_config = null, $cache_environment = false ) {
		$this->docker            = Docker::create();
		$this->suite_config      = $suite_config;
		$this->cache_environment = $cache_environment;
		$this->environment_id    = 'wpa-' . self::generateEnvironmentId( $suite_config );
	}

	/**
	 * Run shell command to get volume name
	 *
	 * @return bool|string
	 */
	private function getGitLabVolumeName() {
		static $volume_name;

		if ( ! isset( $volume_name ) ) {
			$volume_name = exec( 'CURRENT_CONTAINER_ID=$(docker ps -q -f "label=com.gitlab.gitlab-runner.job.id=$CI_JOB_ID" -f "label=com.gitlab.gitlab-runner.type=build") /usr/bin/docker inspect --format "{{ range .Mounts }}{{ if eq .Destination \"/builds/$CI_PROJECT_NAMESPACE\"}}{{ .Name }}{{ end }}{{ end }}" $CURRENT_CONTAINER_ID' );

			if ( empty( $volume_name ) ) {
				$volume_name = false;
			}
		}

		return $volume_name;
	}

	/**
	 * Get local IP. Different for gitlab
	 *
	 * @return string
	 */
	public function getLocalIP() {
		return ( GitLab::get()->isGitLab() ) ? $this->getGatewayIP() : '127.0.0.1';
	}

	/**
	 * Get repo root in WP container. Different for gitlab
	 *
	 * @return string
	 */
	public function getWPContainerRepoRoot() {
		return ( GitLab::get()->isGitLab() ) ? '/gitlab/' . GitLab::get()->getProjectDirectory() : '/root/repo';
	}

	/**
	 * Attempt to populate environment from cache
	 *
	 * @return bool
	 */
	public function populateEnvironmentFromCache() {
		Log::instance()->write( 'Getting environment meta...', 1 );

		$command = 'cat /root/environment_meta.json';

		$exec_config = new ContainersIdExecPostBody();
		$exec_config->setTty( true );
		$exec_config->setAttachStdout( true );
		$exec_config->setAttachStderr( true );
		$exec_config->setCmd( [ '/bin/sh', '-c', $command ] );

		try {
			$exec_command      = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config );
			$exec_id           = $exec_command->getId();
			$exec_start_config = new ExecIdStartPostBody();
			$exec_start_config->setDetach( false );

			$stream = $this->docker->execStart( $exec_id, $exec_start_config );
		} catch ( \Exception $e ) {
			Log::instance()->write( 'Environment NOT found in cache.', 1 );
			return false;
		}

		Log::instance()->write( 'Environment found in cache.' );

		$environment_meta = '';

		$stream->onStdout(
			function( $stdout ) use ( &$environment_meta ) {
				$environment_meta = $stdout;
			}
		);

		$stream->wait();

		$environment_meta = json_decode( $environment_meta, true );

		$this->suite_config           = $environment_meta['suite_config'];
		$this->wordpress_port         = $environment_meta['wordpress_port'];
		$this->selenium_port          = $environment_meta['selenium_port'];
		$this->mysql_port             = $environment_meta['mysql_port'];
		$this->gateway_ip             = $environment_meta['gateway_ip'];
		$this->snapshot_wpassure_path = $environment_meta['snapshot_wpassure_path'];
		$this->snapshot_repo_path     = $environment_meta['snapshot_repo_path'];
		$this->snapshot               = Snapshot::get( $this->suite_config['snapshot_id'] );

		return true;
	}

	/**
	 * Clone the clean WordPress DB into another DB. Set the new db name as the current DB
	 *
	 * @return string
	 */
	public function makeCleanDB() {
		static $db_number = 0;

		$db_name = 'wordpress';

		if ( 0 < $db_number ) {
			$db_name .= $db_number;
		}

		Log::instance()->write( 'Setting up clean MySQL database: ' . $db_name, 1 );

		$this->duplicateDB( 'wordpress_clean', $db_name );

		$this->current_mysql_db = $db_name;

		$db_number++;

		/**
		 * Insert new db name into wp-config.php
		 */
		$command = 'sed -i -e "s/[\'\"]DB_NAME[\'\"],[ \t]*[\'\"].*[\'\"]/\'DB_NAME\', \'' . $db_name . '\'/g" /var/www/html/wp-config.php';

		$exec_config = new ContainersIdExecPostBody();
		$exec_config->setTty( true );
		$exec_config->setAttachStdout( true );
		$exec_config->setAttachStderr( true );
		$exec_config->setCmd( [ '/bin/sh', '-c', $command ] );

		$exec_command      = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config );
		$exec_id           = $exec_command->getId();
		$exec_start_config = new ExecIdStartPostBody();
		$exec_start_config->setDetach( false );

		$stream = $this->docker->execStart( $exec_id, $exec_start_config );

		$stream->onStdout(
			function( $stdout ) {
				Log::instance()->write( $stdout, 2 );
			}
		);

		$stream->onStderr(
			function( $stderr ) {
				Log::instance()->write( $stderr, 2 );
			}
		);

		$stream->wait();

		$exit_code = $this->docker->execInspect( $exec_id )->getExitCode();

		if ( 0 !== $exit_code ) {
			Log::instance()->write( 'Could not modify database in wp-config.php.', 0, 'error' );
			return false;
		}

		$this->mysql_client = new MySQL( $this->getMySQLCredentials(), $this->getLocalIP(), $this->mysql_port, $this->snapshot->meta['table_prefix'] );

		return $this->current_mysql_db;
	}

	/**
	 * Duplicate a MySQL DB
	 *
	 * @param  string $original Original db name
	 * @param  string $copy     New DB name
	 */
	protected function duplicateDB( $original, $copy ) {
		Log::instance()->write( 'Duplicating MySQL database...', 1 );

		$command = 'echo "create database if not exists ' . $copy . '" | mysql --password=password && mysqldump ' . $original . ' --password=password | mysql ' . $copy . ' --password=password';

		$exec_config = new ContainersIdExecPostBody();
		$exec_config->setTty( true );
		$exec_config->setAttachStdout( true );
		$exec_config->setAttachStderr( true );
		$exec_config->setCmd( [ '/bin/sh', '-c', $command ] );

		$exec_command      = $this->docker->containerExec( 'mysql-' . $this->environment_id, $exec_config );
		$exec_id           = $exec_command->getId();
		$exec_start_config = new ExecIdStartPostBody();
		$exec_start_config->setDetach( false );

		$stream = $this->docker->execStart( $exec_id, $exec_start_config );

		$stream->onStdout(
			function( $stdout ) {
				Log::instance()->write( $stdout, 2 );
			}
		);

		$stream->onStderr(
			function( $stderr ) {
				Log::instance()->write( $stderr, 2 );
			}
		);

		$stream->wait();

		$exit_code = $this->docker->execInspect( $exec_id )->getExitCode();

		if ( 0 !== $exit_code ) {
			Log::instance()->write( 'Could not duplicate MySQL database.', 0, 'error' );
			return false;
		}
	}

	/**
	 * Run before scripts
	 *
	 * @return bool
	 */
	public function runBeforeScripts() {
		if ( empty( $this->suite_config['before_scripts'] ) ) {
			return true;
		}

		if ( ! empty( $this->suite_config['skip_before_scripts'] ) ) {
			return true;
		}

		foreach ( $this->suite_config['before_scripts'] as $script ) {
			Log::instance()->write( 'Running script: ' . $script, 1 );

			$command = 'cd ' . $this->snapshot_wpassure_path . ' && ' . $script;

			$exec_config = new ContainersIdExecPostBody();
			$exec_config->setTty( true );
			$exec_config->setAttachStdout( true );
			$exec_config->setAttachStderr( true );
			$exec_config->setCmd( [ '/bin/bash', '-c', $command ] );

			$exec_command      = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config );
			$exec_id           = $exec_command->getId();
			$exec_start_config = new ExecIdStartPostBody();
			$exec_start_config->setDetach( false );

			$stream = $this->docker->execStart( $exec_id, $exec_start_config );

			$stream->onStdout(
				function( $stdout ) {
					Log::instance()->write( $stdout, 2 );
				}
			);

			$stream->onStderr(
				function( $stderr ) {
					Log::instance()->write( $stderr, 2 );
				}
			);

			$stream->wait();

			$exit_code = $this->docker->execInspect( $exec_id )->getExitCode();

			if ( 0 !== $exit_code ) {
				Log::instance()->write( 'Before script returned a non-zero exit code: ' . $script, 0, 'warning' );
			}
		}

		return true;
	}

	/**
	 * Pull WP Snapshot into container
	 *
	 * @return  bool
	 */
	public function pullSnapshot() {
		/**
		 * Pulling snapshot
		 */

		Log::instance()->write( 'Pulling snapshot...', 1 );

		$this->snapshot = Snapshot::get( $this->suite_config['snapshot_id'] );

		$site_mapping = [];

		Log::instance()->write( 'Snapshot site mapping:', 1 );

		foreach ( $this->snapshot->meta['sites'] as $site ) {
			$home_host = parse_url( $site['home_url'], PHP_URL_HOST );
			$site_host = parse_url( $site['site_url'], PHP_URL_HOST );

			$map = [
				'home_url' => preg_replace( '#^https?://' . $home_host . '(.*)$#i', 'http://wpassure.test:' . $this->wordpress_port . '$1', $site['home_url'] ),
				'site_url' => preg_replace( '#^https?://' . $site_host . '(.*)$#i', 'http://wpassure.test:' . $this->wordpress_port . '$1', $site['site_url'] ),
			];

			if ( ! empty( $site['blog_id'] ) ) {
				$map['blog_id'] = (int) $site['blog_id'];

				Log::instance()->write( 'Blog ID: ' . $map['blog_id'], 1 );
			}

			$site_mapping[] = $map;

			Log::instance()->write( 'Home URL: ' . $map['home_url'], 1 );
			Log::instance()->write( 'Site URL: ' . $map['site_url'], 1 );
		}

		$mysql_creds = $this->getMySQLCredentials();

		$verbose = '';

		if ( 1 === Log::instance()->getVerbosity() ) {
			$verbose = '-v';
		} elseif ( 2 === Log::instance()->getVerbosity() ) {
			$verbose = '-vv';
		} elseif ( 3 === Log::instance()->getVerbosity() ) {
			$verbose = '-vvv';
		}

		$command = '/root/.composer/vendor/bin/wpsnapshots pull ' . $this->suite_config['snapshot_id'] . ' --repository=' . $this->suite_config['repository'] . ' --confirm --confirm_wp_version_change --confirm_ms_constant_update --config_db_name="' . $mysql_creds['DB_NAME'] . '" --config_db_user="' . $mysql_creds['DB_USER'] . '" --config_db_password="' . $mysql_creds['DB_PASSWORD'] . '" --config_db_host="' . $mysql_creds['DB_HOST'] . '" --confirm_wp_download --confirm_config_create --main_domain="wpassure.test:' . $this->wordpress_port . '" --site_mapping="' . addslashes( json_encode( $site_mapping ) ) . '" ' . $verbose;

		Log::instance()->write( 'Running command:', 1 );
		Log::instance()->write( $command, 1 );

		$exec_config = new ContainersIdExecPostBody();
		$exec_config->setTty( true );
		$exec_config->setAttachStdout( true );
		$exec_config->setAttachStderr( true );
		$exec_config->setCmd( [ '/bin/sh', '-c', $command ] );

		$exec_command      = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config );
		$exec_id           = $exec_command->getId();
		$exec_start_config = new ExecIdStartPostBody();
		$exec_start_config->setDetach( false );

		$stream = $this->docker->execStart( $exec_id, $exec_start_config );

		$stream->onStdout(
			function( $stdout ) {
				Log::instance()->write( $stdout, 1 );
			}
		);

		$stream->onStderr(
			function( $stderr ) {
				Log::instance()->write( $stderr, 1 );
			}
		);

		$stream->wait();

		$exit_code = $this->docker->execInspect( $exec_id )->getExitCode();

		if ( 0 !== $exit_code ) {
			Log::instance()->write( 'Failed to pull snapshot into WordPress container.', 0, 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Setup MySQL
	 *
	 * @return boolean
	 */
	public function setupMySQL() {
		$this->mysql_client = new MySQL( $this->getMySQLCredentials(), $this->getLocalIP(), $this->mysql_port, $this->snapshot->meta['table_prefix'] );

		// Enable general logging
		$this->mysql_client->query( 'SET global general_log = 1' );
		$this->mysql_client->query( 'SET global log_output = "table"' );

		/**
		 * Create duplicate WP DB to dirty
		 */
		if ( empty( $this->suite_config['disable_clean_db'] ) ) {
			$this->makeCleanDB();
		}

		return true;
	}

	/**
	 * Mount repository to container
	 *
	 * @return boolean
	 */
	public function insertRepo() {
		/**
		 * Determine where codebase is located in snapshot
		 */
		Log::instance()->write( 'Finding codebase in snapshot...', 1 );

		$exec_config = new ContainersIdExecPostBody();
		$exec_config->setTty( true );
		$exec_config->setAttachStdout( true );
		$exec_config->setAttachStderr( true );
		$exec_config->setCmd( [ '/bin/sh', '-c', 'find /var/www/html -name "wpassure.json" -not -path "/var/www/html/wp-includes/*" -not -path "/var/www/html/wp-admin/*"' ] );

		$exec_id           = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config )->getId();
		$exec_start_config = new ExecIdStartPostBody();
		$exec_start_config->setDetach( false );

		$stream = $this->docker->execStart( $exec_id, $exec_start_config );

		$suite_config_files = [];

		$stream->onStdout(
			function( $stdout ) use ( &$suite_config_files ) {
				$files = preg_split( '/\R/', $stdout );

				foreach ( $files as $file ) {
					$file = trim( $file );

					if ( ! empty( $file ) ) {
						$suite_config_files[] = $file;
					}
				}
			}
		);

		$stream->onStderr(
			function( $stderr ) {
				Log::instance()->write( $stderr, 1 );
			}
		);

		$stream->wait();

		$exit_code = $this->docker->execInspect( $exec_id )->getExitCode();

		if ( 0 !== $exit_code ) {
			Log::instance()->write( 'Failed to find codebase in snapshot.', 0, 'error' );
			return false;
		}

		foreach ( $suite_config_files as $suite_config_file ) {
			$exec_config = new ContainersIdExecPostBody();
			$exec_config->setTty( true );
			$exec_config->setAttachStdout( true );
			$exec_config->setAttachStderr( true );
			$exec_config->setCmd( [ '/bin/sh', '-c', 'php -r "\$config = json_decode(file_get_contents(\"' . $suite_config_file . '\"), true); echo \$config[\"name\"];"' ] );

			$exec_id           = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config )->getId();
			$exec_start_config = new ExecIdStartPostBody();
			$exec_start_config->setDetach( false );

			$stream = $this->docker->execStart( $exec_id, $exec_start_config );

			$suite_config_files = [];
			$suite_config_name  = '';

			$stream->onStdout(
				function( $name ) use ( &$suite_config_name ) {
					$suite_config_name = $name;
				}
			);

			$stream->onStderr(
				function( $stderr ) {
					Log::instance()->write( $stderr, 1 );
				}
			);

			$stream->wait();

			if ( trim( $suite_config_name ) === trim( $this->suite_config['name'] ) ) {
				$this->snapshot_wpassure_path = Utils\trailingslash( dirname( $suite_config_file ) );
				break;
			}
		}

		if ( empty( $this->snapshot_wpassure_path ) ) {
			Log::instance()->write( 'Could not copy codebase files into snapshot. The snapshot must contain a codebase with a wpassure.json file.', 0, 'error' );
			return false;
		}

		// If no repo_path or repo_path is relative
		if ( empty( $this->suite_config['repo_path'] ) ) {
			$this->snapshot_repo_path = $this->snapshot_wpassure_path;
		} else {
			if ( false === stripos( $this->suite_config['repo_path'], '%WP_ROOT%' ) ) {
				$this->snapshot_repo_path = $this->snapshot_wpassure_path . $this->suite_config['repo_path'];
			} else {
				$this->snapshot_repo_path = preg_replace( '#^/?%WP_ROOT%/?(.*)$#i', '/var/www/html/$1', $this->suite_config['repo_path'] );
			}
		}

		/**
		 * Copy repo files into container
		 */

		Log::instance()->write( 'Copying codebase into container...', 1 );
		Log::instance()->write( 'Repo path in snapshot: ' . $this->snapshot_repo_path, 2 );

		$excludes = '';

		if ( ! empty( $this->suite_config['exclude'] ) ) {
			foreach ( $this->suite_config['exclude'] as $exclude ) {
				$exclude = preg_replace( '#^\.?/(.*)$#i', '$1', $exclude );

				if ( false !== stripos( $exclude, '%REPO_ROOT%' ) ) {
					// Exclude contains %REPO_ROOT%
					$excludes .= '--exclude="' . preg_replace( '#^/?%REPO_ROOT%/?(.*)$#i', '$1', $exclude ) . '" ';
				} else {
					// Exclude is relative
					if ( $this->snapshot_repo_path === $this->snapshot_wpassure_path ) {
						$excludes .= '--exclude="' . $exclude . '" ';
					} else {
						$abs_exclude = Utils\resolve_absolute_path( $this->snapshot_wpassure_path . $exclude );

						$excludes .= '--exclude="' . str_replace( Utils\trailingslash( $this->snapshot_repo_path ), '', $abs_exclude ) . '" ';
					}
				}
			}
		}

		$rsync_command = 'rsync -a -I --exclude=".git" ' . $excludes . ' ' . $this->getWPContainerRepoRoot() . '/ ' . $this->snapshot_repo_path;

		Log::instance()->write( $rsync_command, 2 );

		$exec_config = new ContainersIdExecPostBody();
		$exec_config->setTty( true );
		$exec_config->setAttachStdout( true );
		$exec_config->setAttachStderr( true );
		$exec_config->setCmd( [ '/bin/sh', '-c', $rsync_command ] );

		$exec_id           = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config )->getId();
		$exec_start_config = new ExecIdStartPostBody();
		$exec_start_config->setDetach( false );

		$stream = $this->docker->execStart( $exec_id, $exec_start_config );

		$stream->onStdout(
			function( $stdout ) {
				Log::instance()->write( $stdout, 1 );
			}
		);

		$stream->onStderr(
			function( $stderr ) {
				Log::instance()->write( $stderr, 1 );
			}
		);

		$stream->wait();

		$exit_code = $this->docker->execInspect( $exec_id )->getExitCode();

		if ( 0 !== $exit_code ) {
			Log::instance()->write( 'Failed to copy codebase into WordPress container.', 0, 'error' );
			return false;
		}

		/**
		 * Chmod uploads dir
		 */

		Log::instance()->write( 'Chmoding uploads directory...', 1 );

		$exec_config = new ContainersIdExecPostBody();
		$exec_config->setTty( true );
		$exec_config->setAttachStdout( true );
		$exec_config->setAttachStderr( true );
		$exec_config->setCmd( [ '/bin/sh', '-c', 'chmod -R 0777 /var/www/html/wp-content/uploads' ] );

		$exec_id           = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config )->getId();
		$exec_start_config = new ExecIdStartPostBody();
		$exec_start_config->setDetach( false );

		$stream = $this->docker->execStart( $exec_id, $exec_start_config );

		$suite_config_files = [];

		$stream->onStdout(
			function( $stdout ) {
				Log::instance()->write( $stdout, 1 );
			}
		);

		$stream->onStderr(
			function( $stderr ) {
				Log::instance()->write( $stderr, 1 );
			}
		);

		$stream->wait();

		$exit_code = $this->docker->execInspect( $exec_id )->getExitCode();

		if ( 0 !== $exit_code ) {
			Log::instance()->write( 'Failed to chmod uploads directory.', 0, 'error' );
		}

		return true;
	}

	/**
	 * Destroy environment
	 *
	 * @return  bool
	 */
	public function destroy() {
		if ( $this->cache_environment ) {
			Log::instance()->write( 'Caching environment.' );
			return false;
		}

		Log::instance()->write( 'Destroying environment...', 1 );

		$this->stopContainers();
		$this->deleteContainers();
		$this->deleteNetwork();

		return true;
	}

	/**
	 * Download Docker images
	 *
	 * @return  bool
	 */
	public function downloadImages() {
		Log::instance()->write( 'Downloading Docker images...', 1 );

		$images = [
			[
				'name' => 'mysql',
				'tag'  => '5.7',
			],
			[
				'name' => '10up/wpassure-wordpress',
				'tag'  => 'latest',
			],
			[
				'name' => 'selenium/standalone-chrome',
				'tag'  => '3.4.0',
			],
		];

		foreach ( $images as $image ) {
			$create_image = $this->docker->imageCreate(
				'',
				[
					'fromImage' => $image['name'],
					'tag'       => $image['tag'],
				]
			);

			$create_image->wait();
		}

		return true;
	}

	/**
	 * Get environment ID
	 *
	 * @return string
	 */
	public function getEnvironmentId() {
		return $this->environment_id;
	}

	/**
	 * Get open port
	 *
	 * @return int|boolean
	 */
	protected function getOpenPort() {
		static $used_ports = [];

		for ( $i = 1000; $i <= 9999; $i++ ) {
			if ( ! in_array( $i, $used_ports, true ) && Utils\is_open_port( $this->getLocalIP(), $i ) ) {
				$used_ports[] = $i;

				return $i;
			}
		}

		return false;
	}

	/**
	 * Create Docker containers
	 *
	 * @return  bool
	 */
	public function createContainers() {
		Log::instance()->write( 'Creating containers...' );

		$stream_factory = \Http\Discovery\StreamFactoryDiscovery::find();
		$serializer     = new \Symfony\Component\Serializer\Serializer( \Docker\API\Normalizer\NormalizerFactory::create(), [ new \Symfony\Component\Serializer\Encoder\JsonEncoder( new \Symfony\Component\Serializer\Encoder\JsonEncode(), new \Symfony\Component\Serializer\Encoder\JsonDecode() ) ] );

		/**
		 * Create MySQL
		 */

		$this->mysql_port = $this->getOpenPort();

		$host_config = new HostConfig();
		$host_config->setNetworkMode( $this->environment_id );
		$host_config->setBinds(
			[
				WPASSURE_DIR . '/docker/mysql:/etc/mysql/conf.d',
			]
		);

		$port_binding = new PortBinding();
		$port_binding->setHostPort( $this->mysql_port );
		$port_binding->setHostIp( '0.0.0.0' );

		$host_port_map             = new \ArrayObject();
		$host_port_map['3306/tcp'] = [ $port_binding ];

		$host_config->setPortBindings( $host_port_map );

		$container_config = new ContainersCreatePostBody();
		$container_config->setImage( 'mysql:5.7' );
		$container_config->setAttachStdin( true );
		$container_config->setAttachStdout( true );
		$container_config->setAttachStderr( true );
		$container_config->setEnv(
			[
				'MYSQL_ROOT_PASSWORD=password',
				'MYSQL_DATABASE=wordpress_clean',
			]
		);
		$container_config->setHostConfig( $host_config );

		$container_create = new ContainerCreate( $container_config );

		$container_body = $container_create->getBody( $serializer, $stream_factory );

		Log::instance()->write( 'Container Request Body (MySQL):', 2 );
		Log::instance()->write( $container_body[1], 2 );

		$this->containers['mysql'] = $this->docker->containerCreate( $container_config, [ 'name' => 'mysql-' . $this->environment_id ] );

		$this->mysql_stream = $this->docker->containerAttach(
			'mysql-' . $this->environment_id,
			[
				'stream' => true,
				'stdin'  => true,
				'stdout' => true,
				'stderr' => true,
			]
		);

		/**
		 * Create WP container
		 */

		$this->wordpress_port = $this->getOpenPort();

		$host_config = new HostConfig();

		$host_config->setNetworkMode( $this->environment_id );
		$host_config->setExtraHosts( [ 'wpassure.test:' . $this->gateway_ip ] );

		$container_config = new ContainersCreatePostBody();

		if ( GitLab::get()->isGitLab() ) {
			$container_config->setEnv( [ 'WPSNAPSHOTS_DIR=/gitlab/.wpsnapshots/' ] );

			$binds = [
				GitLab::get()->getVolumeName() . ':/gitlab',
			];
		} else {
			$binds = [
				\WPSnapshots\Utils\get_snapshot_directory() . $this->suite_config['snapshot_id'] . ':/root/.wpsnapshots/' . $this->suite_config['snapshot_id'],
				\WPSnapshots\Utils\get_snapshot_directory() . 'config.json:/root/.wpsnapshots/config.json',
				$this->suite_config['host_repo_path'] . ':/root/repo',
			];
		}

		$host_config->setBinds( $binds );

		Log::instance()->write( 'Mapping ' . $this->suite_config['host_repo_path'] . ' to ' . $this->getWPContainerRepoRoot(), 2 );

		$container_port_map           = new \ArrayObject();
		$container_port_map['80/tcp'] = new \stdClass();

		$container_config->setImage( '10up/wpassure-wordpress' );
		$container_config->setAttachStdin( true );
		$container_config->setAttachStdout( true );
		$container_config->setExposedPorts( $container_port_map );
		$container_config->setAttachStderr( true );
		$container_config->setTty( true );

		$port_binding = new PortBinding();
		$port_binding->setHostPort( $this->wordpress_port );
		$port_binding->setHostIp( '0.0.0.0' );

		$host_port_map           = new \ArrayObject();
		$host_port_map['80/tcp'] = [ $port_binding ];

		$host_config->setPortBindings( $host_port_map );
		$container_config->setHostConfig( $host_config );

		$container_create = new ContainerCreate( $container_config );

		$container_body = $container_create->getBody( $serializer, $stream_factory );

		Log::instance()->write( 'Container Request Body (WordPress):', 2 );
		Log::instance()->write( $container_body[1], 2 );

		$this->containers['wordpress'] = $this->docker->containerCreate( $container_config, [ 'name' => 'wordpress-' . $this->environment_id ] );

		/**
		 * Create selenium container
		 */

		$this->selenium_port = $this->getOpenPort();

		$host_config = new HostConfig();
		$host_config->setNetworkMode( $this->environment_id );
		$host_config->setExtraHosts( [ 'wpassure.test:' . $this->gateway_ip ] );
		$host_config->setShmSize( ( 1000 * 1000 * 1000 ) ); // 1GB in bytes

		$container_config = new ContainersCreatePostBody();
		$container_config->setImage( 'selenium/standalone-chrome:3.4.0' );

		$port_binding = new PortBinding();
		$port_binding->setHostPort( $this->selenium_port );
		$port_binding->setHostIp( '0.0.0.0' );

		$host_port_map             = new \ArrayObject();
		$host_port_map['4444/tcp'] = [ $port_binding ];
		$host_config->setPortBindings( $host_port_map );

		$container_config->setHostConfig( $host_config );

		$container_create = new ContainerCreate( $container_config );

		$container_body = $container_create->getBody( $serializer, $stream_factory );

		Log::instance()->write( 'Container Request Body (Selenium):', 2 );
		Log::instance()->write( $container_body[1], 2 );

		$this->containers['selenium'] = $this->docker->containerCreate( $container_config, [ 'name' => 'selenium-' . $this->environment_id ] );

		return true;
	}

	/**
	 * Wait for MySQL to be available by continually running mysqladmin command on WP container
	 *
	 * @return  bool
	 */
	public function waitForMySQL() {
		Log::instance()->write( 'Waiting for MySQL to start...', 1 );

		sleep( 1 );

		$mysql_creds = $this->getMySQLCredentials();

		$exit_code = null;

		for ( $i = 0; $i < 20; $i ++ ) {
			$exec_config = new ContainersIdExecPostBody();
			$exec_config->setTty( true );
			$exec_config->setAttachStdout( true );
			$exec_config->setAttachStderr( true );
			$exec_config->setCmd( [ '/bin/sh', '-c', 'mysqladmin ping -h"' . $mysql_creds['DB_HOST'] . '" -u ' . $mysql_creds['DB_USER'] . ' -p' . $mysql_creds['DB_PASSWORD'] ] );

			$exec_id           = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config )->getId();
			$exec_start_config = new ExecIdStartPostBody();
			$exec_start_config->setDetach( false );

			$stream = $this->docker->execStart( $exec_id, $exec_start_config );

			$stream->wait();

			$exit_code = $this->docker->execInspect( $exec_id )->getExitCode();

			if ( 0 === $exit_code ) {
				Log::instance()->write( 'MySQL connection available after ' . ( $i + 2 ) . ' seconds.', 2 );

				return true;
			}

			sleep( 1 );
		}

		Log::instance()->write( 'MySQL never became available.', 0, 'error' );

		return false;
	}

	/**
	 * Start containers
	 *
	 * @return  bool
	 */
	public function startContainers() {
		Log::instance()->write( 'Starting containers...', 1 );

		foreach ( $this->containers as $container ) {
			$response = $this->docker->containerStart( $container->getId() );
		}

		return $this->waitForMySQL();
	}

	/**
	 * Stop Docker containers
	 *
	 * @return  bool
	 */
	public function stopContainers() {
		Log::instance()->write( 'Stopping containers...', 1 );

		foreach ( $this->containers as $container ) {
			try {
				$this->docker->containerStop( $container->getId() );
			} catch ( \Exception $exception ) {
				// Proceed no matter what
			}
		}

		return true;
	}

	/**
	 * Delete Docker containers
	 *
	 * @return  bool
	 */
	public function deleteContainers() {
		Log::instance()->write( 'Deleting containers...', 1 );

		foreach ( $this->containers as $container ) {
			try {
				$this->docker->containerDelete(
					$container->getId(),
					[
						'v'     => true,
						'force' => true,
					]
				);
			} catch ( \Exception $exception ) {
				// Proceed no matter what
			}
		}

		return true;
	}

	/**
	 * Create Docker network
	 *
	 * @return  bool
	 */
	public function createNetwork() {
		Log::instance()->write( 'Creating network...', 1 );

		$network_config = new NetworksCreatePostBody();
		$network_config->setName( $this->environment_id );

		try {
			$this->network = $this->docker->networkCreate( $network_config );

			$network     = $this->docker->networkInspect( $this->environment_id );
			$ipam_config = $network->getIPAM()->getConfig();
		} catch ( \Exception $e ) {
			Log::instance()->write( 'Could not create network.', 0, 'error' );

			return false;
		}

		$this->gateway_ip = $ipam_config[0]['Gateway'];

		Log::instance()->write( 'Environment ID: ' . $this->environment_id, 1 );
		Log::instance()->write( 'Gateway IP: ' . $this->gateway_ip, 1 );

		return true;
	}

	/**
	 * Delete Docker network
	 *
	 * @return  bool
	 */
	public function deleteNetwork() {
		Log::instance()->write( 'Deleting network...', 1 );

		try {
			$this->docker->networkDelete( $this->environment_id );
		} catch ( \Exception $exception ) {
			// Proceed no matter what
		}

		return true;
	}

	/**
	 * Get Selenium server URL
	 *
	 * @return string
	 */
	public function getSeleniumServerUrl() {
		return 'http://' . $this->getLocalIP() . ':' . intval( $this->selenium_port ) . '/wd/hub';
	}

	/**
	 * Get WordPress homepage URL
	 *
	 * @return string
	 */
	public function getWpHomepageUrl() {
		return 'http://wpassure.test:' . intval( $this->wordpress_port );
	}

	/**
	 * Get Gateway IP
	 *
	 * @return string
	 */
	public function getGatewayIP() {
		return $this->gateway_ip;
	}

	/**
	 * Get current snapshot
	 *
	 * @return \WPSnapshots\Snapshot
	 */
	public function getSnapshot() {
		return $this->snapshot;
	}

	/**
	 * Get MySQL credentials to use in WordPress
	 *
	 * @return array
	 */
	public function getMySQLCredentials() {
		return [
			'DB_HOST'     => 'mysql-' . $this->environment_id,
			'DB_NAME'     => $this->current_mysql_db,
			'DB_USER'     => 'root',
			'DB_PASSWORD' => 'password',
		];
	}

	/**
	 * Get MySQL client
	 *
	 * @return \WPAssure\MySQL
	 */
	public function getMySQLClient() {
		return $this->mysql_client;
	}

	/**
	 * Get suite config
	 *
	 * @return \WPAssure\Config
	 */
	public function getSuiteConfig() {
		return $this->suite_config;
	}

	/**
	 * Get meta data about environment
	 *
	 * @return array
	 */
	public function getEnvironmentMeta() {
		return [
			'suite_config'           => $this->suite_config->toArray(),
			'wordpress_port'         => $this->wordpress_port,
			'selenium_port'          => $this->selenium_port,
			'mysql_port'             => $this->mysql_port,
			'snapshot_id'            => $this->suite_config['snapshot_id'],
			'environment_id'         => $this->environment_id,
			'gateway_ip'             => $this->gateway_ip,
			'snapshot_wpassure_path' => $this->snapshot_wpassure_path,
			'snapshot_repo_path'     => $this->snapshot_repo_path,
		];
	}

	/**
	 * Write meta data to WP container
	 */
	public function writeMetaToWPContainer() {
		Log::instance()->write( 'Saving environment meta data to container...', 1 );

		$command = 'touch /root/environment_meta.json && echo "' . addslashes( json_encode( $this->getEnvironmentMeta() ) ) . '" >> /root/environment_meta.json';

		Log::instance()->write( $command, 2 );

		$exec_config = new ContainersIdExecPostBody();
		$exec_config->setTty( true );
		$exec_config->setAttachStdout( true );
		$exec_config->setAttachStderr( true );
		$exec_config->setCmd( [ '/bin/sh', '-c', $command ] );

		$exec_command      = $this->docker->containerExec( 'wordpress-' . $this->environment_id, $exec_config );
		$exec_id           = $exec_command->getId();
		$exec_start_config = new ExecIdStartPostBody();
		$exec_start_config->setDetach( false );

		$stream = $this->docker->execStart( $exec_id, $exec_start_config );

		$stream->onStdout(
			function( $stdout ) {
				Log::instance()->write( $stdout, 1 );
			}
		);

		$stream->onStderr(
			function( $stderr ) {
				Log::instance()->write( $stderr, 1 );
			}
		);

		$stream = $stream->wait();

		return true;
	}

	/**
	 * Generate an environment ID from config. This is a cache of critical suite config parameters
	 *
	 * @param  array $suite_config Suite config
	 * @return string
	 */
	public static function generateEnvironmentId( $suite_config ) {
		$string = 'name=' . $suite_config['name'] . ',snapshot_id=' . $suite_config['snapshot_id'] . ',host_repo_path=' . $suite_config['host_repo_path'] . ',repo_path=' . $suite_config['repo_path'] . ',repository=' . (int) $suite_config['repository'];

		if ( isset( $suite_config['enforce_clean_db'] ) ) {
			$string .= ',enforce_clean_db=' . (int) $suite_config['enforce_clean_db'];
		}

		if ( isset( $suite_config['disable_clean_db'] ) ) {
			$string .= ',disable_clean_db=' . (int) $suite_config['disable_clean_db'];
		}

		return md5( $string );
	}
}
