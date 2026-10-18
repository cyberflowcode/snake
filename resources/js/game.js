/**
 * Snake Arena — Game Client
 * Handles all UI, networking, sessions, and delegates rendering to SnakeEngine.
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------
    let currentRoom  = null;
    let myPlayer     = null;
    let guestToken   = null;

    // WebSocket
    let echoInstance  = null;
    let roomChannel   = null;

    // Input state
    let targetAngle   = 0;
    let isBoosting    = false;
    let activeControl = 'mouse';

    // Network throttle
    let networkTimer = null;
    let syncPending  = false;
    let lastSentAngle = null;
    let lastSentBoost = false;
    let lastSendTime  = 0;

    // Score tracking (optimistic)
    let localScore      = 0;
    let localBestScore  = 0;
    let localLength     = 14;

    // Leaderboard state
    let leaderboardMode = 'live'; // 'live' or 'alltime'
    let cachedAllTimeLeaderboard = [];
    let lastAllTimeFetch = 0;

    // Track last processed state tick to prevent HTTP/WebSocket race conditions
    let lastProcessedTick = -1;

    // DOM element cache
    const el = {};

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------
    function init() {
        _cacheDom();
        _setupCanvas();
        _bindEvents();
        _checkSavedSession();
        _fetchAllTimeLeaderboard(false);

        networkTimer = setInterval(_networkTick, 80);   // ~12 Hz

        // Auto-leave when user closes tab / navigates away — prevents ghost players in DB
        window.addEventListener('beforeunload', () => {
            if (!currentRoom) return;
            const url = `/api/rooms/${currentRoom.code}/leave`;
            // Include guest_token in body so server can auth without headers
            const payload = JSON.stringify({ guest_token: guestToken || '' });
            const blob = new Blob([payload], { type: 'application/json' });
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, blob);
            } else {
                fetch(url, { method: 'POST', body: blob, keepalive: true }).catch(() => {});
            }
            _clearSession();
        });
    }

    function _cacheDom() {
        el.canvas          = document.getElementById('gameCanvas');
        el.minimap         = document.getElementById('minimapCanvas');
        el.welcomeModal    = document.getElementById('welcome-modal');
        el.lobbyModal      = document.getElementById('lobby-modal');
        el.gameOverModal   = document.getElementById('game-over-modal');
        el.countdown       = document.getElementById('countdown-overlay');
        el.nicknameInput   = document.getElementById('nickname-input');
        el.roomCodeInput   = document.getElementById('room-code-input');
        el.colorOptions    = document.querySelectorAll('.color-option');
        el.btnPlayNow      = document.getElementById('btn-play-now');
        el.btnCreate       = document.getElementById('btn-create-room');
        el.btnJoin         = document.getElementById('btn-join-room');
        el.btnStart        = document.getElementById('btn-start-match');
        el.btnLeave        = document.getElementById('btn-leave-room');
        el.btnRematch      = document.getElementById('btn-rematch');
        el.btnExit         = document.getElementById('btn-exit-menu');
        el.btnCopyCode     = document.getElementById('btn-copy-code');
        el.btnCopyLink     = document.getElementById('btn-copy-link');
        el.btnHudExit      = document.getElementById('btn-hud-exit');
        el.hudRoom         = document.getElementById('hud-room-code');
        el.hudLeaderboard  = document.getElementById('hud-leaderboard-list');
        el.lbTabLive       = document.getElementById('lb-tab-live');
        el.lbTabAlltime    = document.getElementById('lb-tab-alltime');
        el.lbHeading       = document.getElementById('lb-heading');
        el.welcomeLbList   = document.getElementById('welcome-lb-list');
        el.welcomeLbStatus = document.getElementById('welcome-lb-status');
        el.hudLength       = document.getElementById('hud-length');
        el.hudRank         = document.getElementById('hud-rank');
        el.hudScore        = document.getElementById('hud-score');
        el.statusDot       = document.getElementById('status-indicator');
        el.lobbyPlayers    = document.getElementById('lobby-players-list');
        el.lobbyCode       = document.getElementById('lobby-room-code');
        el.toasts          = document.getElementById('toast-container');
    }

    function _setupCanvas() {
        function resize() {
            el.canvas.width  = window.innerWidth;
            el.canvas.height = window.innerHeight;
            el.minimap.width  = 130;
            el.minimap.height = 130;
            SnakeEngine.rebuildPattern();
        }
        window.addEventListener('resize', resize);
        resize();

        // Start the engine rendering loop
        SnakeEngine.init(el.canvas, el.minimap);
        SnakeEngine.rebuildPattern();

        // Pump HUD updates from engine every 100ms
        setInterval(_updateHUD, 100);
    }

    function _bindEvents() {
        // Color picker with persistence
        const savedColor = localStorage.getItem('snake_preferred_color');
        if (savedColor) {
            el.colorOptions.forEach(o => {
                if (o.dataset.color === savedColor) {
                    el.colorOptions.forEach(x => x.classList.remove('selected'));
                    o.classList.add('selected');
                }
            });
        }

        el.colorOptions.forEach(opt => {
            opt.addEventListener('click', () => {
                el.colorOptions.forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
                if (opt.dataset.color) {
                    localStorage.setItem('snake_preferred_color', opt.dataset.color);
                }
            });
        });

        // Nickname persistence & change listeners
        const savedNick = localStorage.getItem('snake_player_nickname');
        if (savedNick && el.nicknameInput) {
            el.nicknameInput.value = savedNick;
        }
        if (el.nicknameInput) {
            el.nicknameInput.addEventListener('input', () => {
                const val = el.nicknameInput.value.trim();
                if (val) localStorage.setItem('snake_player_nickname', val);
            });
        }

        // Leaderboard tabs (Live Arena vs Saved All-Time High Scores)
        if (el.lbTabLive) {
            el.lbTabLive.addEventListener('click', () => {
                leaderboardMode = 'live';
                el.lbTabLive.classList.add('active');
                el.lbTabAlltime?.classList.remove('active');
                if (el.lbHeading) el.lbHeading.innerText = 'Live Arena';
                const state = SnakeEngine.getAuthState();
                _updateLeaderboard(state?.snakes || {});
            });
        }

        if (el.lbTabAlltime) {
            el.lbTabAlltime.addEventListener('click', () => {
                leaderboardMode = 'alltime';
                el.lbTabAlltime.classList.add('active');
                el.lbTabLive?.classList.remove('active');
                if (el.lbHeading) el.lbHeading.innerText = '🏆 Saved High Scores';
                _fetchAllTimeLeaderboard(true);
            });
        }

        // Enter key on nickname → if room code filled go to join, else play now
        if (el.nicknameInput) {
            el.nicknameInput.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const code = (el.roomCodeInput?.value || '').trim();
                    if (code.length >= 4) joinRoom();
                    else playNow();
                }
            });
        }

        // Room code input: Enter = join, and auto-join when 6 chars typed
        if (el.roomCodeInput) {
            let _joinDebounce = null;
            el.roomCodeInput.addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); joinRoom(); }
            });
            el.roomCodeInput.addEventListener('input', () => {
                const val = el.roomCodeInput.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 8);
                el.roomCodeInput.value = val;
                // Highlight join button when code looks complete
                if (el.btnJoin) {
                    if (val.length >= 6) {
                        el.btnJoin.style.boxShadow = '0 0 20px rgba(0,229,255,0.6)';
                        el.btnJoin.style.transform = 'scale(1.02)';
                    } else {
                        el.btnJoin.style.boxShadow = '';
                        el.btnJoin.style.transform = '';
                    }
                }
                // Auto-join 600ms after typing stops when code is 6+ chars
                clearTimeout(_joinDebounce);
                if (val.length >= 6) {
                    _joinDebounce = setTimeout(() => {
                        if (!currentRoom && (el.roomCodeInput?.value || '').trim().length >= 6) joinRoom();
                    }, 600);
                }
            });
        }

        if (el.btnPlayNow)  el.btnPlayNow.addEventListener('click', playNow);
        if (el.btnCreate)   el.btnCreate.addEventListener('click',  createRoom);
        if (el.btnJoin)     el.btnJoin.addEventListener('click',    joinRoom);
        if (el.btnStart)    el.btnStart.addEventListener('click',   startMatch);
        if (el.btnLeave)    el.btnLeave.addEventListener('click',   leaveRoom);
        if (el.btnRematch)  el.btnRematch.addEventListener('click', requestRematch);
        if (el.btnExit)     el.btnExit.addEventListener('click',    exitToMenu);
        if (el.btnHudExit)  el.btnHudExit.addEventListener('click', leaveRoom);

        if (el.btnCopyCode) el.btnCopyCode.addEventListener('click', () => {
            if (currentRoom) { navigator.clipboard.writeText(currentRoom.code); toast('Code copied!'); }
        });
        if (el.btnCopyLink) el.btnCopyLink.addEventListener('click', () => {
            if (currentRoom) {
                const url = (window.APP_BASE_URL || location.origin).replace(/\/+$/, '') + '/room/' + currentRoom.code;
                navigator.clipboard.writeText(url);
                toast('Invite link copied!');
            }
        });

        // Keyboard
        window.addEventListener('keydown', _onKeyDown);
        window.addEventListener('keyup',   _onKeyUp);
        window.addEventListener('mousemove', _onMouseMove);
        window.addEventListener('mousedown', e => { if (e.button === 0) { isBoosting = true; _pushTarget(); } });
        window.addEventListener('mouseup',   e => { if (e.button === 0) { isBoosting = false; _pushTarget(); } });

        _setupTouch();
    }

    // -----------------------------------------------------------------------
    // Input handlers
    // -----------------------------------------------------------------------
    function _onMouseMove(e) {
        activeControl = 'mouse';
        const dx = e.clientX - window.innerWidth  / 2;
        const dy = e.clientY - window.innerHeight / 2;
        if (Math.hypot(dx, dy) > 8) {
            targetAngle = Math.atan2(dy, dx);
            SnakeEngine.setTarget(targetAngle, isBoosting);
        }
    }

    function _onKeyDown(e) {
        if (e.code === 'Space') { isBoosting = true; SnakeEngine.setTarget(targetAngle, true); return; }
        const map = {
            ArrowRight: 0, d: 0,
            ArrowDown: Math.PI / 2, s: Math.PI / 2,
            ArrowLeft: Math.PI,    a: Math.PI,
            ArrowUp: -Math.PI / 2, w: -Math.PI / 2,
        };
        const k = map[e.key] ?? map[e.key.toLowerCase()];
        if (k !== undefined) { activeControl = 'keyboard'; targetAngle = k; SnakeEngine.setTarget(targetAngle, isBoosting); }
    }

    function _onKeyUp(e) {
        if (e.code === 'Space') { isBoosting = false; SnakeEngine.setTarget(targetAngle, false); }
    }

    function _pushTarget() {
        SnakeEngine.setTarget(targetAngle, isBoosting);
    }

    function _setupTouch() {
        const zone  = document.getElementById('joystick-zone');
        const knob  = document.getElementById('joystick-knob');
        const boost = document.getElementById('mobile-boost-btn');
        if (!zone) return;

        let tid = null, rect = null;

        zone.addEventListener('touchstart', e => {
            e.preventDefault();
            tid  = e.changedTouches[0].identifier;
            rect = zone.getBoundingClientRect();
            _moveJoystick(e.changedTouches[0]);
        }, { passive: false });

        zone.addEventListener('touchmove', e => {
            e.preventDefault();
            for (const t of e.changedTouches) if (t.identifier === tid) { _moveJoystick(t); break; }
        }, { passive: false });

        const end = () => { tid = null; if (knob) knob.style.transform = 'translate(0,0)'; };
        zone.addEventListener('touchend',   end);
        zone.addEventListener('touchcancel', end);

        function _moveJoystick(t) {
            const cx = rect.left + rect.width / 2;
            const cy = rect.top  + rect.height / 2;
            const dx = t.clientX - cx, dy = t.clientY - cy;
            const d  = Math.min(45, Math.hypot(dx, dy));
            const a  = Math.atan2(dy, dx);
            if (knob) knob.style.transform = `translate(${Math.cos(a) * d}px,${Math.sin(a) * d}px)`;
            targetAngle = a;
            SnakeEngine.setTarget(targetAngle, isBoosting);
        }

        if (boost) {
            boost.addEventListener('touchstart', e => { e.preventDefault(); isBoosting = true; SnakeEngine.setTarget(targetAngle, true); });
            boost.addEventListener('touchend',   e => { e.preventDefault(); isBoosting = false; SnakeEngine.setTarget(targetAngle, false); });
        }
    }

    // -----------------------------------------------------------------------
    // Network layer
    // -----------------------------------------------------------------------
    // Tracks last lobby poll timestamp separately from game tick
    let lastLobbyPoll = 0;

    async function _networkTick() {
        if (!currentRoom || syncPending) return;

        const state       = SnakeEngine.getAuthState();
        const isActive    = state && (state.status === 'in_progress' || state.status === 'countdown');
        const now         = Date.now();
        const angleChg    = Math.abs(targetAngle - (lastSentAngle ?? 999)) > 0.02;
        const boostChg    = isBoosting !== lastSentBoost;
        const timeOut     = now - lastSendTime > (isActive ? 80 : 300);

        if (isActive && !angleChg && !boostChg && !timeOut) return;

        syncPending    = true;
        lastSentAngle  = targetAngle;
        lastSentBoost  = isBoosting;
        lastSendTime   = now;

        try {
            if (isActive) {
                const eatenFoodIds = SnakeEngine.pollNewlyEatenIds ? SnakeEngine.pollNewlyEatenIds() : [];
                const payload = {
                    angle: targetAngle,
                    boost: isBoosting,
                };
                if (eatenFoodIds && eatenFoodIds.length > 0) {
                    payload.eaten_foods = eatenFoodIds;
                }
                const res = await _api(`/api/rooms/${currentRoom.code}/direction`, 'POST', payload, 1500);
                if (res?.state) _onState(res.state);
            } else if (currentRoom) {
                const res = await _api(`/api/rooms/${currentRoom.code}/snapshot`, 'GET', null, 1500);
                if (res?.state) _onState(res.state);

                // Update lobby player list from authoritative room data every 1.5s
                const inLobby = el.lobbyModal && el.lobbyModal.style.display !== 'none';
                if (res?.room && inLobby && now - lastLobbyPoll > 1500) {
                    lastLobbyPoll = now;
                    _updateLobbyUI(res.room);
                }
            }
            _setDot('online');
        } catch (e) {
            // transient
        } finally {
            syncPending = false;
        }
    }

    // -----------------------------------------------------------------------
    // API helper
    // -----------------------------------------------------------------------
    async function _api(endpoint, method = 'GET', data = null, timeout = 3000) {
        let ep = endpoint;
        if (ep.startsWith('/api/')) ep = ep.slice(5);
        else if (ep.startsWith('/api')) ep = ep.slice(4);
        else if (ep.startsWith('/')) ep = ep.slice(1);

        const base = (window.API_BASE_URL || (location.origin + '/api')).replace(/\/+$/, '');
        const url  = ep.startsWith('http') ? ep : `${base}/${ep}`;

        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (window.CSRF_TOKEN) headers['X-CSRF-TOKEN'] = window.CSRF_TOKEN;
        if (guestToken)        headers['X-Guest-Token'] = guestToken;

        const ctl   = new AbortController();
        const timer = setTimeout(() => ctl.abort(), timeout);
        try {
            const res  = await fetch(url, { method, headers, body: (data && method !== 'GET') ? JSON.stringify(data) : undefined, signal: ctl.signal });
            const json = await res.json();
            if (!res.ok) {
                const msg = json.error || (json.errors ? Object.values(json.errors)[0][0] : (json.message || 'Error'));
                throw new Error(msg);
            }
            return json;
        } finally {
            clearTimeout(timer);
        }
    }

    // -----------------------------------------------------------------------
    // Room lifecycle
    // -----------------------------------------------------------------------
    async function playNow() {
        const nick = (el.nicknameInput?.value || '').trim() || ('Viper_' + (Math.random() * 900 + 100 | 0));
        if (el.nicknameInput) el.nicknameInput.value = nick;
        localStorage.setItem('snake_player_nickname', nick);

        _btnState(el.btnPlayNow, true, 'Entering Arena...');
        try {
            // Join global public matchmaking room (20 players max, NO HOST, direct play)
            const res = await _api('/api/rooms/global/join', 'POST', { nickname: nick, color: _getColor() });
            _saveSession(res.room.code, res.player, res.guest_token);
            _initWS(res.room.code);

            currentRoom = res.room;
            myPlayer    = res.player;
            SnakeEngine.setMyPlayer(res.player?.id);

            // In global rooms, ALWAYS play directly! No host, no lobby stage!
            const st = res.state || {
                status: 'in_progress',
                width: res.room?.width || 3200,
                height: res.room?.height || 3200,
                snakes: {},
                foods: []
            };
            _onMatchStarted(st);
            toast('🎮 Playing in Global Arena!');
        } catch (err) {
            toast(err.message || 'Cannot join arena');
        } finally {
            _btnState(el.btnPlayNow, false, '🎮 PLAY NOW');
        }
    }

    async function createRoom() {
        const nick = (el.nicknameInput?.value || '').trim() || ('Viper_' + (Math.random() * 900 + 100 | 0));
        localStorage.setItem('snake_player_nickname', nick);
        _btnState(el.btnCreate, true, 'Creating...');
        try {
            const res = await _api('/api/rooms', 'POST', { nickname: nick, color: _getColor(), max_players: 8 });
            _saveSession(res.room.code, res.player, res.guest_token);
            _enterLobby(res.room, res.player);
            toast('Room created: ' + res.room.code);
        } catch (err) {
            toast(err.message);
        } finally {
            _btnState(el.btnCreate, false, '⚡ Create Private Room (Invite Friends)');
        }
    }

    async function joinRoom() {
        const nick = (el.nicknameInput?.value || '').trim();
        const code = (el.roomCodeInput?.value || '').trim().toUpperCase();
        if (!nick)  { toast('Enter a nickname first!'); el.nicknameInput?.focus(); return; }
        if (!code)  { toast('Enter the room code!'); el.roomCodeInput?.focus(); return; }
        localStorage.setItem('snake_player_nickname', nick);

        _btnState(el.btnJoin, true, 'Joining...');
        try {
            const res = await _api(`/api/rooms/${code}/join`, 'POST', { nickname: nick, color: _getColor() });
            _saveSession(res.room.code, res.player, res.guest_token);
            _initWS(res.room.code);

            const status = res.state?.status || res.room?.status;

            // If game is already running — skip lobby and enter arena directly
            if (res.state && (status === 'in_progress' || status === 'countdown')) {
                _onMatchStarted(res.state);
                toast('Joined arena — good luck!');
            } else {
                _enterLobby(res.room, res.player);
                toast('Joined room ' + res.room.code + '. Waiting for host to start…');
            }
        } catch (err) {
            toast(err.message || 'Could not join room.');
        } finally {
            _btnState(el.btnJoin, false, 'Join Existing Arena');
        }
    }

    async function startMatch() {
        if (!currentRoom) return;
        _btnState(el.btnStart, true, 'Starting...');
        try {
            const res = await _api(`/api/rooms/${currentRoom.code}/start`, 'POST');
            if (res?.state) _onMatchStarted(res.state);
        } catch (err) {
            toast(err.message);
        } finally {
            _btnState(el.btnStart, false, '🚀 Start Match Now');
        }
    }

    async function leaveRoom() {
        // Disable leave button immediately to prevent double-click
        if (el.btnLeave) { el.btnLeave.disabled = true; el.btnLeave.innerText = 'Leaving...'; }
        if (currentRoom) {
            try { await _api(`/api/rooms/${currentRoom.code}/leave`, 'POST'); } catch (_) {}
        }
        _clearSession();
        exitToMenu();
    }

    async function requestRematch() {
        if (!currentRoom) return;
        _btnState(el.btnRematch, true, 'Preparing...');
        try {
            const res = await _api(`/api/rooms/${currentRoom.code}/rematch`, 'POST');
            el.gameOverModal.style.display = 'none';
            SnakeEngine.setAuthState(null);

            if (res?.state) {
                _onMatchStarted(res.state);
            } else if (res?.room) {
                currentRoom = res.room;
                el.lobbyModal.style.display = 'flex';
                _updateLobbyUI(res.room);
            }
        } catch (err) {
            toast(err.message);
        } finally {
            _btnState(el.btnRematch, false, '🔄 Play Again');
        }
    }

    function exitToMenu() {
        if (echoInstance && currentRoom) {
            try { echoInstance.leave(`room.${currentRoom.code}`); } catch (_) {}
        }
        currentRoom = null;
        myPlayer    = null;
        localScore  = 0;
        localLength = 14;
        lastProcessedTick = -1;
        SnakeEngine.setMyPlayer(null);
        SnakeEngine.setAuthState(null);

        el.welcomeModal.style.display  = 'flex';
        el.lobbyModal.style.display    = 'none';
        el.gameOverModal.style.display = 'none';
        el.countdown.style.display     = 'none';
        el.countdown.classList.remove('active');
        _endAutoRespawn();

        _btnState(el.btnPlayNow, false, '🎮 PLAY NOW');
        _btnState(el.btnCreate,  false, '⚡ Create Private Room (Invite Friends)');
        _btnState(el.btnJoin,    false, '⚡ Join Existing Arena');

        _clearSession();
    }

    // -----------------------------------------------------------------------
    // Session persistence
    // -----------------------------------------------------------------------
    function _saveSession(code, player, token) {
        currentRoom = { code };
        myPlayer    = player;
        guestToken  = token;
        SnakeEngine.setMyPlayer(player?.id);
        localStorage.setItem('snake_session', JSON.stringify({ roomCode: code, player, guestToken: token }));
    }

    function _clearSession() {
        guestToken = null;
        localStorage.removeItem('snake_session');

        // Clear the invite URL so refreshing doesn't auto-rejoin
        if (location.pathname !== '/') {
            history.pushState(null, '', '/');
        }

        // Clear the room code input field
        if (el.roomCodeInput) {
            el.roomCodeInput.value = '';
            el.roomCodeInput.style.boxShadow = '';
        }
        if (el.btnJoin) {
            el.btnJoin.style.boxShadow = '';
            el.btnJoin.style.transform = '';
        }
    }

    async function _checkSavedSession() {
        // Pre-fill room code from URL (e.g. /room/DX4BN8)
        const m = location.pathname.match(/\/room\/([A-Za-z0-9]+)/);
        const urlRoomCode = m ? m[1].toUpperCase() : null;

        if (urlRoomCode && el.roomCodeInput) {
            el.roomCodeInput.value = urlRoomCode;
        }

        // Try to restore existing session first
        const saved = localStorage.getItem('snake_session');
        if (saved) {
            try {
                const data = JSON.parse(saved);
                if (data.roomCode && data.guestToken) {
                    guestToken = data.guestToken;
                    const res = await _api(`/api/rooms/${data.roomCode}/reconnect`, 'POST');
                    if (res?.room && res.room.status !== 'finished') {
                        _saveSession(res.room.code, res.player, data.guestToken);
                        _initWS(res.room.code);
                        const status = res.state?.status || res.room?.status;
                        if (res.state && (status === 'in_progress' || status === 'countdown')) {
                            _onMatchStarted(res.state);
                            toast('Reconnected — you\'re back in the arena!');
                        } else {
                            _enterLobby(res.room, res.player);
                            if (res.state && status !== 'finished') _onState(res.state);
                            toast('Reconnected to ' + res.room.code);
                        }
                        return; // Session restored — skip auto-join
                    }
                }
            } catch (_) {}
            _clearSession();
        }

        // Auto-join if opened via invite link (/room/CODE)
        if (urlRoomCode) {
            const nick = (el.nicknameInput?.value || '').trim();
            if (!nick) {
                // Highlight nickname field so user knows to fill it first
                if (el.nicknameInput) {
                    el.nicknameInput.focus();
                    el.nicknameInput.style.boxShadow = '0 0 0 2px #00e5ff';
                    setTimeout(() => { if (el.nicknameInput) el.nicknameInput.style.boxShadow = ''; }, 3000);
                }
                if (el.btnJoin) {
                    el.btnJoin.style.boxShadow = '0 0 15px rgba(0,229,255,0.5)';
                    el.btnJoin.innerText = '⚡ Join Arena ' + urlRoomCode + ' Now';
                }
                // Still auto-join after 1.5s using the pre-filled nickname
                setTimeout(() => {
                    const n = (el.nicknameInput?.value || '').trim();
                    if (n && !currentRoom) joinRoom();
                }, 1500);
            } else {
                // Nickname already filled — join immediately
                setTimeout(() => { if (!currentRoom) joinRoom(); }, 600);
            }
        }
    }

    // -----------------------------------------------------------------------
    // WebSocket (Laravel Reverb / Pusher)
    // -----------------------------------------------------------------------
    function _initWS(code) {
        if (!window.Echo || !window.Pusher) { _setDot('online'); return; }
        try {
            echoInstance = new window.Echo({
                broadcaster:       'reverb',
                key:               window.REVERB_APP_KEY  || 'snake-app-key',
                wsHost:            window.REVERB_HOST     || location.hostname,
                wsPort:            window.REVERB_PORT     || 8080,
                wssPort:           window.REVERB_PORT     || 8080,
                forceTLS:          (window.REVERB_SCHEME  || 'http') === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint:      (window.API_BASE_URL || (location.origin + '/api')).replace(/\/+$/, '') + '/broadcasting/auth',
                auth: { headers: { 'X-Guest-Token': guestToken, 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' } },
            });

            const conn = echoInstance.connector?.pusher?.connection;
            if (conn) {
                conn.bind('connected',   () => _setDot('online'));
                conn.bind('unavailable', () => _setDot('online'));
                conn.bind('error',       () => {});
            }

            roomChannel = echoInstance.private(`room.${code}`);
            roomChannel.listen('.game.tick',    e => _onState(e));
            roomChannel.listen('.game.started', e => _onMatchStarted(e.initialState));
            roomChannel.listen('.game.over',    e => _onGameOver(e.results));
            roomChannel.listen('.player.joined', e => { toast(e.player?.nickname + ' joined!'); _refreshLobby(); });
            roomChannel.listen('.player.left',   e => { toast((e.data?.nickname || 'Player') + ' left.'); _refreshLobby(); });

            _setDot('online');
        } catch (err) {
            _setDot('online');
        }
    }

    // -----------------------------------------------------------------------
    // Lobby UI
    // -----------------------------------------------------------------------
    function _enterLobby(room, player) {
        currentRoom = room;
        myPlayer    = player;
        SnakeEngine.setMyPlayer(player?.id);

        el.welcomeModal.style.display  = 'none';
        el.lobbyModal.style.display    = 'flex';
        el.gameOverModal.style.display = 'none';
        if (el.lobbyCode) el.lobbyCode.innerText = room.code;
        if (el.hudRoom)   el.hudRoom.innerText   = room.code;

        _updateLobbyUI(room);
        _initWS(room.code);
    }

    function _updateLobbyUI(room) {
        currentRoom = room;
        if (el.lobbyCode) el.lobbyCode.innerText = room.code;
        if (!el.lobbyPlayers) return;
        el.lobbyPlayers.innerHTML = '';

        const isGlobal = Boolean(room.is_public || (room.max_players >= 20));
        const me     = (room.players || []).find(p => myPlayer && Number(p.id) === Number(myPlayer.id));
        const isHost = !isGlobal && (me ? Boolean(me.is_host) : (myPlayer && Number(room.host_id) === Number(myPlayer.id)));

        // Only show Start Match button in private lobbies — never in global rooms or active games
        const engineState = SnakeEngine.getAuthState();
        const gameActive  = engineState && (engineState.status === 'in_progress' || engineState.status === 'countdown');
        if (el.btnStart) {
            el.btnStart.style.display = (!isGlobal && isHost && !gameActive) ? 'inline-flex' : 'none';
            el.btnStart.disabled  = false;
            el.btnStart.innerText = '🚀 Start Match Now';
        }

        const cmap = SnakeEngine.COLOR_MAP;
        (room.players || []).forEach(p => {
            const card = document.createElement('div');
            card.className = 'player-card';
            const col = cmap[p.color] || cmap.cyan;
            const isRainbow = (p.color === 'rainbow');
            const dotStyle = isRainbow
                ? 'background:conic-gradient(#ff1744,#ffd600,#00e676,#00e5ff,#d500f9,#ff1744);box-shadow:0 0 10px rgba(255,0,128,0.7);'
                : `background:${col.main};box-shadow:0 0 8px ${col.main}`;
            card.innerHTML = `
                <div class="p-info">
                    <div class="dot" style="${dotStyle}"></div>
                    <span class="name">${_esc(p.nickname)}${p.id === myPlayer?.id ? ' (You)' : ''}</span>
                </div>
                ${(!isGlobal && p.is_host) ? '<span class="badge">HOST</span>' : ''}
            `;
            el.lobbyPlayers.appendChild(card);
        });
    }

    async function _refreshLobby() {
        if (!currentRoom) return;
        try {
            const res = await _api(`/api/rooms/${currentRoom.code}`);
            if (!res?.room) return;

            // If a game is active, silently update room data only — don't touch any UI
            const engineState = SnakeEngine.getAuthState();
            const gameActive  = engineState && (engineState.status === 'in_progress' || engineState.status === 'countdown');
            if (gameActive) {
                currentRoom = res.room; // keep room data fresh
                return;
            }

            _updateLobbyUI(res.room);
        } catch (_) {}
    }

    // -----------------------------------------------------------------------
    // Game state handling
    // -----------------------------------------------------------------------
    function _onMatchStarted(state) {
        el.lobbyModal.style.display    = 'none';
        el.welcomeModal.style.display  = 'none';
        el.gameOverModal.style.display = 'none';
        localScore  = 0;
        localLength = 14;
        lastProcessedTick = -1;
        _onState(state);
    }

    function _onState(state) {
        if (!state || !state.status) return;

        // Discard stale out-of-order packets (e.g. slow HTTP responses arriving after a newer WebSocket tick)
        if (typeof state.tick === 'number') {
            if (state.tick < lastProcessedTick) {
                return;
            }
            lastProcessedTick = state.tick;
        }

        SnakeEngine.setAuthState(state);

        // Countdown overlay
        if (state.status === 'countdown') {
            el.lobbyModal.style.display   = 'none';
            el.welcomeModal.style.display = 'none';
            el.countdown.style.display    = 'block';
            el.countdown.innerText        = state.countdown > 0 ? state.countdown : 'GO!';
            el.countdown.classList.add('active');
        } else {
            el.countdown.classList.remove('active');
            el.countdown.style.display = 'none';
        }

        // Game over (private rooms only)
        const isGlobalState = Boolean(currentRoom?.is_public || (currentRoom?.max_players >= 20));
        if (!isGlobalState && state.status === 'finished' && el.gameOverModal.style.display !== 'flex') {
            _onGameOver({ winner_id: state.winner_id, scoreboard: _buildScoreboard(state) });
        }
    }

    // -----------------------------------------------------------------------
    // Global Arena Auto-Revive (3 seconds countdown)
    // -----------------------------------------------------------------------
    let _respawnInProgress = false;
    let _respawnTimerInterval = null;

    function _startAutoRespawn() {
        if (_respawnInProgress) return;
        _respawnInProgress = true;
        let secondsLeft = 3;

        const banner = document.getElementById('respawn-banner');
        const timerText = document.getElementById('respawn-timer');
        if (banner) banner.style.display = 'block';
        if (timerText) timerText.innerText = `Reviving in ${secondsLeft}s...`;

        clearInterval(_respawnTimerInterval);
        _respawnTimerInterval = setInterval(() => {
            secondsLeft--;
            if (secondsLeft > 0) {
                if (timerText) timerText.innerText = `Reviving in ${secondsLeft}s...`;
            } else {
                if (timerText) timerText.innerText = `Spawning!`;
                clearInterval(_respawnTimerInterval);
                _respawnTimerInterval = null;
                _doRespawn();
            }
        }, 1000);
    }

    async function _doRespawn() {
        if (!currentRoom) return;
        localBestScore = Math.max(localBestScore, localScore);
        try {
            const res = await _api(`/api/rooms/${currentRoom.code}/respawn`, 'POST');
            if (res?.state) {
                _onState(res.state);
            }
        } catch (_) {}
        localScore = 0;
        localLength = 14;
        _endAutoRespawn();
    }

    function _endAutoRespawn() {
        _respawnInProgress = false;
        clearInterval(_respawnTimerInterval);
        _respawnTimerInterval = null;
        const banner = document.getElementById('respawn-banner');
        if (banner) banner.style.display = 'none';
        localScore = 0;
        localLength = 14;
    }

    // -----------------------------------------------------------------------
    // HUD updates (called every 100ms)
    // -----------------------------------------------------------------------
    function _updateHUD() {
        const state = SnakeEngine.getAuthState();

        // Collect any food eaten optimistically
        const eats = SnakeEngine.pollLocalEats();
        eats.forEach(f => {
            const val = f.value || 100;
            localScore  += val;
            localLength += Math.max(1, Math.round(val / 100));
        });
        localBestScore = Math.max(localBestScore, localScore);

        if (el.hudScore)  el.hudScore.innerText  = localScore.toLocaleString();
        if (el.hudLength) el.hudLength.innerText = localLength;

        if (!state || !state.snakes || !myPlayer) return;

        const me = state.snakes[myPlayer.id];
        if (me) {
            // Sync from authoritative when server has higher value
            if (me.score  > localScore)  localScore  = me.score;
            if (me.length > localLength) localLength  = me.length;
            const serverBest = me.best_score || me.score || 0;
            localBestScore = Math.max(localBestScore, localScore, serverBest);

            const total = Object.keys(state.snakes).length;
            const rank  = me.rank || _rankOf(myPlayer.id, state.snakes);
            if (el.hudRank) el.hudRank.innerText = `${rank} of ${total}`;

            const isGlobal = Boolean(currentRoom?.is_public || (currentRoom?.max_players >= 20));
            if (isGlobal) {
                // In global arena: auto-revive in 3 seconds! Never show game-over modal!
                if (!me.is_alive && !_respawnInProgress) {
                    _startAutoRespawn();
                } else if (me.is_alive && _respawnInProgress) {
                    _endAutoRespawn();
                }
            } else {
                // In private rooms: show standard match-over screen
                if (!me.is_alive && el.gameOverModal.style.display !== 'flex' && el.welcomeModal.style.display !== 'flex') {
                    _onGameOver({ winner_id: null, scoreboard: _buildScoreboard(state) });
                }
            }
        }

        _updateLeaderboard(state.snakes);
    }

    function _rankOf(id, snakes) {
        const sorted = Object.values(snakes).sort((a, b) => {
            const sa = Math.max(a.score || 0, a.best_score || 0);
            const sb = Math.max(b.score || 0, b.best_score || 0);
            return sb - sa;
        });
        const idx = sorted.findIndex(s => Number(s.id) === Number(id));
        return idx !== -1 ? idx + 1 : 1;
    }

    function _updateLeaderboard(snakes) {
        if (!el.hudLeaderboard) return;

        // If user is currently looking at All-Time saved scores, don't overwrite with live
        if (leaderboardMode === 'alltime') {
            if (Date.now() - lastAllTimeFetch > 15000) {
                _fetchAllTimeLeaderboard(true);
            }
            return;
        }

        if (!snakes) return;
        const cmap   = SnakeEngine.COLOR_MAP;
        const ranked = SnakeEngine.RANK_COLORS;
        const snakeList = Object.values(snakes).filter(s => !s.left_voluntarily);

        const sorted = snakeList.map(s => {
            let score = Math.max(s.score || 0, s.best_score || 0);
            if (myPlayer && Number(s.id) === Number(myPlayer.id)) {
                score = Math.max(score, localScore, localBestScore);
                localBestScore = score;
            }
            return { ...s, score };
        }).sort((a, b) => b.score - a.score).slice(0, 10);

        el.hudLeaderboard.innerHTML = '';
        if (sorted.length === 0) {
            el.hudLeaderboard.innerHTML = '<div class="leaderboard-item"><span class="name" style="color:#8b9bb4;">Waiting for players...</span></div>';
            return;
        }

        sorted.forEach((s, i) => {
            const col = cmap[s.color] || cmap.cyan;
            const isMe = myPlayer && Number(s.id) === Number(myPlayer.id);
            const row = document.createElement('div');
            row.className = 'leaderboard-item' + (isMe ? ' me' : '');
            row.innerHTML = `
                <span class="rank" style="color:${ranked[i % ranked.length]}">#${i + 1}</span>
                <span class="name" style="color:${s.is_alive ? col.text : '#757575'}">${_esc(s.nickname)}${isMe ? ' (You)' : ''}</span>
                <span class="score">${s.score.toLocaleString()}</span>
            `;
            el.hudLeaderboard.appendChild(row);
        });
    }

    async function _fetchAllTimeLeaderboard(renderHud = false) {
        try {
            const res = await _api('/api/leaderboard', 'GET');
            if (res && Array.isArray(res.leaderboard)) {
                cachedAllTimeLeaderboard = res.leaderboard;
                lastAllTimeFetch = Date.now();
                _renderWelcomeLeaderboard(cachedAllTimeLeaderboard);
                if (renderHud || leaderboardMode === 'alltime') {
                    _renderAllTimeLeaderboard(cachedAllTimeLeaderboard);
                }
            }
        } catch (err) {
            if (el.welcomeLbStatus) el.welcomeLbStatus.innerText = 'Offline';
        }
    }

    function _renderWelcomeLeaderboard(list) {
        if (!el.welcomeLbList) return;
        if (el.welcomeLbStatus) el.welcomeLbStatus.innerText = `${list.length} Champions`;

        if (!list || list.length === 0) {
            el.welcomeLbList.innerHTML = '<div style="font-size:11px;color:#8b9bb4;text-align:center;padding:10px 0;">No saved high scores yet. Play now to be #1!</div>';
            return;
        }

        const rankColors = ['#ffd600', '#c0c0c0', '#cd7f32', '#00e5ff', '#00e676', '#d500f9', '#2979ff'];
        el.welcomeLbList.innerHTML = '';
        list.slice(0, 8).forEach((item, idx) => {
            const row = document.createElement('div');
            row.className = 'welcome-lb-row' + (idx === 0 ? ' top-1' : '');
            const col = rankColors[idx] || '#8b9bb4';
            const currentNick = (el.nicknameInput?.value || '').trim().toLowerCase();
            const isCurrentNick = currentNick && currentNick === (item.player_nickname || '').toLowerCase();

            row.innerHTML = `
                <span class="lb-rank" style="color:${col}">#${idx + 1}</span>
                <span class="lb-name" style="${isCurrentNick ? 'color:#00e5ff;font-weight:800;' : ''}">
                    ${_esc(item.player_nickname)} ${isCurrentNick ? '⭐' : ''}
                </span>
                <span class="lb-score">${Number(item.high_score).toLocaleString()}</span>
                <span class="lb-games">${item.games_played || 1}G</span>
            `;
            el.welcomeLbList.appendChild(row);
        });
    }

    function _renderAllTimeLeaderboard(list) {
        if (!el.hudLeaderboard) return;
        const ranked = SnakeEngine.RANK_COLORS || ['#ffd600', '#d500f9', '#00e5ff', '#00e676'];
        el.hudLeaderboard.innerHTML = '';

        if (!list || list.length === 0) {
            el.hudLeaderboard.innerHTML = '<div class="leaderboard-item"><span class="name" style="color:#8b9bb4;">No saved scores yet</span></div>';
            return;
        }

        const myNick = (el.nicknameInput?.value || '').trim().toLowerCase();
        list.slice(0, 10).forEach((item, idx) => {
            const isMe = myNick && (item.player_nickname || '').toLowerCase() === myNick;
            const row = document.createElement('div');
            row.className = 'leaderboard-item' + (isMe ? ' me' : '');
            row.innerHTML = `
                <span class="rank" style="color:${ranked[idx % ranked.length]}">#${idx + 1}</span>
                <span class="name" style="color:${idx === 0 ? '#ffd600' : (isMe ? '#00e5ff' : '#e0e7ff')}">
                    ${_esc(item.player_nickname)}${isMe ? ' (You)' : ''}
                </span>
                <span class="score">${Number(item.high_score).toLocaleString()}</span>
            `;
            el.hudLeaderboard.appendChild(row);
        });
    }

    // -----------------------------------------------------------------------
    // Game over screen
    // -----------------------------------------------------------------------
    function _onGameOver(results) {
        const isGlobalRoom = Boolean(currentRoom?.is_public || (currentRoom?.max_players >= 20));
        if (isGlobalRoom) return; // NEVER show game-over modal in global room!

        el.gameOverModal.style.display = 'flex';
        const state   = SnakeEngine.getAuthState();
        const cmap    = SnakeEngine.COLOR_MAP;
        const banner  = document.getElementById('winner-banner');
        const board   = document.getElementById('final-scoreboard');

        const winner = results.winner_id && state?.snakes?.[results.winner_id];
        if (banner) {
            banner.innerHTML = winner
                ? `👑 Winner: <span style="color:${cmap[winner.color]?.main || '#ffd600'};font-weight:900">${_esc(winner.nickname)}</span>`
                : '💥 Match Concluded!';
        }

        if (board) {
            board.innerHTML = '';
            const items = (results.scoreboard || _buildScoreboard(state || {}))
                .filter(p => !state?.snakes?.[p.id]?.left_voluntarily);
            items.forEach((p, i) => {
                const item = document.createElement('div');
                item.className = 'player-card';
                const col = cmap[p.color]?.main || '#00e5ff';
                item.innerHTML = `
                    <div class="p-info">
                        <span style="font-weight:800;color:#ffd600;width:30px">#${p.rank || i + 1}</span>
                        <div class="dot" style="background:${col}"></div>
                        <span class="name">${_esc(p.nickname)} ${Number(p.id) === Number(myPlayer?.id) ? '(You)' : ''}</span>
                    </div>
                    <div style="font-weight:800;color:#fff">Score: ${p.score}</div>
                `;
                board.appendChild(item);
            });
        }

        // Global rooms: hide Rematch (no point), show Play Again → matchmaking
        if (el.btnRematch) el.btnRematch.style.display = isGlobalRoom ? 'none' : 'inline-flex';

        // Inject Play Again button for global room if not already there
        const oldBtn = document.getElementById('btn-play-again-global');
        if (isGlobalRoom && !oldBtn && el.btnRematch) {
            const btn = document.createElement('button');
            btn.id = 'btn-play-again-global';
            btn.className = 'btn btn-primary';
            btn.style.marginBottom = '8px';
            btn.innerHTML = '🎮 Play Again';
            btn.onclick = () => {
                el.gameOverModal.style.display = 'none';
                document.getElementById('btn-play-again-global')?.remove();
                leaveRoom();
                setTimeout(() => playNow(), 500);
            };
            el.btnRematch.parentNode.insertBefore(btn, el.btnRematch);
        } else if (!isGlobalRoom && oldBtn) {
            oldBtn.remove();
        }
    }

    function _buildScoreboard(state) {
        return Object.values(state.snakes || {}).sort((a, b) => {
            if (a.rank && b.rank && a.rank !== b.rank) return a.rank - b.rank;
            return b.score - a.score;
        });
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------
    function _getColor() {
        const sel = document.querySelector('.color-option.selected');
        return sel ? sel.dataset.color : 'cyan';
    }

    function _setDot(status) {
        if (!el.statusDot) return;
        el.statusDot.className = 'status-indicator ' + status;
        el.statusDot.title = status === 'online' ? 'Real-time sync active' : status;
    }

    function _btnState(btn, disabled, text) {
        if (!btn) return;
        btn.disabled   = disabled;
        btn.innerText  = text;
    }

    function _esc(s) {
        if (!s) return '';
        return s.replace(/[&<>'"]/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[t] || t));
    }

    function toast(msg) {
        if (!el.toasts) return;
        const t = document.createElement('div');
        t.className = 'toast';
        t.innerText = msg;
        el.toasts.appendChild(t);
        setTimeout(() => {
            t.style.opacity    = '0';
            t.style.transform  = 'translateY(-10px)';
            t.style.transition = 'all 0.3s ease';
            setTimeout(() => t.remove(), 300);
        }, 3200);
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
