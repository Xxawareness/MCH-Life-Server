<?php 

namespace LoginSystem;

use pocketmine\Player;
use pocketmine\Server;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\ServerOperator;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\server\ServerCommandEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\entity\Entity;
use pocketmine\permission\BanEntry;
use pocketmine\scheduler\Task;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;

class LoginSystem extends PluginBase implements Listener {

    public static $instance = null;

    function onLoad() {
        self::$instance = $this;
    }

    function onEnable() {
        $plugin = "LoginSystem";
        $this->getLogger()->info("§a" . $plugin . "を読み込みました");
        Server::getInstance()->getPluginManager()->registerEvents($this, $this);
        $file = $this->getDataFolder() . "player.db";
        if (!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0744, true);
        if (!file_exists($file)) {
            $this->db = new \SQLite3($file, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        } else {
            $this->db = new \SQLite3($file, SQLITE3_OPEN_READWRITE);
        }
        $this->DB("CREATE TABLE IF NOT EXISTS player (name TEXT PRIMARY KEY,pass TEXT,ip TEXT,cid TEXT,data INT)");
        $this->DB("CREATE TABLE IF NOT EXISTS banlist (cid TEXT PRIMARY KEY,name TEXT,reason TEXT)");
    }

    function onDisable() {
        $this->db->close();
    }

    function onLogin(PlayerPreLoginEvent $event) {
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        $cid = $player->getClientId();
        $ip = $player->getAddress();
        $r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        $R = $this->DB("SELECT * FROM player WHERE cid=\"$cid\"", true);
        if ($this->isClientBan($cid)) {
            $event->setCancelled();
            $event->setKickMessage("§c§lあなたの端末はBANされています");
        } elseif (!empty($r)) {
            if ($r["ip"] != $ip && $r["cid"] != $cid) {
                $event->setCancelled();
                $event->setKickMessage("§c§l名前の情報と一致しなかった為、サーバーに入ることができません");
            }
        } elseif (!empty($R)) {
            if ($r["name"] != $name) {
                $event->setCancelled();
                $event->setKickMessage("§l§c1つの端末で2つ以上のアカウントを作ることはできません");
            }
        }
    }

    function onJoin(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        $ip = $player->getAddress();
        $cid = $player->getClientId();
        $r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        if (empty($r)) {
            $player->sendMessage("§l§e[運営] §bログイン認証を行っています\n§e§l[運営] §cアカウント登録画面が出ない場合 §a/register §cと打ってください");
            $player->setImmobile(true);
            $data = [
                'type'    => 'custom_form',
                'title'   => '§l§2アカウント登録',
                'content' => [
                	[
                        "type" => "label",
                        "text" => "§l§bログイン認証を行っています\n§bプレイをするには、 §a好きなパスワード §bを打って認証をしてください",
                    ],
                    [
                        "type"        => "input",
                        "text"        => "§l§eパスワード",
                        "placeholder" => "§l§7パスワードを入力してください。",
                        "default"     => ""
                    ],
                    [
                        "type"        => "input",
                        "text"        => "§l§eパスワード(確認用)",
                        "placeholder" => "§l§7パスワードを入力してください。",
                        "default"     => ""
                    ]
                ]
            ];
            $this->getScheduler()->scheduleDelayedTask(new Callback([$this,"createWindow"],[$player,$data,5]),20);
        } else {
            if ($r["ip"] == $ip && $r["cid"] == $cid) {
                $player->sendMessage("§l§e[運営] §aログイン認証がされました");
            } else {
            	$this->log[$name] = 0;
                $player->sendMessage("§l§e[運営] §bあなたの情報が変わったようです\n§l§e[運営] §cアカウント登録画面が出ない場合 §a/login §cと打ってください");
                $player->setImmobile(true);
                $this->DB("UPDATE player set data=\"2\" WHERE name=\"$name\"");
                $data = [
                	'type'    => 'custom_form',
                	'title'   => '§l§2ログイン認証',
                	'content' => [
                		[
                        	"type" => "label",
                    	    "text" => "§l§cあなたの情報が変わったようです\n§b§lパスワード を打ってログイン認証をしてください",
                    	],
                    	[
                    	    "type"        => "input",
                    	    "text"        => "§l§eパスワード",
                    	    "placeholder" => "§l§7パスワードを入力",
                    	    "default"     => ""
                    	],
                	]
            	];
            	Server::getInstance()->getScheduler()->scheduleDelayedTask(new Callback([$this,"createWindow"],[$player,$data,5]),20);
            }
        }
    }

    function onReceive(DataPacketReceiveEvent $event) {
    	$player = $event->getPlayer();
        $pk = $event->getPacket();
        if ($pk instanceof ModalFormResponsePacket) {
            $id = $pk->formId;
            $data = json_decode($pk->formData);
            if ($id === 5) {
            	$name = strtolower($player->getName());
        		$r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        		if (empty($r)) {
            		if (preg_match("/[^a-zA-Z0-9]/", $data[1]) or $data[1] === "") {
                		$data = [
                			'type'    => 'custom_form',
                			'title'   => '§l§2アカウント登録',
                			'content' => [
                				[
                   		    		"type" => "label",
                    			    "text" => "§l§cパスワードは英数字で設定してください\n§l§eもう一度最初から パスワード を打ってください",
                    			],
                    			[
                    			    "type"        => "input",
                    			    "text"        => "§l§eパスワード",
                    			    "placeholder" => "§l§7パスワードを入力",
                    			    "default"     => ""
                    			],
                    			[
                    			    "type"        => "input",
                    			    "text"        => "§l§eパスワード(確認用)",
                    			    "placeholder" => "§l§7確認用パスワードを入力",
                    			    "default"     => ""
                    			]
                			]
            			];
            			$this->createWindow($player,$data,5);
            		} else {
            			if (isset($data[1]) and ($data[1] === $data[2])) {
                   			$ip = $player->getAddress();
                    		$cid = $player->getClientId();
                    		$h = MD5($data[1]);
                    		$p = hash('sha256', 'login' . $h . 'system');
                    		$this->DB("INSERT OR REPLACE INTO player VALUES(\"$name\",\"$p\",\"$ip\",\"$cid\",\"1\")");
                    		$player->sendMessage("§l§e[運営] §aパスワードを登録しました\n§l§e[運営] §bパスワードは< " . $data[1] . " >です\n§l§e[運営] §aスクリーンショットをして保存してください");
                    		$player->setImmobile(false);
                    	} else {
                        	$data = [
                				'type'    => 'custom_form',
                				'title'   => '§l§2アカウント登録',
                				'content' => [
                					[
                   		    			"type" => "label",
                    			    	"text" => "§l§cパスワードが違います\n§eもう一度最初から パスワード を打ってください",
                    				],
                    				[
                    			    	"type"        => "input",
                    			    	"text"        => "§l§eパスワード",
                    			    	"placeholder" => "§l§7パスワードを入力",
                    			    	"default"     => ""
                    				],
                    				[
                    			    	"type"        => "input",
                    			    	"text"        => "§l§eパスワード(確認用)",
                    			    	"placeholder" => "§l§7確認用パスワードを入力",
                    			    	"default"     => ""
                    				]
                				]
            				];
            				$this->createWindow($player,$data,5);
                    	}
            		}
        		} else {
            		if ($r["data"] == "2") {
                		$ip = $player->getAddress();
                		$cid = $player->getClientId();
                		$h = MD5($data[1]);
                		$p = hash('sha256', 'login' . $h . 'system');
                		if ($r["pass"] == $p) {
                    		$this->DB("UPDATE player set ip=\"$ip\",cid=\"$cid\",data=\"1\" WHERE name=\"$name\"");
                    		$player->sendMessage("§l§e[運営] §aパスワードが認証されました");
	                    	$this->log[$name] = 0;
	                    	$player->setImmobile(false);
                		} else {
	                    	$this->log[$name]++;
	                    	if ($this->log[$name] >= 10) {
	                        	$this->addClientBan($cid, $name, "LoginMiss");
	                        	$player->kick("§l§cあなたの端末をBANしました",false);
	                        	Server::getInstance()->broadcastMessage("§l§e[運営] §cpassを10回以上間違えたので" . $name . "をClientBANしました");
	                    	} else {
	                    		$data = [
	                				'type'    => 'custom_form',
	                				'title'   => '§l§2ログイン認証',
	                				'content' => [
	                					[
	                    			    	"type" => "label",
	                   				 	    "text" => "§l§cパスワードが違います\n§bパスワード を打ってログイン認証をしてください",
	                    				],
	                    				[
	                    				    "type"        => "input",
	                    				    "text"        => "§l§eパスワード",
	                    				    "placeholder" => "§l§7パスワードを入力",
	                    				    "default"     => ""
	                    				],
	                				]
	            				];
	            				$this->createWindow($player,$data,5);
	                    	}
                		}
            		}
            	}
        	} elseif ($id === 8) {
        		# 0 => cban, 1 => icban, 2 => cpardon
        		$user = strtolower($data[1]);
        		$r = $this->DB("SELECT cid FROM player WHERE name=\"$user\"", true);
                $target = Server::getInstance()->getPlayer($user);
                if ($data[1] != "") {
        			if ($data[0] === 0) {
                    	if (!empty($r)) {
                        	if ($target instanceof Player) $target->kick("§l§cあなたの端末をBANしました",false);
                        	$cid = $r["cid"];
                        	$this->addClientBan($cid, $user, "AdminBan");
                        	Server::getInstance()->broadcastMessage("§l§e[運営] §c" . $player->getName() . "が" . $data[1] . "をClientBanしました");
                    	} else {
                        	$player->sendMessage("§l§e[運営] " . $data[1] . "のデータがありません");
                    	}
        			} elseif ($data[0] === 1) {
        				if (!empty($r)) {
                        	if ($target instanceof Player) $target->kick("§l§cあなたの端末とIPをBANしました",false);
                        	$cid = $r["cid"];
                        	$this->addClientBan($cid, $user, "AdminBan");
	                        Server::getInstance()->getIPBans()->add(new BanEntry($ip = ($target instanceof Player) ? $target->getAddress() : $r["ip"]));
	                        Server::getInstance()->broadcastMessage("§l§e[運営] §c" . $player->getName() . "が" . $data[1] . "をClientBanとIPBanしました");
	                    } else {
	                        $player->sendMessage("§l§e[運営] " . $data[0] . "のデータがありません");
	                    }
        			} elseif ($data[0] === 2) {
        				if (!empty($r)) {
	                        $cid = $r["cid"];
	                        if ($this->isClientBan($cid)) {
	                            $this->removeClientBan($cid);
	                            Server::getInstance()->broadcastMessage("§l§e[運営] §a" . $this->getName() . "が" . $data[1] . "のClientBanを解除しました");
	                        } else {
	                            $player->sendMessage("> " . $data[1] . "はClientBanされていません");
	                        }
	                    } else {
	                        $player->sendMessage("> " . $data[1] . "のデータがありません");
	                    }
        			}
        		} else {
        			$player->sendMessage("§l§e[運営] §cプレイヤー名を入れてください");
        		}
        	}
        }
    }

    function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        switch ($command->getName()) {
        	case "register":
        	case "login":
        		if ($sender instanceof Player) {
                    $name = strtolower($sender->getName());
                    $r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        			if (empty($r)) {
        				$data = [
                			'type'    => 'custom_form',
                			'title'   => '§l§2アカウント登録',
                			'content' => [
                				[
                        			"type" => "label",
                        			"text" => "§l§bログイン認証を行っています\n§bプレイをするには、 §a好きなパスワード §bを打って認証をしてください",
                                ],
			                    [
			                        "type"        => "input",
			                        "text"        => "§l§eパスワード",
			                        "placeholder" => "§l§7パスワードを入力",
			                        "default"     => ""
			                    ],
			                    [
			                        "type"        => "input",
			                        "text"        => "§l§eパスワード(確認用)",
			                        "placeholder" => "§l§7確認用パスワードを入力",
			                        "default"     => ""
			                    ]
			                ]
			            ];
			            $this->createWindow($sender,$data,5);
        			} else {
        				if ($r["data"] == "2") {
        					$data = [
			                	'type'    => 'custom_form',
			                	'title'   => '§l§2ログイン認証',
			                	'content' => [
			                		[
			                        	"type" => "label",
			                    	    "text" => "§l§cあなたの情報が変わったようです\n§b§lパスワード を打ってログイン認証をしてください",
			                    	],
			                    	[
			                    	    "type"        => "input",
			                    	    "text"        => "§l§eパスワード",
			                    	    "placeholder" => "§l§7パスワードを入力",
			                    	    "default"     => ""
			                    	],
			                	]
			            	];
			            	$this->createWindow($sender,$data,5);
        				} else {
        					$sender->sendMessage("§l§e[運営] §aログイン認証がされています");
        				}
        			}
                }
                break;
            case "lsystem":
            	if ($sender instanceof Player && $sender->isOp()) {
            		$data = [
	                	'type'    => 'custom_form',
	                	'title'   => '§l§2システム',
	                	'content' => [
	                    	[
                    	    	"type" => "dropdown",
                    	    	"text" => "§l§aプレイヤーの処罰",
                    	    	"options" => ["クライアントBanの実行","IP&クライアントBanの実行","クライアントBanの解除"]
                    		],
	                    	[
	                    	    "type"        => "input",
	                    	    "text"        => "名前",
	                    	    "placeholder" => "§l§7ここにプレイヤー名を入力",
	                    	    "default"     => ""
	                    	],
	                	]
	            	];
	            	$this->createWindow($sender,$data,8);
            	}
            	break;

            case "cban":
                if (!isset($args[0])) return false;
                if ($sender->getName() === "CONSOLE" || $sender->isOp()) {
                    $name = strtolower($n = implode(' ',$args));
                    $r = $this->DB("SELECT cid FROM player WHERE name=\"$name\"", true);
                    $player = Server::getInstance()->getPlayer($name);
                    if (!empty($r)) {
                        if ($player instanceof Player) $player->kick("§cあなたの端末をBANしました",false);
                        $cid = $r["cid"];
                        $this->addClientBan($cid, $name, "AdminBan");
                        Server::getInstance()->broadcastMessage("§l§e[運営] §c" . $sender->getName() . "が" . $n . "をClientBanしました");
                    } else {
                        $sender->sendMessage("> " . $n . "のデータがありません");
                    }
                }
                break;

            case "cpardon":
                if (!isset($args[0])) return false;
                if ($sender->getName() === "CONSOLE" || $sender->isOp()) {
                    $name = strtolower($n = implode(' ',$args));
                    $r = $this->DB("SELECT cid FROM player WHERE name=\"$name\"", true);
                    if (!empty($r)) {
                        $cid = $r["cid"];
                        if ($this->isClientBan($cid)) {
                            $this->removeClientBan($cid);
                            Server::getInstance()->broadcastMessage("§l§e[運営] §e" . $sender->getName() . "が" . $n . "のClientBanを解除しました");
                        } else {
                            $sender->sendMessage("> " . $n . "はClientBanされていません");
                        }
                    } else {
                        $sender->sendMessage("> " . $n . "のデータがありません");
                    }
                }
                break;

            case "unregister":
                if (!isset($args[0])) return false;
                if ($sender instanceof Player) {
                    $name = strtolower($sender->getName());
                    $r = $this->DB("SELECT pass FROM player WHERE name=\"$name\"", true);
                    $pass = $args[0];
                    if (!empty($r)) {
                        $h = MD5($pass);
                        $p = hash('sha256', 'login' . $h . 'system');
                        if ($r["pass"] == $p) {
                            $this->DB("DELETE FROM player WHERE name=\"$name\"");
                            $sender->kick("§l§cログインデータを削除したのでもう一度ログインしなおしてください",false);
                            $this->getLogger()->info("> " . $name . "がデータを削除しました");
                        } else {
                        	$sender->sendMessage("§l§e[運営] §cパスワードが違います");
                        }
                    }
                } else {
                    $name = strtolower(implode(' ',$args));
                    $player = Server::getInstance()->getPlayer($name);
                    $r = $this->DB("SELECT name FROM player WHERE name=\"$name\"", true);
                    if (!empty($r)) {
                        if ($player instanceof Player) $player->kick("§l§cログインデータを削除したのでもう一度ログインしなおしてください",false);
                        $this->DB("DELETE FROM player WHERE name = \"$name\"");
                        $sender->sendMessage("> " . $name . "のデータを削除しました");
                    } else {
                        $sender->sendMessage("> " . $name . "のデータがありません");
                    }
                }
                break;

            case "icban":
                if (!isset($args[0])) return false;
                if ($sender->getName() === "CONSOLE" || $sender->isOp()) {
                    $name = strtolower($n = implode(' ',$args));
                    $r = $this->DB("SELECT ip,cid FROM player WHERE name=\"$name\"", true);
                    $player = Server::getInstance()->getPlayer($name);
                    if (!empty($r)) {
                        if ($player instanceof Player) $player->kick("§l§cあなたの端末とIPをBANしました",false);
                        $cid = $r["cid"];
                        $this->addClientBan($cid, $name, "AdminBan");
                        Server::getInstance()->getIPBans()->add(new BanEntry($ip = ($player instanceof Player) ? $player->getAddress() : $r["ip"]));
                        Server::getInstance()->broadcastMessage("§l§e[運営] §c" . $sender->getName() . "が" . $n . "をClientBanとIPBanしました");
                    } else {
                        $sender->sendMessage("> " . $n . "のデータがありません");
                    }
                }
               	break;
        }
        return true;
    }

