/* ============================================================
   MOBILE GAME UI — tab bar, touch map, keyboard handling.
   Only initialises on screens <= 768px. Desktop is untouched.
   ============================================================ */
(function () {
    'use strict';

    // Check class set by inline script in game.php, or cookie fallback
    if (!document.documentElement.classList.contains('is-mobile')
        && !document.cookie.includes('force_mobile=1')) return;

    // ---- Inject bottom tab bar ----
    const BAR_HTML = `
    <div id="mobile-tab-bar">
        <button data-tab="game" class="active" aria-label="Game">
            <svg viewBox="0 0 24 24"><path d="M12 2L2 12h3v8h6v-6h2v6h6v-8h3L12 2z"/></svg>
            <span>Game</span>
        </button>
        <button data-tab="map" aria-label="Map">
            <svg viewBox="0 0 24 24"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>
            <span>Map</span>
        </button>
        <button data-tab="chat" aria-label="Chat">
            <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
            <span>Chat</span>
        </button>
        <button data-tab="notebook" aria-label="Notes">
            <svg viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 18H6V4h2v8l2.5-1.5L13 12V4h5v16z"/></svg>
            <span>Notes</span>
        </button>
        <button data-tab="inventory" aria-label="Items">
            <svg viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-8-2h4v2h-4V4zM20 20H4V8h16v12z"/></svg>
            <span>Items</span>
        </button>
    </div>`;

    // Run init immediately if DOM is already loaded, otherwise wait
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        // Inject the tab bar
        document.body.insertAdjacentHTML('beforeend', BAR_HTML);

        const bar = document.getElementById('mobile-tab-bar');
        const sidebar = document.querySelector('.sidebar');
        const buttons = bar.querySelectorAll('button');

        let activeTab = 'game';

        bar.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-tab]');
            if (!btn) return;
            const tab = btn.dataset.tab;
            if (tab === activeTab && tab !== 'game') return; // already active

            // Update active button
            buttons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeTab = tab;

            if (tab === 'game') {
                // Show main panel, hide sidebar overlay
                sidebar.classList.remove('mobile-active');
                closeMapOverlay();
            } else if (tab === 'map') {
                // Use existing fullscreen map overlay
                sidebar.classList.remove('mobile-active');
                openMapOverlay();
            } else {
                // Show sidebar as overlay with the correct tab
                sidebar.classList.add('mobile-active');
                switchTab(tab);
            }
        });

        // When map overlay is closed (e.g. via X button), revert tab to game
        const mapOverlay = document.getElementById('map-overlay');
        if (mapOverlay) {
            const observer = new MutationObserver(() => {
                if (!mapOverlay.classList.contains('active') && activeTab === 'map') {
                    activeTab = 'game';
                    buttons.forEach(b => b.classList.toggle('active', b.dataset.tab === 'game'));
                }
            });
            observer.observe(mapOverlay, { attributes: true, attributeFilter: ['class'] });
        }

        // Shorten placeholder on mobile
        const actionInput = document.getElementById('action-input');
        if (actionInput) {
            actionInput.placeholder = 'What do you do?';
        }


        // ---- Override map drawing for spacious mobile layout ----
        window._mobileMapVerticalFloors = true;
        setupMobileMapDraw();

        // ---- Touch-based map panning & pinch-to-zoom ----
        setupTouchMap();

        // ---- Keyboard / viewport handling ----
        setupKeyboardHandling();

        // ---- Back button support for panels ----
        setupBackButton(sidebar, buttons);
    }

    // ================================================================
    //  MOBILE MAP DRAW — center on current location after draw
    // ================================================================
    function setupMobileMapDraw() {
        const _origDraw = window.drawFullscreenMap;

        window.drawFullscreenMap = function () {
            const canvas = document.getElementById('map-fullscreen-canvas');
            if (!canvas) return _origDraw();

            // Clear CSS transform so getBoundingClientRect() returns true dimensions
            canvas.style.transform = 'none';

            // Calculate canvas size based on actual location data so nodes never overlap.
            // Each grid position needs at least nodeSize px, plus padding and floor gaps.
            const locations = (currentState?.map_locations || []).filter(l => l.discovered);
            if (locations.length) {
                const nodeSize = 180; // 160 node + 20 gap
                const padding = 100;
                const floorGap = 80;

                const xs = locations.map(l => +l.x_pos);
                const ys = locations.map(l => +l.y_pos);
                const zs = [...new Set(locations.map(l => +(l.z_pos || 0)))];
                const rangeX = (Math.max(...xs) - Math.min(...xs)) || 1;
                const rangeY = (Math.max(...ys) - Math.min(...ys)) || 1;
                const numFloors = zs.length;

                // Width: enough for all x positions
                const needW = (rangeX + 1) * nodeSize + padding * 2;
                // Height: enough for all y positions × all floors stacked vertically
                const perFloor = (rangeY + 1) * nodeSize + padding * 2;
                const needH = perFloor * numFloors + floorGap * (numFloors - 1);

                canvas.style.width = Math.max(needW, 800) + 'px';
                canvas.style.minWidth = canvas.style.width;
                canvas.style.height = Math.max(needH, 800) + 'px';
                canvas.style.minHeight = canvas.style.height;
            }

            _origDraw();
            centerMapOnCurrentLocation();
        };
    }

    // ================================================================
    //  TOUCH MAP — pan with finger, pinch to zoom
    // ================================================================
    // Shared transform state accessible by both touch and centering logic
    let _mapTransform = { x: 0, y: 0, scale: 1 };
    let _mapCanvas = null;

    function applyMapTransform() {
        if (!_mapCanvas) return;
        _mapCanvas.style.transform =
            `translate(${_mapTransform.x}px, ${_mapTransform.y}px) scale(${_mapTransform.scale})`;
    }

    function centerMapOnCurrentLocation() {
        const canvas = document.getElementById('map-fullscreen-canvas');
        const overlay = document.getElementById('map-overlay');
        if (!canvas || !overlay || typeof _mapNodeRects === 'undefined') return;

        _mapCanvas = canvas;
        const viewW = overlay.clientWidth;
        const viewH = overlay.clientHeight;

        // Find the current location's node rect
        const currentLocId = currentState?.location?.id;
        let target = null;
        if (currentLocId) {
            target = _mapNodeRects.find(n => n.id == currentLocId);
        }

        if (!target && _mapNodeRects.length) {
            // Fallback: center on the middle of all nodes
            const midX = _mapNodeRects.reduce((s, n) => s + n.x + n.w / 2, 0) / _mapNodeRects.length;
            const midY = _mapNodeRects.reduce((s, n) => s + n.y + n.h / 2, 0) / _mapNodeRects.length;
            target = { x: midX - 80, y: midY - 80, w: 160, h: 160 };
        }

        if (target) {
            // Calculate scale to fit all nodes in the viewport with some margin
            const allX = _mapNodeRects.map(n => [n.x, n.x + n.w]).flat();
            const allY = _mapNodeRects.map(n => [n.y, n.y + n.h]).flat();
            const contentW = Math.max(...allX) - Math.min(...allX);
            const contentH = Math.max(...allY) - Math.min(...allY);
            const margin = 40;
            const scaleX = (viewW - margin * 2) / (contentW || 1);
            const scaleY = (viewH - margin * 2) / (contentH || 1);
            const scale = Math.min(scaleX, scaleY, 1); // never zoom in beyond 1

            const nodeCenterX = target.x + target.w / 2;
            const nodeCenterY = target.y + target.h / 2;
            _mapTransform = {
                x: viewW / 2 - nodeCenterX * scale,
                y: viewH / 2 - nodeCenterY * scale,
                scale: scale
            };
        } else {
            _mapTransform = { x: 0, y: 0, scale: 1 };
        }

        applyMapTransform();
    }

    function setupTouchMap() {
        const canvas = document.getElementById('map-fullscreen-canvas');
        if (!canvas) return;
        _mapCanvas = canvas;

        let touchStart = null;
        let pinchStart = null;
        let pinchScaleStart = 1;
        let isDragging = false;

        function dist(t1, t2) {
            const dx = t1.clientX - t2.clientX;
            const dy = t1.clientY - t2.clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        canvas.addEventListener('touchstart', (e) => {
            if (e.touches.length === 1) {
                touchStart = {
                    x: e.touches[0].clientX - _mapTransform.x,
                    y: e.touches[0].clientY - _mapTransform.y
                };
                isDragging = false;
            } else if (e.touches.length === 2) {
                pinchStart = dist(e.touches[0], e.touches[1]);
                pinchScaleStart = _mapTransform.scale;
            }
        }, { passive: true });

        canvas.addEventListener('touchmove', (e) => {
            if (e.touches.length === 1 && touchStart) {
                const newX = e.touches[0].clientX - touchStart.x;
                const newY = e.touches[0].clientY - touchStart.y;
                if (Math.abs(newX - _mapTransform.x) > 4 ||
                    Math.abs(newY - _mapTransform.y) > 4) isDragging = true;
                _mapTransform.x = newX;
                _mapTransform.y = newY;
                applyMapTransform();
            } else if (e.touches.length === 2 && pinchStart) {
                const d = dist(e.touches[0], e.touches[1]);
                let newScale = pinchScaleStart * (d / pinchStart);
                newScale = Math.max(0.2, Math.min(3, newScale));
                _mapTransform.scale = newScale;
                applyMapTransform();
                isDragging = true;
            }
            e.preventDefault();
        }, { passive: false });

        canvas.addEventListener('touchend', (e) => {
            if (e.touches.length === 0) {
                if (!isDragging && e.changedTouches.length === 1) {
                    handleMapTap(e.changedTouches[0]);
                }
                touchStart = null;
                pinchStart = null;
                isDragging = false;
            } else if (e.touches.length === 1) {
                touchStart = {
                    x: e.touches[0].clientX - _mapTransform.x,
                    y: e.touches[0].clientY - _mapTransform.y
                };
                pinchStart = null;
            }
        }, { passive: true });
    }

    function handleMapTap(touch) {
        if (typeof _mapNodeRects === 'undefined' || !_mapNodeRects.length) return;

        // Convert screen position to canvas logical coordinates
        // Canvas is at (0,0) in overlay, with CSS transform: translate(tx,ty) scale(s)
        // Screen point → canvas point: cx = (screenX - tx) / s
        const canvasX = (touch.clientX - _mapTransform.x) / _mapTransform.scale;
        const canvasY = (touch.clientY - _mapTransform.y) / _mapTransform.scale;

        for (const node of _mapNodeRects) {
            if (canvasX >= node.x && canvasX <= node.x + node.w &&
                canvasY >= node.y && canvasY <= node.y + node.h) {
                showMapDetail(node.id);
                return;
            }
        }
        closeMapDetail();
    }

    // ================================================================
    //  KEYBOARD HANDLING — let iOS handle it natively, just hide
    //  the tab bar when the keyboard is open to save space.
    // ================================================================
    function setupKeyboardHandling() {
        const tabBar = document.getElementById('mobile-tab-bar');
        if (!tabBar) return;

        function setKeyboardOpen(open) {
            tabBar.style.display = open ? 'none' : '';
            // Remove/restore the bottom gap that makes room for the tab bar
            document.documentElement.classList.toggle('keyboard-open', open);
        }

        // Hide tab bar when any text input is focused (keyboard open)
        document.addEventListener('focusin', (e) => {
            if (e.target.tagName === 'INPUT' && e.target.type === 'text') {
                setKeyboardOpen(true);
            }
        });

        document.addEventListener('focusout', () => {
            setTimeout(() => {
                if (!document.activeElement || document.activeElement.tagName !== 'INPUT') {
                    setKeyboardOpen(false);
                }
            }, 100);
        });
    }

    // ================================================================
    //  BACK BUTTON — dismiss panels with browser back gesture
    // ================================================================
    function setupBackButton(sidebar, buttons) {
        // Push state when a panel opens
        const bar = document.getElementById('mobile-tab-bar');
        bar.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-tab]');
            if (!btn) return;
            const tab = btn.dataset.tab;
            if (tab !== 'game') {
                history.pushState({ mobilePanel: tab }, '');
            }
        });

        window.addEventListener('popstate', (e) => {
            // Close any open panel and go back to Game tab
            sidebar.classList.remove('mobile-active');
            closeMapOverlay();
            buttons.forEach(b => b.classList.toggle('active', b.dataset.tab === 'game'));
        });
    }

})();