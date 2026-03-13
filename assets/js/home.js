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

// ---- Load Saved Games on Splash ----
let savedGamesData = [];

async function loadSavedGames() {
    try {
        const result = await api('api/games.php');
        const container = document.getElementById('saved-games');
        if (!result.success || !result.games.length) {
            container.innerHTML = '';
            return;
        }
        savedGamesData = result.games;
        container.innerHTML = '';
        result.games.forEach(g => {
            const card = document.createElement('div');
            card.className = 'game-card' + (g.is_own ? '' : ' game-card-other');
            card.onclick = () => showGameDetail(g);

            const coverStyle = g.cover_image
                ? `background-image: url('${g.cover_image}')`
                : `background: linear-gradient(135deg, #1a1a2e 0%, #0f3460 100%); display:flex; align-items:center; justify-content:center;`;

            const coverInner = g.cover_image
                ? ''
                : `<span style="font-size:2.5em; opacity:0.3;">?</span>`;

            const statusLabel = g.status === 'won' ? 'Solved' : g.status === 'lost' ? 'Cold Case' : 'Active';

            const ownerBadge = !g.is_own && g.profile_name
                ? `<span class="card-owner" style="color:${g.profile_color || '#888'}">${esc(g.profile_name)}</span>`
                : '';

            card.innerHTML = `
                <div class="card-cover" style="${coverStyle}">
                    ${coverInner}
                    <span class="card-status ${g.status}">${statusLabel}</span>
                    ${ownerBadge}
                </div>
                <div class="card-info">
                    <div class="card-title">${esc(g.title)}</div>
                    <div class="card-meta">${g.is_own ? (g.moves_taken || 0) + ' moves &middot; ' + (g.clues_found || 0) + ' clues' : 'Tap to play your own copy'}</div>
                </div>
            `;
            container.appendChild(card);
        });
    } catch (e) {}
}

let selectedGame = null;

function showGameDetail(g) {
    selectedGame = g;
    const modal = document.getElementById('game-detail-modal');

    // Cover
    const cover = document.getElementById('detail-cover');
    if (g.cover_image) {
        cover.style.backgroundImage = `url('${g.cover_image}')`;
    } else {
        cover.style.backgroundImage = 'none';
        cover.style.background = 'linear-gradient(135deg, #1a1a2e 0%, #0f3460 100%)';
    }

    // Info
    document.getElementById('detail-title').textContent = g.title;

    const setting = [g.setting_description, g.time_period].filter(Boolean).join(' - ');
    document.getElementById('detail-setting').textContent = setting || '';

    const stats = document.getElementById('detail-stats');
    stats.innerHTML = `
        <div><span>${g.moves_taken || 0}</span> moves</div>
        <div><span>${g.clues_found || 0}</span> clues</div>
        <div><span>${g.character_count || 0}</span> characters</div>
        <div><span>${g.location_count || 0}</span> locations</div>
    `;

    const statusLabel = g.status === 'won' ? 'Solved' : g.status === 'lost' ? 'Cold Case' : 'Active';
    document.getElementById('detail-status').innerHTML = `<span class="badge ${g.status}">${statusLabel}</span>`;

    document.getElementById('detail-summary').textContent = g.summary || 'A mysterious case awaits...';

    // Debug link
    document.getElementById('detail-debug').href = 'debug.php?game_id=' + g.id;

    // Button text
    const resumeBtn = document.getElementById('detail-resume');
    const deleteBtn = document.getElementById('detail-delete');
    const debugBtn = document.getElementById('detail-debug');

    const exportBtn = document.getElementById('detail-export');

    if (g.is_own) {
        if (g.status === 'active') {
            resumeBtn.textContent = (g.moves_taken > 0) ? 'Resume' : 'Play';
        } else {
            resumeBtn.textContent = 'Review';
        }
        resumeBtn.onclick = resumeFromDetail;
        resumeBtn.style.display = '';
        deleteBtn.style.display = '';
        debugBtn.style.display = '';
        exportBtn.style.display = '';
    } else {
        resumeBtn.textContent = 'Clone';
        resumeBtn.onclick = cloneFromDetail;
        resumeBtn.style.display = '';
        deleteBtn.style.display = 'none';
        debugBtn.style.display = 'none';
        exportBtn.style.display = 'none';
    }

    modal.classList.add('active');
}

function hideGameDetail() {
    document.getElementById('game-detail-modal').classList.remove('active');
    selectedGame = null;
}

function resumeFromDetail() {
    if (!selectedGame) return;

    // Cinematic intro for first-time play only
    if (selectedGame.is_own && selectedGame.status === 'active' && !(selectedGame.moves_taken > 0)) {
        const game = selectedGame;
        hideGameDetail();
        playIntro(game);
        return;
    }

    window.location = 'game.php?id=' + selectedGame.id;
}

