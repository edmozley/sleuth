// Menu music player — shared between index.php and profiles.php
(function() {
    let tracks = [];
    let trackIndex = 0;
    let audio = null;
    let playing = false;
    let loaded = false;

    async function loadMenuMusic() {
        if (loaded) return;
        loaded = true;

        try {
            const res = await fetch('api/menu_music.php');
            const data = await res.json();
            if (data.success && data.tracks?.length) {
                tracks = data.tracks;
                document.getElementById('menu-music').style.display = '';
                playMenuTrack(0);
            }
        } catch (e) {
            // Music is non-essential
        }
    }

    function playMenuTrack(index) {
        if (index >= tracks.length) index = 0;
        trackIndex = index;

        const track = tracks[index];
        if (audio) {
            audio.pause();
            audio.removeEventListener('ended', onEnded);
        }

        audio = new Audio(track.url);
        audio.volume = 0.3;
        audio.addEventListener('ended', onEnded);

        const titleEl = document.getElementById('menu-music-title');
        if (titleEl) titleEl.textContent = track.name;

        const toggleBtn = document.getElementById('menu-music-toggle');

        audio.play().then(() => {
            playing = true;
            if (toggleBtn) toggleBtn.classList.add('playing');
        }).catch(() => {
            // Autoplay blocked — user must click toggle
            playing = false;
            if (toggleBtn) toggleBtn.classList.remove('playing');
        });
    }

    function onEnded() {
        if (!playing) return;
        playMenuTrack(trackIndex + 1);
    }

    window.toggleMenuMusic = function() {
        if (!tracks.length) {
            loaded = false;
            loadMenuMusic();
            return;
        }

        const toggleBtn = document.getElementById('menu-music-toggle');

        if (playing) {
            if (audio) audio.pause();
            playing = false;
            if (toggleBtn) toggleBtn.classList.remove('playing');
        } else {
            if (audio) {
                audio.play().then(() => {
                    playing = true;
                    if (toggleBtn) toggleBtn.classList.add('playing');
                });
            } else {
                playMenuTrack(trackIndex);
            }
        }
    };

    window.skipMenuTrack = function() {
        if (!tracks.length) return;
        playMenuTrack(trackIndex + 1);
    };

    window.stopMenuMusic = function() {
        if (audio) {
            audio.pause();
            audio.removeEventListener('ended', onEnded);
        }
        playing = false;
        const toggleBtn = document.getElementById('menu-music-toggle');
        if (toggleBtn) toggleBtn.classList.remove('playing');
    };

    document.addEventListener('DOMContentLoaded', loadMenuMusic);
})();
