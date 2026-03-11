// SVG icons for motive categories - 23 categories
// Each icon is a simple 32x32 SVG path
const MOTIVE_ICONS = {
    money: {
        label: 'Money / Greed',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="16" cy="16" r="12"/>
            <path d="M16 8v16M12 12c0-1.5 1.8-3 4-3s4 1.5 4 3-1.8 2.5-4 3-4 1.5-4 3 1.8 3 4 3 4-1.5 4-3"/>
        </svg>`
    },
    jealousy: {
        label: 'Jealousy',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 28s-10-6-10-14c0-4 3-7 6.5-7 2 0 3.5 1.5 3.5 1.5s1.5-1.5 3.5-1.5c3.5 0 6.5 3 6.5 7 0 8-10 14-10 14z"/>
            <path d="M12 16l8-4M12 12l8 4"/>
        </svg>`
    },
    revenge: {
        label: 'Revenge',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4v8l6-3M16 12l-6-3"/>
            <path d="M8 18l8 10 8-10"/>
            <line x1="16" y1="22" x2="16" y2="28"/>
        </svg>`
    },
    power: {
        label: 'Power / Ambition',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="16,3 19,12 28,12 21,18 23,27 16,22 9,27 11,18 4,12 13,12"/>
        </svg>`
    },
    secret: {
        label: 'Secret / Blackmail',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="6" y="14" width="20" height="14" rx="2"/>
            <path d="M10 14v-4a6 6 0 0 1 12 0v4"/>
            <circle cx="16" cy="21" r="2"/>
            <line x1="16" y1="23" x2="16" y2="25"/>
        </svg>`
    },
    betrayal: {
        label: 'Betrayal',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10 8c0 0 3 2 6 2s6-2 6-2"/>
            <path d="M16 10v8"/>
            <path d="M8 18l4 4-4 4"/>
            <path d="M24 18l-4 4 4 4"/>
            <line x1="12" y1="22" x2="20" y2="22"/>
        </svg>`
    },
    love: {
        label: 'Love / Passion',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 28s-10-6-10-14c0-4 3-7 6.5-7 2 0 3.5 1.5 3.5 1.5s1.5-1.5 3.5-1.5c3.5 0 6.5 3 6.5 7 0 8-10 14-10 14z"/>
        </svg>`
    },
    fear: {
        label: 'Fear',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="16" cy="14" r="10"/>
            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
            <circle cx="20" cy="12" r="1.5" fill="currentColor"/>
            <ellipse cx="16" cy="19" rx="3" ry="4"/>
        </svg>`
    },
    honor: {
        label: 'Honor / Pride',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4l3 6h7l-5.5 4.5 2 7L16 17l-6.5 4.5 2-7L6 10h7z"/>
            <path d="M10 26h12"/>
            <path d="M12 28h8"/>
        </svg>`
    },
    family: {
        label: 'Family / Protection',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="8" r="3"/>
            <circle cx="21" cy="8" r="3"/>
            <path d="M6 20c0-3 2.5-5 5-5s5 2 5 5"/>
            <path d="M16 20c0-3 2.5-5 5-5s5 2 5 5"/>
            <circle cx="16" cy="22" r="2.5"/>
            <path d="M11 28c0-2 2.2-3.5 5-3.5s5 1.5 5 3.5"/>
        </svg>`
    },
    ideology: {
        label: 'Ideology / Belief',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4v10"/>
            <path d="M10 8h12"/>
            <circle cx="16" cy="20" r="6"/>
            <path d="M16 17v6M13 20h6"/>
        </svg>`
    },
    madness: {
        label: 'Madness / Obsession',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="16" cy="16" r="11"/>
            <path d="M11 13c0-1 1.5-2 2.5-1s-1 3-1 3"/>
            <path d="M21 13c0-1-1.5-2-2.5-1s1 3 1 3"/>
            <path d="M10 20c2 3 4 4 6 4s4-1 6-4"/>
        </svg>`
    },
    freedom: {
        label: 'Freedom / Escape',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 24l4-8-4-8"/>
            <path d="M14 24l4-8-4-8"/>
            <path d="M24 8v16"/>
            <path d="M22 10l4-2"/>
            <path d="M22 14l4-2"/>
        </svg>`
    },
    desperation: {
        label: 'Desperation',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4v4M8 8l2 3M24 8l-2 3"/>
            <circle cx="16" cy="18" r="8"/>
            <path d="M12 21c1.5-2 6.5-2 8 0"/>
            <circle cx="13" cy="16" r="1" fill="currentColor"/>
            <circle cx="19" cy="16" r="1" fill="currentColor"/>
        </svg>`
    },
    rivalry: {
        label: 'Rivalry',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 22V10l5 6 5-6 5 6 5-6v12"/>
            <line x1="4" y1="26" x2="28" y2="26"/>
            <line x1="16" y1="10" x2="16" y2="26"/>
        </svg>`
    },
    justice: {
        label: 'Justice / Vigilantism',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="16" y1="4" x2="16" y2="28"/>
            <line x1="6" y1="10" x2="26" y2="10"/>
            <path d="M6 10l2 8h0a4 4 0 0 0 4-4l-4-4"/>
            <path d="M26 10l-2 8h0a4 4 0 0 1-4-4l4-4"/>
            <line x1="10" y1="28" x2="22" y2="28"/>
        </svg>`
    },
    accident: {
        label: 'Accident / Cover-Up',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4l1 16"/>
            <circle cx="16" cy="26" r="2.5" fill="currentColor"/>
            <path d="M8 6c2 2 5 3 8 3s6-1 8-3"/>
        </svg>`
    },
    loyalty: {
        label: 'Loyalty / Duty',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4l-10 6v8c0 6 4.5 9.5 10 12 5.5-2.5 10-6 10-12v-8z"/>
            <polyline points="11,16 15,20 21,12"/>
        </svg>`
    },
    manipulation: {
        label: 'Manipulation / Framing',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="16" cy="22" r="4"/>
            <line x1="16" y1="4" x2="16" y2="18"/>
            <line x1="10" y1="6" x2="16" y2="12"/>
            <line x1="22" y1="6" x2="16" y2="12"/>
            <line x1="12" y1="18" x2="10" y2="14"/>
            <line x1="20" y1="18" x2="22" y2="14"/>
        </svg>`
    },
    curiosity: {
        label: 'Curiosity / Experiment',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 4v10l-4 8c-.7 1.4.3 3 2 3h12c1.7 0 2.7-1.6 2-3l-4-8V4"/>
            <line x1="10" y1="4" x2="22" y2="4"/>
            <path d="M14 17h4" opacity="0.5"/>
            <circle cx="14" cy="21" r="1" fill="currentColor"/>
            <circle cx="18" cy="19" r="1" fill="currentColor"/>
        </svg>`
    },
    thrill: {
        label: 'Thrill',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="16,3 18,12 28,12 20,18 23,28 16,22 9,28 12,18 4,12 14,12"/>
            <circle cx="16" cy="15" r="3"/>
        </svg>`
    },
    mercy: {
        label: 'Mercy',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 6a5 5 0 0 0-5 5c0 5 5 8 5 8s5-3 5-8a5 5 0 0 0-5-5z"/>
            <path d="M8 24c2-2 5-3 8-3s6 1 8 3"/>
            <line x1="8" y1="28" x2="24" y2="28"/>
        </svg>`
    },
    political: {
        label: 'Political',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="6" y="8" width="20" height="18" rx="2"/>
            <path d="M6 14h20"/>
            <line x1="16" y1="4" x2="16" y2="8"/>
            <line x1="12" y1="4" x2="20" y2="4"/>
            <path d="M11 19h4M11 23h8"/>
        </svg>`
    },
    tradition: {
        label: 'Tradition / Ritual',
        svg: `<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4c0 0-4 4-4 8s2 6 4 8c2-2 4-4 4-8s-4-8-4-8z"/>
            <line x1="16" y1="20" x2="16" y2="28"/>
            <line x1="10" y1="28" x2="22" y2="28"/>
            <path d="M8 12c2 0 4-1 4-3"/>
            <path d="M24 12c-2 0-4-1-4-3"/>
        </svg>`
    }
};

function getMotiveIcon(category) {
    return MOTIVE_ICONS[category]?.svg || MOTIVE_ICONS['secret'].svg;
}

function getMotiveLabel(category) {
    return MOTIVE_ICONS[category]?.label || category;
}