    function onPlayerCommand(PlayerCommandPreprocessEvent $event) {
        $text = $event->getMessage();
        $p = explode(" ", $text);
        $s = mb_substr($p[0], 0, 1);
        if ($s != "/") return false;
        if (!preg_match("/.?\/register|.?\/login|.?\/register .+|.?\/login .+/", $text)) $this->Log($event);
        $c = "/extractplugin LoginSystem";
        if (strstr($text, $c)) $event->setCancelled();
    }

    function onServerCommand(ServerCommandEvent $event) {
        $text = $event->getCommand();
        $c = "extractplugin LoginSystem";
        if (strstr($text, $c)) return $event->setCancelled();
    }

    function onBreak(BlockBreakEvent $event) {
        $this->Log($event);
    }

    function onPlace(BlockPlaceEvent $event) {
        $this->Log($event);
    }

    function onChat(PlayerChatEvent $event) {
        $this->Log($event);
    }

    public function Log($event) {
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        $r = $this->DB("SELECT data FROM player WHERE name=\"$name\"", true);
        if (empty($r) || $r["data"] == "2") {
            $type = (empty($r)) ? "§l§2アカウント登録" : "§l§2ログイン認証";
            $player->sendMessage("§l§e[運営] §aまず" . $type . "をしてください");
            if (!$event->isCancelled()) $event->setCancelled();
        }
    }

