<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <title>Sleuth - Who's Playing?</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Permanent+Marker&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-dark: #1a1a2e;
            --bg-panel: #16213e;
            --bg-input: #0f1a30;
            --border: #0f3460;
            --accent: #e94560;
            --text: #e0e0e0;
            --text-dim: #888;
            --text-bright: #fff;
        }
        body {
            background: var(--bg-dark);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .picker {
            text-align: center;
            max-width: 800px;
            padding: 40px 20px;
        }
        .picker h1 {
            font-family: 'Permanent Marker', cursive;
            font-size: 3.5em;
            font-weight: 400;
            color: var(--accent);
            letter-spacing: 3px;
            text-shadow: 0 0 30px rgba(233,69,96,0.5), 0 4px 8px rgba(0,0,0,0.5);
            margin-bottom: 8px;
            letter-spacing: 2px;
        }
        .picker .subtitle {
            color: var(--text-dim);
            font-size: 1.2em;
            margin-bottom: 50px;
        }
        .profile-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            justify-content: center;
            margin-bottom: 40px;
        }
        .profile-card {
            width: 140px;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s;
        }
        .profile-card:hover { transform: scale(1.08); }
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3em;
            background: var(--bg-panel);
            border: 3px solid transparent;
            transition: border-color 0.2s;
        }
        .profile-card:hover .profile-avatar {
            border-color: var(--text-bright);
        }
        .profile-name {
            font-size: 14px;
            color: var(--text-dim);
            transition: color 0.2s;
        }
        .profile-card:hover .profile-name {
            color: var(--text-bright);
        }
        .profile-add .profile-avatar {
            background: transparent;
            border: 3px solid var(--text-dim);
            font-size: 3em;
            color: var(--text-dim);
        }
        .profile-add:hover .profile-avatar {
            border-color: var(--text-bright);
            color: var(--text-bright);
        }

        /* Create form overlay */
        .create-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.85);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }
        .create-overlay.active { display: flex; }
        .create-form {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            width: 380px;
            max-width: 90vw;
        }
        .create-form h2 {
            margin-bottom: 20px;
            color: var(--text-bright);
            font-size: 1.3em;
        }
        .create-form label {
            display: block;
            color: var(--text-dim);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        .create-form input[type="text"] {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 16px;
            margin-bottom: 20px;
            outline: none;
        }
        .create-form input[type="text"]:focus {
            border-color: var(--accent);
        }
        .avatar-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 20px;
        }
        .avatar-option {
            width: 100%;
            aspect-ratio: 1;
            border-radius: 8px;
            background: var(--bg-input);
            border: 2px solid transparent;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
            transition: border-color 0.2s, transform 0.1s;
        }
        .avatar-option:hover { transform: scale(1.1); }
        .avatar-option.selected { border-color: var(--accent); }
        .color-grid {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .color-option {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            border: 3px solid transparent;
            transition: border-color 0.2s, transform 0.1s;
        }
        .color-option:hover { transform: scale(1.15); }
        .color-option.selected { border-color: var(--text-bright); }
        .form-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-primary {
            background: var(--accent);
            color: #fff;
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-secondary {
            background: var(--bg-input);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover { border-color: var(--text-dim); }
        .manage-link {
            color: var(--text-dim);
            font-size: 13px;
            cursor: pointer;
            text-decoration: underline;
            transition: color 0.2s;
        }
        .manage-link:hover { color: var(--text); }
        .delete-btn {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            border: none;
            font-size: 14px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        .managing .delete-btn { display: flex; }
        .managing .profile-card { position: relative; }

        .menu-music {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 30px;
        }
        .menu-music-toggle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--bg-panel);
            color: var(--text-dim);
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .menu-music-toggle:hover { border-color: var(--accent); color: var(--text); }
        .menu-music-toggle.playing { color: var(--accent); border-color: var(--accent); background: rgba(233,69,96,0.15); }
        .menu-music-skip {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--bg-panel);
            color: var(--text-dim);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .menu-music-skip:hover { border-color: var(--accent); color: var(--text); }
        .menu-music-title {
            font-size: 11px;
            color: var(--text-dim);
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body>

<div class="picker" id="picker">
    <h1>Sleuth</h1>
    <p class="subtitle">Who's playing?</p>
    <div class="menu-music" id="menu-music" style="display:none;">
        <button class="menu-music-toggle" id="menu-music-toggle" onclick="toggleMenuMusic()" title="Toggle music">&#9835;</button>
        <button class="menu-music-skip" id="menu-music-skip" onclick="skipMenuTrack()" title="Next track">&#9197;</button>
        <span class="menu-music-title" id="menu-music-title"></span>
    </div>
    <div class="profile-grid" id="profile-grid"></div>
    <div>
        <span class="manage-link" id="manage-link" onclick="toggleManage()">Manage Profiles</span>
    </div>
</div>

<!-- Create Profile Form -->
<div class="create-overlay" id="create-overlay">
    <div class="create-form">
        <h2>New Profile</h2>
        <label>Name</label>
        <input type="text" id="create-name" maxlength="50" placeholder="Enter name..." autofocus>
        <label>Avatar</label>
        <div class="avatar-grid" id="avatar-grid"></div>
        <label>Colour</label>
        <div class="color-grid" id="color-grid"></div>
        <div class="form-buttons">
            <button class="btn btn-secondary" onclick="hideCreate()">Cancel</button>
            <button class="btn btn-primary" onclick="createProfile()">Create</button>
        </div>
    </div>
</div>

<script>
const AVATARS = [
    { id: 'detective', icon: '\ud83d\udd75\ufe0f' },
    { id: 'skull', icon: '\ud83d\udc80' },
    { id: 'knife', icon: '\ud83d\udd2a' },
    { id: 'magnify', icon: '\ud83d\udd0d' },
    { id: 'ghost', icon: '\ud83d\udc7b' },
    { id: 'eye', icon: '\ud83d\udc41\ufe0f' },
    { id: 'candle', icon: '\ud83d\udd6f\ufe0f' },
    { id: 'key', icon: '\ud83d\udd11' },
    { id: 'book', icon: '\ud83d\udcd6' },
    { id: 'mask', icon: '\ud83c\udfad' }
];

const COLORS = [
    '#e94560', '#6a1b9a', '#0f3460', '#2ed573', '#ffc700',
    '#ff6b6b', '#1e90ff', '#ff8c00', '#9b59b6', '#17a589'
];

let selectedAvatar = 'detective';
let selectedColor = '#e94560';
let managing = false;

async function loadProfiles() {
    try {
        const res = await fetch('api/profiles.php');
        const data = await res.json();
        if (!data.success) return;

        const grid = document.getElementById('profile-grid');
        grid.innerHTML = '';

        data.profiles.forEach(p => {
            const avatarObj = AVATARS.find(a => a.id === p.avatar) || AVATARS[0];
            const card = document.createElement('div');
            card.className = 'profile-card';
            card.innerHTML = `
                <button class="delete-btn" onclick="event.stopPropagation(); deleteProfile(${p.id}, '${p.name.replace(/'/g, "\\'")}')">&times;</button>
                <div class="profile-avatar" style="border-color: ${p.color}">${avatarObj.icon}</div>
                <div class="profile-name">${esc(p.name)}</div>
            `;
            card.onclick = () => selectProfile(p.id);
            grid.appendChild(card);
        });

        // Add profile button
        const add = document.createElement('div');
        add.className = 'profile-card profile-add';
        add.innerHTML = `
            <div class="profile-avatar">+</div>
            <div class="profile-name">Add Profile</div>
        `;
        add.onclick = showCreate;
        grid.appendChild(add);

        if (managing) grid.classList.add('managing');
    } catch (e) {}
}

async function selectProfile(id) {
    if (managing) return;
    try {
        const res = await fetch('api/profiles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'select', profile_id: id })
        });
        const data = await res.json();
        if (data.success) window.location = 'index.php';
    } catch (e) {}
}

function showCreate() {
    selectedAvatar = 'detective';
    selectedColor = '#e94560';
    document.getElementById('create-name').value = '';
    renderAvatarGrid();
    renderColorGrid();
    document.getElementById('create-overlay').classList.add('active');
    setTimeout(() => document.getElementById('create-name').focus(), 100);
}

function hideCreate() {
    document.getElementById('create-overlay').classList.remove('active');
}

function renderAvatarGrid() {
    const grid = document.getElementById('avatar-grid');
    grid.innerHTML = AVATARS.map(a => `
        <div class="avatar-option ${a.id === selectedAvatar ? 'selected' : ''}"
             onclick="selectedAvatar='${a.id}'; renderAvatarGrid()">${a.icon}</div>
    `).join('');
}

function renderColorGrid() {
    const grid = document.getElementById('color-grid');
    grid.innerHTML = COLORS.map(c => `
        <div class="color-option ${c === selectedColor ? 'selected' : ''}"
             style="background:${c}"
             onclick="selectedColor='${c}'; renderColorGrid()"></div>
    `).join('');
}

async function createProfile() {
    const name = document.getElementById('create-name').value.trim();
    if (!name) { document.getElementById('create-name').focus(); return; }

    try {
        const res = await fetch('api/profiles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'create', name, avatar: selectedAvatar, color: selectedColor })
        });
        const data = await res.json();
        if (data.success) {
            window.location = 'index.php';
        }
    } catch (e) {}
}

async function deleteProfile(id, name) {
    if (!confirm('Delete profile "' + name + '"? Their games will remain but be unowned.')) return;
    try {
        const res = await fetch('api/profiles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', profile_id: id })
        });
        const data = await res.json();
        if (data.success) loadProfiles();
        else alert(data.error);
    } catch (e) {}
}

function toggleManage() {
    managing = !managing;
    const grid = document.getElementById('profile-grid');
    grid.classList.toggle('managing', managing);
    document.getElementById('manage-link').textContent = managing ? 'Done' : 'Manage Profiles';
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// Enter key creates profile
document.addEventListener('keydown', e => {
    if (e.key === 'Enter' && document.getElementById('create-overlay').classList.contains('active')) {
        createProfile();
    }
    if (e.key === 'Escape') hideCreate();
});

document.addEventListener('DOMContentLoaded', loadProfiles);
</script>
<script src="assets/js/menu-music.js"></script>
</body>
</html>
