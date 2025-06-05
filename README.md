# ScoreboardX

**ScoreboardX** is a powerful and customizable PocketMine-MP plugin designed to display server information and a dynamic scoreboard for players. With support for multi-world scoreboards, flickering titles, and placeholder replacements, this plugin offers a seamless way to keep players informed about server details such as player count, ping, time, and more.

## Features
- Display server information with the `/wai` command (e.g., server ID, type, description, ping, etc.).
- Customizable scoreboard with support for multi-world configurations.
- Dynamic placeholder replacement (e.g., `%playername%`, `%onlineplayers%`, `%ping%`, `%serverinfo_time%`).
- Per-world scoreboard titles with optional flickering titles for a dynamic look.
- Toggle the scoreboard on or off using the `/scoreboard toggle` command (for operators).
- Lightweight and optimized for performance.

## Installation
1. **Download the Plugin**:
   - Download the latest `.phar` file from the [releases page](#) or compile it from the source code.

2. **Install the Plugin**:
   - Place the `ScoreboardPE.phar` file into the `plugins/` folder of your PocketMine-MP server.

3. **Restart the Server**:
   - Restart your server to load the plugin. A `config.yml` file will be generated in the `plugins/ScoreboardPE/` folder.

4. **Configure the Plugin**:
   - Open `plugins/ScoreboardPE/config.yml` and customize the settings as needed (see the [Configuration](#configuration) section for details).

## Configuration
The plugin generates a `config.yml` file in the `plugins/ScoreboardPE/` folder upon first run. This file contains detailed comments explaining each setting. Below is a summary of the main sections:

- **Server Information**:
  - `server-id`: Unique ID of your server.
  - `server-type`: Type of your server (e.g., Survival, Creative).
  - `server-description`: A short description of your server.
  - `proxy-network`: Proxy network address (if applicable).
  - `lobby-id`: Lobby ID for use in scoreboard placeholders.

- **Scoreboard Settings**:
  - `enabled`: Enable or disable the scoreboard (`true`/`false`).
  - `title`: Default title of the scoreboard.
  - `flicker`: Enable flickering titles for the default scoreboard (`true`/`false`).
  - `period`: Time (in seconds) between title changes if flicker is enabled.
  - `titles`: List of titles to cycle through if flicker is enabled.
  - `worlds`: Worlds where the scoreboard should be displayed (if multi-world is disabled).
  - `disabled_worlds`: Worlds where the scoreboard should not be displayed.
  - `lines`: Default scoreboard lines (used if multi-world is disabled).

- **Multi-World Settings**:
  - `multi_world.active`: Enable multi-world scoreboards (`true`/`false`).
  - `scoreboards`: Define custom scoreboards for each world, including:
    - `title`: Custom title for the world's scoreboard.
    - `flicker`: Enable flickering titles for this world (`true`/`false`).
    - `flicker_titles`: List of titles to cycle through if flicker is enabled.
    - `lines`: Custom lines for the world's scoreboard.

### Available Placeholders
- `%playername%`: The player's name.
- `%onlineplayers%`: Number of online players.
- `%maxplayers%`: Maximum number of players.
- `%lobbyid%`: Lobby ID from server settings.
- `%ping%`: Player's ping in milliseconds.
- `%idserver%`: Server ID from server settings.
- `%serverinfo_time%`: Current time (HH:MM:SS).
- `%serverinfo_date%`: Current date (YYYY-MM-DD).
- `%serverinfo_datetime%`: Current date and time (YYYY-MM-DD HH:MM:SS).

## Usage
### Commands
- `/wai`:
  - Displays server information (e.g., server ID, type, description, ping, etc.).
  - Permission: `serverinfo.wai` (default: true for all players).

- `/scoreboard toggle`:
  - Toggles the scoreboard on or off (server-wide).
  - Permission: `serverinfo.scoreboard` (default: op only).

### Examples
- **Display a Scoreboard in a Specific World**:
  - Set `multi_world.active` to `true` in `config.yml`.
  - Configure the `scoreboards` section to define a scoreboard for your world:
    ```yaml
    scoreboards:
      world:
        title: "§l§bWorld 1"
        flicker: false
        flicker_titles:
          - "§l§bWorld 1"
          - "§l§aWorld 1"
        lines:
          - "§l§bPlayer: §f%playername%"
          - "§l§aOnline: §f%onlineplayers% §7/ §f%maxplayers%"
    ```

- **Enable Flickering Titles for a World**:
  - In the `scoreboards` section, set `flicker` to `true` for the desired world:
    ```yaml
    scoreboards:
      world2:
        title: "§l§cWorld 2"
        flicker: true
        flicker_titles:
          - "§l§cWorld 2"
          - "§l§eWorld 2"
          - "§l§aWorld 2"
          - "§l§bWorld 2"
        lines:
          - "§l§bPlayer: §f%playername%"
          - "§l§cLobby: §f%lobbyid%"
    ```



## License
This plugin is licensed under the [MIT License](LICENSE). Feel free to use, modify, and distribute it as per the license terms.

## Credits
- **Author**: MEMOxiiii
