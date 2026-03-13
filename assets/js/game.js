let gameId = null;
let currentState = null;
let busy = false;

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeMapOverlay();
});

// ---- API Helpers ----
async function api(url, data = null) {
    const opts = data
        ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }
        : { method: 'GET' };
    const res = await fetch(url, opts);
    const json = await res.json();
    if (json.error === 'not_logged_in') {
        window.location = 'profiles.php';
        return json;
    }
    return json;
}

// ---- Error Modal ----
function showError(message, raw = null) {
    const modal = document.getElementById('error-modal');
    document.getElementById('error-modal-message').textContent = message;
    const details = document.getElementById('error-modal-details');
    const rawEl = document.getElementById('error-modal-raw');
    if (raw) {
        details.style.display = '';
        rawEl.textContent = typeof raw === 'string' ? raw : JSON.stringify(raw, null, 2);
    } else {
        details.style.display = 'none';
        rawEl.textContent = '';
    }
    modal.style.display = 'flex';
}

// ---- Utility ----
function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ---- Init: Read game_id from URL ----
document.addEventListener('DOMContentLoaded', async () => {
    const params = new URLSearchParams(window.location.search);
    gameId = parseInt(params.get('id'));
    if (!gameId) {
        window.location = 'index.php';
        return;
    }

    // Load game title from state
    await refreshState(true);

    // Set title from game data
    if (currentState?.game?.title) {
        document.getElementById('game-title').textContent = currentState.game.title;
        document.title = currentState.game.title + ' - Sleuth';
    }

    // Generate any missing artwork in background
    generateAllArtBackground();

    // Load toolbar tile background
    loadToolbarTile();

    // Load ambient music
    loadMusic();

    // Close character info modal on overlay click
    document.getElementById('character-info-modal').addEventListener('click', (e) => {
        if (e.target.classList.contains('modal-overlay')) hideCharacterInfo();
    });
});

function exitToHome() {
    window.location = 'index.php';
}

function styledConfirm(message) {
    return new Promise(resolve => {
        const overlay = document.getElementById('confirm-overlay');
        const msgEl = document.getElementById('confirm-message');
        msgEl.innerHTML = message.replace(/\n/g, '<br>');
        overlay.classList.add('active');
        const ok = document.getElementById('confirm-ok');
        const cancel = document.getElementById('confirm-cancel');
        function cleanup(result) {
            overlay.classList.remove('active');
            ok.replaceWith(ok.cloneNode(true));
            cancel.replaceWith(cancel.cloneNode(true));
            resolve(result);
        }
        ok.addEventListener('click', () => cleanup(true), { once: true });
        cancel.addEventListener('click', () => cleanup(false), { once: true });
        overlay.addEventListener('click', (e) => { if (e.target === overlay) cleanup(false); }, { once: true });
    });
}

async function openDebug() {
    if (!gameId) return;
    if (await styledConfirm('WARNING: The debug page reveals sensitive information including the plot, killer identity, motive, clues, and full backstory.\n\nOnly use this if you believe the game is broken.\n\nContinue?')) {
        window.open('debug.php?game_id=' + gameId, '_blank');
    }
}

