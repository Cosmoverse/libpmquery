<?php

declare(strict_types=1);

/**
 * @name PMQueryInterruptible
 * @main cosmicpe\pmqueryinterruptible\Loader
 * @api 5.0.0
 * @version 0.0.1
 */
namespace cosmicpe\pmqueryinterruptible{

	use Closure;
	use jasonw4331\libpmquery\PMQuery;
	use jasonw4331\libpmquery\PmQueryException;
	use pocketmine\plugin\PluginBase;
	use pocketmine\scheduler\AsyncTask;
	use function fclose;
	use function json_encode;
	use function stream_socket_shutdown;
	use const STREAM_SHUT_RDWR;

	final class Loader extends PluginBase{

		/** @var array{resource, string} */
		private array $interruptible;

		protected function onEnable() : void{
			$this->interruptible = PMQuery::interruptible();

			$servers = [];
			$servers[] = ["play.cosmicpe.me", 19876]; // <-- does not exist!
			$servers[] = ["play.nethergames.org", 19132];
			$servers[] = ["zeqa.net", 19132];

			$interruptible = $this->interruptible[1];
			$pool = $this->getServer()->getAsyncPool();
			foreach($servers as [$host, $port]){
				$this->getLogger()->info("Scheduling query {$host}:{$port}");
				$callback = fn($result) => $this->getLogger()->info("{$host}:{$port}: " . json_encode($result));
				$pool->submitTask(new PMQueryAsyncTask($host, $port, $interruptible, $callback));
			}
		}

		protected function onDisable() : void{
			$ipc_socket = $this->interruptible[0];
			stream_socket_shutdown($ipc_socket, STREAM_SHUT_RDWR);
			fclose($ipc_socket);
			unset($this->interruptible);
		}
	}

	final class PMQueryAsyncTask extends AsyncTask{
		public function __construct(
			readonly private string $host,
			readonly private int $port,
			readonly private string $interruptible,
			Closure $callback
		){
			$this->storeLocal("callback", $callback);
		}

		public function onRun() : void{
			try{
				$result = PMQuery::query($this->host, $this->port, 60, $this->interruptible);
			}catch(PmQueryException $e){
				$result = ["error" => $e->getMessage()];
			}
			$this->setResult($result);
		}

		public function onCompletion() : void{
			$result = $this->getResult();
			$callback = $this->fetchLocal("callback");
			$callback($result);
		}
	}
}

/**
[11:30:40.976] [Server thread/INFO]: Loading server configuration
[11:30:40.985] [Server thread/INFO]: Selected English (eng) as the base language
[11:30:40.986] [Server thread/INFO]: Starting Minecraft: Bedrock Edition server version v1.21.70
[11:30:40.988] [Server thread/INFO]: Online mode is enabled. The server will verify that players are authenticated to Xbox Live.
[11:30:40.994] [Server thread/INFO]: This server is running PocketMine-MP version 5.27.1+dev
[11:30:40.994] [Server thread/INFO]: PocketMine-MP is distributed under the LGPL License
[11:30:41.208] [Server thread/INFO]: Loading resource packs...
[11:30:41.214] [Server thread/INFO]: Loading DEVirion v1.3.0
[11:30:41.217] [Server thread/INFO]: [DEVirion] Loading virion libpmquery v1.0.0 by jasonw4331 (antigen: jasonw4331\libpmquery)
[11:30:41.217] [Server thread/WARNING]: [DEVirion] Virions should be bundled into plugins, not redistributed separately! Do NOT use DEVirion on production servers!!
[11:30:41.223] [Server thread/INFO]: Loading DevTools v1.17.0+dev
[11:30:41.225] [Server thread/INFO]: [DevTools] Registered folder plugin loader
[11:30:41.225] [Server thread/INFO]: Loading PMQueryInterruptible v0.0.1
[11:30:41.225] [Server thread/INFO]: Enabling DEVirion v1.3.0
[11:30:41.226] [Server thread/INFO]: Enabling DevTools v1.17.0+dev
[11:30:41.354] [Server thread/INFO]: Preparing world "world"
[11:30:41.365] [Server thread/INFO]: Enabling PMQueryInterruptible v0.0.1
[11:30:41.366] [Server thread/INFO]: [PMQueryInterruptible] Scheduling query play.cosmicpe.me:19876
[11:30:41.366] [Server thread/INFO]: [PMQueryInterruptible] Scheduling query play.nethergames.org:19132
[11:30:41.366] [Server thread/INFO]: [PMQueryInterruptible] Scheduling query zeqa.net:19132
[11:30:41.527] [Server thread/INFO]: Minecraft network interface running on 10.0.16.1:19131
[11:30:41.527] [Server thread/INFO]: GS4 Query listener running on 10.0.16.1:19131
[11:30:41.527] [Server thread/INFO]: Default game type: Survival Mode
[11:30:41.527] [Server thread/INFO]: If you find this project useful, please consider donating to support development: https://patreon.com/pocketminemp
[11:30:41.527] [Server thread/INFO]: Done (0.552s)! For help, type "help" or "?"
[11:30:41.528] [Server thread/INFO]: [PMQueryInterruptible] play.nethergames.org:19132: {"GameName":"MCPE","HostName":"\u00a7o\u00a7e\u00a7lN\u00a76G\u00a7r\u00a77: \u00a7bWinter Update","Protocol":"786","Version":"1.21.70","Players":569,"MaxPlayers":574,"ServerId":"1351583408888257663","Map":"NetherGames","GameMode":"Creative","NintendoLimited":"1","IPv4Port":19132,"IPv6Port":19132,"Extra":"0"}
[11:30:41.528] [Server thread/INFO]: [PMQueryInterruptible] zeqa.net:19132: {"GameName":"MCPE","HostName":"\u00a7l\u00a7gZe\u00a7fqa \u00a7eS8 OUT\u00a7r","Protocol":"786","Version":"1.21.70","Players":705,"MaxPlayers":715,"ServerId":"4918301947763560782","Map":"19132","GameMode":"0","NintendoLimited":"","IPv4Port":0,"IPv6Port":0,"Extra":null}
[11:30:41.536] [Server thread/INFO]: [Memory Manager] [Cyclic Garbage Collector] Run #1 took 7.26 ms (104767 -> 0 roots, 0 cycles collected) - cumulative GC time: 7.26 ms
stop
Command output | Stopping the server
[11:30:42.780] [Server thread/INFO]: [CONSOLE: Stopping the server]
[11:30:42.829] [Server thread/INFO]: Disabling DEVirion v1.3.0
[11:30:42.829] [Server thread/INFO]: Disabling DevTools v1.17.0+dev
[11:30:42.829] [Server thread/INFO]: Disabling PMQueryInterruptible v0.0.1
[11:30:42.829] [Server thread/INFO]: Unloading world "world"
[11:30:42.830] [Server thread/INFO]: [PMQueryInterruptible] play.cosmicpe.me:19876: {"error":"Request interrupted"}
[11:30:42.834] [Server thread/INFO]: Stopping other threads
 */