// ---- Cinematic Intro ----
let introAudio = null;

function playIntro(game) {
    const overlay = document.getElementById('intro-overlay');
    const title = document.getElementById('intro-title');
    const image = document.getElementById('intro-image');
    const setting = document.getElementById('intro-setting');
    const hook = document.getElementById('intro-hook');
    const beginBtn = document.getElementById('intro-begin');

    // Populate content
    title.textContent = game.title;

    if (game.cover_image) {
        image.style.backgroundImage = `url('${game.cover_image}')`;
        image.style.display = '';
    } else {
        image.style.display = 'none';
    }

    // Setting text — spoiler-free only (backstory reveals the killer!)
    const settingText = game.summary || game.setting_description || '';
    setting.textContent = settingText;

    // Hook — the dramatic red text
    const victim = game.victim_name || 'Someone';
    hook.textContent = `${victim} has been found dead. You have been called in to investigate.`;

    // Reset all animations
    title.classList.remove('show');
    image.classList.remove('show');
    setting.classList.remove('show');
    hook.classList.remove('show');
    beginBtn.classList.remove('show');

    // Stop menu music if playing
    if (typeof stopMenuMusic === 'function') stopMenuMusic();

    // Show overlay and fade to black
    overlay.classList.add('active');
    requestAnimationFrame(() => {
        overlay.classList.add('visible');
    });

    // Start game-specific music
    startIntroMusic(game);

    // Sequence the reveals — slow, cinematic pacing
    setTimeout(() => title.classList.add('show'), 3500);
    setTimeout(() => image.classList.add('show'), 8000);
    setTimeout(() => setting.classList.add('show'), 14000);
    setTimeout(() => hook.classList.add('show'), 20000);
    setTimeout(() => beginBtn.classList.add('show'), 26000);

    // BEGIN button handler
    beginBtn.onclick = () => {
        beginBtn.disabled = true;
        beginBtn.textContent = 'BEGIN';

        // Fade out music over 4s
        if (introAudio) {
            fadeOutAudio(introAudio, 4000);
        }

        // Fade overlay to black then navigate
        title.classList.remove('show');
        image.classList.remove('show');
        setting.classList.remove('show');
        hook.classList.remove('show');
        beginBtn.classList.remove('show');

        setTimeout(() => {
            window.location = 'game.php?id=' + game.id;
        }, 4000);
    };
}

async function startIntroMusic(game) {
    try {
        const params = new URLSearchParams();
        if (game.setting_description) params.set('setting', game.setting_description);
        if (game.time_period) params.set('period', game.time_period);

        const res = await fetch('api/music.php?' + params.toString());
        const data = await res.json();

        if (data.success && data.tracks?.length) {
            const track = data.tracks[Math.floor(Math.random() * data.tracks.length)];
            introAudio = new Audio(track.url);
            introAudio.volume = 0;
            introAudio.play().then(() => {
                // Fade in over 3s
                fadeInAudio(introAudio, 0.3, 3000);
            }).catch(() => {});
        }
    } catch (e) {
        // Music is non-essential
    }
}

function fadeInAudio(audio, targetVol, duration) {
    const steps = 30;
    const interval = duration / steps;
    const increment = targetVol / steps;
    let current = 0;
    const timer = setInterval(() => {
        current += increment;
        if (current >= targetVol) {
            audio.volume = targetVol;
            clearInterval(timer);
        } else {
            audio.volume = current;
        }
    }, interval);
}

function fadeOutAudio(audio, duration) {
    const steps = 30;
    const interval = duration / steps;
    const startVol = audio.volume;
    const decrement = startVol / steps;
    let current = startVol;
    const timer = setInterval(() => {
        current -= decrement;
        if (current <= 0) {
            audio.volume = 0;
            audio.pause();
            clearInterval(timer);
        } else {
            audio.volume = current;
        }
    }, interval);
}

