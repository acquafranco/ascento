{{-- Estilos del Centro de ayuda: legibles en el celular, con modo oscuro, sin dependencias. --}}
<style>
    .hg { --hg-accent: var(--primary-600, #d97706); border-top: 1px solid rgba(148,163,184,.25); padding: .65rem 0; }
    .hg:first-of-type { border-top: 0; }
    .hg-summary { cursor: pointer; list-style: none; display: flex; flex-direction: column; gap: .15rem; padding: .15rem 1.75rem .15rem 0; position: relative; border-radius: .4rem; }
    .hg-summary::-webkit-details-marker { display: none; }
    .hg-summary::after { content: ''; position: absolute; right: .35rem; top: .45rem; width: .5rem; height: .5rem; border-right: 2px solid currentColor; border-bottom: 2px solid currentColor; transform: rotate(45deg); opacity: .5; transition: transform .15s ease; }
    .hg[open] > .hg-summary::after { transform: rotate(-135deg); top: .7rem; }
    .hg-summary:focus-visible { outline: 2px solid var(--hg-accent); outline-offset: 2px; }
    .hg-title { font-weight: 600; font-size: .98rem; line-height: 1.35; }
    .hg-sub { font-size: .85rem; opacity: .7; line-height: 1.4; }
    .hg-body { padding: .35rem 0 .25rem; font-size: .92rem; line-height: 1.5; max-width: 46rem; }
    .hg-label { font-size: .72rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; opacity: .6; margin: .9rem 0 .3rem; }
    .hg-steps { margin: 0 0 .25rem 1.25rem; padding: 0; list-style: decimal; }
    .hg-steps li { margin: .3rem 0; padding-left: .15rem; }
    .hg-before ul { margin: 0 0 0 1.1rem; padding: 0; list-style: disc; }
    .hg-before { border-radius: .5rem; padding: .1rem .75rem .5rem; background: rgba(148,163,184,.1); margin-top: .5rem; }
    .hg-after { margin: 0; }
    .hg-note { font-size: .85rem; opacity: .85; margin: .55rem 0 0; padding-left: .65rem; border-left: 3px solid var(--hg-accent); }
    .hg-flow { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin: .75rem 0 .1rem; font-size: .8rem; font-weight: 600; }
    .hg-chip { background: rgba(245,158,11,.14); color: inherit; padding: .2rem .6rem; border-radius: 9999px; }
    .hg-arrow { opacity: .5; }
    .hg-problems { margin: 0; }
    .hg-problems dt { font-weight: 600; margin-top: .4rem; }
    .hg-problems dd { margin: .1rem 0 0; opacity: .85; }
    .hg-figure { margin: .85rem 0 .25rem; }
    .hg-figure img { display: block; max-width: 100%; height: auto; border-radius: .6rem; border: 1px solid rgba(148,163,184,.3); }
    .hg-figure figcaption { font-size: .78rem; opacity: .65; margin-top: .35rem; }
    .hg-foot { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .5rem 1rem; margin-top: .4rem; }
    .hg-related { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: .2rem; }
    .hg-related a, .hg-open { color: var(--hg-accent); font-weight: 600; text-decoration: none; display: inline-block; padding: .2rem 0; }
    .hg-related a:hover, .hg-open:hover { text-decoration: underline; }
    .hg-open { white-space: nowrap; }
    @media (prefers-reduced-motion: reduce) { .hg-summary::after { transition: none; } }
</style>
