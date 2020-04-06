<?php

declare(strict_types = 1);

class PDO_MySQLi_Polyfill {
	/**
	 * Name of the option to set connection flags
	 */
	const OPTION_FLAGS = 'flags';

	/** @var mysqli */
	private $conn;

	/**
	 * @param array<string, mixed> $params
	 * @param array<int, mixed>    $driverOptions
	 *
	 * @throws Exception
	 */
	public function __construct( $params, $username, $password, $driverOptions = [] ) {
		if ( is_string( $params ) ) {
			$config = substr( $params, 6 );
			$parts  = explode( ';', $config );
			$params = [];
			foreach ( $parts as $part ) {
				if ( strpos( $part, '=' ) === false ) {
					continue;
				}
				list( $key, $value ) = explode( '=', $part );
				$params[ $key ] = $value;
			}
		}


		$port = isset( $params['port'] ) ? $params['port'] : (int) ini_get( 'mysqli.default_port' );

		// Fallback to default MySQL port if not given.
		if ( ! $port ) {
			$port = 3306;
		}

		$socket = isset( $params['unix_socket'] ) ? $params['unix_socket'] : ini_get( 'mysqli.default_socket' );
		$dbname = isset( $params['dbname'] ) ? $params['dbname'] : '';
		$host   = $params['host'];

		if ( ! empty( $params['persistent'] ) ) {
			$host = 'p:' . $host;
		}

		$flags = isset( $driverOptions[ static::OPTION_FLAGS ] ) ? $driverOptions[ static::OPTION_FLAGS ] : 0;

		$this->conn = mysqli_init();

		$this->setSecureConnection( $params );
		$this->setDriverOptions( $driverOptions );

		set_error_handler(static function () {
			return true;
		});
		try {
			if ( ! $this->conn->real_connect( $host, $username, $password, $dbname, $port, $socket, $flags ) ) {
				throw new Exception( $this->conn->error, $this->conn->errno );
			}
		} finally {
			restore_error_handler();
		}

		if ( ! isset( $params['charset'] ) ) {
			return;
		}

		$this->conn->set_charset( $params['charset'] );
	}

	/**
	 * Retrieves mysqli native resource handle.
	 *
	 * Could be used if part of your application is not using DBAL.
	 */
	public function getWrappedResourceHandle() {
		return $this->conn;
	}

	/**
	 * {@inheritdoc}
	 *
	 * The server version detection includes a special case for MariaDB
	 * to support '5.5.5-' prefixed versions introduced in Maria 10+
	 *
	 * @link https://jira.mariadb.org/browse/MDEV-4088
	 */
	public function getServerVersion() {
		$serverInfos = $this->conn->get_server_info();
		if ( stripos( $serverInfos, 'mariadb' ) !== false ) {
			return $serverInfos;
		}

		$majorVersion = floor( $this->conn->server_version / 10000 );
		$minorVersion = floor( ($this->conn->server_version - $majorVersion * 10000) / 100 );
		$patchVersion = floor( $this->conn->server_version - $majorVersion * 10000 - $minorVersion * 100 );

		return $majorVersion . '.' . $minorVersion . '.' . $patchVersion;
	}

	public function prepare( $sql ) {
		return new PDO_MySQLi_Statement_Polyfill( $this->conn, $sql );
	}

	public function query( $sql ) {
		$stmt = $this->prepare( $sql );
		$stmt->execute();

		return $stmt;
	}

	public function quote( $input ) {
		return "'" . $this->conn->escape_string( $input ) . "'";
	}

	public function exec( $statement ) {
		if ( $this->conn->query( $statement ) === false ) {
			throw new Exception( $this->conn->error, $this->conn->errno );
		}

		return $this->conn->affected_rows;
	}

	public function lastInsertId( $name = null ) {
		return (string) $this->conn->insert_id;
	}

	public function beginTransaction() {
		$this->conn->query( 'START TRANSACTION' );
	}

	public function commit() {
		if ( ! $this->conn->commit() ) {
			throw new Exception( $this->conn->error, $this->conn->errno );
		}
	}

	public function rollBack() {
		if ( ! $this->conn->rollback() ) {
			throw new Exception( $this->conn->error, $this->conn->errno );
		}
	}

	/**
	 * Apply the driver options to the connection.
	 *
	 * @param array<int, mixed> $driverOptions
	 *
	 * @throws Exception When one of of the options is not supported.
	 * @throws Exception When applying doesn't work - e.g. due to incorrect value.
	 */
	private function setDriverOptions( $driverOptions = [] ) {
		if ( empty( $driverOptions ) ) {
			return;
		}

		$supportedDriverOptions = [
			MYSQLI_OPT_CONNECT_TIMEOUT,
			MYSQLI_OPT_LOCAL_INFILE,
			MYSQLI_INIT_COMMAND,
			MYSQLI_READ_DEFAULT_FILE,
			MYSQLI_READ_DEFAULT_GROUP,
		];

		if ( defined( 'MYSQLI_SERVER_PUBLIC_KEY' ) ) {
			$supportedDriverOptions[] = MYSQLI_SERVER_PUBLIC_KEY;
		}

		$exceptionMsg = "%s option '%s' with value '%s'";

		foreach ( $driverOptions as $option => $value ) {
			if ( $option === static::OPTION_FLAGS ) {
				continue;
			}

			if ( ! in_array( $option, $supportedDriverOptions, true ) ) {
				throw new Exception(
					sprintf( $exceptionMsg, 'Unsupported', $option, $value )
				);
			}

			if ( @mysqli_options( $this->conn, $option, $value ) ) {
				continue;
			}

			throw new Exception( $this->conn->error, $this->conn->errno );
		}
	}

	/**
	 * Pings the server and re-connects when `mysqli.reconnect = 1`
	 *
	 * {@inheritDoc}
	 */
	public function ping() {
		if ( ! $this->conn->ping() ) {
			throw new Exception( $this->conn->error, $this->conn->errno );
		}
	}

	public function getAttribute( $name ) {
		if ( $name === PDO::ATTR_DRIVER_NAME ) {
			return 'mysql';
		}
		return null;
	}

	/**
	 * Establish a secure connection
	 *
	 * @param array<string, mixed> $params
	 *
	 * @throws Exception
	 */
	private function setSecureConnection( $params ) {
		if ( ! isset( $params['ssl_key'] ) &&
			! isset( $params['ssl_cert'] ) &&
			! isset( $params['ssl_ca'] ) &&
			! isset( $params['ssl_capath'] ) &&
			! isset( $params['ssl_cipher'] )
		) {
			return;
		}

		$this->conn->ssl_set(
			isset( $params['ssl_key'] ) ? $params['ssl_key'] : null,
			isset( $params['ssl_cert'] ) ? $params['ssl_cert'] : null,
			isset( $params['ssl_ca'] ) ? $params['ssl_ca'] : null,
			isset( $params['ssl_capath'] ) ? $params['ssl_capath'] : null,
			isset( $params['ssl_cipher'] ) ? $params['ssl_cipher'] : null
		);
	}
}
