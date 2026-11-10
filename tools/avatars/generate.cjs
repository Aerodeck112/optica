/*
 * Generează colecția originală de avatare Another World Cluj (SVG, fără personaje din jocuri sau fotografii).
 * Rulare: node tools/avatars/generate.cjs  → public/media/avatars/*.svg
 * Cheile corespund AvatarCatalog::AVATARS (app/Modules/Arena/Services/AvatarCatalog.php).
 */
const fs = require('fs');
const path = require('path');

const OUT = path.join(__dirname, '../../public/media/avatars');
fs.mkdirSync(OUT, { recursive: true });

// Paleta brandului: violet, cyan, magenta, crimson, pe fundal „midnight”.
const PALETTES = [
  { bg: ['#2a1a6b', '#0b0920'], a: '#7b4dff', b: '#3ee6ff', c: '#b39cff' },
  { bg: ['#4a0f3a', '#0d0618'], a: '#f23db3', b: '#ff8ad8', c: '#ffd1f0' },
  { bg: ['#0c3b55', '#06101d'], a: '#3ee6ff', b: '#7b4dff', c: '#c9f8ff' },
  { bg: ['#5a0d22', '#12050c'], a: '#e61c4a', b: '#ff6b8b', c: '#ffc3cf' },
  { bg: ['#1f2f6e', '#070a1f'], a: '#5b8cff', b: '#3ee6ff', c: '#cfe0ff' },
  { bg: ['#3d1670', '#0e0620'], a: '#b39cff', b: '#f23db3', c: '#efe6ff' },
];

const svg = (p, body, extraDefs = '') => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256">
<defs>
<radialGradient id="bg" cx="50%" cy="36%" r="72%"><stop offset="0" stop-color="${p.bg[0]}"/><stop offset="1" stop-color="${p.bg[1]}"/></radialGradient>
<linearGradient id="ac" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${p.a}"/><stop offset="1" stop-color="${p.b}"/></linearGradient>
<linearGradient id="visor" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${p.b}" stop-opacity=".95"/><stop offset=".55" stop-color="#140c33"/><stop offset="1" stop-color="${p.a}" stop-opacity=".9"/></linearGradient>
<linearGradient id="suit" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e9ecff"/><stop offset="1" stop-color="#9aa3d6"/></linearGradient>
<radialGradient id="glow" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="${p.c}" stop-opacity=".9"/><stop offset="1" stop-color="${p.a}" stop-opacity="0"/></radialGradient>
<clipPath id="disc"><circle cx="128" cy="128" r="128"/></clipPath>
${extraDefs}
</defs>
<g clip-path="url(#disc)">
<rect width="256" height="256" fill="url(#bg)"/>
${stars(p)}
<circle cx="128" cy="118" r="92" fill="url(#glow)" opacity=".35"/>
${body}
</g>
<circle cx="128" cy="128" r="126" fill="none" stroke="${p.a}" stroke-opacity=".55" stroke-width="4"/>
</svg>
`;

function stars(p) {
  const pts = [[40, 52], [210, 46], [62, 196], [200, 186], [222, 112], [30, 128], [96, 30], [170, 222]];
  return pts.map(([x, y], i) => `<circle cx="${x}" cy="${y}" r="${i % 3 ? 1.6 : 2.4}" fill="${p.c}" opacity="${i % 2 ? 0.55 : 0.8}"/>`).join('');
}

const shoulders = (fill, accent) => `<path d="M38 256c4-46 40-70 90-70s86 24 90 70z" fill="${fill}"/><path d="M98 192c8 14 20 20 30 20s22-6 30-20" fill="none" stroke="${accent}" stroke-width="6" stroke-linecap="round"/>`;

// ——— Exploratori spațiali: cască deschisă cu vizor, costum, antenă ———
function explorer(p, v) {
  const visors = [
    '<rect x="78" y="86" width="100" height="58" rx="29" fill="url(#visor)"/>',
    '<path d="M74 100q54-30 108 0v22q-54 30-108 0z" fill="url(#visor)"/>',
    '<rect x="80" y="92" width="96" height="44" rx="10" fill="url(#visor)"/>',
    '<ellipse cx="128" cy="114" rx="52" ry="32" fill="url(#visor)"/>',
    '<path d="M76 92h104l-10 46H86z" fill="url(#visor)"/>',
    '<rect x="76" y="96" width="104" height="36" rx="18" fill="url(#visor)"/><rect x="76" y="96" width="104" height="36" rx="18" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="3"/>',
  ];
  const antenna = v % 2 === 0 ? `<path d="M168 66l14-24" stroke="${p.c}" stroke-width="5" stroke-linecap="round"/><circle cx="183" cy="40" r="7" fill="${p.b}"/>` : `<path d="M86 66l-10-22" stroke="${p.c}" stroke-width="5" stroke-linecap="round"/><circle cx="75" cy="42" r="6" fill="${p.a}"/>`;
  return `${shoulders('url(#suit)', p.a)}