async function resetCurrentGame() {
    if (!gameId) return;
    if (!await styledConfirm('Restart this case? All progress (moves, clues, inventory, chat) will be lost.')) return;
    try {
        const result = await api('api/reset.php', { game_id: gameId });
        if (result.success) {
            document.getElementById('narrative').innerHTML = '';
            await refreshState(true);
        } else {
            showError('Error resetting: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        showError(e.message);
    }
}

// ---- Background Art Generation ----
async function generateAllArtBackground() {
    if (!gameId) return;
    const toast = document.getElementById('art-toast');
    const toastText = document.getElementById('art-toast-text');

    try {
        const check = await api('api/generate_image.php?game_id=' + gameId);
        if (!check.success || check.done) return;

        toast.classList.add('active');
        toastText.textContent = 'Generating missing artwork...';

        let result = check;
        let failures = 0;
        while (failures < 3) {
            if (!result.success || result.done) break;
            if (!result.generated?.image) { failures++; }
            else {
                failures = 0;
                if (result.generated.type === 'location' && currentState?.location?.id === result.generated.id) {
                    currentState.location.image = result.generated.image;
                    updateLocationImage();
                }
                try { await refreshState(); } catch (e) {}
            }
            const remaining = result.remaining;
            const total = result.total;
            toastText.textContent = `Generating artwork... (${total - remaining}/${total})`;
            try {
                result = await api('api/generate_image.php?game_id=' + gameId);
            } catch (e) { failures++; break; }
        }
        toast.classList.remove('active');
    } catch (e) {}
}

// ---- Refresh State ----
async function refreshState(isInitial = false) {
    const result = await api('api/state.php?game_id=' + gameId);
    if (!result.success) return;
    currentState = result;

    updateMeta();
    updateLocationImage();
    updateCharacterSelect();
    updateChatPortrait();
    updateInventory();
    updateNotebook();
    drawMap();

    if (isInitial) {
        if ((currentState.player?.moves_taken || 0) === 0 && currentState.plot) {
            showIntro();
        }
        replayActionLog();
        showLocationEntry();
    }
}

function updateMeta() {
    const s = currentState;
    document.getElementById('move-counter').textContent = 'Moves: ' + (s.player.moves_taken || 0);
    document.getElementById('probe-counter').textContent = 'Probes: ' + (s.player.probes_remaining ?? 5);
    document.getElementById('accusation-counter').textContent = 'Accusations: ' + (s.player.accusations_remaining || 0);

    const probeBtn = document.getElementById('probe-btn');
    if (probeBtn) probeBtn.disabled = (s.player.probes_remaining ?? 5) <= 0;
}

// ---- Narrative ----
function showIntro() {
    const p = currentState.plot;
    const g = currentState.game;
    if (!p) return;

    const setting = [p.setting_description, p.time_period].filter(Boolean).join(' — ');
    let html = `<div class="intro-block">`;
    if (g?.cover_image) {
        html += `<div class="intro-cover" style="background-image:url('${g.cover_image}')"></div>`;
    }
    html += `<div class="intro-title">${esc(g?.title || 'A New Case')}</div>`;
    if (setting) html += `<div class="intro-setting">${esc(setting)}</div>`;
    html += `<div class="intro-text">${esc(p.victim_name)} has been found dead. You have been called in to investigate.</div>`;
    html += `</div>`;
    appendNarrative(html);
}

function replayActionLog() {
    const log = currentState?.action_log || [];
    if (!log.length) return;

    log.forEach(entry => {
        let html = '';
        if (entry.action_text) {
            html += `<div class="action-input-echo">&gt; ${esc(entry.action_text)}</div>`;
        }
        if (entry.result_text) {
            html += `<div class="action-result">${esc(entry.result_text)}</div>`;
        }
        appendNarrative(html);
    });
}

function appendNarrative(html, typewriteSelector = null) {
    const area = document.getElementById('narrative');
    const div = document.createElement('div');
    div.className = 'entry';
    div.innerHTML = html;
    area.appendChild(div);
    area.scrollTop = area.scrollHeight;

    if (typewriteSelector) {
        const target = div.querySelector(typewriteSelector);
        if (target) typewriteElement(target);
    }
}

function typewriteElement(el) {
    const text = el.textContent;
    el.textContent = '';
    el.style.visibility = 'visible';
    const cursor = document.createElement('span');
    cursor.className = 'typewriter-cursor';
    el.appendChild(cursor);

    let i = 0;
    const speed = 8; // ms per character
    const area = document.getElementById('narrative');

    function tick() {
        if (i < text.length) {
            cursor.before(text[i]);
            i++;
            area.scrollTop = area.scrollHeight;
            setTimeout(tick, speed);
        } else {
            cursor.remove();
        }
    }
    tick();
}

function showLocationEntry(animate = false) {
    const s = currentState;
    if (!s.location) return;

    let html = `<div class="location-name">${esc(s.location.name)}</div>`;
    html += `<div class="description">${esc(s.location.description)}</div>`;

    if (s.characters.length) {
        const people = s.characters.map(c => {
            if (!c.is_alive) return `the body of <span>${esc(c.name)}</span>`;
            return `<span>${esc(c.name)}</span>`;
        }).join(', ');
        html += `<div class="people-here">You see: ${people}</div>`;
    }

    if (s.objects.length) {
        const objs = s.objects.map(o => {
            if (o.image) {
                return `<span class="obj-link" onclick="showImageViewer('${o.image}', '${esc(o.name).replace(/'/g, "\\'")}', '${esc(o.name).replace(/'/g, "\\'")}')">${esc(o.name)}</span>`;
            }
            return `<span>${esc(o.name)}</span>`;
        }).join(', ');
        html += `<div class="objects-here">Objects: ${objs}</div>`;
    }

    if (s.exits.length) {
        const exitBadges = s.exits.map(e => {
            const thumb = e.image ? `<div class="exit-thumb" style="background-image:url('${e.image}')"></div>` : `<div class="exit-thumb"><span class="exit-thumb-placeholder">&rarr;</span></div>`;
            const locked = e.is_locked ? '<span class="exit-locked">LOCKED</span>' : '';
            return `<div class="exit-badge${e.is_locked ? ' locked' : ''}" onclick="exitGo('${esc(e.direction).replace(/'/g, "\\'")}')">
                ${thumb}
                <div class="exit-info">
                    <div class="exit-name">${esc(e.name)}</div>
                    <div class="exit-dir">${esc(e.direction)}${locked ? ' ' + locked : ''}</div>
                </div>
            </div>`;
        }).join('');
        html += `<div class="exits-here"><div class="exit-badges">${exitBadges}</div></div>`;
    }

    appendNarrative(html, animate ? '.description' : null);
}

// ---- Actions ----
async function submitAction(e) {
    e.preventDefault();
    const input = document.getElementById('action-input');
    const actionText = input.value.trim();
    if (!actionText || busy) return false;

    busy = true;
    input.value = '';
    document.getElementById('action-btn').disabled = true;

    appendNarrative(`<div class="action-input-echo">&gt; ${esc(actionText)}</div>`);

    try {
        const result = await api('api/action.php', { game_id: gameId, action: actionText });

        if (!result.success) {
            appendNarrative(`<div class="error-message">${esc(result.error)}</div>`);
        } else {
            appendNarrative(`<div class="action-result">${esc(result.narrative)}</div>`, '.action-result');
        }

        const oldLocation = currentState?.location?.id;
        await refreshState();

        if (currentState.location && currentState.location.id !== oldLocation) {
            updateLocationImage();
            showLocationEntry(true);
        }

    } catch (e) {
        appendNarrative(`<div class="error-message">Error: ${esc(e.message)}</div>`);
    }

    busy = false;
    document.getElementById('action-btn').disabled = false;
    input.focus();
    return false;
}

function exitGo(direction) {
    if (busy) return;
    const input = document.getElementById('action-input');
    input.value = `go ${direction}`;
    input.form.dispatchEvent(new Event('submit', { cancelable: true }));
}

// ---- Chat ----
function updateCharacterSelect() {
    const select = document.getElementById('chat-character');
    const current = select.value;
    const chars = (currentState?.characters || []).filter(c => c.is_alive);

    select.innerHTML = '<option value="">-- Select someone to talk to --</option>';
    chars.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        select.appendChild(opt);
    });

    if (current && select.querySelector(`option[value="${current}"]`)) {
        select.value = current;
    }
}

async function loadChatHistory() {
    const charId = document.getElementById('chat-character').value;
    const container = document.getElementById('chat-messages');
    container.innerHTML = '';

    updateChatPortrait();

    if (!charId) return;

    try {
        const result = await api(`api/chatlog.php?game_id=${gameId}&character_id=${charId}`);
        if (result.success && result.messages.length) {
            result.messages.forEach(msg => {
                const speaker = msg.role === 'player' ? 'You' : msg.character_name;
                addChatBubble(container, speaker, msg.message, msg.role);
            });
        }
    } catch (e) {}
}

async function sendChat() {
    const charId = document.getElementById('chat-character').value;
    const input = document.getElementById('chat-input');
    const message = input.value.trim();

    if (!charId || !message || busy) return;

    busy = true;
    input.value = '';

    const container = document.getElementById('chat-messages');
    addChatBubble(container, 'You', message, 'player');

    try {
        const result = await api('api/chat.php', {
            game_id: gameId,
            character_id: parseInt(charId),
            message: message
        });

        if (!result.success) {
            addChatBubble(container, 'System', result.error, 'character');
        } else {
            const emotionTag = result.emotion ? ` <span class="emotion-tag">[${result.emotion}]</span>` : '';
            const trustTag = result.trust_change ? ` <span class="trust-change ${result.trust_change > 0 ? 'positive' : 'negative'}">${result.trust_change > 0 ? '+' : ''}${result.trust_change}</span>` : '';
            addChatBubble(container, result.character_name, result.dialogue, 'character', emotionTag + trustTag);
            if (result.trust_level !== undefined) updateTrustBar(result.trust_level);
        }

        await refreshState();

    } catch (e) {
        addChatBubble(container, 'System', 'Error: ' + e.message, 'character');
    }

    busy = false;
    input.focus();
}

