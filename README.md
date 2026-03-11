# Sleuth

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

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- A web server (Apache/Nginx) or WAMP/XAMPP/MAMP
- An [Anthropic API key](https://console.anthropic.com/) (required)
- An [OpenAI API key](https://platform.openai.com/api-keys) or [Venice.ai API key](https://venice.ai/settings/api) (optional, for artwork)
- A [Freesound API key](https://freesound.org/apiv2/apply/) (optional, for ambient music)

## Setup

1. **Clone the repo** into your web server's document root:
   ```
   git clone https://github.com/yourusername/sleuth.git
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

MIT
