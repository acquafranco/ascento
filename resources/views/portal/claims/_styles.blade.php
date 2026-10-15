<style>
    .pc-safety { background: #FDE8E8; color: #7F1D1D; border: 1px solid #F8B4B4; border-radius: 12px; padding: 12px 14px; font-size: 14px; line-height: 1.45; margin: 0 0 16px; }
    .pc-safety strong { display: block; margin-bottom: 2px; }
    .pc-form { display: grid; gap: 14px; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
    .pc-field { display: grid; gap: 6px; }
    fieldset.pc-field { border: 0; padding: 0; margin: 0; min-width: 0; }
    .pc-field label, .pc-field legend { font-weight: 600; font-size: 14px; }
    .pc-field input:not([type=radio]), .pc-field select, .pc-field textarea { width: 100%; border: 1px solid #D6D3C8; border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 16px; background: #fff; color: inherit; box-sizing: border-box; }
    .pc-field textarea { min-height: 120px; resize: vertical; }
    .pc-field input:focus, .pc-field select:focus, .pc-field textarea:focus { outline: 2px solid var(--orange); outline-offset: 1px; }
    .pc-hint { color: var(--muted); font-size: 13px; margin: 0; }
    .pc-error { color: #9B1C1C; font-size: 13px; margin: 0; }
    .pc-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 200px), 1fr)); gap: 8px; border: 0; padding: 0; margin: 0; }
    .pc-cat input { width: 18px; height: 18px; flex: 0 0 auto; margin: 0; accent-color: var(--orange); }
    .pc-cat { display: flex; gap: 10px; align-items: center; justify-content: flex-start; text-align: left; font-weight: 500 !important; border: 1px solid #D6D3C8; border-radius: 10px; padding: 10px 12px; cursor: pointer; font-size: 14px; }
    .pc-cat:has(input:checked) { border-color: var(--orange); background: #FFF6EE; }
    .pc-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .pc-ok { background: #E3F4EA; color: #17663A; border-radius: 12px; padding: 12px 14px; font-size: 14px; margin: 0 0 14px; }
    .pc-timeline { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    .pc-timeline li { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; font-size: 14px; }
    .pc-timeline time { color: var(--muted); font-size: 12px; display: block; }
    .pc-photos { display: flex; flex-wrap: wrap; gap: 8px; }
    .pc-photos img { width: 96px; height: 96px; object-fit: cover; border-radius: 10px; border: 1px solid var(--line); }
    .pc-dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; font-size: 14px; margin: 0; }
    .pc-dl dt { color: var(--muted); }
    .pc-dl dd { margin: 0; overflow-wrap: anywhere; }
    @media (max-width: 480px) { .pc-dl { grid-template-columns: 1fr; } .pc-dl dd { margin-bottom: 6px; } }
</style>