<path d="M58 118c0-46 31-74 70-74s70 28 70 74c0 34-24 58-70 58s-70-24-70-58z" fill="#dfe3ff"/>
<path d="M58 118c0-46 31-74 70-74s70 28 70 74" fill="none" stroke="${p.a}" stroke-width="6"/>
${visors[v]}
<path d="M92 104q14-10 30-8" stroke="#fff" stroke-opacity=".7" stroke-width="5" stroke-linecap="round" fill="none"/>
<rect x="108" y="154" width="40" height="8" rx="4" fill="${p.a}"/>
${antenna}`;
}

// ——— Roboți: cap geometric, ochi LED, antenă ———
function robot(p, v) {
  const heads = [
    '<rect x="62" y="64" width="132" height="112" rx="22" fill="url(#ac)"/>',
    '<path d="M70 64h116l14 56-14 56H70l-14-56z" fill="url(#ac)"/>',
    '<rect x="66" y="60" width="124" height="118" rx="50" fill="url(#ac)"/>',
    '<path d="M128 52l66 38v62l-66 38-66-38V90z" fill="url(#ac)"/>',
    '<rect x="58" y="72" width="140" height="98" rx="14" fill="url(#ac)"/>',
    '<circle cx="128" cy="118" r="66" fill="url(#ac)"/>',
  ];
  const eyes = [
    '<circle cx="104" cy="112" r="15" fill="#0b0920"/><circle cx="152" cy="112" r="15" fill="#0b0920"/><circle cx="104" cy="112" r="7" fill="#fff"/><circle cx="152" cy="112" r="7" fill="#fff"/>',
    '<rect x="84" y="100" width="88" height="22" rx="11" fill="#0b0920"/><rect x="92" y="106" width="72" height="10" rx="5" fill="#fff" opacity=".9"/>',
    '<circle cx="128" cy="110" r="24" fill="#0b0920"/><circle cx="128" cy="110" r="11" fill="#fff"/>',
    '<rect x="90" y="98" width="28" height="22" rx="4" fill="#0b0920"/><rect x="138" y="98" width="28" height="22" rx="4" fill="#0b0920"/><rect x="97" y="104" width="14" height="10" fill="#fff"/><rect x="145" y="104" width="14" height="10" fill="#fff"/>',
    '<path d="M86 116q18-20 36 0M134 116q18-20 36 0" stroke="#0b0920" stroke-width="10" fill="none" stroke-linecap="round"/>',
    '<circle cx="106" cy="112" r="13" fill="#0b0920"/><circle cx="150" cy="112" r="13" fill="#0b0920"/><circle cx="110" cy="108" r="5" fill="#fff"/><circle cx="154" cy="108" r="5" fill="#fff"/>',
  ];
  const mouth = v % 3 === 0
    ? '<rect x="100" y="140" width="56" height="14" rx="4" fill="#0b0920" opacity=".75"/><path d="M110 140v14M122 140v14M134 140v14M146 140v14" stroke="#fff" stroke-opacity=".5" stroke-width="2"/>'
    : v % 3 === 1 ? '<path d="M104 146q24 14 48 0" stroke="#0b0920" stroke-width="7" fill="none" stroke-linecap="round"/>' : '<rect x="110" y="142" width="36" height="8" rx="4" fill="#0b0920" opacity=".75"/>';
  return `${shoulders('#2b2f57', p.b)}
