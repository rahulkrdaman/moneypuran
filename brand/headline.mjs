import { createRequire } from 'module';
const require = createRequire('C:/Users/Hp/Downloads/toolhub/toolhub/');
const sharp = require('sharp');

/* MoneyPuran headline image generator.
   Clean editorial card - serif headline, sans label, faint market line.
   Output: 2816 x 1536 PNG.  Usage:
     node headline.mjs "Headline text" "Category" out-name [theme]
   theme = light (default) | dark | blue
*/
const [, , TITLE = 'How Is Capital Gains Tax on Stocks Calculated?', CAT = 'Personal Finance', OUT = 'headline-sample', THEME = 'light'] = process.argv;

const W = 2816, H = 1536;
const M = 190;                 // page margin
const FS = 176;                // headline font size
const LH = 1.14;               // line height factor
const MAXW = W - M * 2 - 40;   // usable text width

const palettes = {
  light: { bg1: '#ffffff', bg2: '#eaf0f9', ink: '#0f172a', sub: '#5b6b83', brand: '#0057ff', line: '#0057ff', lineOp: 0.10, chip: '#0057ff', chipInk: '#ffffff' },
  dark:  { bg1: '#0c1428', bg2: '#070b16', ink: '#f4f7fc', sub: '#9fb2cc', brand: '#5b9bff', line: '#5b9bff', lineOp: 0.16, chip: '#1e3a72', chipInk: '#dbe8ff' },
  blue:  { bg1: '#0b49d6', bg2: '#002a8f', ink: '#ffffff', sub: '#c9dbff', brand: '#ffffff', line: '#ffffff', lineOp: 0.14, chip: '#ffffff', chipInk: '#0b49d6' },
};
const P = palettes[THEME] || palettes.light;

const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// rough proportional width for Georgia bold ~ 0.53em average, caps a bit wider
function wrap(text, fontSize, maxWidth) {
  const words = text.trim().split(/\s+/);
  const wpc = fontSize * 0.54;
  const lines = [];
  let cur = '';
  for (const word of words) {
    const test = cur ? cur + ' ' + word : word;
    if (test.length * wpc > maxWidth && cur) { lines.push(cur); cur = word; }
    else cur = test;
  }
  if (cur) lines.push(cur);
  return lines;
}

let fs = FS;
let lines = wrap(TITLE, fs, MAXW);
while (lines.length > 4 && fs > 96) { fs -= 8; lines = wrap(TITLE, fs, MAXW); }

const blockH = lines.length * fs * LH;
const startY = (H - blockH) / 2 + fs * 0.82 + 40;   // vertically centred, nudged down for the label above

const tspans = lines.map((l, i) =>
  `<tspan x="${M}" dy="${i === 0 ? 0 : fs * LH}">${esc(l)}</tspan>`).join('');

// faint market polyline along the lower third
const pts = [];
let y = H * 0.72;
for (let x = 0; x <= W; x += W / 16) {
  y += (Math.sin(x / 190) * 46) + (Math.random() * 40 - 20);
  y = Math.max(H * 0.60, Math.min(H * 0.9, y));
  pts.push(`${x.toFixed(0)},${y.toFixed(0)}`);
}
const linePath = 'M' + pts.join(' L');

const labelY = startY - fs * 0.82 - 132;

const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="0.6" y2="1">
      <stop offset="0" stop-color="${P.bg1}"/><stop offset="1" stop-color="${P.bg2}"/>
    </linearGradient>
    <linearGradient id="mk" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="${P.line}" stop-opacity="${P.lineOp + 0.06}"/>
      <stop offset="1" stop-color="${P.line}" stop-opacity="0"/>
    </linearGradient>
  </defs>
  <rect width="${W}" height="${H}" fill="url(#bg)"/>
  <path d="${linePath} L${W},${H} L0,${H} Z" fill="url(#mk)"/>
  <path d="${linePath}" fill="none" stroke="${P.line}" stroke-width="4" stroke-opacity="${P.lineOp + 0.14}" stroke-linejoin="round"/>

  <!-- brand lockup, top-left -->
  <g transform="translate(${M},120)">
    <rect width="96" height="96" rx="24" fill="${P.brand === '#ffffff' ? '#ffffff' : 'url(#logoG)'}"/>
    <defs><linearGradient id="logoG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2f7bff"/><stop offset="1" stop-color="#0038bd"/></linearGradient></defs>
    <circle cx="25" cy="69" r="6.2" fill="${P.brand === '#ffffff' ? '#0b49d6' : '#ffffff'}"/>
    <path d="M25 69 L42 54 L56 60 L77 33" fill="none" stroke="${P.brand === '#ffffff' ? '#0b49d6' : '#ffffff'}" stroke-width="6.6" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M65 33 L77 33 L77 45" fill="none" stroke="${P.brand === '#ffffff' ? '#0b49d6' : '#ffffff'}" stroke-width="6.6" stroke-linecap="round" stroke-linejoin="round"/>
    <text x="128" y="68" font-family="'Segoe UI',Roboto,Arial,sans-serif" font-size="70" font-weight="700" letter-spacing="-1.5">
      <tspan fill="${P.ink}">Money</tspan><tspan fill="${P.brand === '#ffffff' ? '#c9dbff' : P.brand}">Puran</tspan>
    </text>
  </g>

  <!-- category label -->
  <text x="${M}" y="${labelY}" font-family="'Segoe UI',Roboto,Arial,sans-serif" font-size="42" font-weight="700" letter-spacing="6" fill="${P.brand === '#ffffff' ? '#ffffff' : P.brand}">${esc(CAT.toUpperCase())}</text>
  <rect x="${M}" y="${labelY + 26}" width="132" height="8" rx="4" fill="${P.brand === '#ffffff' ? '#ffffff' : P.brand}"/>

  <!-- headline -->
  <text x="${M}" y="${startY}" font-family="Georgia,'Times New Roman',serif" font-size="${fs}" font-weight="700" fill="${P.ink}" style="letter-spacing:-1px">${tspans}</text>

  <!-- footer -->
  <text x="${M}" y="${H - 108}" font-family="'Segoe UI',Roboto,Arial,sans-serif" font-size="40" font-weight="500" fill="${P.sub}">moneypuran.com &#8226; Not investment advice</text>
</svg>`;

const dir = new URL('.', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
await sharp(Buffer.from(svg), { density: 96 }).png().toFile(dir + OUT + '.png');
console.log('wrote', OUT + '.png', W + 'x' + H, '(' + lines.length + ' lines @ ' + fs + 'px)');