async function cloneFromDetail() {
    if (!selectedGame) return;
    const btn = document.getElementById('detail-resume');
    btn.disabled = true;
    btn.textContent = 'Creating your copy...';

    try {
        const result = await api('api/clone_game.php', { game_id: selectedGame.id });
        if (result.success) {
            window.location = 'game.php?id=' + result.game_id;
        } else {
            showError('Failed to clone: ' + (result.error || 'Unknown error'));
            btn.disabled = false;
            btn.textContent = 'Clone';
        }
    } catch (e) {
        showError(e.message);
        btn.disabled = false;
        btn.textContent = 'Clone';
    }
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

async function deleteFromDetail() {
    if (!selectedGame) return;
    if (!await styledConfirm(`Delete "${selectedGame.title}"? This cannot be undone.`)) return;

    try {
        const result = await api('api/delete.php', { game_id: selectedGame.id });
        if (result.success) {
            hideGameDetail();
            loadSavedGames();
        } else {
            showError('Error deleting: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        showError(e.message);
    }
}

// ---- New Game ----
const PROGRESS_STEPS = [
    'Crafting the plot and backstory...',
    'Designing locations and map...',
    'Writing location descriptions...',
    'Creating characters and suspects...',
    'Placing objects and evidence...',
    'Planting clues and red herrings...',
    'Setting the scene...',
    'Finalising the mystery...'
];

function showProgressSteps() {
    const container = document.getElementById('progress-steps');
    container.innerHTML = '';
    PROGRESS_STEPS.forEach((text, i) => {
        const div = document.createElement('div');
        div.className = 'progress-step waiting';
        div.id = 'pstep-' + i;
        div.innerHTML = `<span class="step-icon"></span><span>${text}</span>`;
        container.appendChild(div);
    });
}

function advanceProgress(stepIndex) {
    for (let i = 0; i < stepIndex; i++) {
        const el = document.getElementById('pstep-' + i);
        if (el) { el.className = 'progress-step done'; }
    }
    const current = document.getElementById('pstep-' + stepIndex);
    if (current) {
        current.className = 'progress-step active';
        current.querySelector('.step-icon').innerHTML = '<span class="spinner" style="width:14px;height:14px;border-width:2px;"></span>';
    }
}

function completeAllProgress() {
    PROGRESS_STEPS.forEach((_, i) => {
        const el = document.getElementById('pstep-' + i);
        if (el) { el.className = 'progress-step done'; }
    });
}

// ---- Custom Mystery Modal ----
function showCustomModal() {
    document.getElementById('custom-modal').classList.add('active');
    document.getElementById('custom-theme-input').focus();
}

function hideCustomModal() {
    document.getElementById('custom-modal').classList.remove('active');
}

function startCustomGame() {
    const theme = document.getElementById('custom-theme-input').value.trim();
    if (!theme) return;
    document.getElementById('theme-input').value = theme;
    hideCustomModal();
    newGame();
}

async function newGame() {
    const btnRandom = document.getElementById('btn-random-game');
    const btnCustom = document.getElementById('btn-custom-game');
    const gen = document.getElementById('generating');
    const buttonsContainer = document.getElementById('new-game-buttons');
    const theme = document.getElementById('theme-input')?.value?.trim() || '';

    if (btnRandom) btnRandom.disabled = true;
    if (btnCustom) btnCustom.disabled = true;
    if (buttonsContainer) buttonsContainer.style.display = 'none';
    if (gen) gen.classList.add('active');

    showProgressSteps();

    const resetUI = () => {
        if (btnRandom) btnRandom.disabled = false;
        if (btnCustom) btnCustom.disabled = false;
        if (buttonsContainer) buttonsContainer.style.display = '';
        if (gen) gen.classList.remove('active');
    };

    try {
        // Step 1: Plot
        advanceProgress(0);
        const plotResult = await api('api/generate.php', { step: 'plot', theme });
        if (!plotResult.success) { showError(plotResult.error, plotResult.raw); resetUI(); return; }

        const gameId = plotResult.game_id;

        // Step 2: Location skeleton (map structure)
        advanceProgress(1);
        const locResult = await api('api/generate.php', { step: 'locations', game_id: gameId });
        if (!locResult.success) { showError(locResult.error, locResult.raw); resetUI(); return; }

        // Step 3: Location descriptions (atmospheric detail)
        advanceProgress(2);
        const descResult = await api('api/generate.php', { step: 'location_descriptions', game_id: gameId });
        if (!descResult.success) { showError(descResult.error, descResult.raw); resetUI(); return; }

        // Step 4: Characters
        advanceProgress(3);
        const charResult = await api('api/generate.php', {
            step: 'characters', game_id: gameId,
            victim: plotResult.plot.victim,
            killer: plotResult.plot.killer
        });
        if (!charResult.success) { showError(charResult.error, charResult.raw); resetUI(); return; }

        // Step 5: Objects
        advanceProgress(4);
        const objResult = await api('api/generate.php', { step: 'objects', game_id: gameId });
        if (!objResult.success) { showError(objResult.error, objResult.raw); resetUI(); return; }

        // Step 6: Clues + finalize
        advanceProgress(5);
        const clueResult = await api('api/generate.php', { step: 'clues', game_id: gameId });
        if (!clueResult.success) { showError(clueResult.error, clueResult.raw); resetUI(); return; }

        completeAllProgress();

        // Generate all artwork before entering the game
        const artProgress = document.getElementById('art-progress');
        if (artProgress) artProgress.style.display = 'block';
        await generateAllArt(artProgress, gameId);
        if (artProgress) artProgress.style.display = 'none';

        // Fetch game data for cinematic intro
        try {
            const gamesResult = await api('api/games.php');
            const newGame = gamesResult.games?.find(g => g.id == gameId);
            if (newGame) {
                resetUI();
                playIntro(newGame);
                return;
            }
        } catch (e) {}

        // Fallback: navigate directly
        window.location = 'game.php?id=' + gameId;

    } catch (e) {
        showError(e.message);
        resetUI();
    }
}

// ---- Art Generation (during new game creation) ----
async function generateAllArt(progressContainer, gameId) {
    if (!gameId) return;

    let failures = 0;
    while (failures < 3) {
        try {
            const result = await api('api/generate_image.php?game_id=' + gameId);

            if (!result.success || result.done) {
                if (result.counts) updateArtProgress(progressContainer, result.counts);
                break;
            }

            if (result.counts) updateArtProgress(progressContainer, result.counts);

            if (!result.generated?.image) {
                failures++;
                continue;
            }
            failures = 0;

        } catch (e) {
            failures++;
        }
    }
}

function updateArtProgress(container, counts) {
    if (!container) return;
    const categories = [
        { key: 'locations', label: 'Locations' },
        { key: 'characters', label: 'Characters' },
        { key: 'objects', label: 'Objects' }
    ];

    container.innerHTML = categories.map(cat => {
        const c = counts[cat.key];
        if (!c || c.total === 0) return '';
        const pct = Math.round((c.done / c.total) * 100);
        return `
            <div class="art-progress-row">
                <span class="art-progress-label">${cat.label}</span>
                <div class="art-progress-bar"><div class="art-progress-fill" style="width:${pct}%"></div></div>
                <span class="art-progress-count">${c.done}/${c.total}</span>
            </div>
        `;
    }).join('');
}

// ---- Export / Import ----
function exportFromDetail() {
    if (!selectedGame) return;
    const btn = document.getElementById('detail-export');
    const origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Exporting...';

    // Use fetch to detect errors, then trigger download via blob
    fetch('api/export_game.php?game_id=' + selectedGame.id)
        .then(res => {
            if (res.headers.get('content-type')?.includes('application/json')) {
                return res.json().then(j => { throw new Error(j.error || 'Export failed'); });
            }
            return res.blob().then(blob => {
                const disposition = res.headers.get('content-disposition') || '';
                const match = disposition.match(/filename="(.+?)"/);
                const filename = match ? match[1] : 'sleuth_export.zip';
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.click();
                URL.revokeObjectURL(url);
            });
        })
        .catch(e => showError('Export failed: ' + e.message))
        .finally(() => {
            btn.disabled = false;
            btn.textContent = origText;
        });
}

async function importGame(file) {
    const dropZone = document.getElementById('import-drop');
    const dropText = dropZone.querySelector('.import-drop-text');
    const origText = dropText.innerHTML;
    dropText.textContent = 'Importing...';
    dropZone.classList.add('importing');

    try {
        const form = new FormData();
        form.append('file', file);
        const res = await fetch('api/import_game.php', { method: 'POST', body: form });
        const text = await res.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (parseErr) {
            showError('Import failed — server error', text);
            dropText.innerHTML = origText;
            dropZone.classList.remove('importing');
            return;
        }
        if (result.success) {
            dropText.textContent = 'Imported! Loading...';
            await loadSavedGames();
            dropText.innerHTML = origText;
            dropZone.classList.remove('importing');
        } else {
            showError('Import failed: ' + (result.error || 'Unknown error'));
            dropText.innerHTML = origText;
            dropZone.classList.remove('importing');
        }
    } catch (e) {
        showError('Import failed: ' + e.message);
        dropText.innerHTML = origText;
        dropZone.classList.remove('importing');
    }
}

// Load saved games on page load
document.addEventListener('DOMContentLoaded', () => {
    loadSavedGames();

    // Close detail modal on overlay click
    document.getElementById('game-detail-modal').addEventListener('click', (e) => {
        if (e.target.classList.contains('modal-overlay')) hideGameDetail();
    });

    // Close custom modal on overlay click
    document.getElementById('custom-modal').addEventListener('click', (e) => {
        if (e.target.classList.contains('modal-overlay')) hideCustomModal();
    });

    // Escape closes custom modal
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            hideCustomModal();
            hideGameDetail();
        }
    });

    // Import drop zone
    const dropZone = document.getElementById('import-drop');
    const fileInput = document.getElementById('import-file');

    if (dropZone) {
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('drag-over');
        });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            const file = e.dataTransfer.files[0];
            if (file && file.name.endsWith('.zip')) {
                importGame(file);
            } else {
                showError('Please drop a .zip file');
            }
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            if (fileInput.files[0]) {
                importGame(fileInput.files[0]);
                fileInput.value = '';
            }
        });
    }
});