async function sendProbe() {
    const charId = document.getElementById('chat-character').value;
    if (!charId || busy) return;
    if ((currentState?.player?.probes_remaining ?? 5) <= 0) return;

    busy = true;
    const container = document.getElementById('chat-messages');
    addChatBubble(container, 'You', '[Pressing hard for information...]', 'player probe');

    try {
        const result = await api('api/chat.php', {
            game_id: gameId,
            character_id: parseInt(charId),
            message: 'I need you to tell me the truth. What are you hiding? This is serious.',
            probe: true
        });

        if (!result.success) {
            addChatBubble(container, 'System', result.error, 'character');
        } else {
            const emotionTag = result.emotion ? ` <span class="emotion-tag">[${result.emotion}]</span>` : '';
            addChatBubble(container, result.character_name, result.dialogue, 'character probe-response', emotionTag);
            if (result.trust_level !== undefined) updateTrustBar(result.trust_level);
        }

        await refreshState();

    } catch (e) {
        addChatBubble(container, 'System', 'Error: ' + e.message, 'character');
    }

    busy = false;
}

function addChatBubble(container, speaker, text, type, extra = '') {
    const div = document.createElement('div');
    div.className = 'chat-msg ' + type;
    div.innerHTML = `<div class="speaker">${esc(speaker)}${extra}</div><div>${esc(text)}</div>`;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

// ---- Sidebar Tabs ----
function switchTab(tab) {
    document.querySelectorAll('.sidebar-tabs button').forEach(btn => {
        btn.classList.toggle('active', btn.textContent.toLowerCase() === tab);
    });
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');

    if (tab === 'map') drawMap();
}

// ---- Inventory ----
function updateInventory() {
    const container = document.getElementById('inventory-list');
    const items = currentState?.inventory || [];

    if (!items.length) {
        container.innerHTML = '<div class="empty-state">Your pockets are empty.</div>';
        return;
    }

    container.innerHTML = '';
    items.forEach(item => {
        const div = document.createElement('div');
        div.className = 'inventory-item';
        const imageHtml = item.image
            ? `<div class="item-image" style="background-image:url('${item.image}')" onclick="event.stopPropagation(); showImageViewer('${item.image}', '${esc(item.name).replace(/'/g, "\\'")}')"></div>`
            : '';
        div.innerHTML = `
            ${imageHtml}
            <div class="item-name">${esc(item.name)}</div>
            <div class="item-desc">${esc(item.description)}</div>
            ${item.inspect_text ? `<div class="inspect-text">${esc(item.inspect_text)}</div>` : ''}
        `;
        div.onclick = () => {
            div.classList.toggle('expanded');
            if (div.classList.contains('expanded') && item.inspect_text) {
                appendNarrative(`<div class="action-input-echo">&gt; inspect ${esc(item.name)}</div><div class="action-result">${esc(item.inspect_text)}</div>`);
            }
        };
        container.appendChild(div);
    });
}

// ---- Notebook ----
function updateNotebook() {
    const container = document.getElementById('notebook-list');
    const entries = currentState?.notebook || [];

    if (!entries.length) {
        container.innerHTML = '<div class="empty-state">No clues discovered yet. Start investigating!</div>';
        return;
    }

    container.innerHTML = '';
    entries.forEach(entry => {
        const div = document.createElement('div');
        div.className = 'notebook-entry ' + (entry.entry_type || 'clue');
        div.innerHTML = `
            <div>${esc(entry.entry_text)}</div>
            ${entry.source ? `<div class="entry-source">Source: ${esc(entry.source)}</div>` : ''}
        `;
        container.appendChild(div);
    });
}

// ---- Map ----
// ---- Fullscreen Map ----
const _mapImageCache = {};

function openMapOverlay() {
    const overlay = document.getElementById('map-overlay');
    overlay.classList.add('active');
    setTimeout(() => drawFullscreenMap(), 50);
}

function closeMapOverlay() {
    document.getElementById('map-overlay').classList.remove('active');
    closeMapDetail();
}

function drawMap() {
    // Sidebar thumbnail map
    const canvas = document.getElementById('map-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const locations = (currentState?.map_locations || []).filter(l => l.discovered);
    const connections = currentState?.map_connections || [];

    canvas.width = canvas.parentElement.clientWidth || 290;
    canvas.height = canvas.width;
    const w = canvas.width, h = canvas.height, padding = 40;
    ctx.clearRect(0, 0, w, h);

    if (!locations.length) {
        ctx.fillStyle = '#888';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('No map data yet', w / 2, h / 2);
        return;
    }

    const xs = locations.map(l => +l.x_pos), ys = locations.map(l => +l.y_pos);
    const minX = Math.min(...xs), maxX = Math.max(...xs);
    const minY = Math.min(...ys), maxY = Math.max(...ys);
    const rangeX = maxX - minX || 1, rangeY = maxY - minY || 1;
    function toScreen(x, y) {
        return {
            sx: padding + ((x - minX) / rangeX) * (w - padding * 2),
            sy: padding + ((y - minY) / rangeY) * (h - padding * 2)
        };
    }

    const locMap = {};
    locations.forEach(l => locMap[l.id] = l);

    ctx.strokeStyle = '#0f3460';
    ctx.lineWidth = 2;
    connections.forEach(conn => {
        const from = locMap[conn.from_location_id];
        const to = locMap[conn.to_location_id];
        if (!from || !to) return;
        const a = toScreen(from.x_pos, from.y_pos);
        const b = toScreen(to.x_pos, to.y_pos);
        ctx.beginPath(); ctx.moveTo(a.sx, a.sy); ctx.lineTo(b.sx, b.sy); ctx.stroke();
    });

    const currentLocId = currentState?.location?.id;
    locations.forEach(loc => {
        const p = toScreen(loc.x_pos, loc.y_pos);
        const isCurrent = (loc.id == currentLocId);
        ctx.beginPath();
        ctx.arc(p.sx, p.sy, isCurrent ? 10 : 7, 0, Math.PI * 2);
        ctx.fillStyle = isCurrent ? '#e94560' : '#0f3460';
        ctx.fill();
        ctx.strokeStyle = isCurrent ? '#fff' : '#e94560';
        ctx.lineWidth = 2;
        ctx.stroke();
        ctx.fillStyle = isCurrent ? '#fff' : '#aaa';
        ctx.font = (isCurrent ? 'bold ' : '') + '10px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(loc.name, p.sx, p.sy - 14);
    });
}

let _mapNodeRects = []; // Store node positions for click detection

function drawFullscreenMap() {
    const canvas = document.getElementById('map-fullscreen-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    const w = rect.width, h = rect.height;
    ctx.clearRect(0, 0, w, h);
    _mapNodeRects = [];

    const allLocations = currentState?.map_locations || [];
    const locations = allLocations.filter(l => l.discovered);
    const connections = currentState?.map_connections || [];
    const characters = currentState?.map_characters || [];
    const currentLocId = currentState?.location?.id;

    if (!locations.length) {
        ctx.fillStyle = '#888';
        ctx.font = '16px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('No discovered locations yet', w / 2, h / 2);
        return;
    }

    // Group by floor
    const floors = {};
    locations.forEach(l => {
        const z = +(l.z_pos || 0);
        if (!floors[z]) floors[z] = [];
        floors[z].push(l);
    });
    const floorKeys = Object.keys(floors).map(Number).sort((a, b) => b - a);
    const numFloors = floorKeys.length;

    // Grid bounds
    const xs = locations.map(l => +l.x_pos), ys = locations.map(l => +l.y_pos);
    const minX = Math.min(...xs), maxX = Math.max(...xs);
    const minY = Math.min(...ys), maxY = Math.max(...ys);
    const rangeX = maxX - minX || 1, rangeY = maxY - minY || 1;

    const padding = 90;
    const nodeW = 160, nodeH = 160;
    const floorGap = 50;
    const verticalFloors = window._mobileMapVerticalFloors && numFloors > 1;
    const floorWidth = verticalFloors ? w : (numFloors > 1 ? (w - floorGap * (numFloors - 1)) / numFloors : w);
    const floorHeight = verticalFloors ? (h - floorGap * (numFloors - 1)) / numFloors : h;

    // Group characters and objects by location
    const charsByLoc = {};
    characters.forEach(ch => {
        if (!charsByLoc[ch.location_id]) charsByLoc[ch.location_id] = [];
        charsByLoc[ch.location_id].push(ch);
    });
    const objsByLoc = {};
    (currentState?.map_objects || []).forEach(obj => {
        if (!objsByLoc[obj.location_id]) objsByLoc[obj.location_id] = [];
        objsByLoc[obj.location_id].push(obj);
    });

    function pos(l) {
        const z = +(l.z_pos || 0);
        const floorIdx = floorKeys.indexOf(z);
        if (verticalFloors) {
            const floorOff = floorIdx * (floorHeight + floorGap);
            return {
                x: padding + ((+l.x_pos - minX) / rangeX) * (floorWidth - padding * 2 - nodeW) + nodeW / 2,
                y: floorOff + padding + 25 + ((+l.y_pos - minY) / rangeY) * (floorHeight - padding * 2 - nodeH - 25) + nodeH / 2
            };
        }
        const floorOff = floorIdx * (floorWidth + floorGap);
        return {
            x: floorOff + padding + ((+l.x_pos - minX) / rangeX) * (floorWidth - padding * 2 - nodeW) + nodeW / 2,
            y: padding + 25 + ((+l.y_pos - minY) / rangeY) * (h - padding * 2 - nodeH - 25) + nodeH / 2
        };
    }

    // Floor labels and dividers
    const floorLabels = { '-1': 'Basement', '0': 'Ground Floor', '1': 'Upper Floor', '2': '2nd Floor' };
    floorKeys.forEach((z, idx) => {
        if (verticalFloors) {
            const floorOff = idx * (floorHeight + floorGap);
            ctx.fillStyle = 'rgba(255,255,255,0.5)';
            ctx.font = 'bold 14px sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText(floorLabels[z] || 'Floor ' + z, 12, floorOff + 22);
            if (idx > 0) {
                ctx.strokeStyle = 'rgba(255,255,255,0.12)';
                ctx.lineWidth = 1;
                ctx.setLineDash([6, 4]);
                ctx.beginPath();
                ctx.moveTo(0, floorOff - floorGap / 2);
                ctx.lineTo(w, floorOff - floorGap / 2);
                ctx.stroke();
                ctx.setLineDash([]);
            }
        } else {
            const floorOff = idx * (floorWidth + floorGap);
            ctx.fillStyle = 'rgba(255,255,255,0.5)';
            ctx.font = 'bold 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(floorLabels[z] || 'Floor ' + z, floorOff + floorWidth / 2, 22);
            if (idx > 0) {
                ctx.strokeStyle = 'rgba(255,255,255,0.12)';
                ctx.lineWidth = 1;
                ctx.setLineDash([6, 4]);
                ctx.beginPath();
                ctx.moveTo(floorOff - floorGap / 2, 0);
                ctx.lineTo(floorOff - floorGap / 2, h);
                ctx.stroke();
                ctx.setLineDash([]);
            }
        }
    });

    const locMap = {};
    locations.forEach(l => locMap[l.id] = l);

    // Draw connections
    connections.forEach(conn => {
        const from = locMap[conn.from_location_id];
        const to = locMap[conn.to_location_id];
        if (!from || !to) return;
        const p1 = pos(from), p2 = pos(to);
        const isVertical = conn.direction === 'up' || conn.direction === 'down';
        ctx.beginPath();
        ctx.moveTo(p1.x, p1.y);
        if (isVertical) {
            const mx = (p1.x + p2.x) / 2;
            const my = (p1.y + p2.y) / 2;
            ctx.quadraticCurveTo(mx, my - 40, p2.x, p2.y);
        } else {
            ctx.lineTo(p2.x, p2.y);
        }
        ctx.strokeStyle = isVertical ? 'rgba(233, 69, 96, 0.4)' : 'rgba(15, 52, 96, 0.5)';
        ctx.lineWidth = 2;
        if (isVertical) ctx.setLineDash([8, 4]);
        ctx.stroke();
        ctx.setLineDash([]);

        // Direction label at midpoint
        const mx = (p1.x + p2.x) / 2, my = (p1.y + p2.y) / 2;
        ctx.fillStyle = 'rgba(255,255,255,0.25)';
        ctx.font = '9px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(conn.direction, mx, my - 6);
    });

    // Draw nodes
    locations.forEach(loc => {
        const p = pos(loc);
        const isCurrent = (loc.id == currentLocId);
        const rx = nodeW / 2, ry = nodeH / 2;
        const imgH = nodeH - 36; // image area height

        // Store rect for click detection
        _mapNodeRects.push({ id: loc.id, x: p.x - rx, y: p.y - ry, w: nodeW, h: nodeH });

        // Node background
        ctx.fillStyle = isCurrent ? 'rgba(233, 69, 96, 0.15)' : 'rgba(22, 33, 62, 0.9)';
        ctx.strokeStyle = isCurrent ? '#e94560' : 'rgba(15, 52, 96, 0.8)';
        ctx.lineWidth = isCurrent ? 3 : 1.5;
        ctx.beginPath();
        ctx.roundRect(p.x - rx, p.y - ry, nodeW, nodeH, 10);
        ctx.fill();
        ctx.stroke();

        // Location image — fills most of the node
        if (loc.image) {
            const imgKey = 'loc_' + loc.id;
            if (!_mapImageCache[imgKey]) {
                const img = new Image();
                img.src = loc.image;
                img.onload = () => { _mapImageCache[imgKey] = img; drawFullscreenMap(); };
                _mapImageCache[imgKey] = 'loading';
            } else if (_mapImageCache[imgKey] !== 'loading') {
                const img = _mapImageCache[imgKey];
                const imgX = p.x - rx + 4, imgY = p.y - ry + 4;
                const imgW = nodeW - 8;
                ctx.save();
                ctx.beginPath();
                ctx.roundRect(imgX, imgY, imgW, imgH, 6);
                ctx.clip();
                // Cover-fit the image
                const aspect = img.width / img.height;
                let dw = imgW, dh = imgH;
                if (aspect > imgW / imgH) { dh = imgH; dw = dh * aspect; }
                else { dw = imgW; dh = dw / aspect; }
                const dx = imgX + (imgW - dw) / 2;
                const dy = imgY + (imgH - dh) / 2;
                ctx.drawImage(img, dx, dy, dw, dh);
                ctx.restore();
            }
        } else {
            // No image — dark placeholder
            ctx.fillStyle = 'rgba(0,0,0,0.3)';
            ctx.beginPath();
            ctx.roundRect(p.x - rx + 4, p.y - ry + 4, nodeW - 8, imgH, 6);
            ctx.fill();
        }

        // Location name below image
        ctx.fillStyle = isCurrent ? '#fff' : '#dee2e6';
        ctx.font = (isCurrent ? 'bold ' : '') + '12px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        let name = loc.name;
        while (ctx.measureText(name).width > nodeW - 12 && name.length > 3) name = name.slice(0, -1);
        if (name !== loc.name) name += '…';
        ctx.fillText(name, p.x, p.y + ry - 30);

        // Character avatars along the bottom
        const chars = charsByLoc[loc.id] || [];
        if (chars.length > 0) {
            const charSize = 22;
            const charY = p.y + ry - charSize - 2;
            const totalW = chars.length * (charSize + 3) - 3;
            let cx = p.x - totalW / 2;
            chars.forEach(ch => {
                const cacheKey = 'ch_' + ch.name;
                if (ch.image && !_mapImageCache[cacheKey]) {
                    const img = new Image();
                    img.src = ch.image;
                    img.onload = () => { _mapImageCache[cacheKey] = img; drawFullscreenMap(); };
                    _mapImageCache[cacheKey] = 'loading';
                } else if (ch.image && _mapImageCache[cacheKey] && _mapImageCache[cacheKey] !== 'loading') {
                    const img = _mapImageCache[cacheKey];
                    ctx.save();
                    ctx.beginPath();
                    ctx.arc(cx + charSize / 2, charY + charSize / 2, charSize / 2, 0, Math.PI * 2);
                    ctx.clip();
                    ctx.drawImage(img, cx, charY, charSize, charSize);
                    ctx.restore();
                    // Border
                    ctx.strokeStyle = ch.is_alive ? 'rgba(255,255,255,0.4)' : '#e94560';
                    ctx.lineWidth = ch.is_alive ? 1 : 2;
                    ctx.beginPath();
                    ctx.arc(cx + charSize / 2, charY + charSize / 2, charSize / 2, 0, Math.PI * 2);
                    ctx.stroke();
                } else {
                    // Fallback dot with initial
                    ctx.fillStyle = ch.is_alive ? '#0f3460' : '#e94560';
                    ctx.beginPath();
                    ctx.arc(cx + charSize / 2, charY + charSize / 2, charSize / 2, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.fillStyle = '#fff';
                    ctx.font = '10px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(ch.name[0], cx + charSize / 2, charY + charSize / 2);
                }
                cx += charSize + 3;
            });
        }

        // "You are here" glow
        if (isCurrent) {
            ctx.fillStyle = '#e94560';
            ctx.beginPath();
            ctx.arc(p.x + rx - 10, p.y - ry + 10, 6, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 8px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('YOU', p.x + rx - 10, p.y - ry + 10);
        }
    });

    // Undiscovered "?" nodes
    allLocations.forEach(loc => {
        if (loc.discovered) return;
        const hasLink = connections.some(c =>
            c.from_location_id == loc.id || c.to_location_id == loc.id
        );
        if (!hasLink) return;
        const p = pos(loc);
        ctx.fillStyle = 'rgba(255,255,255,0.06)';
        ctx.beginPath();
        ctx.roundRect(p.x - 30, p.y - 20, 60, 40, 8);
        ctx.fill();
        ctx.strokeStyle = 'rgba(255,255,255,0.1)';
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.fillStyle = '#444';
        ctx.font = '18px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('?', p.x, p.y);
    });
}

// Click handler for map nodes
(function() {
    let _mapClickBound = false;
    const observer = new MutationObserver(() => {
        const canvas = document.getElementById('map-fullscreen-canvas');
        if (canvas && !_mapClickBound) {
            _mapClickBound = true;
            canvas.style.cursor = 'pointer';
            canvas.addEventListener('click', (e) => {
                const rect = canvas.getBoundingClientRect();
                const mx = e.clientX - rect.left;
                const my = e.clientY - rect.top;
                for (const node of _mapNodeRects) {
                    if (mx >= node.x && mx <= node.x + node.w && my >= node.y && my <= node.y + node.h) {
                        showMapDetail(node.id);
                        return;
                    }
                }
                closeMapDetail();
            });
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();

function showMapDetail(locId) {
    const loc = (currentState?.map_locations || []).find(l => l.id == locId);
    if (!loc || !loc.discovered) return;

    const characters = (currentState?.map_characters || []).filter(ch => ch.location_id == locId);
    const objects = (currentState?.map_objects || []).filter(o => o.location_id == locId);
    const currentLocId = currentState?.location?.id;

    document.getElementById('map-detail-name').textContent = loc.name;

    // Image
    const imgWrap = document.getElementById('map-detail-image-wrap');
    const imgEl = document.getElementById('map-detail-image');
    if (loc.image) {
        imgEl.src = loc.image;
        imgWrap.style.display = 'block';
    } else {
        imgWrap.style.display = 'none';
    }

    // Description
    document.getElementById('map-detail-desc').textContent = loc.short_description || '';

    // Current location indicator
    const detail = document.getElementById('map-detail');
    const existing = detail.querySelector('.map-detail-current');
    if (existing) existing.remove();
    if (loc.id == currentLocId) {
        const tag = document.createElement('div');
        tag.className = 'map-detail-current';
        tag.textContent = 'You are here';
        detail.querySelector('.map-detail-header').after(tag);
    }

    // Characters
    const charsDiv = document.getElementById('map-detail-chars');
    if (characters.length > 0) {
        let html = '<div class="map-detail-section"><div class="map-detail-section-title">People here</div>';
        characters.forEach(ch => {
            const nameClass = ch.is_alive ? '' : ' dead';
            const imgHtml = ch.image
                ? '<img src="' + ch.image + '" alt="">'
                : '<div class="map-detail-dot">' + ch.name[0] + '</div>';
            const status = ch.is_alive ? (ch.has_met ? '' : ' <span style="color:#888;font-size:11px;">(not met)</span>') : ' <span style="color:#e94560;font-size:11px;">(dead)</span>';
            html += '<div class="map-detail-item">' + imgHtml + '<span class="map-detail-item-name' + nameClass + '">' + ch.name + status + '</span></div>';
        });
        html += '</div>';
        charsDiv.innerHTML = html;
    } else {
        charsDiv.innerHTML = '';
    }

    // Objects
    const objsDiv = document.getElementById('map-detail-objects');
    if (objects.length > 0) {
        let html = '<div class="map-detail-section"><div class="map-detail-section-title">Objects here</div>';
        objects.forEach(obj => {
            const nameClass = obj.is_evidence ? ' evidence' : '';
            const imgHtml = obj.image
                ? '<img src="' + obj.image + '" alt="">'
                : '<div class="map-detail-dot">?</div>';
            html += '<div class="map-detail-item">' + imgHtml + '<span class="map-detail-item-name' + nameClass + '">' + obj.name + '</span></div>';
        });
        html += '</div>';
        objsDiv.innerHTML = html;
    } else {
        objsDiv.innerHTML = '';
    }

    detail.style.display = 'block';
}

function closeMapDetail() {
    document.getElementById('map-detail').style.display = 'none';
}

// ---- Accusation ----
let selectedSuspectId = null;
let selectedWeaponId = null;
let selectedMotiveId = null;
let accuseStep = 0;
let accuseHasMotives = false;

// Step order: 0=suspect, 1=weapon, 2=motive (if available), then confirm
function getAccusePages() {
    const pages = ['suspect', 'weapon'];
    if (accuseHasMotives) pages.push('motive');
    pages.push('confirm');
    return pages;
}

function accuseGoToStep(step) {
    accuseStep = step;
    const pages = getAccusePages();

    // Show/hide pages
    document.querySelectorAll('.accuse-page').forEach(p => p.classList.remove('active'));
    document.getElementById('accuse-page-' + pages[step]).classList.add('active');

    // Update step dots
    const dots = document.querySelectorAll('.accuse-step-dot');
    const lines = document.querySelectorAll('.accuse-step-line');
    // Hide motive dot/line if no motives
    const motiveDot = document.getElementById('accuse-step-dot-motive');
    const motiveLine = document.getElementById('accuse-step-line-motive');
    if (motiveDot) motiveDot.style.display = accuseHasMotives ? '' : 'none';
    if (motiveLine) motiveLine.style.display = accuseHasMotives ? '' : 'none';

    dots.forEach((dot, i) => {
        if (dot.style.display === 'none') return;
        dot.classList.remove('active', 'done');
        if (i < step) dot.classList.add('done');
        else if (i === step) dot.classList.add('active');
    });
    lines.forEach((line, i) => {
        if (line.style.display === 'none') return;
        line.classList.toggle('done', i < step);
    });

    // Update confirm summary when reaching confirm page
    if (pages[step] === 'confirm') updateAccuseSummary();
}

function accuseNext() {
    accuseGoToStep(accuseStep + 1);
}

function accuseBack() {
    if (accuseStep > 0) accuseGoToStep(accuseStep - 1);
}

async function showAccuseModal() {
    selectedSuspectId = null;
    selectedWeaponId = null;
    selectedMotiveId = null;

    const suspectsGrid = document.getElementById('accuse-suspects');
    const weaponsGrid = document.getElementById('accuse-weapons');
    suspectsGrid.innerHTML = '';
    weaponsGrid.innerHTML = '';

    // Determine if per-character motives are available
    const charMotives = currentState?.character_motives || null;
    accuseHasMotives = charMotives && Object.keys(charMotives).length > 0;

    // Load met characters
    try {
        const result = await api('api/characters.php?game_id=' + gameId + '&met_only=1');
        if (result.success && result.characters.length) {
            document.getElementById('accuse-suspects-empty').style.display = 'none';
            result.characters.forEach(c => {
                const card = document.createElement('div');
                card.className = 'accuse-card';
                card.dataset.id = c.id;
                card.innerHTML = `
                    <div class="accuse-card-img" style="${c.image ? "background-image:url('" + c.image + "')" : ''}">
                        ${!c.image ? '<div class="accuse-card-placeholder">?</div>' : ''}
                    </div>
                    <div class="accuse-card-name">${esc(c.name)}</div>
                `;
                card.onclick = () => selectSuspect(c.id, card);
                suspectsGrid.appendChild(card);
            });
        } else {
            document.getElementById('accuse-suspects-empty').style.display = '';
        }
    } catch (e) {}

    // Load inventory as weapon options
    const items = currentState?.inventory || [];
    if (items.length) {
        document.getElementById('accuse-weapons-empty').style.display = 'none';
        items.forEach(item => {
            const card = document.createElement('div');
            card.className = 'accuse-card';
            card.dataset.id = item.id;
            card.innerHTML = `
                <div class="accuse-card-img" style="${item.image ? "background-image:url('" + item.image + "')" : ''}">
                    ${!item.image ? '<div class="accuse-card-placeholder">?</div>' : ''}
                </div>
                <div class="accuse-card-name">${esc(item.name)}</div>
            `;
            card.onclick = () => selectWeapon(item.id, card);
            weaponsGrid.appendChild(card);
        });
    } else {
        document.getElementById('accuse-weapons-empty').style.display = '';
    }

    // Clear motive grid (will be populated when suspect is selected)
    const motivesGrid = document.getElementById('accuse-motives');
    motivesGrid.innerHTML = '';

    accuseGoToStep(0);
    document.getElementById('accuse-modal').classList.add('active');
}

function loadMotiveCards(motives) {
    const motivesGrid = document.getElementById('accuse-motives');
    motivesGrid.innerHTML = '';
    // Shuffle motives so correct answer isn't always in the same position
    const shuffled = [...motives];
    for (let i = shuffled.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
    }
    shuffled.forEach((m) => {
        const card = document.createElement('div');
        card.className = 'accuse-motive-card';
        card.setAttribute('data-motive-id', m.id);
        const iconSvg = (typeof getMotiveIcon === 'function') ? getMotiveIcon(m.category) : '';
        const categoryLabel = (typeof getMotiveLabel === 'function') ? getMotiveLabel(m.category) : m.category;
        card.innerHTML = `
            <div class="motive-icon">${iconSvg}</div>
            <div class="motive-category">${esc(categoryLabel)}</div>
            <div class="motive-text">${esc(m.text)}</div>
        `;
        card.onclick = () => selectMotive(m.id, card);
        motivesGrid.appendChild(card);
    });
}

function selectSuspect(id, card) {
    document.querySelectorAll('#accuse-suspects .accuse-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    selectedSuspectId = id;
    selectedMotiveId = null;
    document.getElementById('accuse-next-suspect').disabled = false;
    document.getElementById('accuse-next-motive').disabled = true;

    // Load per-character motives for selected suspect
    const charMotives = currentState?.character_motives || null;
    if (charMotives && charMotives[id]) {
        loadMotiveCards(charMotives[id]);
    }
}

function selectWeapon(id, card) {
    document.querySelectorAll('#accuse-weapons .accuse-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    selectedWeaponId = id;
    document.getElementById('accuse-next-weapon').disabled = false;
}

function selectMotive(id, card) {
    document.querySelectorAll('#accuse-motives .accuse-motive-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    selectedMotiveId = id;
    document.getElementById('accuse-next-motive').disabled = false;
}

function updateAccuseSummary() {
    const suspectCard = selectedSuspectId ? document.querySelector(`#accuse-suspects .accuse-card[data-id="${selectedSuspectId}"]`) : null;
    const weaponCard = selectedWeaponId ? document.querySelector(`#accuse-weapons .accuse-card[data-id="${selectedWeaponId}"]`) : null;
    const suspectName = suspectCard ? suspectCard.querySelector('.accuse-card-name').textContent : '???';
    const weaponName = weaponCard ? weaponCard.querySelector('.accuse-card-name').textContent : '???';

    let summaryHtml = `<strong>${esc(suspectName)}</strong> did it with the <strong>${esc(weaponName)}</strong>`;

    if (accuseHasMotives) {
        const motiveCard = (selectedMotiveId !== null) ? document.querySelector(`#accuse-motives .accuse-motive-card[data-motive-id="${selectedMotiveId}"]`) : null;
        const motiveLabel = motiveCard ? motiveCard.querySelector('.motive-category').textContent : '???';
        summaryHtml += `<br>because of <strong>${esc(motiveLabel)}</strong>`;
    }

    document.getElementById('accuse-summary').innerHTML = summaryHtml;
}

function hideAccuseModal() {
    document.getElementById('accuse-modal').classList.remove('active');
}

async function submitAccusation() {
    if (!selectedSuspectId || !selectedWeaponId) return;

    hideAccuseModal();

    try {
        const payload = {
            game_id: gameId,
            accused_id: selectedSuspectId,
            weapon_id: selectedWeaponId
        };
        if (selectedMotiveId !== null) {
            payload.motive_id = selectedMotiveId;
        }
        const result = await api('api/accuse.php', payload);

        if (!result.success) {
            appendNarrative(`<div class="error-message">${esc(result.error)}</div>`);
            return;
        }

        if (result.game_over) {
            showGameOver(result);
        } else {
            appendNarrative(`<div class="system-message">${esc(result.message)}</div>`);
            await refreshState();
        }

    } catch (e) {
        appendNarrative(`<div class="error-message">Error: ${esc(e.message)}</div>`);
    }
}

function showGameOver(result) {
    const overlay = document.getElementById('game-over');
    document.getElementById('game-over-title').textContent = result.correct ? 'Case Solved!' : 'Case Closed';
    document.getElementById('game-over-text').textContent = result.message;

    if (result.solution) {
        let sol = `<strong>Killer:</strong> ${esc(result.solution.killer)}<br>`;
        sol += `<strong>Weapon:</strong> ${esc(result.solution.weapon)}<br>`;
        sol += `<strong>Motive:</strong> ${esc(result.solution.motive)}`;
        if (result.solution.backstory) {
            sol += `<br><br><strong>The Full Story:</strong><br>${esc(result.solution.backstory)}`;
        }
        if (result.moves_taken) {
            const probesUsed = result.probes_used ?? (5 - (currentState?.player?.probes_remaining ?? 5));
            sol += `<br><br>Solved in <strong>${result.moves_taken}</strong> moves.`;
            if (probesUsed > 0) {
                sol += ` Used <strong>${probesUsed}</strong> probe${probesUsed !== 1 ? 's' : ''}.`;
            } else {
                sol += ` No probes used — pure detective work!`;
            }
        }
        document.getElementById('game-over-solution').innerHTML = sol;
    }

    overlay.classList.add('active');
}

// ---- Location Image Bar ----
function updateLocationImage() {
    const bar = document.getElementById('location-image-bar');
    const loc = currentState?.location;

    if (loc?.image) {
        // Remove and re-add class to retrigger fade animation
        bar.classList.remove('active');
        bar.style.backgroundImage = `url('${loc.image}')`;
        // Force reflow so animation replays
        void bar.offsetWidth;
        bar.classList.add('active');
        bar.onclick = () => showImageViewer(loc.image, loc.name);
    } else {
        bar.classList.remove('active');
        bar.style.backgroundImage = '';
        bar.onclick = null;
    }
}

// ---- Character Portrait in Chat ----
function showChatPortrait(image, name) {
    const portrait = document.getElementById('chat-portrait');
    const nameEl = document.getElementById('chat-portrait-name');
    if (image) {
        portrait.style.backgroundImage = `url('${image}')`;
        portrait.classList.add('active');
        if (nameEl) nameEl.textContent = name || '';
    } else {
        portrait.classList.remove('active');
        portrait.style.backgroundImage = '';
        if (nameEl) nameEl.textContent = '';
    }
}

function updateChatPortrait() {
    const charId = parseInt(document.getElementById('chat-character').value);
    if (!charId) {
        showChatPortrait(null, null);
        updateTrustBar(null);
        return;
    }
    const char = (currentState?.characters || []).find(c => parseInt(c.id) === charId);
    showChatPortrait(char?.image || null, char?.name || null);
    updateTrustBar(char ? parseInt(char.trust_level) : null);
}

function updateTrustBar(level) {
    const container = document.getElementById('trust-bar-container');
    const fill = document.getElementById('trust-bar-fill');
    const label = document.getElementById('trust-label');
    if (level === null || level === undefined) {
        container.style.display = 'none';
        return;
    }
    container.style.display = 'flex';
    fill.style.width = level + '%';
    // Color: red < 30, yellow 30-60, blue 60-80, green 80+
    if (level >= 80) { fill.style.backgroundColor = '#2ed573'; }
    else if (level >= 60) { fill.style.backgroundColor = '#5b9bd5'; }
    else if (level >= 30) { fill.style.backgroundColor = '#ffc700'; }
    else { fill.style.backgroundColor = '#e94560'; }
    label.textContent = level >= 80 ? 'Trusting' : level >= 60 ? 'Warm' : level >= 30 ? 'Cautious' : 'Guarded';
}

// ---- Character Info Modal ----
function showCharacterInfo() {
    const charId = parseInt(document.getElementById('chat-character').value);
    if (!charId) return;

    const char = (currentState?.characters || []).find(c => parseInt(c.id) === charId);
    if (!char) return;

    const modal = document.getElementById('character-info-modal');
    const imageEl = document.getElementById('char-info-image');
    const nameEl = document.getElementById('char-info-name');
    const descEl = document.getElementById('char-info-desc');

    // Image
    if (char.image) {
        imageEl.style.backgroundImage = `url('${char.image}')`;
        imageEl.classList.add('has-image');
    } else {
        imageEl.style.backgroundImage = '';
        imageEl.classList.remove('has-image');
    }

    // Info
    nameEl.textContent = char.name;

    descEl.textContent = char.description || '';

    modal.classList.add('active');
}

function hideCharacterInfo() {
    document.getElementById('character-info-modal').classList.remove('active');
}

// ---- Image Viewer ----
let imageViewerObjectName = null;

function showImageViewer(imageSrc, caption, objectName) {
    const overlay = document.getElementById('image-viewer');
    document.getElementById('image-viewer-img').src = imageSrc;
    document.getElementById('image-viewer-caption').textContent = caption || '';

    // Show action buttons only for objects (not locations)
    const actions = document.getElementById('image-viewer-actions');
    imageViewerObjectName = objectName || null;
    actions.style.display = imageViewerObjectName ? '' : 'none';

    overlay.classList.add('active');
}

function closeImageViewer() {
    document.getElementById('image-viewer').classList.remove('active');
}

function imageViewerAction(action) {
    if (!imageViewerObjectName) return;
    const command = action === 'pickup' ? `pick up ${imageViewerObjectName}` : `inspect ${imageViewerObjectName}`;
    document.getElementById('image-viewer').classList.remove('active');

    // Put command in input and submit it
    const input = document.getElementById('action-input');
    input.value = command;
    input.form.dispatchEvent(new Event('submit', { cancelable: true }));
}

// ---- Ambient Music Player ----
// ---- Toolbar Tile Background ----
async function loadToolbarTile() {
    if (!gameId) return;
    try {
        const res = await fetch(`api/generate_tile.php?game_id=${gameId}`);
        const data = await res.json();
        if (data.success && data.path) {
            const bar = document.getElementById('title-bar');
            bar.style.backgroundImage = `url('${data.path}')`;
            bar.style.backgroundSize = '256px 256px';
            bar.style.backgroundRepeat = 'repeat';
        }
    } catch (e) {
        console.log('Tile load failed:', e);
    }
}

// ---- Ambient Music ----
let _musicTracks = [];
let _musicIndex = 0;
let _musicAudio = null;
let _musicPlaying = false;
let _musicLoaded = false;

async function loadMusic() {
    if (_musicLoaded) return;
    _musicLoaded = true;

    const p = currentState?.plot;
    if (!p) return;

    const params = new URLSearchParams();
    if (p.setting_description) params.set('setting', p.setting_description);
    if (p.time_period) params.set('period', p.time_period);

    try {
        const result = await api('api/music.php?' + params.toString());
        if (result.success && result.tracks?.length) {
            _musicTracks = result.tracks;
            startMusic();
        }
    } catch (e) {
        // Music is non-essential, fail silently
    }
}

function startMusic() {
    if (!_musicTracks.length) return;
    _musicIndex = 0;
    playTrack(_musicIndex);
}

function playTrack(index) {
    if (index >= _musicTracks.length) index = 0;
    _musicIndex = index;

    const track = _musicTracks[index];
    if (_musicAudio) {
        _musicAudio.pause();
        _musicAudio.removeEventListener('ended', onTrackEnded);
    }

    _musicAudio = new Audio(track.url);
    _musicAudio.volume = 0.3;
    _musicAudio.addEventListener('ended', onTrackEnded);

    const titleEl = document.getElementById('music-title');
    if (titleEl) titleEl.textContent = track.name;

    const toggleBtn = document.getElementById('music-toggle');

    _musicAudio.play().then(() => {
        _musicPlaying = true;
        if (toggleBtn) toggleBtn.classList.add('playing');
    }).catch(() => {
        // Autoplay blocked — user must click
        _musicPlaying = false;
        if (toggleBtn) toggleBtn.classList.remove('playing');
    });
}

function onTrackEnded() {
    if (!_musicPlaying) return;
    playTrack(_musicIndex + 1);
}

function toggleMusic() {
    if (!_musicTracks.length) {
        // Try loading if not loaded yet
        _musicLoaded = false;
        loadMusic();
        return;
    }

    const toggleBtn = document.getElementById('music-toggle');

    if (_musicPlaying) {
        if (_musicAudio) _musicAudio.pause();
        _musicPlaying = false;
        if (toggleBtn) toggleBtn.classList.remove('playing');
    } else {
        if (_musicAudio) {
            _musicAudio.play().then(() => {
                _musicPlaying = true;
                if (toggleBtn) toggleBtn.classList.add('playing');
            });
        } else {
            playTrack(_musicIndex);
        }
    }
}

function skipTrack() {
    if (!_musicTracks.length) return;
    playTrack(_musicIndex + 1);
}
