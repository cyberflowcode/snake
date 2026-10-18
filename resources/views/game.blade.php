<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Snake Arena - Multiplayer Slither Arena</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/game.css') }}">
</head>
<body>
    <div id="game-container">
        <!-- Main Game Canvas -->
        <canvas id="gameCanvas"></canvas>

        <!-- Top-Left HUD Info -->
        <div class="hud-top-left">
            <div class="room-badge">
                <span class="status-indicator" id="status-indicator" title="Connecting..."></span>
                <span>ROOM:</span>
                <span class="code" id="hud-room-code">{{ $initialRoomCode ?? 'LOBBY' }}</span>
                <button class="copy-btn" id="btn-copy-code" title="Copy Room Code">Copy</button>
                <button class="copy-btn" id="btn-copy-link" title="Copy Invite Link">Link</button>
                <button class="exit-btn" id="btn-hud-exit" title="Leave Room">✕ Exit</button>
            </div>
        </div>

        <!-- Top-Right Leaderboard (Matching Reference Image) -->
        <div class="hud-leaderboard glass-panel">
            <div class="leaderboard-title">Leaderboard</div>
            <div id="hud-leaderboard-list">
                <div class="leaderboard-item">
                    <span class="rank" style="color: #d500f9;">#1</span>
                    <span class="name" style="color: #00e5ff;">Waiting...</span>
                    <span class="score">0</span>
                </div>
            </div>
        </div>

        <!-- Lower-Left HUD Stats (Matching Reference Image) -->
        <div class="hud-bottom-left">
            <div class="stat-line">Length: <span class="stat-val" id="hud-length">14</span></div>
            <div class="stat-line">Rank: <span class="stat-val" id="hud-rank">1 of 1</span></div>
            <div class="stat-line">Score: <span class="stat-val" id="hud-score">0</span></div>
        </div>

        <!-- Bottom-Right Radar Minimap -->
        <div class="hud-minimap">
            <canvas id="minimapCanvas" width="130" height="130"></canvas>
        </div>

        <!-- Countdown Overlay (3, 2, 1, GO!) -->
        <div id="countdown-overlay">3</div>

        <!-- Mobile Touch Controls -->
        <div class="mobile-controls">
            <div class="joystick-zone" id="joystick-zone">
                <div class="joystick-knob" id="joystick-knob"></div>
            </div>
            <div class="mobile-boost-btn" id="mobile-boost-btn">
                BOOST
            </div>
        </div>

        <!-- Toast Notifications -->
        <div class="toast-container" id="toast-container"></div>

        <!-- Welcome & Join Modal -->
        <div class="modal-overlay" id="welcome-modal">
            <div class="modal-card glass-panel">
                <h1 class="modal-title">SNAKE ARENA</h1>
                <p class="modal-subtitle">Real-Time Multiplayer Slither Battle</p>

                <div class="input-group">
                    <label class="input-label" for="nickname-input">Choose Your Nickname</label>
                    <input type="text" id="nickname-input" class="styled-input" placeholder="e.g. ShadowViper" maxlength="20" autofocus value="Viper_{{ rand(100, 999) }}">
                </div>

                <div class="input-group">
                    <label class="input-label">Select Snake Color</label>
                    <div class="color-picker">
                        <div class="color-option selected" data-color="cyan" style="background: #00e5ff;"></div>
                        <div class="color-option" data-color="yellow" style="background: #ffd600;"></div>
                        <div class="color-option" data-color="red" style="background: #ff1744;"></div>
                        <div class="color-option" data-color="purple" style="background: #d500f9;"></div>
                        <div class="color-option" data-color="green" style="background: #00e676;"></div>
                        <div class="color-option" data-color="blue" style="background: #2979ff;"></div>
                    </div>
                </div>

                @if(!empty($initialRoomCode))
                    <div style="background: rgba(0, 229, 255, 0.12); border: 1px solid rgba(0, 229, 255, 0.4); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; text-align: center;">
                        <span style="font-size: 13px; color: #80f2ff; font-weight: 600;">Invited to Arena: </span>
                        <span style="font-size: 16px; font-weight: 900; color: #00e5ff; letter-spacing: 2px;">{{ $initialRoomCode }}</span>
                    </div>

                    <div class="input-group">
                        <input type="text" id="room-code-input" class="styled-input" value="{{ $initialRoomCode }}" style="text-align: center; font-weight: 800; letter-spacing: 2px; text-transform: uppercase;">
                    </div>

                    <button class="btn btn-primary" id="btn-join-room" style="box-shadow: 0 0 15px rgba(0, 229, 255, 0.4);">
                        ⚡ Join Arena {{ $initialRoomCode }} Now
                    </button>

                    <button class="btn btn-success" id="btn-play-now" style="margin-top: 4px;">
                        🎮 Play Instant Solo Match
                    </button>

                    <div style="margin: 14px 0; display: flex; align-items: center; gap: 12px;">
                        <div style="flex: 1; height: 1px; background: rgba(255,255,255,0.1);"></div>
                        <span style="font-size: 11px; font-weight: 700; color: #718096;">OR CREATE NEW</span>
                        <div style="flex: 1; height: 1px; background: rgba(255,255,255,0.1);"></div>
                    </div>

                    <button class="btn btn-secondary" id="btn-create-room">
                        Create Private Room Instead
                    </button>
                @else
                    <button class="btn btn-primary" id="btn-play-now" style="font-size: 17px; padding: 16px 20px; letter-spacing: 1.5px; box-shadow: 0 0 25px rgba(0, 229, 255, 0.5);">
                        🎮 PLAY NOW
                    </button>

                    <button class="btn btn-secondary" id="btn-create-room" style="margin-top: 4px;">
                        ⚡ Create Private Room (Invite Friends)
                    </button>

                    <div style="margin: 18px 0; display: flex; align-items: center; gap: 12px;">
                        <div style="flex: 1; height: 1px; background: rgba(255,255,255,0.1);"></div>
                        <span style="font-size: 12px; font-weight: 700; color: #718096;">OR JOIN ROOM</span>
                        <div style="flex: 1; height: 1px; background: rgba(255,255,255,0.1);"></div>
                    </div>

                    <div class="input-group" style="position: relative;">
                        <input type="text" id="room-code-input" class="styled-input" placeholder="Enter 6-char room code" maxlength="8" style="text-transform: uppercase; letter-spacing: 3px; font-weight: 700; font-size: 18px; text-align: center;" value="{{ $initialRoomCode ?? '' }}" autocomplete="off">
                    </div>

                    <button class="btn btn-primary" id="btn-join-room" style="letter-spacing: 1px;">
                        ⚡ Join Existing Arena
                    </button>
                @endif

            </div>
        </div>

        <!-- Room Lobby Modal -->
        <div class="modal-overlay" id="lobby-modal" style="display: none;">
            <div class="modal-card glass-panel">
                <h2 class="modal-title">ARENA LOBBY</h2>
                <p class="modal-subtitle">Share this room code with other players to join</p>

                <div style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-bottom: 20px;">
                    <span style="font-size: 32px; font-weight: 900; letter-spacing: 4px; color: #00e5ff;" id="lobby-room-code">------</span>
                </div>

                <div class="player-list" id="lobby-players-list">
                    <!-- Populated dynamically -->
                </div>

                <button class="btn btn-success" id="btn-start-match" style="display: none;">
                    🚀 Start Match Now
                </button>

                <button class="btn btn-secondary" id="btn-leave-room">
                    Leave Room
                </button>
            </div>
        </div>

        <!-- Game Over Modal -->
        <div class="modal-overlay" id="game-over-modal" style="display: none;">
            <div class="modal-card glass-panel">
                <h2 class="modal-title" id="winner-banner">👑 Match Finished!</h2>
                <p class="modal-subtitle">Final Match Rankings & Scores</p>

                <div class="player-list" id="final-scoreboard">
                    <!-- Populated dynamically -->
                </div>

                <button class="btn btn-primary" id="btn-rematch">
                    🔄 Request Rematch
                </button>

                <button class="btn btn-secondary" id="btn-exit-menu">
                    Exit to Main Menu
                </button>
            </div>
        </div>
    </div>

    <!-- Reverb / Pusher & Game API Configuration -->
    <script>
        window.APP_BASE_URL = "{{ url('/') }}";
        window.API_BASE_URL = "{{ url('/api') }}";
        window.CSRF_TOKEN = "{{ csrf_token() }}";
        window.REVERB_APP_KEY = "{{ env('REVERB_APP_KEY', 'snake-app-key') }}";
        window.REVERB_HOST = "{{ env('REVERB_HOST', request()->getHost()) }}";
        window.REVERB_PORT = {{ env('REVERB_PORT', 8080) }};
        window.REVERB_SCHEME = "{{ env('REVERB_SCHEME', request()->isSecure() ? 'https' : 'http') }}";
    </script>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
    <script src="{{ asset('js/engine.js') }}?v={{ file_exists(public_path('js/engine.js')) ? filemtime(public_path('js/engine.js')) : time() }}"></script>
    <script src="{{ asset('js/game.js') }}?v={{ file_exists(public_path('js/game.js')) ? filemtime(public_path('js/game.js')) : time() }}"></script>
</body>
</html>
