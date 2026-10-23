// Ilustraciones de repuestos para los datos de demostración (dibujos SVG, no fotos de productos reales).
const puppeteer = require('puppeteer-core');
const OUT = process.argv[2]; // carpeta de salida de las imágenes
const defs = `
<defs>
  <radialGradient id="bg" cx="50%" cy="38%" r="75%"><stop offset="0" stop-color="#FBFBFA"/><stop offset="1" stop-color="#E4E6E9"/></radialGradient>
  <radialGradient id="shadow" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#000" stop-opacity=".28"/><stop offset="1" stop-color="#000" stop-opacity="0"/></radialGradient>
  <linearGradient id="steel" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F4F6F8"/><stop offset=".45" stop-color="#C9CED4"/><stop offset=".55" stop-color="#B4BAC1"/><stop offset="1" stop-color="#E3E6EA"/></linearGradient>
  <linearGradient id="steelH" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#AEB4BB"/><stop offset=".3" stop-color="#EEF1F3"/><stop offset=".7" stop-color="#C3C8CE"/><stop offset="1" stop-color="#9AA1A9"/></linearGradient>
  <linearGradient id="dark" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3A414B"/><stop offset="1" stop-color="#1A1F26"/></linearGradient>
  <linearGradient id="grey" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8F969F"/><stop offset="1" stop-color="#5E656E"/></linearGradient>
  <linearGradient id="orange" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FF8533"/><stop offset="1" stop-color="#D95400"/></linearGradient>
  <linearGradient id="green" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2F8F5B"/><stop offset="1" stop-color="#1D5E3B"/></linearGradient>
  <linearGradient id="beige" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F1F1EC"/><stop offset="1" stop-color="#D6D6CF"/></linearGradient>
  <linearGradient id="blue" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4C6FD6"/><stop offset="1" stop-color="#233D99"/></linearGradient>
  <radialGradient id="disc" cx="45%" cy="40%" r="60%"><stop offset="0" stop-color="#F2F4F6"/><stop offset=".7" stop-color="#B9BFC6"/><stop offset="1" stop-color="#8A9199"/></radialGradient>
  <radialGradient id="btn" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="#FFFFFF"/><stop offset=".6" stop-color="#D3D7DC"/><stop offset="1" stop-color="#9AA1A9"/></radialGradient>
</defs>`;
const scene = (body, sw = 900) => `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900">${defs}
<rect width="1200" height="900" fill="url(#bg)"/><ellipse cx="600" cy="760" rx="${sw / 2}" ry="46" fill="url(#shadow)"/>${body}</svg>`;
const bolts = (pts, r = 9) => pts.map(([x, y]) => `<circle cx="${x}" cy="${y}" r="${r}" fill="url(#disc)" stroke="#7A818A" stroke-width="2"/><path d="M${x - r * .55} ${y}H${x + r * .55}" stroke="#666" stroke-width="2"/>`).join('');
const terminals = (x, y, n, gap) => Array.from({ length: n }, (_, i) => `<rect x="${x + i * gap}" y="${y}" width="${gap * .62}" height="38" rx="5" fill="#2B3139"/><circle cx="${x + i * gap + gap * .31}" cy="${y + 19}" r="9" fill="url(#disc)"/><path d="M${x + i * gap + gap * .31 - 5} ${y + 19}H${x + i * gap + gap * .31 + 5}" stroke="#555" stroke-width="2.4"/>`).join('');

