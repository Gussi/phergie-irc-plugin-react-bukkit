<?php
/**
 * Pirsm plugin
 * @author Gussi <gussi@gussi.is>
 */

namespace Gussi\Irc\Plugin\Minecraft\Bukkit\SHT;

use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventEmitterAwareInterface;
use Phergie\Irc\Event\UserEventInterface as Event;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use React\EventLoop\LoopInterface;
use Phergie\Irc\Plugin\React\Command\CommandEvent;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Gussi\Wrapper\PDO;

class Plugin extends AbstractPlugin implements EventEmitterAwareInterface {
	private $config;

	private $shtDatabase;
    private $tickets;

    private $lastCheck;

	public function __construct(Array $config) {
		$this->config = $config;

		if (!isset($config['db']['dsn'])) {
			throw new \DomainException('$config must contain a "db->dsn" key with Prism DSN');
		}

		$this->shtDatabase = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass']);
        $this->lastCheck = time();
        $this->tickets = $this->getTickets();
	}

    public function getSubscribedEvents() {
        return [
            'irc.tick'          => 'tick',
            'command.ticket'   => 'handleCommandTicket',
        ];
    }

    public function handleCommandTicket(CommandEvent $event, EventQueueInterface $queue) {
        $args = $event->getCustomParams();
        if (empty($args)) {
            $tickets = $this->getTickets();
            foreach ($tickets as $id => $ticket) {
                if ($ticket['status'] == 'OPEN') {
                    $msg = sprintf('[SHT #%d] "%s" - %s'
                        , $ticket['id']
                        , $ticket['description']
                        , $ticket['owner']
                    );
                    $queue->ircPrivmsg('#gussi.is-staff', $msg);
                }
            }
        } else if (count($args) == 1) {
            if (isset($this->tickets[$args[0]])) {
                $ticket = $this->tickets[$args[0]];
                $msg = sprintf('[SHT #%d] Owner: %s | Status: %s | Admin: %s | Admin reply: %s | User reply: %s | "%s"'
                    , $ticket['id']
                    , $ticket['owner']
                    , $ticket['status']
                    , $ticket['admin']
                    , $ticket['adminreply']
                    , $ticket['userreply']
                    , $ticket['description']
                );
                $queue->ircPrivmsg('#gussi.is-staff', $msg);
            }
        }
    }

    public function tick(\Phergie\Irc\Client\React\WriteStream $write, \Phergie\Irc\ConnectionInterface $connection, \Psr\Log\LoggerInterface $logger) {
        if (time()-5 > $this->lastCheck) {
            $this->lastCheck = time();
            $this->periodicMonitor($write);
        }
    }

	/**
	 * Monitor commands
	 */
	public function periodicMonitor($write) {
        $tickets = $this->getTickets();
        foreach ($tickets as $id => $ticket) {
            if (isset($this->tickets[$id])) {
                $diff = array_diff($ticket, $this->tickets[$id]);
                if (!empty($diff)) {
                    foreach ($diff as $key => $val) {
                        $msg = NULL;
                        switch ($key) {
                            case "adminreply":
                                $msg = sprintf('[SHT #%d] admin svaraði "%s" -%s'
                                    , $ticket['id']
                                    , $ticket['adminreply']
                                    , $ticket['admin']
                                );
                                break;
                            case "userreply":
                                $msg = sprintf('[SHT #%d] notandi svaraði "%s" -%s'
                                    , $ticket['id']
                                    , $ticket['userreply']
                                    , $ticket['owner']
                                );
                                break;
                            case "status":
                                $msg = sprintf('[SHT #%d] Status breyttist frá %s í %s'
                                    , $ticket['id']
                                    , $this->tickets[$ticket['id']]['status']
                                    , $ticket['status']
                                );
                                break;
                            case "admin":
                                $msg = sprintf('[SHT #%d] %s tók ticket "%s" -%s'
                                    , $ticket['id']
                                    , $ticket['admin']
                                    , $ticket['description']
                                    , $ticket['owner']
                                );
                                break;
                        }
                        if (!empty($msg)) {
                            $write->ircPrivmsg('#gussi.is-staff', $msg);
                        }
                    }
                }
            } else {
                $msg = sprintf('[SHT #%d] Nýr ticket: "%s" -%s'
                    , $ticket['id']
                    , $ticket['description']
                    , $ticket['owner']
                );
                $write->ircPrivmsg('#gussi.is-staff', $msg);
            }
        }
        $this->tickets = $tickets;
	}

	/**
	 * Get latest records, reset latest id marker
	 */
	private function getTickets() {
        $out = [];
        foreach ($this->shtDatabase->get_all('SELECT * FROM `SHT_Tickets`') as $row) {
            foreach ($row as $key => $val) {
                $row[$key] = utf8_encode($val);
            }
            $out[$row['id']] = $row;
        }
        return $out;
	}
}