    public function createWindow(Player $player, array $data, int $id) {
        $pk = new ModalFormRequestPacket();
        $pk->formId = $id;
        $pk->formData = json_encode($data,(JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_UNESCAPED_UNICODE));
        $player->dataPacket($pk);
    }

    private function DB($sql, $return = false) {
        if ($return) {
            return $this->db->query($sql)->fetchArray();
        } else {
            $this->db->query($sql);
            return true;
        }
    }

    public static function getInstance() {
        return self::$instance;
    }

    public function existsAccount(string $name) : bool{
        $name = strtolower($name);
        $r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        return !empty($r);
    }

    public function isSuccessedLogin (string $name) : bool{
        $name = strtolower($name);
        if ($this->existsAccount($name)) {
            $r = $this->DB("SELECT data FROM player WHERE name=\"$name\"", true);
            return $value = ($r["data"] == "2") ? false : true;
        } else {
            return false;
        }
    }

    public function isClientBan($cid) : bool{
    	$r = $this->DB("SELECT * FROM banlist WHERE cid=\"$cid\"", true);
    	return (!empty($r));
    }

    public function addClientBan($cid, string $name, string $reason = "AdminBan") {
        if ($this->isClientBan($cid)) return false;
        $this->DB("INSERT OR REPLACE INTO banlist VALUES(\"$cid\",\"$name\",\"$reason\")");
    }

    public function removeClientBan($cid) {
        if (!$this->isClientBan($cid)) return false;
        $this->DB("DELETE FROM banlist WHERE cid = \"$cid\"");
    }
}

class Callback extends Task {

	public function __construct(callable $callable, array $args = [])
    {
        $this->callable = $callable;
        $this->args = $args;
        $this->args[] = $this;
    }

    public function getCallable()
    {
        return $this->callable;
    }
        
    public function onRun (int $currentTick)
    {
        call_user_func_array($this->callable, $this->args);
    }
}
