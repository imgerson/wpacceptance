<?php
/**
 * Functionality used if WP Assure is used inside Gitlab. We do all this because GitLab runs docker in docker.
 *
 * @package wpassure
 */

namespace WPAssure;

/**
 * Gitlab singleton
 */
class GitLab {

	/**
	 * Are we inside gitlab or not
	 *
	 * @var boolean
	 */
	private $is_gitlab = false;

	/**
	 * Name of GitLab build volume
	 *
	 * @var string
	 */
	private $volume_name;

	/**
	 * Gitlab container ID
	 *
	 * @var string
	 */
	private $container_id;

	/**
	 * Path to wpsnapshots directory
	 *
	 * @var string
	 */
	private $snapshots_directory;

	/**
	 * Are we inside gitlab or not
	 *
	 * @return boolean
	 */
	public function isGitlab() {
		return $this->is_gitlab;
	}

	/**
	 * Get name of GitLab build build
	 *
	 * @return string
	 */
	public function getVolumeName() {
		return $this->volume_name;
	}

	/**
	 * Get wp snapshots directory
	 *
	 * @return string
	 */
	public function getSnapshotsDirectory() {
		return $this->snapshots_directory;
	}

	/**
	 * Setup singleton class
	 */
	private function __construct() {
		$this->is_gitlab           = ! empty( getenv( 'CI_CONFIG_PATH' ) );
		$this->container_id        = exec( 'docker ps -q -f "label=com.gitlab.gitlab-runner.job.id=$CI_JOB_ID" -f "label=com.gitlab.gitlab-runner.type=build"' );
		$this->volume_name         = exec( 'docker inspect --format "{{ range .Mounts }}{{ if eq .Destination \"/builds/$CI_PROJECT_NAMESPACE\"}}{{ .Name }}{{ end }}{{ end }}" ' . $this->container_id );
		$this->snapshots_directory = '/builds/' . getenv( 'CI_PROJECT_NAMESPACE' );
	}

	/**
	 * Get singleton GitLab instance
	 *
	 * @return self
	 */
	public static function get() {
		static $instance;

		if ( empty( $instance ) ) {
			$instance = new self();
		}

		return $instance;
	}
}