<rect x="114" y="168" width="28" height="22" fill="#3a3f75"/>
${heads[v]}
<path d="M128 60V38" stroke="${p.c}" stroke-width="6" stroke-linecap="round"/><circle cx="128" cy="34" r="9" fill="${p.b}"/>
<circle cx="58" cy="118" r="10" fill="${p.c}"/><circle cx="198" cy="118" r="10" fill="${p.c}"/>
${eyes[v]}
${mouth}
<path d="M80 80q20-10 40-8" stroke="#fff" stroke-opacity=".45" stroke-width="5" fill="none" stroke-linecap="round"/>`;
}

// ——— Astronauți: cască sferică, vizor închis cu reflexie, rucsac ———
function astronaut(p, v) {
  const patch = ['<circle cx="168" cy="216" r="10" fill="url(#ac)"/>', '<path d="M160 210l8-10 8 10-8 10z" fill="url(#ac)"/>', '<path d="M158 206h20l-10 18z" fill="url(#ac)"/>',
    '<rect x="158" y="206" width="20" height="14" rx="3" fill="url(#ac)"/>', '<path d="M168 202l3 7h7l-6 4 2 7-6-4-6 4 2-7-6-4h7z" fill="url(#ac)"/>', '<circle cx="168" cy="214" r="9" fill="none" stroke="url(#ac)" stroke-width="4"/>'][v];
  const visorShape = v % 2 === 0 ? '<ellipse cx="128" cy="116" rx="54" ry="44" fill="url(#visor)"/>' : '<path d="M76 104q52-36 104 0v20q-52 40-104 0z" fill="url(#visor)"/>';
  return `${shoulders('url(#suit)', p.a)}
