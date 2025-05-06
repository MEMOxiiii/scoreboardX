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

    /**
     * Called when the plugin is enabled
     */
    public function onEnable(): void {
        // Saving instance for API access
        self::$instance = $this;

        // Save the default config.yml with comments
        $this->saveResource("config.yml", false);

        // Log config loading
        $this->getLogger()->info("Loading config.yml...");
        
        // Try to load config.yml
        try {
            $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        } catch (\Exception $e) {
            $this->getLogger()->error("Failed to load config.yml: " . $e->getMessage());
            $this->getLogger()->info("Generating default config.yml...");
            $this->saveResource("config.yml", true);
            $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        }

        // Load scoreboard settings
        $this->scoreboardEnabled = $this->config->getNested("scoreboard.enabled", true);
        $this->scoreboardTitle = $this->config->getNested("scoreboard.title", "§l§6ServerInfo");
        $this->flickerEnabled = $this->config->getNested("scoreboard.flicker", false);
        $this->flickerPeriod = $this->config->getNested("scoreboard.period", 5) * 20; // Convert seconds to ticks
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

        // Log loaded settings for debugging
        $this->getLogger()->info("Scoreboard settings loaded:");
        $this->getLogger()->info("Enabled: " . ($this->scoreboardEnabled ? "true" : "false"));
        $this->getLogger()->info("Multi-world active: " . ($this->multiWorldActive ? "true" : "false"));
        $this->getLogger()->info("Worlds: " . implode(", ", $this->scoreboardWorlds));
        $this->getLogger()->info("Disabled worlds: " . implode(", ", $this->scoreboardDisabledWorlds));

        // Register event listeners
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Schedule a repeating task to update scoreboards
        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private Main $plugin;

            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                $this->plugin->updateScoreboards();
            }
        }, 20); // Update every 1 second (20 ticks)

        // Schedule a task for flickering titles if enabled globally or for any world
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

    /**
     * Called when a player joins the server
     * @param PlayerJoinEvent $event
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->playerWorlds[$player->getName()] = $player->getWorld()->getFolderName();
        $this->playerTitleIndices[$player->getName()] = 0; // Initialize title index for the player
        $this->createScoreboard($player);
    }

    /**
     * Called when a player leaves the server
     * @param PlayerQuitEvent $event
     */
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

    /**
     * Called when a player sends a chat message
     * Replaces placeholders in the chat message
     * @param PlayerChatEvent $event
     */
    public function onPlayerChat(PlayerChatEvent $event): void {
        $message = $event->getMessage();
        // Replace placeholders in chat message
        $message = $this->replacePlaceholders($message);
        $event->setMessage($message);
    }

    /**
     * Creates a scoreboard for a player
     * @param Player $player
     */
    private function createScoreboard(Player $player): void {
        if (!$this->scoreboardEnabled) {
            return;
        }

        $worldName = $player->getWorld()->getFolderName();
        $shouldDisplay = false;

        // Check if the scoreboard should be displayed based on multi-world settings
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

        // Determine the title to use
        $title = $this->scoreboardTitle;
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["title"])) {
            $title = $this->worldScoreboards[$worldName]["title"];
        }

        // Determine if flicker is enabled for this world
        $isFlickerEnabled = $this->multiWorldActive ? false : $this->flickerEnabled;
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker"])) {
            $isFlickerEnabled = $this->worldScoreboards[$worldName]["flicker"];
        }

        // If flicker is enabled, override the title with the current flickering title
        if ($isFlickerEnabled) {
            $titleIndex = $this->playerTitleIndices[$player->getName()] ?? 0;
            if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker_titles"])) {
                $worldFlickerTitles = $this->worldScoreboards[$worldName]["flicker_titles"];
                $title = $worldFlickerTitles[$titleIndex % count($worldFlickerTitles)];
            } else {
                $title = $this->flickerTitles[$titleIndex];
            }
        }

        // Create the scoreboard using SetDisplayObjectivePacket
        $packet = SetDisplayObjectivePacket::create(
            SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
            "server_info_" . $player->getName(),
            $title,
            "dummy",
            SetDisplayObjectivePacket::SORT_ORDER_DESCENDING
        );
        $player->getNetworkSession()->sendDataPacket($packet);

        // Add lines to the scoreboard
        $this->scoreboards[$player->getName()] = true;
        $this->updateScoreboard($player);
    }

    /**
     * Updates the scoreboard for a player
     * @param Player $player
     */
    private function updateScoreboard(Player $player): void {
        if (!$this->scoreboardEnabled || !isset($this->scoreboards[$player->getName()])) {
            return;
        }

        $worldName = $player->getWorld()->getFolderName();
        $lines = $this->scoreboardLines;

        // Use multi-world scoreboard if active and available
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["lines"])) {
            $lines = $this->worldScoreboards[$worldName]["lines"];
        } elseif ($this->multiWorldActive) {
            $this->hideScoreboard($player);
            unset($this->scoreboards[$player->getName()]);
            return;
        }

        // Remove the existing scoreboard to refresh it
        $this->hideScoreboard($player);

        // Determine the title to use
        $title = $this->scoreboardTitle;
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["title"])) {
            $title = $this->worldScoreboards[$worldName]["title"];
        }

        // Determine if flicker is enabled for this world
        $isFlickerEnabled = $this->multiWorldActive ? false : $this->flickerEnabled;
        if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker"])) {
            $isFlickerEnabled = $this->worldScoreboards[$worldName]["flicker"];
        }

        // If flicker is enabled, override the title with the current flickering title
        if ($isFlickerEnabled) {
            $titleIndex = $this->playerTitleIndices[$player->getName()] ?? 0;
            if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker_titles"])) {
                $worldFlickerTitles = $this->worldScoreboards[$worldName]["flicker_titles"];
                $title = $worldFlickerTitles[$titleIndex % count($worldFlickerTitles)];
            } else {
                $title = $this->flickerTitles[$titleIndex];
            }
        }

        // Recreate the scoreboard
        $packet = SetDisplayObjectivePacket::create(
            SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
            "server_info_" . $player->getName(),
            $title,
            "dummy",
            SetDisplayObjectivePacket::SORT_ORDER_DESCENDING
        );
        $player->getNetworkSession()->sendDataPacket($packet);

        // Add lines to the scoreboard with fresh placeholder replacement
        $score = count($lines) - 1; // Start from the highest score
        foreach ($lines as $line) {
            $processedLine = $this->replacePlaceholders($line, $player);
            $this->addLine($player, $score, $processedLine);
            $score--; // Decrease score to maintain order
        }
    }

    /**
     * Adds a line to the player's scoreboard
     * @param Player $player
     * @param int $score
     * @param string $text
     */
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

    /**
     * Hides the scoreboard for a player
     * @param Player $player
     */
    private function hideScoreboard(Player $player): void {
        if (!isset($this->scoreboards[$player->getName()])) {
            return;
        }

        $packet = RemoveObjectivePacket::create("server_info_" . $player->getName());
        $player->getNetworkSession()->sendDataPacket($packet);
    }

    /**
     * Updates all players' scoreboards
     */
    public function updateScoreboards(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $playerName = $player->getName();
            $currentWorld = $player->getWorld()->getFolderName();

            // Update player's current world
            $this->playerWorlds[$playerName] = $currentWorld;

            // Update scoreboard visibility and content
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

    /**
     * Updates the flickering titles for all scoreboards
     */
    public function updateFlickerTitles(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if (!isset($this->scoreboards[$player->getName()])) {
                continue;
            }

            $playerName = $player->getName();
            $worldName = $player->getWorld()->getFolderName();

            // Determine if flicker is enabled for this world
            $isFlickerEnabled = $this->multiWorldActive ? false : $this->flickerEnabled;
            if ($this->multiWorldActive && isset($this->worldScoreboards[$worldName]["flicker"])) {
                $isFlickerEnabled = $this->worldScoreboards[$worldName]["flicker"];
            }

            if ($isFlickerEnabled) {
                // Increment the title index for this player
                $this->playerTitleIndices[$playerName] = ($this->playerTitleIndices[$playerName] ?? 0) + 1;
                $this->hideScoreboard($player);
                $this->createScoreboard($player);
            }
        }
    }

    /**
     * Replaces placeholders in a string with actual values
     * @param string $text
     * @param Player|null $player
     * @return string
     */
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

    /**
     * Returns the instance of this plugin
     * @return Main
     */
    public static function getInstance(): Main {
        return self::$instance;
    }

    /**
     * Returns the server ID from config
     * @return string
     */
    public function getServerId(): string {
        return $this->config->get("server-id", "12345");
    }

    /**
     * Returns the server date and time in a formatted string
     * @return string
     */
    public function getTimeServerInfo(): string {
        // Full date-time for compatibility
        return date("Y-m-d H:i:s");
    }

    /**
     * Handles commands sent by players
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "wai") {
            $serverId = $this->config->get("server-id");
            $serverType = $this->config->get("server-type");
            $serverDescription = $this->config->get("server-description");
            $proxyNetwork = $this->config->get("proxy-network");
            
            // Getting current date and time
            $dateTime = date("Y-m-d H:i:s");
            
            // Getting server ping (approximate, based on server TPS)
            $ping = round(1000 / max(1, $this->getServer()->getTicksPerSecondAverage()), 2) . " ms";

            // Sending formatted message to the player (lobbyid not included)
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