const operator = [
  scene(`<rect x="140" y="330" width="920" height="170" rx="18" fill="url(#steel)" stroke="#9AA1A9" stroke-width="3"/>
    <rect x="160" y="350" width="880" height="16" rx="8" fill="#fff" opacity=".6"/>
    <rect x="420" y="395" width="250" height="150" rx="16" fill="url(#dark)"/><rect x="440" y="415" width="210" height="18" rx="6" fill="#4A525C"/>
    <circle cx="545" cy="500" r="22" fill="url(#orange)"/><rect x="460" y="455" width="40" height="8" rx="4" fill="#5F6873"/><rect x="590" y="455" width="40" height="8" rx="4" fill="#5F6873"/>
    <circle cx="230" cy="415" r="58" fill="url(#disc)" stroke="#7A818A" stroke-width="3"/><circle cx="230" cy="415" r="16" fill="#3A414B"/>
    <circle cx="970" cy="415" r="58" fill="url(#disc)" stroke="#7A818A" stroke-width="3"/><circle cx="970" cy="415" r="16" fill="#3A414B"/>
    <path d="M230 357H970M230 473H420M670 473H970" stroke="#20252C" stroke-width="12" stroke-linecap="round"/>
    ${bolts([[170, 600], [1030, 600]])}<rect x="150" y="520" width="900" height="26" rx="8" fill="url(#grey)"/>
    <rect x="200" y="560" width="120" height="70" rx="10" fill="url(#steelH)"/><rect x="880" y="560" width="120" height="70" rx="10" fill="url(#steelH)"/>`, 1000),
  scene(`<g transform="translate(600 450) scale(1.6) translate(-600 -450)">
    <rect x="420" y="330" width="360" height="230" rx="20" fill="url(#dark)"/><rect x="450" y="360" width="300" height="26" rx="8" fill="#4A525C"/>
    <rect x="460" y="410" width="120" height="60" rx="8" fill="#0E1A12"/><text x="520" y="451" font-family="monospace" font-size="30" fill="#4ADE80" text-anchor="middle">0.75</text>
    <circle cx="640" cy="440" r="14" fill="url(#orange)"/><circle cx="690" cy="440" r="14" fill="#5F6873"/><circle cx="740" cy="440" r="14" fill="#5F6873"/>
    ${terminals(450, 495, 7, 44)}</g>`, 700),
  scene(`<circle cx="600" cy="430" r="230" fill="url(#disc)" stroke="#7A818A" stroke-width="4"/>
    <circle cx="600" cy="430" r="170" fill="none" stroke="#20252C" stroke-width="26" stroke-dasharray="10 8"/>
    <circle cx="600" cy="430" r="70" fill="url(#steelH)" stroke="#7A818A" stroke-width="3"/><circle cx="600" cy="430" r="26" fill="#2B3139"/>
    ${bolts([[600, 300], [730, 430], [600, 560], [470, 430]], 11)}`, 520),
  scene(`<rect x="210" y="300" width="780" height="300" rx="16" fill="url(#steel)" stroke="#9AA1A9" stroke-width="3"/>
    <rect x="250" y="340" width="700" height="34" rx="10" fill="url(#dark)"/>
    ${[0, 1, 2, 3].map(i => `<rect x="${290 + i * 165}" y="420" width="120" height="140" rx="12" fill="url(#steelH)" stroke="#8A9199"/><circle cx="${350 + i * 165}" cy="490" r="26" fill="url(#disc)"/>`).join('')}`, 860),
];
const panel = [
  scene(`<rect x="430" y="110" width="340" height="620" rx="22" fill="url(#steelH)" stroke="#8A9199" stroke-width="3"/>
    <rect x="470" y="150" width="260" height="90" rx="10" fill="#0B0F14"/><text x="600" y="215" font-family="monospace" font-size="58" fill="#FF6600" text-anchor="middle">04</text>
    ${[0, 1, 2, 3].map(r => [0, 1].map(c => `<circle cx="${535 + c * 130}" cy="${300 + r * 90}" r="34" fill="url(#btn)" stroke="#7A818A" stroke-width="3"/><text x="${535 + c * 130}" y="${312 + r * 90}" font-family="Helvetica" font-weight="700" font-size="30" fill="#3A414B" text-anchor="middle">${8 - (r * 2 + c)}</text>`).join('')).join('')}
    <circle cx="535" cy="670" r="30" fill="url(#orange)"/><circle cx="665" cy="670" r="30" fill="url(#btn)" stroke="#7A818A" stroke-width="3"/>
    ${bolts([[460, 140], [740, 140], [460, 700], [740, 700]], 7)}`, 420),
  scene(`<g transform="translate(600 450) scale(2.2) translate(-600 -420)">
    <rect x="470" y="330" width="260" height="190" rx="14" fill="url(#steelH)" stroke="#8A9199" stroke-width="2"/>
    <circle cx="535" cy="390" r="34" fill="url(#btn)" stroke="#7A818A" stroke-width="3"/><text x="535" y="402" font-family="Helvetica" font-weight="700" font-size="30" fill="#3A414B" text-anchor="middle">3</text>
    <circle cx="665" cy="390" r="34" fill="url(#btn)" stroke="#7A818A" stroke-width="3"/><text x="665" y="402" font-family="Helvetica" font-weight="700" font-size="30" fill="#3A414B" text-anchor="middle">4</text>
    <circle cx="535" cy="480" r="28" fill="url(#orange)"/><circle cx="665" cy="480" r="28" fill="url(#btn)" stroke="#7A818A" stroke-width="3"/></g>`, 700),
  scene(`<g transform="rotate(-8 600 430)"><rect x="440" y="140" width="300" height="560" rx="20" fill="url(#dark)"/>
    <rect x="470" y="180" width="240" height="60" rx="8" fill="#2B3139"/>${terminals(470, 280, 5, 48)}
    <path d="M500 360C520 460 640 420 660 560M540 360C540 520 700 480 690 640" stroke="#FF6600" stroke-width="8" fill="none"/>
    <path d="M520 360C560 500 600 520 600 650" stroke="#2E4FBE" stroke-width="8" fill="none"/></g>`, 460),
];
const singles = {
  contactor: [scene(`<rect x="430" y="210" width="340" height="460" rx="20" fill="url(#beige)" stroke="#B9B9B2" stroke-width="3"/>
    ${terminals(455, 235, 3, 108)}${terminals(455, 605, 3, 108)}
    <rect x="490" y="330" width="220" height="210" rx="14" fill="#E8E8E2" stroke="#C4C4BD"/><rect x="560" y="360" width="80" height="60" rx="8" fill="url(#dark)"/>
    <rect x="510" y="460" width="180" height="22" rx="6" fill="#C9C9C2"/><text x="600" y="520" font-family="Helvetica" font-size="24" fill="#6B6B65" text-anchor="middle">18 A</text>`, 420),
    scene(`<g transform="translate(600 450) rotate(-12) translate(-600 -450)"><rect x="380" y="240" width="300" height="420" rx="18" fill="url(#beige)" stroke="#B9B9B2" stroke-width="3"/>
    <rect x="680" y="270" width="140" height="390" rx="14" fill="#CFCFC8" stroke="#B9B9B2" stroke-width="3"/>${terminals(400, 262, 3, 96)}${terminals(400, 600, 3, 96)}
    <rect x="460" y="380" width="140" height="120" rx="10" fill="#E8E8E2"/></g>`, 480)],
  cable: [scene(`${[0, 1, 2, 3, 4, 5, 6, 7].map(i => `<ellipse cx="600" cy="${330 + i * 34}" rx="300" ry="110" fill="none" stroke="url(#steelH)" stroke-width="22"/><ellipse cx="600" cy="${330 + i * 34}" rx="300" ry="110" fill="none" stroke="#6E757D" stroke-width="2" stroke-dasharray="6 10"/>`).join('')}
    <path d="M900 600C980 640 1000 700 1060 720" stroke="url(#steelH)" stroke-width="22" stroke-linecap="round" fill="none"/>`, 720)],
  board: [scene(`<g transform="rotate(-6 600 440)"><rect x="250" y="200" width="700" height="460" rx="16" fill="url(#green)" stroke="#123D27" stroke-width="3"/>
    <rect x="300" y="250" width="180" height="180" rx="8" fill="#151A20"/><text x="390" y="350" font-family="monospace" font-size="20" fill="#7F8892" text-anchor="middle">MCU</text>
    ${[0, 1, 2, 3, 4, 5].map(i => `<rect x="${530 + (i % 3) * 120}" y="${260 + Math.floor(i / 3) * 110}" width="90" height="70" rx="6" fill="#20262E"/>`).join('')}
    ${terminals(300, 590, 12, 54)}${[0, 1, 2, 3].map(i => `<circle cx="${320 + i * 34}" cy="470" r="9" fill="${i === 0 ? '#4ADE80' : '#FF6600'}"/>`).join('')}
    <path d="M480 300H530M480 380H530M760 300H820V420H900" stroke="#C9A227" stroke-width="3" fill="none"/></g>`, 820)],
  bearing: [scene(`<circle cx="600" cy="430" r="240" fill="url(#disc)" stroke="#7A818A" stroke-width="4"/><circle cx="600" cy="430" r="200" fill="#2B3139"/>
    ${Array.from({ length: 12 }, (_, i) => { const a = i * Math.PI / 6; return `<circle cx="${600 + Math.cos(a) * 165}" cy="${430 + Math.sin(a) * 165}" r="30" fill="url(#btn)"/>`; }).join('')}
    <circle cx="600" cy="430" r="130" fill="url(#disc)" stroke="#7A818A" stroke-width="3"/><circle cx="600" cy="430" r="84" fill="url(#bg)" stroke="#8A9199" stroke-width="3"/>`, 540)],
  pushbutton: [scene(`<rect x="380" y="220" width="440" height="440" rx="40" fill="url(#steelH)" stroke="#8A9199" stroke-width="3"/>
    <circle cx="600" cy="440" r="150" fill="url(#disc)" stroke="#7A818A" stroke-width="4"/><circle cx="600" cy="440" r="112" fill="url(#btn)"/>
    <circle cx="600" cy="440" r="124" fill="none" stroke="#FF6600" stroke-width="8" opacity=".85"/>
    ${[[-24, -20], [-24, 0], [-24, 20], [24, -20], [24, 0]].map(([dx, dy]) => `<circle cx="${600 + dx}" cy="${440 + dy}" r="6" fill="#6B727B"/>`).join('')}`, 460),
    scene(`<g transform="translate(600 450) scale(.8) translate(-600 -450)">${[0, 1].map(k => `<g transform="translate(${k ? 200 : -200} ${k ? 30 : -20})"><rect x="430" y="270" width="340" height="340" rx="34" fill="url(#steelH)" stroke="#8A9199" stroke-width="3"/><circle cx="600" cy="440" r="110" fill="url(#btn)" stroke="#7A818A" stroke-width="4"/></g>`).join('')}</g>`, 700)],
  governor: [scene(`<rect x="300" y="560" width="600" height="80" rx="12" fill="url(#grey)"/>
    <circle cx="600" cy="390" r="200" fill="url(#orange)" stroke="#A84300" stroke-width="4"/><circle cx="600" cy="390" r="150" fill="none" stroke="#7A3200" stroke-width="18"/>
    <circle cx="600" cy="390" r="46" fill="url(#disc)" stroke="#7A818A" stroke-width="3"/>
    <rect x="560" y="390" width="80" height="200" fill="url(#grey)"/>${bolts([[340, 600], [860, 600]], 11)}
    <path d="M440 300L520 350M760 300L680 350" stroke="#20252C" stroke-width="16" stroke-linecap="round"/>`, 640)],
  shoe: [scene(`<rect x="320" y="470" width="560" height="120" rx="14" fill="url(#grey)"/>${bolts([[370, 530], [830, 530]], 14)}
    <path d="M430 470V300H500V420H700V300H770V470Z" fill="url(#dark)"/><rect x="500" y="300" width="200" height="120" fill="#151A20"/>
    <rect x="540" y="300" width="120" height="120" fill="url(#steelH)"/>`, 620)],
  brake: [scene(`<circle cx="600" cy="430" r="250" fill="url(#dark)"/><circle cx="600" cy="430" r="200" fill="url(#disc)" stroke="#7A818A" stroke-width="4"/>
    <circle cx="600" cy="430" r="120" fill="url(#steelH)" stroke="#7A818A" stroke-width="3"/><circle cx="600" cy="430" r="52" fill="#2B3139"/>
    ${bolts(Array.from({ length: 6 }, (_, i) => [600 + Math.cos(i * Math.PI / 3) * 160, 430 + Math.sin(i * Math.PI / 3) * 160]), 13)}
    <rect x="820" y="400" width="140" height="60" rx="10" fill="#20252C"/><path d="M960 430C1020 430 1040 500 1090 520" stroke="#20252C" stroke-width="12" fill="none"/>`, 600)],
  psu: [scene(`<rect x="330" y="230" width="540" height="420" rx="14" fill="url(#steelH)" stroke="#8A9199" stroke-width="3"/>
    ${Array.from({ length: 9 }, (_, i) => `<rect x="${380 + i * 50}" y="300" width="22" height="170" rx="11" fill="#5F6873"/>`).join('')}
    ${terminals(370, 520, 8, 58)}<rect x="380" y="250" width="120" height="30" rx="6" fill="url(#blue)"/><circle cx="820" cy="270" r="9" fill="#4ADE80"/>`, 620)],
  curtain: [scene(`<rect x="420" y="90" width="70" height="680" rx="14" fill="url(#dark)"/><rect x="710" y="90" width="70" height="680" rx="14" fill="url(#dark)"/>
    ${Array.from({ length: 16 }, (_, i) => `<circle cx="490" cy="${130 + i * 40}" r="6" fill="#FF6600"/><path d="M500 ${130 + i * 40}H700" stroke="#FF6600" stroke-width="2" opacity=".25"/>`).join('')}`, 520)],
};
const all = { operator, panel, ...singles };
(async () => {
  const b = await puppeteer.launch({ executablePath: process.env.CHROME, headless: 'new' });
  const p = await b.newPage(); await p.setViewport({ width: 1200, height: 900 });
  for (const [name, list] of Object.entries(all)) for (const [i, svg] of list.entries()) {
    await p.setContent(`<html><body style="margin:0">${svg}</body></html>`);
    await p.screenshot({ path: `${OUT}/${name}-${i + 1}.jpg`, type: 'jpeg', quality: 90, clip: { x: 0, y: 0, width: 1200, height: 900 } });
  }
  await b.close(); console.log('ok');
})().catch(e => { console.error(e); process.exit(1); });
