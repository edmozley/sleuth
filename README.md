# Sleuth

[![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)](https://mysql.com)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)](#docker)
[![Claude AI](https://img.shields.io/badge/Claude-Anthropic-D4A574?logo=anthropic&logoColor=white)](https://anthropic.com)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

An AI-powered murder mystery game. Every case is procedurally generated — unique plots, characters, locations, clues, and artwork — all created on the fly by AI. Explore crime scenes, interrogate suspects, collect evidence, and make your accusation before the trail goes cold.

## How it works

Each game generates a complete murder mystery from scratch:

- **Plot** — a victim, a killer, a motive, and a backstory
- **Locations** — interconnected rooms and areas forming an explorable map
- **Characters** — suspects, witnesses, and bystanders with distinct personalities
- **Objects** — items to find, inspect, and collect as evidence
- **Clues** — hidden throughout conversations, locations, and objects
- **Artwork** — AI-generated images for every location, character, and object

You investigate by typing natural-language commands — move between rooms, search for evidence, talk to characters, and piece together what happened. The AI interprets your actions and drives the story forward.

You get 5 probes (hard interrogation presses) and 3 accusations per game. Run out of accusations and the case goes cold.

## Stack

- **Backend:** PHP (no framework)
- **Frontend:** Vanilla JavaScript + CSS
- **Database:** MySQL
- **AI:** Claude (Anthropic) for all game logic and dialogue
- **Images:** OpenAI DALL-E 3 or Venice.ai (configurable)
- **Music:** Freesound API for ambient audio

## Quick start with Docker

The fastest way to get running:

```bash
git clone https://github.com/edmozley/sleuth.git
cd sleuth
docker compose up -d
```

Then visit [http://localhost:8080/dbverify.php](http://localhost:8080/dbverify.php) to set up the database tables, and [http://localhost:8080/settings.php](http://localhost:8080/settings.php) to add your API keys.

The database and generated artwork are stored in Docker volumes so they persist across restarts.

## Manual setup

If you prefer to run without Docker (e.g. on WAMP, XAMPP, or MAMP):

1. **Clone the repo** into your web server's document root:
   ```
   git clone https://github.com/edmozley/sleuth.git
   ```

2. **Create the config file** by copying the example:
   ```
   cp config.example.json config.json
   ```
   Edit `config.json` with your MySQL credentials.

3. **Create the database:**
   ```sql
   CREATE DATABASE sleuth CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. **Run the database migration** by visiting:
   ```
   http://localhost/sleuth/dbverify.php
   ```
   This automatically creates all required tables and columns.

5. **Add your API keys** by visiting:
   ```
   http://localhost/sleuth/settings.php
   ```
   At minimum you need an Anthropic API key. Image and music keys are optional but recommended.

6. **Create a profile** and start playing at:
   ```
   http://localhost/sleuth/
   ```

## API keys

| Service | Required | Purpose |
|---------|----------|---------|
| [Anthropic (Claude)](https://console.anthropic.com/) | Yes | Plot generation, character dialogue, action resolution |
| [OpenAI (DALL-E 3)](https://platform.openai.com/api-keys) | No | Artwork for locations, characters, and objects |
| [Venice.ai](https://venice.ai/settings/api) | No | Alternative image provider (cheaper) |
| [Freesound](https://freesound.org/apiv2/apply/) | No | Ambient music matched to each game's setting |

The game works without image or music APIs — you just won't get artwork or ambient audio.

## Project structure

```
sleuth/
├── api/                    # PHP API endpoints
│   ├── generate.php        # Multi-step game generation
│   ├── action.php          # Process player actions
│   ├── chat.php            # Character dialogue
│   ├── accuse.php          # Submit accusations
│   ├── state.php           # Fetch full game state
│   ├── music.php           # Ambient music search
│   ├── generate_image.php  # Artwork generation
│   └── ...
├── assets/
│   ├── css/game.css        # All styles
│   ├── js/
│   │   ├── game.js         # Game client
│   │   ├── home.js         # Home screen
│   │   └── motive-icons.js # SVG icons for motive categories
│   ├── covers/             # Generated cover images (gitignored)
│   └── images/             # Generated game artwork (gitignored)
├── includes/
│   ├── Database.php        # DB connection & config
│   ├── Auth.php            # Session-based profile auth
│   └── Claude.php          # Anthropic API wrapper
├── index.php               # Home screen
├── game.php                # Game UI
├── profiles.php            # Profile picker
├── settings.php            # Configuration page
├── debug.php               # Game inspector (spoilers!)
├── dbverify.php            # Auto-migration tool
├── help.php                # In-app help guide
├── docker-compose.yml      # Docker setup
├── Dockerfile              # PHP + Apache container
├── config.json             # DB credentials (gitignored)
└── config.example.json     # Template config
```

## Features

- **Natural language interaction** — type anything, the AI figures out what you mean
- **Dynamic characters** — each has a personality, trust level, and emotional state that changes as you interact
- **Probe system** — limited-use hard interrogation for breaking through lies
- **Interactive map** — fullscreen visual map with floor separation and clickable nodes
- **Inventory & evidence** — collect objects, inspect them, use them in your accusation
- **Notebook** — automatic clue tracking with source attribution
- **Ambient music** — setting-appropriate audio sourced from Freesound
- **Multi-profile** — Netflix-style profile switching, game cloning between profiles
- **Custom mysteries** — describe a setting and theme, the AI builds it
- **Full artwork** — AI-generated images for locations, characters, objects, and covers

## License

[GNU General Public License v3.0](LICENSE)
