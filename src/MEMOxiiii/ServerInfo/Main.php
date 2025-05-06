<?php

namespace MEMOxiiii\ServerInfo;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\scheduler\Task;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;

class Main extends PluginBase implements Listener {

    private Config $config;
    private static Main $instance;
    private bool $scoreboardEnabled;
    private array $scoreboardWorlds;
    private array $scoreboardDisabledWorlds;
    private array $scoreboardLines;
    private string $scoreboardTitle;
    private array $playerWorlds = [];
    private array $scoreboards = [];
    private bool $flickerEnabled;
    private int $flickerPeriod;
    private array $flickerTitles;
    private array $playerTitleIndices = [];
    private bool $multiWorldActive;
    private array $worldScoreboards;

    public function onEnable(): void {
        self::$instance = $this;

        $this->saveResource("config.yml", false);

        $this->getLogger()->info("Loading config.yml...");
        
        try {
            $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        } catch (\Exception $e) {
            $this->getLogger()->error("Failed to load config.yml: " . $e->getMessage());
            $this->getLogger()->info("Generating default config.yml...");
            $this->saveResource("config.yml", true);
            $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        }

        $this->scoreboardEnabled = $this->config->getNested("scoreboard.enabled", true);
        $this->scoreboardTitle = $this->config->getNested("scoreboard.title", "§l§6ServerInfo");
        $this->flickerEnabled = $this->config->getNested("scoreboard.flicker", false);
        $this->flickerPeriod = $this->config->getNested("scoreboard.period", 5) * 20;
        $this->flickerTitles = $this->config->getNested("scoreboard.titles", [
            "§l§6ServerInfo",
            "§l§eServerInfo",
            "§l§aServerInfo",
            "§l§bServerInfo"
        ]);
        $this->scoreboardWorlds = $this->config->getNested("scoreboard.worlds", ["world"]);
        $this->scoreboardDisabledWorlds = $this->config->getNested("scoreboard.disabled_worlds", []);
        $this->scoreboardLines = $this->config->getNested("scoreboard.lines", [
            "",
            "§l§bPlayer: §f%playername%",
            "",
            "§l§aOnline: §f%onlineplayers% §7/ §f%maxplayers%",
            "§l§cLobby: §f%lobbyid%",
            "§l§ePing: §f%ping%",
            "",
            "§l§6Server: §f%idserver%",
            "§l§eTime: §f%serverinfo_time%",
            "§l§eDate: §f%serverinfo_date%"
        ]);
        $this->multiWorldActive = $this->config->getNested("scoreboard.multi_world.active", false);
        $this->worldScoreboards = $this->config->getNested("scoreboard.scoreboards", []);

        $this->getLogger()->info("Scoreboard settings loaded:");
        $this->getLogger()->info("Enabled: " . ($this->scoreboardEnabled ? "true" : "false"));
        $this->getLogger()->info("Multi-world active: " . ($this->multiWorldActive ? "true" : "false"));
        $this->getLogger()->info("Worlds: " . implode(", ", $this->scoreboardWorlds));
        $this->getLogger()->info("Disabled worlds: " . implode(", ", $this->scoreboardDisabledWorlds));

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private Main $plugin;

            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                $this->plugin->updateScoreboards();
            }
        }, 20);

        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private Main $plugin;

            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                $this->plugin->updateFlickerTitles();
            }
        }, $this->flickerPeriod);
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->playerWorlds[$player->getName()] = $player->getWorld()->getFolderName();
        $this->playerTitleIndices[$player->getName()] = 0; 
        $this->createScoreboard($player);
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        if (isset($this->scoreboards[$playerName])) {
            $this->hideScoreboard($player);
            unset($this->scoreboards[$playerName]);
        }
        if (isset($this->playerWorlds[$playerName])) {
            unset($this->playerWorlds[$playerName]);
        }
        if (isset($this->playerTitleIndices[$playerName])) {
            unset($this->playerTitleIndices[$playerName]);
        }
    }

    public function onPlayerChat(PlayerChatEvent $event): void {
        $message = $event->getMessage();
        $message = $this->replacePlaceholders($message);
        $event->setMessage($message);
    }

    private function createScoreboard(Player $player): void {
        if (!$this->scoreboardEnabled) {
            return;
        }

        $worldName = $player->getWorld()->getFolderName();
        $shouldDisplay = false;

        if ($this->multiWorldActive) {
            if (isset($this->worldScoreboards[$worldName]["lines"])) {
                $shouldDisplay = true;
            }
        } else {
            if (in_array($worldName, $this->scoreboardWorlds) && !in_array($worldName, $this->scoreboardDisabledWorlds)) {
                $shouldDisplay = true;
            }
        }

        if (!$shouldDisplay) {
            $this->getLogger()->debug("Not displaying scoreboard for player " . $player->getName() . " in world " . $worldName);
            return;
        }

        $title = $this->scoreboardTitle;
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["title"])) {
            $title = $this->worldScoreboards[$worldName]["title"];
        }

        $isFlickerEnabled = $this->multiWorldActive ? false : $this->flickerEnabled;
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker"])) {
            $isFlickerEnabled = $this->worldScoreboards[$worldName]["flicker"];
        }

        if ($isFlickerEnabled) {
            $titleIndex = $this->playerTitleIndices[$player->getName()] ?? 0;
            if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker_titles"])) {
                $worldFlickerTitles = $this->worldScoreboards[$worldName]["flicker_titles"];
                $title = $worldFlickerTitles[$titleIndex % count($worldFlickerTitles)];
            } else {
                $title = $this->flickerTitles[$titleIndex];
            }
        }

        $packet = SetDisplayObjectivePacket::create(
            SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
            "server_info_" . $player->getName(),
            $title,
            "dummy",
            SetDisplayObjectivePacket::SORT_ORDER_DESCENDING
        );
        $player->getNetworkSession()->sendDataPacket($packet);

        $this->scoreboards[$player->getName()] = true;
        $this->updateScoreboard($player);
    }

    private function updateScoreboard(Player $player): void {
        if (!$this->scoreboardEnabled || !isset($this->scoreboards[$player->getName()])) {
            return;
        }

        $worldName = $player->getWorld()->getFolderName();
        $lines = $this->scoreboardLines;

        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["lines"])) {
            $lines = $this->worldScoreboards[$worldName]["lines"];
        } elseif ($this->multiWorldActive) {
            $this->hideScoreboard($player);
            unset($this->scoreboards[$player->getName()]);
            return;
        }

        $this->hideScoreboard($player);

        $title = $this->scoreboardTitle;
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["title"])) {
            $title = $this->worldScoreboards[$worldName]["title"];
        }

        $isFlickerEnabled = $this->multiWorldActive ? false : $this->flickerEnabled;
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker"])) {
            $isFlickerEnabled = $this->worldScoreboards[$worldName]["flicker"];
        }

        if ($isFlickerEnabled) {
            $titleIndex = $this->playerTitleIndices[$player->getName()] ?? 0;
            if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker_titles"])) {
                $worldFlickerTitles = $this->worldScoreboards[$worldName]["flicker_titles"];
                $title = $worldFlickerTitles[$titleIndex % count($worldFlickerTitles)];
            } else {
                $title = $this->flickerTitles[$titleIndex];
            }
        }

        $packet = SetDisplayObjectivePacket::create(
            SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
            "server_info_" . $player->getName(),
            $title,
            "dummy",
            SetDisplayObjectivePacket::SORT_ORDER_DESCENDING
        );
        $player->getNetworkSession()->sendDataPacket($packet);

        $score = count($lines) - 1; 
        foreach ($lines as $line) {
            $processedLine = $this->replacePlaceholders($line, $player);
            $this->addLine($player, $score, $processedLine);
            $score--; 
        }
    }


    private function addLine(Player $player, int $score, string $text): void {
        $entry = new ScorePacketEntry();
        $entry->objectiveName = "server_info_" . $player->getName();
        $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
        $entry->customName = $text;
        $entry->score = $score;
        $entry->scoreboardId = $score;

        $packet = SetScorePacket::create(SetScorePacket::TYPE_CHANGE, [$entry]);
        $player->getNetworkSession()->sendDataPacket($packet);
    }

    private function hideScoreboard(Player $player): void {
        if (!isset($this->scoreboards[$player->getName()])) {
            return;
        }

        $packet = RemoveObjectivePacket::create("server_info_" . $player->getName());
        $player->getNetworkSession()->sendDataPacket($packet);
    }

    public function updateScoreboards(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $playerName = $player->getName();
            $currentWorld = $player->getWorld()->getFolderName();

            $this->playerWorlds[$playerName] = $currentWorld;

            $shouldDisplay = false;
            if ($this->multiWorldActive) {
                if (isset($this->worldScoreboards[$currentWorld]["lines"])) {
                    $shouldDisplay = true;
                }
            } else {
                if (in_array($currentWorld, $this->scoreboardWorlds) && !in_array($currentWorld, $this->scoreboardDisabledWorlds)) {
                    $shouldDisplay = true;
                }
            }

            if ($this->scoreboardEnabled && $shouldDisplay) {
                if (!isset($this->scoreboards[$playerName])) {
                    $this->createScoreboard($player);
                } else {
                    $this->updateScoreboard($player);
                }
            } else {
                if (isset($this->scoreboards[$playerName])) {
                    $this->hideScoreboard($player);
                    unset($this->scoreboards[$playerName]);
                }
            }
        }
    }

    public function updateFlickerTitles(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if (!isset($this->scoreboards[$player->getName()])) {
                continue;
            }

            $playerName = $player->getName();
            $worldName = $player->getWorld()->getFolderName();

            $isFlickerEnabled = $this->multiWorldActive ? false : $this->flickerEnabled;
            if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker"])) {
                $isFlickerEnabled = $this->worldScoreboards[$worldName]["flicker"];
            }

            if ($isFlickerEnabled) {
                $this->playerTitleIndices[$playerName] = ($this->playerTitleIndices[$playerName] ?? 0) + 1;
                $this->hideScoreboard($player);
                $this->createScoreboard($player);
            }
        }
    }

    public function replacePlaceholders(string $text, ?Player $player = null): string {
        $placeholders = [
            "%idserver%" => $this->getServerId(),
            "%serverinfo_datetime%" => $this->getTimeServerInfo(),
            "%serverinfo_time%" => date("H:i:s"),
            "%serverinfo_date%" => date("Y-m-d"),
            "{idserver}" => $this->getServerId(),
            "{serverinfo_datetime}" => $this->getTimeServerInfo(),
            "%playername%" => $player ? $player->getName() : "Unknown",
            "%onlineplayers%" => count($this->getServer()->getOnlinePlayers()),
            "%maxplayers%" => $this->getServer()->getMaxPlayers(),
            "%lobbyid%" => $this->config->get("lobby-id", "lobby_001"),
            "%ping%" => round(1000 / max(1, $this->getServer()->getTicksPerSecondAverage()), 2) . " ms"
        ];

        return str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $text
        );
    }

    public static function getInstance(): Main {
        return self::$instance;
    }

    public function getServerId(): string {
        return $this->config->get("server-id", "12345");
    }


    public function getTimeServerInfo(): string {
        return date("Y-m-d H:i:s");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "wai") {
            $serverId = $this->config->get("server-id");
            $serverType = $this->config->get("server-type");
            $serverDescription = $this->config->get("server-description");
            $proxyNetwork = $this->config->get("proxy-network");
            
            $dateTime = date("Y-m-d H:i:s");
            
            $ping = round(1000 / max(1, $this->getServer()->getTicksPerSecondAverage()), 2) . " ms";

            $message = TextFormat::GOLD . "=== Server Information ===\n" .
                       TextFormat::YELLOW . "Server ID: " . TextFormat::WHITE . $serverId . "\n" .
                       TextFormat::YELLOW . "Server Type: " . TextFormat::WHITE . $serverType . "\n" .
                       TextFormat::YELLOW . "Description: " . TextFormat::WHITE . $serverDescription . "\n" .
                       TextFormat::YELLOW . "Proxy Network: " . TextFormat::WHITE . $proxyNetwork . "\n" .
                       TextFormat::YELLOW . "Date & Time: " . TextFormat::WHITE . $dateTime . "\n" .
                       TextFormat::YELLOW . "Ping: " . TextFormat::WHITE . $ping;

            $sender->sendMessage($message);
            return true;
        }

        if ($command->getName() === "scoreboard") {
            if (!isset($args[0]) || $args[0] !== "toggle") {
                $sender->sendMessage(TextFormat::RED . "Usage: /scoreboard toggle");
                return true;
            }

            $this->scoreboardEnabled = !$this->scoreboardEnabled;
            $this->config->setNested("scoreboard.enabled", $this->scoreboardEnabled);
            $this->config->save();
            $sender->sendMessage(TextFormat::GREEN . "Scoreboard has been " . ($this->scoreboardEnabled ? "enabled" : "disabled") . "!");
            $this->updateScoreboards();
            return true;
        }

        return false;
    }
}