<rect x="44" y="96" width="24" height="70" rx="10" fill="#c6cbef"/><rect x="188" y="96" width="24" height="70" rx="10" fill="#c6cbef"/>
<circle cx="128" cy="116" r="72" fill="#eef0ff"/>
<circle cx="128" cy="116" r="72" fill="none" stroke="${p.a}" stroke-width="5"/>
${visorShape}
<path d="M90 98q16-18 40-18" stroke="#fff" stroke-opacity=".75" stroke-width="7" fill="none" stroke-linecap="round"/>
<circle cx="160" cy="132" r="6" fill="#fff" opacity=".5"/>
${patch}`;
}

// ——— Fantasy: siluete cu glugă, coif sau pălărie; fața în umbră, ochi luminoși ———
function fantasy(p, v) {
  const eyes = `<circle cx="112" cy="126" r="6" fill="${p.b}"/><circle cx="144" cy="126" r="6" fill="${p.b}"/><circle cx="112" cy="126" r="12" fill="${p.b}" opacity=".25"/><circle cx="144" cy="126" r="12" fill="${p.b}" opacity=".25"/>`;
  const face = '<ellipse cx="128" cy="130" rx="38" ry="40" fill="#0b0920"/>';
  const designs = [
    // elfă: glugă cu urechi ascuțite
    `<path d="M40 256c6-50 40-74 88-74s82 24 88 74z" fill="${p.a}"/><path d="M84 128l-34-26 40 6zM172 128l34-26-40 6z" fill="#c9b8ff"/><path d="M128 50c-46 0-62 40-62 80 0 26 18 50 62 50s62-24 62-50c0-40-16-80-62-80z" fill="url(#ac)"/>${face}${eyes}<path d="M110 66q18-10 36 0" stroke="${p.c}" stroke-width="4" fill="none"/>`,
    // vrăjitor: pălărie înaltă, barbă
    `<path d="M40 256c6-48 40-70 88-70s82 22 88 70z" fill="#2d2470"/><path d="M128 18l-50 98h100z" fill="url(#ac)"/><path d="M66 116h124" stroke="${p.c}" stroke-width="10" stroke-linecap="round"/><circle cx="140" cy="62" r="6" fill="#fff"/>${face}${eyes}<path d="M92 150q36 70 72 0-36 14-72 0z" fill="#d9dcf5"/>`,
    // cavaler: coif cu fantă și panaș
    `<path d="M40 256c6-48 40-70 88-70s82 22 88 70z" fill="#5a6290"/><path d="M128 20c22 10 30 26 26 44" stroke="${p.a}" stroke-width="14" fill="none" stroke-linecap="round"/><path d="M76 92c0-30 22-48 52-48s52 18 52 48v54c0 22-22 36-52 36s-52-14-52-36z" fill="url(#suit)"/><rect x="88" y="112" width="80" height="12" rx="6" fill="#0b0920"/><rect x="96" y="115" width="64" height="5" rx="2" fill="${p.b}"/><path d="M128 128v46" stroke="#8a92c4" stroke-width="5"/>`,
    // druidă: glugă cu coarne/ramuri
    `<path d="M40 256c6-50 40-74 88-74s82 24 88 74z" fill="#1f5a52"/><path d="M84 70l-22-34 10 30-20-10zM172 70l22-34-10 30 20-10z" stroke="#9ff5d9" stroke-width="6" fill="none" stroke-linecap="round"/><path d="M128 56c-44 0-60 38-60 76 0 26 18 48 60 48s60-22 60-48c0-38-16-76-60-76z" fill="#2fa58c"/>${face}${eyes}<path d="M120 76q8-10 16 0q-8 10-16 0z" fill="#9ff5d9"/>`,
    // zână: aripi, orb luminos
    `<path d="M128 150c-40-50-96-40-92-6 4 30 52 30 92 6zM128 150c40-50 96-40 92-6-4 30-52 30-92 6z" fill="${p.b}" opacity=".55"/><path d="M60 256c6-40 32-58 68-58s62 18 68 58z" fill="url(#ac)"/><circle cx="128" cy="122" r="44" fill="#f0e9ff"/><circle cx="128" cy="122" r="44" fill="none" stroke="${p.a}" stroke-width="5"/><path d="M106 120q6-6 12 0M138 120q6-6 12 0" stroke="#2a1a6b" stroke-width="5" fill="none" stroke-linecap="round"/><path d="M114 140q14 10 28 0" stroke="#2a1a6b" stroke-width="4" fill="none" stroke-linecap="round"/><circle cx="128" cy="62" r="10" fill="${p.c}"/><circle cx="128" cy="62" r="20" fill="${p.c}" opacity=".3"/>`,
    // paladin: coif cu creastă, emblemă pe piept
    `<path d="M40 256c6-48 40-70 88-70s82 22 88 70z" fill="url(#ac)"/><path d="M110 214l18-14 18 14-18 18z" fill="#fff"/><path d="M76 96c0-34 22-52 52-52s52 18 52 52v46c0 24-22 38-52 38s-52-14-52-38z" fill="#f2d98a"/><path d="M128 40v-20" stroke="${p.a}" stroke-width="12" stroke-linecap="round"/><path d="M90 118h76l-8 18h-60z" fill="#0b0920"/><circle cx="110" cy="126" r="4" fill="${p.b}"/><circle cx="146" cy="126" r="4" fill="${p.b}"/><path d="M128 136v40" stroke="#c8ad5a" stroke-width="5"/>`,
  ];
  return designs[v];
}

// ——— Creaturi cosmice ———
function creature(p, v) {
  const designs = [
    // Glim: blob cu un ochi
    `<path d="M60 230c-8-80 20-150 68-150s76 70 68 150c-10-16-22-16-34 0-12-16-24-16-34 0-12-16-24-16-34 0-10-16-24-16-34 0z" fill="url(#ac)"/><circle cx="128" cy="130" r="30" fill="#fff"/><circle cx="134" cy="130" r="15" fill="#0b0920"/><circle cx="139" cy="125" r="5" fill="#fff"/><path d="M110 172q18 12 36 0" stroke="#0b0920" stroke-width="6" fill="none" stroke-linecap="round"/>`,
    // Nox: bestie cu coarne
    `<path d="M70 92l-26-46 46 30zM186 92l26-46-46 30z" fill="${p.c}"/><path d="M128 64c50 0 74 36 74 82 0 50-30 86-74 86s-74-36-74-86c0-46 24-82 74-82z" fill="url(#ac)"/><path d="M92 128l22 8M164 128l-22 8" stroke="#0b0920" stroke-width="8" stroke-linecap="round"/><circle cx="104" cy="142" r="8" fill="#fff"/><circle cx="152" cy="142" r="8" fill="#fff"/><path d="M104 182h48l-8 10-8-8-8 8-8-8-8 8z" fill="#fff"/>`,
    // Zorb: extraterestru cu trei ochi
    `<path d="M128 46c46 0 68 40 68 84 0 52-30 96-68 96s-68-44-68-96c0-44 22-84 68-84z" fill="#6fe3a8"/><path d="M128 46c46 0 68 40 68 84" stroke="${p.a}" stroke-width="5" fill="none"/><ellipse cx="98" cy="126" rx="14" ry="18" fill="#0b0920"/><ellipse cx="158" cy="126" rx="14" ry="18" fill="#0b0920"/><ellipse cx="128" cy="96" rx="10" ry="13" fill="#0b0920"/><circle cx="102" cy="120" r="4" fill="#fff"/><circle cx="162" cy="120" r="4" fill="#fff"/><circle cx="131" cy="92" r="3" fill="#fff"/><path d="M114 176q14 8 28 0" stroke="#0b0920" stroke-width="5" fill="none" stroke-linecap="round"/>`,
    // Krill: meduză cosmică
    `<path d="M70 132c0-46 26-74 58-74s58 28 58 74z" fill="url(#ac)" opacity=".95"/><path d="M84 132c0 30-8 60-4 90M104 132c0 34 8 58 2 92M128 132v96M152 132c0 34-8 58-2 92M172 132c0 30 8 60 4 90" stroke="${p.c}" stroke-width="6" fill="none" stroke-linecap="round" opacity=".85"/><circle cx="108" cy="108" r="9" fill="#fff"/><circle cx="148" cy="108" r="9" fill="#fff"/><circle cx="110" cy="110" r="4" fill="#0b0920"/><circle cx="150" cy="110" r="4" fill="#0b0920"/>`,
    // Flux: spirit de flacără
    `<path d="M128 30c18 34 62 54 62 112 0 50-28 88-62 88s-62-38-62-88c0-34 18-48 30-70 6 18 14 24 22 26-4-26 2-48 10-68z" fill="url(#ac)"/><path d="M128 110c10 18 28 30 28 56 0 24-12 40-28 40s-28-16-28-40c0-20 14-30 28-56z" fill="${p.c}" opacity=".8"/><circle cx="114" cy="160" r="6" fill="#0b0920"/><circle cx="142" cy="160" r="6" fill="#0b0920"/>`,
    // Orbi: planetă vie cu inel
    `<ellipse cx="128" cy="140" rx="104" ry="26" fill="none" stroke="${p.c}" stroke-width="10" opacity=".6"/><circle cx="128" cy="128" r="62" fill="url(#ac)"/><path d="M24 140q104 40 208 0" stroke="${p.c}" stroke-width="10" fill="none"/><circle cx="108" cy="118" r="10" fill="#fff"/><circle cx="148" cy="118" r="10" fill="#fff"/><circle cx="110" cy="120" r="5" fill="#0b0920"/><circle cx="150" cy="120" r="5" fill="#0b0920"/><path d="M114 148q14 10 28 0" stroke="#0b0920" stroke-width="5" fill="none" stroke-linecap="round"/>`,
  ];
  return designs[v];
}

// ——— Simboluri și măști futuriste ———
function mask(p, v) {
  const designs = [
    // Cipher: mască hexagonală cu fante
    `<path d="M128 40l78 44v88l-78 44-78-44V84z" fill="url(#ac)"/><path d="M128 40l78 44v88l-78 44-78-44V84z" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="4"/><path d="M84 116l30 6-30 10zM172 116l-30 6 30 10z" fill="#0b0920"/><path d="M128 150v30" stroke="#0b0920" stroke-width="6"/>`,
    // Echo: mască ovală cu linii de sunet
    `<ellipse cx="128" cy="128" rx="68" ry="84" fill="#eef0ff"/><path d="M86 118q20-14 34 0M136 118q20-14 34 0" stroke="#0b0920" stroke-width="8" fill="none" stroke-linecap="round"/><path d="M100 168h8M114 160v16M128 152v32M142 160v16M156 168h-8" stroke="${p.a}" stroke-width="6" stroke-linecap="round"/><path d="M128 44v20" stroke="${p.b}" stroke-width="6"/>`,
    // Prisma: diamant cu fațete
    `<path d="M128 34l72 70-72 118-72-118z" fill="url(#ac)"/><path d="M56 104h144M128 34l-30 70 30 118 30-118z" stroke="#fff" stroke-opacity=".5" stroke-width="3" fill="none"/><circle cx="108" cy="114" r="7" fill="#0b0920"/><circle cx="148" cy="114" r="7" fill="#0b0920"/>`,
    // Runa: cerc cu simbol
    `<circle cx="128" cy="128" r="76" fill="#120d33"/><circle cx="128" cy="128" r="76" fill="none" stroke="url(#ac)" stroke-width="10"/><circle cx="128" cy="128" r="54" fill="none" stroke="${p.c}" stroke-width="3" stroke-dasharray="8 8"/><path d="M128 80v96M100 104l28 24 28-24M104 156h48" stroke="url(#ac)" stroke-width="10" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`,
    // Spectru: mască fantomatică
    `<path d="M70 230V120c0-44 26-74 58-74s58 30 58 74v110l-20-16-19 16-19-16-19 16-19-16z" fill="#e8e6ff" opacity=".92"/><ellipse cx="106" cy="122" rx="12" ry="18" fill="#0b0920"/><ellipse cx="150" cy="122" rx="12" ry="18" fill="#0b0920"/><circle cx="106" cy="118" r="4" fill="${p.b}"/><circle cx="150" cy="118" r="4" fill="${p.b}"/><ellipse cx="128" cy="166" rx="10" ry="12" fill="#0b0920" opacity=".8"/>`,
    // Vizor: cască cu bandă luminoasă (ciclop)
    `${shoulders('#2b2f57', p.a)}<path d="M66 128c0-46 26-78 62-78s62 32 62 78-26 64-62 64-62-18-62-64z" fill="#1c1a44"/><path d="M66 128c0-46 26-78 62-78s62 32 62 78" fill="none" stroke="url(#ac)" stroke-width="6"/><rect x="70" y="112" width="116" height="22" rx="11" fill="url(#ac)"/><rect x="90" y="118" width="40" height="10" rx="5" fill="#fff" opacity=".9"/>`,
  ];
  return designs[v];
}

const FAMILIES = { explorer, robot, astronaut, fantasy, creature, mask };
let n = 0;
for (const [family, draw] of Object.entries(FAMILIES)) {
  for (let v = 0; v < 6; v++) {
    const palette = PALETTES[(v + Object.keys(FAMILIES).indexOf(family) * 2) % PALETTES.length];
    const key = `${family}-${String(v + 1).padStart(2, '0')}`;
    fs.writeFileSync(path.join(OUT, `${key}.svg`), svg(palette, draw(palette, v)).replace(/\n+/g, '\n'));
    n++;
  }
}

// Avatar neutru (aliasuri „Explorator 03” și fallback): silueta generică, fără trăsături.
const neutral = { bg: ['#1d1f3f', '#0a0b1c'], a: '#5b6290', b: '#8a92c4', c: '#a9b0d6' };
fs.writeFileSync(path.join(OUT, 'neutral.svg'), svg(neutral, `${shoulders('#3a3f6b', '#5b6290')}<circle cx="128" cy="116" r="54" fill="#4a5080"/><path d="M98 112h60" stroke="#a9b0d6" stroke-width="10" stroke-linecap="round" opacity=".6"/>`));
console.log(`${n + 1} avatare generate în ${OUT}`);
