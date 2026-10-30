// One set of control classes for the app's panels, so every input, select, and button shares a height (h-8),
// a type size, and a label style. Started with the Plan panel; use it for any widget or page you restyle.

// native <option> lists ignore CSS text-transform, so labels are uppercased in JS
export const up = (s) => (s || '').toUpperCase();
// "1 portal" / "3 portals" — `words` for an irregular plural ("1 link crossing", "2 under fields")
export const plural = (n, word, words = word + 's') => `${n} ${n === 1 ? word : words}`;

const box = 'min-w-0 rounded bg-inset border border-line text-ink focus:border-accent focus:outline-none disabled:opacity-40';
const btnBase = 'h-8 inline-flex items-center justify-center gap-1.5 rounded px-2 text-xs font-mono uppercase tracking-wide whitespace-nowrap disabled:opacity-40 disabled:cursor-not-allowed';
export const ui = {
    input: `${box} h-8 w-full px-2 text-sm`,                       // free text
    num: `${box} h-8 w-full px-1 text-xs font-mono text-center`,    // counts
    select: `${box} h-8 w-full px-1.5 text-xs font-mono uppercase`,
    selectPlain: `${box} h-8 w-full px-1.5 text-xs font-mono`,          // values whose case matters (model ids)
    area: `${box} w-full px-2 py-1.5 text-sm resize-y`,
    btn: `${btnBase} border border-line text-ink-dim hover:text-accent hover:border-accent`,
    primary: `${btnBase} bg-accent hover:bg-emerald-400 font-semibold text-accent-ink`,
    danger: `${btnBase} border border-line text-ink-dim hover:text-rose-400 hover:border-rose-500/50`,
    icon: 'size-8 shrink-0 inline-flex items-center justify-center rounded text-ink-faint hover:text-accent hover:bg-emerald-500/10 disabled:opacity-25 disabled:pointer-events-none',
    iconDanger: 'size-8 shrink-0 inline-flex items-center justify-center rounded text-ink-faint hover:text-rose-400 hover:bg-rose-500/10',
    label: 'block mb-1 text-[10px] font-mono uppercase tracking-wide text-ink-faint',
    hint: 'text-[11px] leading-snug text-ink-dim',
    // section heading with a hairline rule filling the rest of the row
    sec: "flex items-center gap-2 mb-2 text-[10px] font-mono uppercase tracking-[0.16em] text-accent/80 after:content-[''] after:flex-1 after:h-px after:bg-line/60",
    // segmented toggle: equal-width options in one bordered strip
    seg: 'h-8 grid grid-flow-col auto-cols-fr rounded border border-line overflow-hidden',
    segBtn: (on) => `text-xs font-mono uppercase border-l border-line first:border-l-0 ${on ? 'bg-accent/15 text-accent' : 'text-ink-dim hover:text-accent'}`,
    // icon-only buttons: the top bar and the compact menu's icon bars
    barIcon: 'flex items-center justify-center w-9 h-8 rounded text-ink-dim hover:bg-emerald-500/10 hover:text-accent disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:bg-transparent disabled:hover:text-ink-dim',
    barIconDanger: 'flex items-center justify-center w-9 h-8 rounded text-ink-faint hover:bg-rose-500/10 hover:text-rose-400',
    // main-menu entries: tiles (labeled menu) and rows (compact menu), each with a danger variant
    menuTile: 'h-9 min-w-0 flex items-center gap-2 px-2 rounded text-xs text-ink-dim hover:bg-emerald-500/10 hover:text-ink disabled:opacity-35 disabled:pointer-events-none',
    menuTileDanger: 'h-9 min-w-0 flex items-center gap-2 px-2 rounded text-xs text-ink-faint hover:bg-rose-500/10 hover:text-rose-400',
    menuRow: 'w-full flex items-center gap-2 px-3 py-[0.45rem] text-left text-ink-dim hover:bg-emerald-500/10 hover:text-ink',
    menuRowDanger: 'w-full flex items-center gap-2 px-3 py-[0.45rem] text-left text-ink-faint hover:bg-rose-500/10 hover:text-rose-400',
    menu: 'absolute left-0 right-0 z-20 mt-1 bg-surface border border-line rounded shadow-xl max-h-52 overflow-auto op-scroll',
    menuItem: 'w-full text-left flex items-center gap-1.5 px-2 py-2 text-sm text-ink-dim hover:bg-emerald-500/10 hover:text-ink',
};
