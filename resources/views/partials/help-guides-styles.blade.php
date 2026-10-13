{{-- Estilos de las guías: legibles en el celular, sin dependencias. --}}
<style>
    .hg { border-top: 1px solid rgba(148,163,184,.25); padding: .55rem 0; }
    .hg:first-of-type { border-top: 0; }
    .hg-summary { cursor: pointer; list-style: none; display: flex; flex-direction: column; gap: .1rem; padding-right: 1.5rem; position: relative; }
    .hg-summary::-webkit-details-marker { display: none; }
    .hg-summary::after { content: '+'; position: absolute; right: .25rem; top: 0; font-weight: 600; opacity: .55; }
    .hg[open] > .hg-summary::after { content: '−'; }
    .hg-title { font-weight: 600; font-size: .95rem; }
    .hg-sub { font-size: .82rem; opacity: .65; }
    .hg-steps { margin: .5rem 0 .25rem 1.15rem; padding: 0; list-style: decimal; font-size: .9rem; line-height: 1.45; }
    .hg-steps li { margin: .25rem 0; }
    .hg-note { font-size: .82rem; opacity: .75; margin: .35rem 0 0; padding-left: .6rem; border-left: 2px solid #f59e0b; }
</style>
