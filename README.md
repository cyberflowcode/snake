# 🐍 Snake Arena — Real-Time Multiplayer Web Game (Laravel + Reverb + MySQL + Railway)

A high-performance, server-authoritative browser-based multiplayer Snake Arena game inspired by **Slither.io**, featuring dark charcoal hexagonal grid visuals, 3D segmented snakes with expressive cartoon eyes, glowing food particles, private rooms, live leaderboards, and real-time WebSocket synchronization.

---

## 🎨 Visual Reference & Gameplay Features

- **Hexagonal Charcoal Arena**: Dark charcoal background with procedurally generated embossed hexagonal grid patterns.
- **Segmented Snakes & 3D Shading**: Spherical gradient ribbing across body segments with distinct player colors (cyan, yellow, red, purple, green, blue).
- **Cartoon Directional Eyes**: Large cartoon eyes with pupils that dynamically shift to track the snake's actual heading direction.
- **Dynamic Glow Food & Death Drops**: Small glowing orbs scattered across the arena; eliminated snakes drop trails of high-value energy orbs along their body path.
- **Top-Right Live Leaderboard**: Real-time rankings showing top 10 players, scores, and colored rank badges (#1, #2, #3...).
- **Lower-Left Player HUD**: Instant display of current snake Length, Rank (e.g. `2 of 6`), and total Score.
- **Bottom-Right Circular Radar**: Anti-aliased radar minimap rendering arena borders and player locations.
- **Smooth 60 FPS Interpolation**: Client-side linear interpolation (`lerp`) provides silky smooth movement between authoritative server ticks (8–12 Hz).
- **Controls**:
  - Keyboard: Arrow keys / WASD to steer, Spacebar to boost.
  - Mouse: Cursor steering with Left-Click boost.
  - Mobile: On-screen touch virtual analog joystick and tactile Boost button.

---

## 🏗️ Architecture & Server-Authoritative Simulation

1. **Server-Authoritative Game Engine (`App\Services\SnakeSimulationService`)**:
   - The browser never dictates coordinates, scores, or collisions.
   - Clients send only validated target angles and boost states.
   - Server runs the simulation at configurable tick rate (default: `10 ticks/sec`):
     - **Wall Collisions**: Eliminates snakes contacting arena borders.
     - **Body Collisions**: Eliminates any snake whose head contacts another snake's body.
     - **Self-Collisions**: Prevents self-cannibalization while allowing natural tight loops.
     - **Simultaneous Head-on Collisions**: Eliminates both colliding snakes simultaneously.
     - **Tail Motion Handling**: Accurately accounts for contracting tail segments during movement ticks.
     - **Reversal Protection**: Clamps angular change to `MAX_TURN_PER_TICK` (0.38 rad) so players cannot 180° reverse into their neck.
     - **Safe Food Placement**: Ensures replenished food never spawns directly atop an active snake.
2. **Private Rooms & Guest Identity**:
   - Rooms identified by cryptographically random 6-character codes (e.g. `K9X2P8`).
   - Guest players enter nicknames without registration; access is guarded by 256-bit cryptographically secure session tokens.
   - Plaintext tokens are **never** stored in the database; only SHA-256 hashes (`token_hash`) are persisted.
   - Database transactions and pessimistic row locks prevent race conditions and overfilled rooms.
3. **WebSockets & Laravel Reverb (`App\Events\*`)**:
   - Events broadcast to authenticated private channels: `private-room.{roomCode}`.
   - Channel authorization verifies guest token validity before allowing subscription.
   - Events:
     - `game.tick` (`GameStateUpdated`): Authoritative positions, lengths, foods, and eliminations.
     - `player.joined` (`PlayerJoined`): New lobby participant notification.
     - `player.left` (`PlayerLeft`): Member departure notification.
     - `game.started` (`GameStarted`): Initiates 3-second countdown and match.
     - `game.over` (`GameOver`): Final scoreboard and winner proclamation.
     - `room.state` (`RoomStateChanged`): Real-time lobby state updates.
   - Adaptive client includes transparent fallback polling (300ms) if WebSockets disconnect.

---

## 📂 Project Structure

```
├── app/
│   ├── Console/Commands/
│   │   └── SnakeGameLoopCommand.php      # Authoritative game loop worker (php artisan snake:game-loop)
│   ├── Events/
│   │   ├── GameOver.php                  # Final results & scoreboard broadcast
│   │   ├── GameStarted.php               # Match start broadcast
│   │   ├── GameStateUpdated.php          # 10 Hz authoritative tick broadcast
│   │   ├── PlayerJoined.php              # Lobby join event
│   │   ├── PlayerLeft.php                # Lobby leave event
│   │   └── RoomStateChanged.php          # Lobby update event
│   ├── Http/Controllers/
│   │   └── GameRoomController.php        # API endpoints, direction inputs & channel auth
│   ├── Models/
│   │   ├── Room.php                      # Private room model & code generator
│   │   ├── Player.php                    # Guest player model & token hashing
│   │   ├── MatchRecord.php               # Match history records
│   │   └── Leaderboard.php               # Persistent high scores & statistics
│   └── Services/
│       ├── SnakeSimulationService.php    # Server-authoritative physics & collision engine
│       └── GameRoomService.php           # Room lifecycle, guests, rematches & results
├── config/
│   ├── broadcasting.php                  # Reverb and Pusher broadcast connections
│   ├── reverb.php                        # Laravel Reverb WebSocket server configuration
│   └── game.php                          # Configurable tick rate, arena dimensions, food counts
├── database/migrations/
│   ├── 2026_10_18_000001_create_rooms_table.php
│   ├── 2026_10_18_000002_create_players_table.php
│   ├── 2026_10_18_000003_create_matches_table.php
│   └── 2026_10_18_000004_create_leaderboards_table.php
├── resources/
│   ├── css/game.css                      # Modern dark theme & Slither HUD styles
│   ├── js/game.js                        # Canvas 2D engine, interpolation & controls
│   └── views/game.blade.php              # Main responsive web interface
├── tests/
│   ├── Unit/SnakeSimulationServiceTest.php # 10 pure simulation & physics tests
│   └── Feature/GameRoomApiTest.php       # 8 feature & authorization tests
├── Dockerfile                            # Production multi-stage container build
├── docker-entrypoint.sh                  # Railway multi-service entrypoint coordinator
├── railway.toml                          # Railway service orchestration configuration
└── .env.example                          # Complete deployment environment template
```

---

## 🚀 Local Development Setup

### 1. Requirements
- PHP 8.3+
- MySQL 5.7+ or 8.0+
- Composer 2.x

### 2. Configure Environment & Database
Create the MySQL database:
```sql
CREATE DATABASE IF NOT EXISTS snake CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Copy configuration:
```bash
cp .env.example .env
php artisan key:generate
```

Run database migrations:
```bash
php artisan migrate
```

### 3. Run Automated Test Suite
Execute the unit and feature tests:
```bash
vendor/bin/phpunit
```
*(All 18 tests execute covering movement, collisions, reversals, room capacity, and guest authorization).*

### 4. Start the Application & Game Loop

In **Terminal 1** (Web Application):
```bash
php artisan serve --port=8000
```

In **Terminal 2** (Dedicated Game Loop Worker):
```bash
php artisan snake:game-loop --fps=10
```

Open `http://localhost:8000` in your browser.

---

## 👥 How to Test a Multiplayer Match with 2 Browser Sessions

1. Open **Browser Window 1** (e.g. Chrome) at `http://localhost:8000`.
2. Enter nickname `Viper1`, pick a color (e.g. **Cyan**), and click **Create Private Room**.
3. You will enter the **Arena Lobby** with a generated 6-character Room Code (e.g. `ABC123`).
4. Click the **Copy** button in the top-left HUD or copy the code from the lobby.
5. Open **Browser Window 2** (e.g. Chrome Incognito or Firefox) at `http://localhost:8000`.
6. Enter nickname `Viper2`, pick a different color (e.g. **Yellow**), paste the Room Code, and click **Join Existing Arena**.
7. Both players now appear in the Lobby player list in real time.
8. In Browser 1 (the Host), click **🚀 Start Match Now**.
9. Both screens display the **3... 2... 1... GO!** countdown overlay and spawn into the arena simultaneously.
10. Steer your snakes with **Arrow Keys / WASD / Mouse**. Hold **Spacebar** to boost. Collect glowing food to grow.
11. Steer one snake's head into the body of the other: the attacker is instantly eliminated, drops glowing food particles, and the survivor wins with the Game Over scoreboard displayed!
12. Click **Request Rematch** to play again immediately.

---

## ☁️ Railway Deployment Instructions

Deploying on Railway requires 4 services within the same Railway project:

### Service 1: MySQL Database
1. In your Railway project, click **New Service** -> **Database** -> **MySQL**.
2. Railway creates the database and populates variables: `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`, and `MYSQL_URL`.

### Service 2: Laravel Web Application (`web`)
1. Add a service from your Git repository.
2. Under **Settings**:
   - **Build Command**: Uses `Dockerfile`.
   - **Start Command**: `docker-entrypoint.sh web`
3. Under **Variables**, add:
   ```env
   APP_NAME="Snake Arena"
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:your-generated-app-key
   APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
   
   DB_CONNECTION=mysql
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_DATABASE=${{MySQL.MYSQLDATABASE}}
   DB_USERNAME=${{MySQL.MYSQLUSER}}
   DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
   
   BROADCAST_DRIVER=reverb
   CACHE_DRIVER=file
   SESSION_DRIVER=file
   
   REVERB_APP_ID=snake-app-id
   REVERB_APP_KEY=snake-app-key
   REVERB_APP_SECRET=snake-app-secret
   
   REVERB_SERVER_HOST=0.0.0.0
   REVERB_SERVER_PORT=8080
   REVERB_HOST=${{Reverb.RAILWAY_PUBLIC_DOMAIN}}
   REVERB_PORT=443
   REVERB_SCHEME=https
   ```
4. Generate a public domain under **Networking** (e.g. `snake-arena.up.railway.app`).

### Service 3: Laravel Reverb WebSocket Server (`reverb`)
1. In Railway, click **New Service** -> Deploy from same Git repo.
2. Under **Settings**:
   - **Start Command**: `docker-entrypoint.sh reverb`
3. Under **Variables**, link the same environment variables as the Web service (`REVERB_APP_ID`, `REVERB_APP_KEY`, etc.).
4. Under **Networking**, generate a public domain for the Reverb service (e.g. `snake-reverb.up.railway.app`) and set **Port** to `8080`.

### Service 4: Dedicated Game Loop Worker (`worker`)
1. Click **New Service** -> Deploy from same Git repo.
2. Under **Settings**:
   - **Start Command**: `docker-entrypoint.sh worker`
3. Connect the same database variables and set:
   ```env
   GAME_TICK_RATE=10
   GAME_ARENA_WIDTH=2400
   GAME_ARENA_HEIGHT=2400
   ```
4. The worker automatically acquires an atomic lock to guarantee exactly one game-loop engine processes active rooms at 10 ticks per second.

---

## 🛡️ Security & Integrity

- **Server Authority**: Client movement is restricted to delta-angle validation. Direct coordinate teleportation is impossible.
- **XSS Prevention**: Nicknames are sanitized on the server and escaped in the DOM using safe text content manipulation.
- **Session Protection**: Guest credentials use 256-bit cryptographically secure random bytes; tokens are stored only as SHA-256 hashes.
- **Rate Limiting**: Throttles room creation, joining, and direction inputs (`throttle:30,1` and `throttle:180,1`).
- **Channel Access Control**: Only guests with verified tokens matching the target room ID can authorize WebSocket subscriptions to `private-room.{roomCode}`.
