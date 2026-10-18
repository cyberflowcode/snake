/**
 * Snake Arena — Client-Side Physics Engine
 * Handles smooth 60-144 FPS client prediction, camera, and rendering.
 * Server: tick_rate = 10 Hz, BASE_SPEED = 9 px/tick = 90 px/sec
 */
(function (global) {
    'use strict';

    // -----------------------------------------------------------------------
    // Constants (must match server SnakeSimulationService.php)
    // -----------------------------------------------------------------------
    const TICK_RATE      = 10;          // Hz
    const BASE_SPEED_S   = 90.0;        // px / second  (9 px/tick * 10 ticks/s)
    const BOOST_SPEED_S  = 160.0;       // px / second  (16 px/tick * 10 ticks/s)
    const SEG_DIST       = 9.0;         // px between segment centres
    const HEAD_R         = 14.0;        // px head radius
    const EAT_R_SQ       = (HEAD_R + 8) ** 2;

    // -----------------------------------------------------------------------
    // Color palette (must match server PALETTE + blade colours)
    // -----------------------------------------------------------------------
    const COLOR_MAP = {
        cyan:   { main: '#00e5ff', light: '#80f2ff', dark: '#0097a7', text: '#00e5ff' },
        yellow: { main: '#ffd600', light: '#ffff52', dark: '#c7a500', text: '#ffd600' },
        red:    { main: '#ff1744', light: '#ff616f', dark: '#c4001d', text: '#ff5252' },
        purple: { main: '#d500f9', light: '#ff64ff', dark: '#9e00c5', text: '#ea80fc' },
        green:  { main: '#00e676', light: '#66ffa6', dark: '#00b348', text: '#69f0ae' },
        blue:   { main: '#2979ff', light: '#75a7ff', dark: '#004ecb', text: '#82b1ff' },
        rainbow: { main: '#ff007f', light: '#ffffff', dark: '#d500f9', text: '#ff007f', isRainbow: true },
    };

    const RANK_COLORS = ['#d500f9','#ff1744','#ff9100','#00e5ff','#00e676','#ffd600','#2979ff','#f50057','#00b0ff','#76ff03'];
    const FOOD_COLORS = ['#00e5ff','#ffd600','#ff1744','#d500f9','#00e676','#ff9100','#e040fb','#ffffff'];

    // -----------------------------------------------------------------------
    // Engine state
    // -----------------------------------------------------------------------
    let authState   = null;   // latest authoritative state from server
    let localSnakes = {};     // id → interpolated snake object
    let camera      = { x: 1200, y: 1200, zoom: 1.0 };

    let myPlayerId  = null;
    let targetAngle = 0;
    let isBoosting  = false;

    // Canvas refs
    let canvas, ctx, minimapCanvas, minimapCtx;

    // Sprite caches
    const segSprites        = {};
    const rainbowSegSprites = [];
    const foodSprites       = {};
    const localEatenMap     = new Map();
    let hexPattern          = null;
    let hexPatCanvas        = null;

    // Frame timing
    let lastTs = performance.now();
    let frame  = 0;

    // -----------------------------------------------------------------------
    // Initialise engine (called once canvases are ready)
    // -----------------------------------------------------------------------
    function init(mainCanvas, minimap) {
        canvas       = mainCanvas;
        ctx          = canvas.getContext('2d', { alpha: false });
        minimapCanvas = minimap;
        minimapCtx   = minimap.getContext('2d');

        _buildSprites();
        _buildHexPattern();
        requestAnimationFrame(_loop);
    }

    // -----------------------------------------------------------------------
    // External API used by game.js
    // -----------------------------------------------------------------------
    function setAuthState(state) {
        if (!state || Array.isArray(state) || !state.status) return;

        // Prune optimistic eaten food timestamps older than 1.5 seconds
        const now = Date.now();
        for (const [id, t] of localEatenMap.entries()) {
            if (now - t > 1500) localEatenMap.delete(id);
        }

        // Filter out foods that the local player already ate optimistically so they do not flash back before server roundtrip
        if (state.foods && Array.isArray(state.foods) && localEatenMap.size > 0) {
            state.foods = state.foods.filter(f => !localEatenMap.has(f.id));
        }

        authState = state;
        // When server sends a new state, reconcile local snakes
        _reconcile();
    }

    function setMyPlayer(id) {
        myPlayerId = id != null ? Number(id) : null;
    }

    function setTarget(angle, boost) {
        targetAngle = angle;
        isBoosting  = boost;
    }

    function getCamera() { return camera; }
    function getAuthState() { return authState; }

    /** Returns list of food items that were "eaten" locally this frame — for optimistic HUD update */
    function pollLocalEats() {
        const eats = _pendingEats;
        _pendingEats = [];
        return eats;
    }

    let _pendingEats = [];

    // -----------------------------------------------------------------------
    // Main render loop
    // -----------------------------------------------------------------------
    function _loop(ts) {
        const dtMs  = ts - lastTs;
        lastTs = ts;
        const dt = Math.min(0.05, Math.max(0.001, dtMs / 1000));
        frame++;

        _predict(dt);
        _draw();
        if (frame % 4 === 0) _drawMinimap();

        requestAnimationFrame(_loop);
    }

    // -----------------------------------------------------------------------
    // Client-side prediction
    // -----------------------------------------------------------------------
    function _reconcile() {
        if (!authState || !authState.snakes) return;
        for (const id in authState.snakes) {
            const a = authState.snakes[id];
            const numId = Number(id);
            if (!a.is_alive) {
                delete localSnakes[numId];
                continue;
            }
            if (!localSnakes[numId]) {
                // First time we see this snake — initialise from server truth
                localSnakes[numId] = {
                    x:       a.x,
                    y:       a.y,
                    angle:   a.angle,
                    speed:   BASE_SPEED_S,
                    body:    a.body ? JSON.parse(JSON.stringify(a.body)) : [{x: a.x, y: a.y}],
                };
            }
        }
        // Remove snakes that are no longer in state
        for (const id in localSnakes) {
            if (!authState.snakes[id] || !authState.snakes[id].is_alive) {
                delete localSnakes[id];
            }
        }
    }

    function _predict(dt) {
        if (!authState || !authState.snakes) return;

        const isLive     = authState.status === 'in_progress';
        const isCountdown = authState.status === 'countdown';
        const arenaW     = authState.width  || 2400;
        const arenaH     = authState.height || 2400;

        for (const id in localSnakes) {
            const s   = localSnakes[id];
            const a   = authState.snakes[id];
            if (!a || !a.is_alive) { delete localSnakes[id]; continue; }

            const isMe = (myPlayerId !== null && Number(id) === myPlayerId);

            // ---- Angular steering ----
            const wantAngle = isMe ? targetAngle : a.angle;
            let diff = wantAngle - s.angle;
            while (diff >  Math.PI) diff -= 2 * Math.PI;
            while (diff < -Math.PI) diff += 2 * Math.PI;
            const turnRate = isMe ? 16.0 : 12.0;   // rad/s
            s.angle += diff * Math.min(1.0, turnRate * dt);

            // ---- Speed ----
            const wantBoost  = isMe ? isBoosting : (a.is_boosting || false);
            const wantSpeed  = wantBoost ? BOOST_SPEED_S : BASE_SPEED_S;
            s.speed += (wantSpeed - s.speed) * Math.min(1.0, 10.0 * dt);

            // ---- Position ----
            if (isLive) {
                const dist = s.speed * dt;

                if (isMe) {
                    // Full client prediction — move independently, then soft-correct vs server
                    s.x += Math.cos(s.angle) * dist;
                    s.y += Math.sin(s.angle) * dist;

                    // Hard clamp to arena
                    s.x = Math.max(HEAD_R, Math.min(arenaW - HEAD_R, s.x));
                    s.y = Math.max(HEAD_R, Math.min(arenaH - HEAD_R, s.y));

                    // Soft reconciliation if > 120 px away from server
                    const errX = a.x - s.x;
                    const errY = a.y - s.y;
                    const err  = Math.hypot(errX, errY);
                    if (err > 120) {
                        const k = Math.min(0.12, 2.0 * dt);
                        s.x += errX * k;
                        s.y += errY * k;
                    }
                } else {
                    // Remote snake continuous dead-reckoning + interpolation:
                    // 1. Advance position along current heading so remote snakes never freeze between 10Hz ticks
                    s.x += Math.cos(s.angle) * dist;
                    s.y += Math.sin(s.angle) * dist;

                    // 2. Smoothly reconcile toward authoritative server position
                    const errX = a.x - s.x;
                    const errY = a.y - s.y;
                    const errDist = Math.hypot(errX, errY);

                    if (errDist > 250) {
                        // Snap if large jump (respawn or teleport)
                        s.x = a.x;
                        s.y = a.y;
                        s.angle = a.angle;
                        if (a.body && Array.isArray(a.body)) {
                            s.body = a.body.map(seg => ({ x: seg.x, y: seg.y }));
                        }
                    } else {
                        // Smooth exponential convergence
                        const blendK = Math.min(1.0, 9.0 * dt);
                        s.x += errX * blendK;
                        s.y += errY * blendK;
                    }
                }
            } else if (isCountdown) {
                // During countdown snakes are stationary — snap to server pos
                s.x += (a.x - s.x) * Math.min(1.0, 12.0 * dt);
                s.y += (a.y - s.y) * Math.min(1.0, 12.0 * dt);
            }

            // ---- Body ribbon IK ----
            const wantLen = a.length || 14;
            // Grow/shrink body array
            while (s.body.length < wantLen) {
                const tail = s.body[s.body.length - 1] || { x: s.x, y: s.y };
                s.body.push({ x: tail.x, y: tail.y });
            }
            while (s.body.length > wantLen) s.body.pop();

            // Drive head
            s.body[0] = { x: s.x, y: s.y };

            // Chain following (IK)
            for (let i = 0; i < s.body.length - 1; i++) {
                const leader   = s.body[i];
                const follower = s.body[i + 1];
                const dx = follower.x - leader.x;
                const dy = follower.y - leader.y;
                const d  = Math.hypot(dx, dy);
                if (d > 0.0001) {
                    follower.x = leader.x + (dx / d) * SEG_DIST;
                    follower.y = leader.y + (dy / d) * SEG_DIST;
                }

                // Remote body segment soft guidance towards authoritative server body
                if (!isMe && a.body && a.body[i + 1]) {
                    const sSeg = a.body[i + 1];
                    const sx = sSeg.x - follower.x;
                    const sy = sSeg.y - follower.y;
                    if (Math.hypot(sx, sy) < 120) {
                        follower.x += sx * Math.min(0.2, 5.0 * dt);
                        follower.y += sy * Math.min(0.2, 5.0 * dt);
                    }
                }
            }
        }

        // ---- Camera ----
        if (myPlayerId !== null && localSnakes[myPlayerId]) {
            const me = localSnakes[myPlayerId];
            // Smooth camera follow (very tight — feels responsive)
            camera.x += (me.x - camera.x) * Math.min(1.0, 18.0 * dt);
            camera.y += (me.y - camera.y) * Math.min(1.0, 18.0 * dt);
        }

        // ---- Optimistic food eating ----
        if (isLive && myPlayerId !== null && localSnakes[myPlayerId] && authState.foods) {
            const me    = localSnakes[myPlayerId];
            const foods = authState.foods;
            const now   = Date.now();
            for (let i = foods.length - 1; i >= 0; i--) {
                const f  = foods[i];
                const dx = me.x - f.x;
                const dy = me.y - f.y;
                if (dx * dx + dy * dy < EAT_R_SQ) {
                    localEatenMap.set(f.id, now);
                    _pendingEats.push(foods.splice(i, 1)[0]);
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------
    function _draw() {
        const W = canvas.width;
        const H = canvas.height;

        ctx.fillStyle = '#0a0c12';
        ctx.fillRect(0, 0, W, H);

        if (!authState) return;

        const arenaW = authState.width  || 2400;
        const arenaH = authState.height || 2400;

        ctx.save();
        ctx.translate(W / 2, H / 2);
        ctx.scale(camera.zoom, camera.zoom);
        ctx.translate(-camera.x, -camera.y);

        // Viewport culling bounds
        const vW = W / camera.zoom;
        const vH = H / camera.zoom;
        const vL = camera.x - vW / 2 - 60;
        const vR = camera.x + vW / 2 + 60;
        const vT = camera.y - vH / 2 - 60;
        const vB = camera.y + vH / 2 + 60;

        // 1. Hex background (viewport-clipped)
        const bx = Math.max(0, vL), by = Math.max(0, vT);
        const bw = Math.min(arenaW, vR) - bx;
        const bh = Math.min(arenaH, vB) - by;
        if (bw > 0 && bh > 0) {
            ctx.fillStyle = hexPattern || '#0e1017';
            ctx.fillRect(bx, by, bw, bh);
        }

        // 2. Arena danger-zone walls (slither.io style)
        const dz = 150; // danger zone depth px

        // Dark void outside arena bounds
        ctx.fillStyle = '#04050a';
        if (vL < 0)       ctx.fillRect(vL, vT, -vL, vB - vT);
        if (vT < 0)       ctx.fillRect(vL, vT, vR - vL, -vT);
        if (vR > arenaW)  ctx.fillRect(arenaW, vT, vR - arenaW, vB - vT);
        if (vB > arenaH)  ctx.fillRect(vL, arenaH, vR - vL, vB - arenaH);

        // Red danger zone — left wall
        let g = ctx.createLinearGradient(0, 0, dz, 0);
        g.addColorStop(0, 'rgba(255,23,68,0.50)');
        g.addColorStop(1, 'rgba(255,23,68,0.00)');
        ctx.fillStyle = g;
        ctx.fillRect(0, Math.max(0, vT), dz, Math.min(arenaH, vB) - Math.max(0, vT));

        // Red danger zone — right wall
        g = ctx.createLinearGradient(arenaW, 0, arenaW - dz, 0);
        g.addColorStop(0, 'rgba(255,23,68,0.50)');
        g.addColorStop(1, 'rgba(255,23,68,0.00)');
        ctx.fillStyle = g;
        ctx.fillRect(arenaW - dz, Math.max(0, vT), dz, Math.min(arenaH, vB) - Math.max(0, vT));

        // Red danger zone — top wall
        g = ctx.createLinearGradient(0, 0, 0, dz);
        g.addColorStop(0, 'rgba(255,23,68,0.50)');
        g.addColorStop(1, 'rgba(255,23,68,0.00)');
        ctx.fillStyle = g;
        ctx.fillRect(Math.max(0, vL), 0, Math.min(arenaW, vR) - Math.max(0, vL), dz);

        // Red danger zone — bottom wall
        g = ctx.createLinearGradient(0, arenaH, 0, arenaH - dz);
        g.addColorStop(0, 'rgba(255,23,68,0.50)');
        g.addColorStop(1, 'rgba(255,23,68,0.00)');
        ctx.fillStyle = g;
        ctx.fillRect(Math.max(0, vL), arenaH - dz, Math.min(arenaW, vR) - Math.max(0, vL), dz);

        // Glowing red edge line
        ctx.save();
        ctx.shadowBlur   = 18;
        ctx.shadowColor  = '#ff1744';
        ctx.strokeStyle  = 'rgba(255,23,68,0.95)';
        ctx.lineWidth    = 3;
        ctx.strokeRect(0, 0, arenaW, arenaH);
        ctx.restore();

        // 3. Foods (culled)
        const foods = authState.foods || [];
        for (let i = 0; i < foods.length; i++) {
            const f = foods[i];
            if (f.x < vL || f.x > vR || f.y < vT || f.y > vB) continue;
            const val = f.value || 100;
            const key = `${f.color}_${val}`;
            const spr = foodSprites[key] || foodSprites[`${f.color}_100`] || foodSprites['#ffd600_100'];
            const half = val >= 500 ? 32 : (val >= 200 ? 24 : 16);
            if (spr) ctx.drawImage(spr, f.x - half, f.y - half);

            // Floating value badge for higher-value food (200 & 500)
            if (val >= 200) {
                ctx.save();
                ctx.font = val >= 500 ? '900 11px Outfit,sans-serif' : '800 9px Outfit,sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = val >= 500 ? '#ffd600' : '#ffffff';
                ctx.shadowColor = 'rgba(0,0,0,0.85)';
                ctx.shadowBlur = 4;
                ctx.fillText(String(val), f.x, f.y);
                ctx.restore();
            }
        }

        // 4. Snakes
        const snakes = authState.snakes || {};
        for (const id in snakes) {
            const a = snakes[id];
            if (!a.is_alive) continue;
            const ls = localSnakes[id];
            if (!ls) continue;
            _drawSnake(ls, a);
        }

        ctx.restore();
    }

    function _drawSnake(ls, auth) {
        const isRainbow = (auth.color === 'rainbow');
        const key    = auth.color || 'cyan';
        const spr    = segSprites[key] || segSprites.cyan;
        const half   = 19;
        const body   = ls.body;
        if (!body || body.length === 0) return;

        // Base rainbow hue cycles smoothly over time
        const baseHue = (performance.now() * 0.08);

        // Body (tail→neck)
        for (let i = body.length - 1; i >= 0; i--) {
            const seg = body[i];
            if (!seg) continue;

            if (isRainbow && rainbowSegSprites.length > 0) {
                // Cycling rainbow gradient through red, orange, yellow, green, cyan, blue, purple, pink
                const segHue = Math.floor(baseHue + (body.length - 1 - i) * 7.5);
                const bucket = Math.floor(((segHue % 360) + 360) % 360 / 10);
                const rSpr = rainbowSegSprites[bucket] || rainbowSegSprites[0];
                ctx.drawImage(rSpr, seg.x - half, seg.y - half);
            } else {
                ctx.drawImage(spr, seg.x - 18, seg.y - 18);
            }
        }

        // Head
        const hx = ls.x, hy = ls.y, ha = ls.angle;

        // Smooth neon aura around rainbow head
        if (isRainbow) {
            ctx.save();
            ctx.shadowBlur = 18;
            const headHue = Math.floor(baseHue + body.length * 7.5) % 360;
            ctx.shadowColor = `hsl(${headHue}, 100%, 65%)`;
            ctx.beginPath();
            ctx.arc(hx, hy, HEAD_R + 1, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255,255,255,0.01)';
            ctx.fill();
            ctx.restore();
        }

        // Spawn protection / invulnerability aura
        if (auth.invulnerable_until_tick && auth.invulnerable_until_tick > (authState?.tick || 0)) {
            const pulse = (Math.sin(performance.now() * 0.01) * 0.25) + 0.75;
            ctx.save();
            ctx.strokeStyle = `rgba(0, 229, 255, ${0.6 * pulse})`;
            ctx.lineWidth = 2.5;
            ctx.shadowBlur = 12;
            ctx.shadowColor = '#00e5ff';
            ctx.beginPath();
            ctx.arc(hx, hy, HEAD_R + 6, 0, Math.PI * 2);
            ctx.stroke();
            ctx.restore();
        }

        // Eyes
        const ED = 8.5, EF = 5, ER = 5.5, PR = 3;
        const lx = hx + Math.cos(ha) * EF + Math.cos(ha - Math.PI / 2) * ED;
        const ly = hy + Math.sin(ha) * EF + Math.sin(ha - Math.PI / 2) * ED;
        const rx = hx + Math.cos(ha) * EF + Math.cos(ha + Math.PI / 2) * ED;
        const ry = hy + Math.sin(ha) * EF + Math.sin(ha + Math.PI / 2) * ED;

        ctx.fillStyle = '#ffffff';
        ctx.beginPath(); ctx.arc(lx, ly, ER, 0, Math.PI * 2);
        ctx.arc(rx, ry, ER, 0, Math.PI * 2); ctx.fill();
        ctx.strokeStyle = '#111'; ctx.lineWidth = 1; ctx.stroke();

        const px = Math.cos(ha) * 2.2, py = Math.sin(ha) * 2.2;
        ctx.fillStyle = '#111';
        ctx.beginPath(); ctx.arc(lx + px, ly + py, PR, 0, Math.PI * 2);
        ctx.arc(rx + px, ry + py, PR, 0, Math.PI * 2); ctx.fill();

        // Nickname
        ctx.font = '700 13px Outfit,sans-serif';
        ctx.textAlign = 'center';
        ctx.fillStyle = '#fff';
        ctx.shadowColor = 'rgba(0,0,0,0.85)';
        ctx.shadowBlur = 4;
        ctx.fillText(auth.nickname || '', hx, hy - 22);
    }

    function _drawMinimap() {
        const w  = minimapCanvas.width;
        const h  = minimapCanvas.height;
        const aw = authState?.width  || 2400;
        const ah = authState?.height || 2400;

        minimapCtx.clearRect(0, 0, w, h);
        minimapCtx.save();
        minimapCtx.beginPath();
        minimapCtx.arc(w / 2, h / 2, w / 2 - 2, 0, Math.PI * 2);
        minimapCtx.clip();

        minimapCtx.fillStyle = 'rgba(10,14,22,0.92)';
        minimapCtx.fillRect(0, 0, w, h);

        minimapCtx.strokeStyle = 'rgba(255,255,255,0.07)';
        minimapCtx.lineWidth = 1;
        minimapCtx.beginPath();
        minimapCtx.moveTo(w / 2, 0); minimapCtx.lineTo(w / 2, h);
        minimapCtx.moveTo(0, h / 2); minimapCtx.lineTo(w, h / 2);
        minimapCtx.stroke();

        const snakes = authState?.snakes || {};
        for (const id in snakes) {
            const s  = snakes[id];
            if (!s.is_alive) continue;
            const mx = (s.x / aw) * w;
            const my = (s.y / ah) * h;
            const me = myPlayerId !== null && Number(id) === myPlayerId;
            minimapCtx.beginPath();
            minimapCtx.arc(mx, my, me ? 4 : 2.5, 0, Math.PI * 2);
            minimapCtx.fillStyle = me ? '#00e5ff' : (COLOR_MAP[s.color]?.main || '#ff1744');
            minimapCtx.fill();
        }
        minimapCtx.restore();
    }

    // -----------------------------------------------------------------------
    // Sprite generation
    // -----------------------------------------------------------------------
    function _buildSprites() {
        for (const key in COLOR_MAP) {
            const def = COLOR_MAP[key];
            const sz  = 36, r = 14, c = sz / 2;
            const cv  = Object.assign(document.createElement('canvas'), { width: sz, height: sz });
            const cx  = cv.getContext('2d');
            const g   = cx.createRadialGradient(c - r * 0.35, c - r * 0.35, r * 0.1, c, c, r);
            g.addColorStop(0, def.light); g.addColorStop(0.7, def.main); g.addColorStop(1, def.dark);
            cx.beginPath(); cx.arc(c, c, r, 0, Math.PI * 2);
            cx.fillStyle = g; cx.fill();
            cx.strokeStyle = 'rgba(0,0,0,0.35)'; cx.lineWidth = 1.2; cx.stroke();
            segSprites[key] = cv;
        }

        // Pre-render 36 hue steps of radiant neon rainbow body segments
        for (let step = 0; step < 36; step++) {
            const hue = step * 10;
            const sz = 38, r = 14, c = sz / 2;
            const cv = Object.assign(document.createElement('canvas'), { width: sz, height: sz });
            const cx = cv.getContext('2d');

            // Radiant neon halo
            const glow = cx.createRadialGradient(c, c, r * 0.35, c, c, r + 4);
            glow.addColorStop(0, `hsla(${hue}, 100%, 65%, 0.85)`);
            glow.addColorStop(0.6, `hsla(${hue}, 100%, 55%, 0.35)`);
            glow.addColorStop(1, 'rgba(0,0,0,0)');
            cx.fillStyle = glow;
            cx.beginPath(); cx.arc(c, c, r + 4, 0, Math.PI * 2); cx.fill();

            // Vibrant gradient core
            const g = cx.createRadialGradient(c - r * 0.35, c - r * 0.35, r * 0.1, c, c, r);
            g.addColorStop(0, '#ffffff'); // bright shine
            g.addColorStop(0.35, `hsl(${hue}, 100%, 75%)`);
            g.addColorStop(0.75, `hsl(${hue}, 100%, 50%)`);
            g.addColorStop(1, `hsl(${hue}, 100%, 35%)`);
            cx.beginPath(); cx.arc(c, c, r, 0, Math.PI * 2);
            cx.fillStyle = g; cx.fill();
            cx.strokeStyle = 'rgba(255,255,255,0.55)'; cx.lineWidth = 1.0; cx.stroke();

            rainbowSegSprites[step] = cv;
        }

        FOOD_COLORS.forEach(color => {
            [100, 200, 500].forEach(val => {
                let sz, r, glowR;
                if (val >= 500) {
                    sz = 64; r = 13; glowR = 30;
                } else if (val >= 200) {
                    sz = 48; r = 10; glowR = 22;
                } else {
                    sz = 32; r = 6; glowR = 15;
                }
                const c = sz / 2;
                const cv = Object.assign(document.createElement('canvas'), { width: sz, height: sz });
                const cx = cv.getContext('2d');

                // Outer halo
                const halo = cx.createRadialGradient(c, c, 2, c, c, glowR);
                halo.addColorStop(0, color);
                halo.addColorStop(val >= 500 ? 0.45 : 0.35, color);
                halo.addColorStop(1, 'rgba(0,0,0,0)');
                cx.fillStyle = halo;
                cx.beginPath();
                cx.arc(c, c, glowR, 0, Math.PI * 2);
                cx.fill();

                // Inner core
                const core = cx.createRadialGradient(c - 1, c - 1, 0, c, c, r);
                core.addColorStop(0, '#ffffff');
                core.addColorStop(0.55, color);
                core.addColorStop(1, color);
                cx.fillStyle = core;
                cx.beginPath();
                cx.arc(c, c, r, 0, Math.PI * 2);
                cx.fill();

                if (val >= 500) {
                    // Golden outer border ring for 500-pt food
                    cx.strokeStyle = '#ffd600';
                    cx.lineWidth = 1.5;
                    cx.beginPath();
                    cx.arc(c, c, r + 2.5, 0, Math.PI * 2);
                    cx.stroke();
                } else if (val >= 200) {
                    // White border ring for 200-pt food
                    cx.strokeStyle = 'rgba(255,255,255,0.7)';
                    cx.lineWidth = 1.2;
                    cx.beginPath();
                    cx.arc(c, c, r + 1.5, 0, Math.PI * 2);
                    cx.stroke();
                }

                foodSprites[`${color}_${val}`] = cv;
            });
        });
    }

    function _buildHexPattern() {
        const R  = 40;
        const HW = Math.sqrt(3) * R;
        const HH = 2 * R;
        const cv = Object.assign(document.createElement('canvas'), { width: HW, height: HH * 0.75 * 2 });
        const cx = cv.getContext('2d');
        cx.fillStyle = '#0b0d13';
        cx.fillRect(0, 0, cv.width, cv.height);

        function hex(x, y, r) {
            cx.beginPath();
            for (let i = 0; i < 6; i++) {
                const a = (i * 60 + 30) * Math.PI / 180;
                const px = x + r * Math.cos(a), py = y + r * Math.sin(a);
                i === 0 ? cx.moveTo(px, py) : cx.lineTo(px, py);
            }
            cx.closePath();
            const g = cx.createRadialGradient(x, y, 2, x, y, r);
            g.addColorStop(0, '#161922'); g.addColorStop(0.85, '#101219'); g.addColorStop(1, '#080a0e');
            cx.fillStyle = g; cx.fill();
            cx.strokeStyle = 'rgba(28,34,48,0.95)'; cx.lineWidth = 2.5; cx.stroke();
        }

        hex(0,   0,          R - 2);
        hex(HW,  0,          R - 2);
        hex(HW / 2, HH * 0.75, R - 2);
        hex(0,   HH * 1.5,  R - 2);
        hex(HW,  HH * 1.5,  R - 2);

        hexPatCanvas = cv;
        // Pattern will be created once we have a real canvas context
    }

    function rebuildPattern() {
        if (hexPatCanvas && ctx) {
            hexPattern = ctx.createPattern(hexPatCanvas, 'repeat');
        }
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------
    global.SnakeEngine = {
        COLOR_MAP,
        RANK_COLORS,
        init,
        setAuthState,
        setMyPlayer,
        setTarget,
        getCamera,
        getAuthState,
        pollLocalEats,
        rebuildPattern,
    };

})(window);
