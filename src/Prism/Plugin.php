<?php
/**
 * Pirsm plugin
 * @author Gussi <gussi@gussi.is>
 */

namespace Gussi\Irc\Plugin\Minecraft\Bukkit\Prism;

use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Client\React\LoopAwareInterface;
use Phergie\Irc\Bot\React\EventEmitterAwareInterface;
use Phergie\Irc\Event\UserEventInterface as Event;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use React\EventLoop\LoopInterface;
use Gussi\Wrapper\PDO;

class Plugin extends AbstractPlugin implements LoopAwareInterface, EventEmitterAwareInterface {
	private $config;

	private $prismDatabase;
	private $prismLastId;

    private $lastCheck;
	private $lastAiCheck;

	public function __construct(Array $config) {
		$this->config = $config;

		if (!isset($config['db']['dsn'])) {
			throw new \DomainException('$config must contain a "db->dsn" key with Prism DSN');
		}

		$this->prismDatabase = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
			PDO::ATTR_TIMEOUT		=> 10,
		]);

		$this->prismLastId = $this->prismGetLatestId();

        $this->lastCheck = time();
		$this->lastAiCheck = time();
	}

    public function getSubscribedEvents() {
        return [
            'irc.tick'      => 'tick',
        ];
    }

    public function tick(\Phergie\Irc\Client\React\WriteStream $write, \Phergie\Irc\ConnectionInterface $connection, \Psr\Log\LoggerInterface $logger) {
        if (time()-5 > $this->lastCheck) {
            $this->periodicMonitor($write);
            $this->lastCheck = time();
        }

		if (time()-300 > $this->lastAiCheck) {
			$this->lastAiCheck = time();
			$this->runAiCheck($write);
		}
    }

	/**
	 * Add monitoring timer to loop
	 */
	public function setLoop(LoopInterface $loop) {
		# $loop->addPeriodicTimer(5, [$this, 'periodicMonitor']);
	}

	/**
	 * Monitor commands
	 */
	public function periodicMonitor($write) {
        foreach ($this->prismGetRecords() as $row) {
            $msg = sprintf("[%s at %d,%d,%d] %s"
                , $row['player']
                , $row['x']
                , $row['y']
                , $row['z']
                , $row['data']
            );
            $write->ircPrivmsg('#gussi.is-staff', $msg);
        }
	}

	/**
	 * Get latest prism ID and return it
	 */
	private function prismGetLatestId() {
		return $this->prismDatabase->get_field("SELECT `id` FROM `prism_data` ORDER BY `id` DESC LIMIT 1");
	}

	/**
	 * Get latest records, reset latest id marker
	 */
	private function prismGetRecords() {
		$oldPrismId = $this->prismLastId;
		$newPrismId = $this->prismGetLatestId();
		$this->prismLastId = $newPrismId;
		return $this->prismDatabase->get_all("
			SELECT *
			FROM `prism_data`
			LEFT JOIN `prism_actions` ON `prism_actions`.`action_id` = `prism_data`.`action_id`
			LEFT JOIN `prism_data_extra` ON `prism_data`.`id` = `prism_data_extra`.`data_id`
			LEFT JOIN `prism_players` ON `prism_data`.`player_id` = `prism_players`.`player_id`
			WHERE `prism_data`.`id` > ? AND `prism_data`.`id` <= ?
			AND `prism_actions`.`action` LIKE 'player-command'
			AND `prism_data_extra`.`data` REGEXP '^(/pr|/prism) (rollback|restore|preview|rb|rs|pv)'
			ORDER BY `prism_data`.`id` ASC"
			, $oldPrismId
			, $newPrismId
		);
	}

	private function runAiCheck($write) {
		foreach ($this->prismAiGetGrief() as $row) {
			if ($row['count'] > 25) {
				$msg = sprintf("[Prism AI] Mögulegt grief á %d, %d, %d eftir %s"
					, $row['x']
					, $row['y']
					, $row['z']
					, $row['player']
				);
				$write->ircPrivmsg('#gussi.is-staff', $msg);
			}
		}
	}

    private function prismAiGetGrief() {
        return $this->prismDatabase->get_all("
            SELECT
                `prism_data`.*,
                `prism_players`.*,
                COUNT(*) AS `count`
            FROM `prism_data`
            LEFT JOIN `prism_data_extra` ON `prism_data_extra`.`data_id` = `prism_data`.`id`
            LEFT JOIN `prism_players` ON `prism_players`.`player_id` = `prism_data`.`player_id`
            LEFT JOIN `prism_actions` ON `prism_actions`.`action_id` = `prism_data`.`action_id`
            WHERE `prism_data`.`epoch` > (UNIX_TIMESTAMP()-300)
            AND `prism_actions`.`action` = 'block-break'
            AND (
                SELECT `previous_prism_data`.`player_id`
                FROM `prism_data` AS `previous_prism_data`
                LEFT JOIN `prism_actions` AS `previous_prism_actions` ON `previous_prism_actions`.`action_id` = `previous_prism_data`.`action_id`
                WHERE `previous_prism_data`.`id` < `prism_data`.`id`
                AND `previous_prism_actions`.`action` = 'block-place'
                AND `prism_data`.`x` = `previous_prism_data`.`x`
                AND `prism_data`.`y` = `previous_prism_data`.`y`
                AND `prism_data`.`z` = `previous_prism_data`.`z`
                AND `prism_data`.`world_id` = `previous_prism_data`.`world_id`
                ORDER BY `previous_prism_data`.`id` LIMIT 1
            ) != `prism_data`.`player_id`
            GROUP BY `prism_data`.`player_id`
            ORDER BY `prism_data`.`id` DESC");
    }
}
