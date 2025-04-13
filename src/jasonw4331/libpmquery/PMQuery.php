<?php

declare(strict_types=1);

namespace jasonw4331\libpmquery;

use function explode;
use function fclose;
use function fread;
use function fsockopen;
use function fwrite;
use function in_array;
use function pack;
use function str_starts_with;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function stream_socket_shutdown;
use function substr;
use function time;
use const E_WARNING;
use const STREAM_SHUT_RDWR;

class PMQuery{

	/**
	 * @param string      $host          Ip/dns address being queried
	 * @param int         $port          Port on the ip being queried
	 * @param int         $timeout       Seconds before socket times out
	 * @param string|null $interruptible IPC socket address to interrupt this request externally
	 *
	 * @return string[]|int[]
	 * @phpstan-return array{
	 *     GameName: string|null,
	 *     HostName: string|null,
	 *     Protocol: string|null,
	 *     Version: string|null,
	 *     Players: int,
	 *     MaxPlayers: int,
	 *     ServerId: string|null,
	 *     Map: string|null,
	 *     GameMode: string|null,
	 *     NintendoLimited: string|null,
	 *     IPv4Port: int,
	 *     IPv6Port: int,
	 *     Extra: string|null,
	 * }
	 * @throws PmQueryException
	 */
	public static function query(string $host, int $port, int $timeout = 4, ?string $interruptible = null) : array{
		if($interruptible !== null){
			$ipc = stream_socket_client($interruptible, $errno, $errstr, $timeout);
			if($ipc === false){
				throw new PmQueryException($errstr, $errno);
			}
		}else{
			$ipc = null;
		}

		$socket = @fsockopen('udp://' . $host, $port, $errno, $errstr, $timeout);

		if($errno !== 0 && $socket !== false){
			fclose($socket);
			throw new PmQueryException($errstr, $errno);
		}elseif($socket === false){
			throw new PmQueryException($errstr, $errno);
		}

		stream_set_timeout($socket, $timeout);
		stream_set_blocking($socket, false);

		// hardcoded magic https://github.com/facebookarchive/RakNet/blob/1a169895a900c9fc4841c556e16514182b75faf8/Source/RakPeer.cpp#L135
		$OFFLINE_MESSAGE_DATA_ID = pack('c*', 0x00, 0xFF, 0xFF, 0x00, 0xFE, 0xFE, 0xFE, 0xFE, 0xFD, 0xFD, 0xFD, 0xFD, 0x12, 0x34, 0x56, 0x78);
		$command = pack('cQ', 0x01, time()); // DefaultMessageIDTypes::ID_UNCONNECTED_PING + 64bit current time
		$command .= $OFFLINE_MESSAGE_DATA_ID;
		$command .= pack('Q', 2); // 64bit guid

		$read = $except = [];
		$write = [$socket];
		$state = "select";
		$data = false;
		$error = null;
		while($state !== null){
			switch($state){
				case "select":
					$r = $read;
					$w = $write;
					if($ipc !== null){
						$r[] = $ipc;
					}
					$result = stream_select($r, $w, $except, $timeout);
					if($result === false){
						$error = "select() call failed";
						$state = "shutdown";
					}elseif($result === 0){
						$error = "select() timed out";
						$state = "shutdown";
					}elseif(in_array($ipc, $r, true)){
						$error = "Request interrupted";
						$state = "shutdown";
					}elseif(in_array($socket, $r, true)){
						$state = "read";
					}elseif(in_array($socket, $w, true)){
						$state = "write";
					}
					break;
				case "write":
					$written = fwrite($socket, $command);
					if($written === false){
						$error = "Failed to write on socket";
						$state = "shutdown";
						break;
					}
					$command = substr($command, $written);
					if($command === ""){
						stream_set_blocking($socket, true);
						$read = [$socket];
						$write = [];
					}
					$state = "select";
					break;
				case "read":
					$data = fread($socket, 4096);
					$state = "shutdown";
					break;
				case "shutdown":
					fclose($socket);
					if($ipc !== null){
						stream_socket_shutdown($ipc, STREAM_SHUT_RDWR);
						fclose($ipc);
					}
					$state = null;
					break;
			}
		}

		if($error !== null){
			throw new PmQueryException($error, E_WARNING);
		}
		if($data === false || $data === ''){
			throw new PmQueryException("Server failed to respond", E_WARNING);
		}
		if(!str_starts_with($data, "\x1C")){
			throw new PmQueryException("First byte is not ID_UNCONNECTED_PONG.", E_WARNING);
		}
		if(substr($data, 17, 16) !== $OFFLINE_MESSAGE_DATA_ID){
			throw new PmQueryException("Magic bytes do not match.");
		}

		// TODO: What are the 2 bytes after the magic?
		$data = substr($data, 35);

		// TODO: If server-name contains a ';' it is not escaped, and will break this parsing
		$data = explode(';', $data);

		return [
			'GameName' => $data[0] ?? null,
			'HostName' => $data[1] ?? null,
			'Protocol' => $data[2] ?? null,
			'Version' => $data[3] ?? null,
			'Players' => isset($data[4]) ? (int) $data[4] : 0,
			'MaxPlayers' => isset($data[5]) ? (int) $data[5] : 0,
			'ServerId' => $data[6] ?? null,
			'Map' => $data[7] ?? null,
			'GameMode' => $data[8] ?? null,
			'NintendoLimited' => $data[9] ?? null,
			'IPv4Port' => isset($data[10]) ? (int) $data[10] : 0,
			'IPv6Port' => isset($data[11]) ? (int) $data[11] : 0,
			'Extra' => $data[12] ?? null, // TODO: What's in this?
		];
	}

	/**
	 * Creates an IPC server to interrupt a query() call.
	 *
	 * @see PMQuery::query()
	 * @return array{resource, string}
	 * @throws PmQueryException
	 */
	public static function interruptible() : array{
		$server = stream_socket_server("tcp://localhost:0", $errno, $errstr);
		$server !== false || throw new PmQueryException($errstr, $errno);
		$name = stream_socket_get_name($server, false);
		$name !== false || throw new PmQueryException("Failed to get IPC name");
		return [$server, $name];
	}
}
