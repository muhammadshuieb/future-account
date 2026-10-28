import { writeFileSync } from 'fs'
import sharp from 'sharp'

function ar(s) {
  return [...s].map((c) => {
    const cp = c.codePointAt(0)
    return cp > 127 ? `&#${cp};` : c
  }).join('')
}

const logo = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 220" role="img">
  <defs>
    <linearGradient id="abGold" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%" stop-color="#B8922A"/>
      <stop offset="100%" stop-color="#D4B45A"/>
    </linearGradient>
  </defs>
  <g fill="#123469" transform="translate(18,18)">
    <rect x="8" y="132" width="18" height="42" rx="1.5"/>
    <rect x="30" y="118" width="16" height="56" rx="1.5"/>
    <rect x="50" y="126" width="20" height="48" rx="1.5"/>
    <rect x="74" y="138" width="14" height="36" rx="1.5"/>
    <g fill="#ffffff" opacity="0.35">
      <circle cx="14" cy="142" r="1.6"/><circle cx="22" cy="142" r="1.6"/>
      <circle cx="14" cy="152" r="1.6"/><circle cx="22" cy="152" r="1.6"/>
      <circle cx="35" cy="128" r="1.6"/><circle cx="42" cy="128" r="1.6"/>
      <circle cx="35" cy="140" r="1.6"/><circle cx="42" cy="140" r="1.6"/>
      <circle cx="57" cy="136" r="1.6"/><circle cx="65" cy="136" r="1.6"/>
      <circle cx="57" cy="148" r="1.6"/><circle cx="65" cy="148" r="1.6"/>
    </g>
    <rect x="92" y="18" width="7" height="150" rx="2"/>
    <path d="M95.5 8 L98.2 15.2 L106 15.4 L99.8 20.2 L102 27.5 L95.5 23.2 L89 27.5 L91.2 20.2 L85 15.4 L92.8 15.2 Z"/>
    <path d="M99 28 C70 36, 42 30, 22 42 C40 52, 68 48, 99 58 Z"/>
    <path d="M99 62 C68 72, 38 64, 18 78 C38 88, 70 84, 99 94 Z"/>
    <path d="M99 98 C72 108, 44 100, 24 114 C44 122, 72 120, 99 128 Z"/>
    <g fill="#ffffff" opacity="0.55">
      <circle cx="48" cy="42" r="2.2"/><circle cx="68" cy="46" r="2"/>
      <circle cx="40" cy="76" r="2.2"/><circle cx="62" cy="80" r="2"/>
      <circle cx="46" cy="112" r="2"/><circle cx="70" cy="116" r="2.2"/>
    </g>
    <g stroke="#ffffff" stroke-width="1.4" opacity="0.45" fill="none">
      <path d="M48 42 H68 M68 46 V80 M40 76 H62 M46 112 H70"/>
    </g>
    <path d="M112 34 H138 C156 34 168 46 168 64 C168 76 162 86 150 90 C164 94 172 106 172 122 C172 144 156 158 132 158 H112 Z M128 48 V76 H138 C146 76 152 70 152 62 C152 54 146 48 138 48 Z M128 92 V142 H134 C146 142 156 134 156 122 C156 108 146 92 134 92 Z"/>
  </g>
  <text x="500" y="108" fill="#123469" font-family="Tahoma, Segoe UI, Arial, sans-serif" font-size="58" font-weight="700" direction="rtl" text-anchor="end">${ar('البيرق تك')}</text>
  <text x="230" y="156" fill="url(#abGold)" font-family="Segoe UI, Helvetica, Arial, sans-serif" font-size="28" font-weight="600" letter-spacing="0.04em">Al-Bairaq Tech</text>
</svg>`

const stamp = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 1024 1024">
  <defs>
    <path id="abTopArc" d="M160,512 A352,352 0 0,1 864,512"/>
    <path id="abBotArc" d="M864,512 A352,352 0 0,1 160,512"/>
  </defs>
  <g fill="none" stroke="#123469" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="512" cy="512" r="470" stroke-width="28"/>
    <circle cx="512" cy="512" r="430" stroke-width="8"/>
    <circle cx="512" cy="512" r="250" stroke-width="6"/>
  </g>
  <text fill="#123469" font-family="Tahoma, Segoe UI, Arial, sans-serif" font-size="52" font-weight="700">
    <textPath xlink:href="#abTopArc" startOffset="50%" text-anchor="middle">${ar('شركة البيرق تك')}</textPath>
  </text>
  <text fill="#123469" font-family="Tahoma, Segoe UI, Arial, sans-serif" font-size="26" font-weight="600">
    <textPath xlink:href="#abBotArc" startOffset="50%" text-anchor="middle">${ar('تجارة المقاولات واستيراد وتصدير الأدوات الإلكترونية')} · ${ar('سجل تجاري')} 20258</textPath>
  </text>
  <g fill="#123469">
    <g transform="translate(140,512)">
      <path d="M0,-18 L4,-4 L18,-4 L7,4 L11,18 L0,10 L-11,18 L-7,4 L-18,-4 L-4,-4 Z" transform="translate(0,-52) scale(0.55)"/>
      <path d="M0,-18 L4,-4 L18,-4 L7,4 L11,18 L0,10 L-11,18 L-7,4 L-18,-4 L-4,-4 Z" transform="scale(0.7)"/>
      <path d="M0,-18 L4,-4 L18,-4 L7,4 L11,18 L0,10 L-11,18 L-7,4 L-18,-4 L-4,-4 Z" transform="translate(0,52) scale(0.55)"/>
    </g>
    <g transform="translate(884,512)">
      <path d="M0,-18 L4,-4 L18,-4 L7,4 L11,18 L0,10 L-11,18 L-7,4 L-18,-4 L-4,-4 Z" transform="translate(0,-52) scale(0.55)"/>
      <path d="M0,-18 L4,-4 L18,-4 L7,4 L11,18 L0,10 L-11,18 L-7,4 L-18,-4 L-4,-4 Z" transform="scale(0.7)"/>
      <path d="M0,-18 L4,-4 L18,-4 L7,4 L11,18 L0,10 L-11,18 L-7,4 L-18,-4 L-4,-4 Z" transform="translate(0,52) scale(0.55)"/>
    </g>
  </g>
  <g fill="#123469" transform="translate(512,512)">
    <g transform="translate(-70,28)">
      <rect x="0" y="36" width="22" height="52" rx="2"/>
      <rect x="26" y="18" width="20" height="70" rx="2"/>
      <rect x="50" y="28" width="24" height="60" rx="2"/>
      <rect x="78" y="40" width="18" height="48" rx="2"/>
    </g>
    <rect x="-6" y="-120" width="10" height="175" rx="2"/>
    <path d="M-1,-148 L4,-136 L17,-136 L6,-128 L10,-115 L-1,-122 L-12,-115 L-8,-128 L-19,-136 L-6,-136 Z"/>
    <path d="M4,-110 C-40,-100 -78,-108 -108,-92 C-78,-80 -40,-84 4,-74 Z"/>
    <path d="M4,-68 C-42,-56 -80,-66 -112,-48 C-80,-38 -40,-40 4,-30 Z"/>
    <path d="M4,-24 C-38,-12 -74,-20 -104,-4 C-74,6 -38,4 4,14 Z"/>
    <g fill="#ffffff" opacity="0.55">
      <circle cx="-70" cy="-95" r="3"/><circle cx="-42" cy="-88" r="2.5"/>
      <circle cx="-78" cy="-52" r="3"/><circle cx="-48" cy="-46" r="2.5"/>
      <circle cx="-70" cy="-10" r="2.8"/><circle cx="-40" cy="-2" r="2.5"/>
    </g>
    <path d="M18,-105 H52 C78,-105 94,-88 94,-62 C94,-44 84,-30 66,-24 C84,-18 98,-2 98,22 C98,54 76,74 46,74 H18 Z M36,-86 V-40 H50 C62,-40 72,-50 72,-62 C72,-76 62,-86 50,-86 Z M36,-10 V52 H48 C64,52 76,40 76,22 C76,2 64,-10 48,-10 Z"/>
  </g>
</svg>`

writeFileSync('public/logo-albairaq.svg', logo, 'utf8')
writeFileSync('public/company-stamp-albairaq.svg', stamp, 'utf8')

await sharp(Buffer.from(logo))
  .resize({ width: 1600, fit: 'inside' })
  .png()
  .toFile('public/logo-albairaq.png')

await sharp(Buffer.from(stamp))
  .resize(2048, 2048)
  .png()
  .toFile('public/company-stamp-albairaq.png')

const logoMeta = await sharp('public/logo-albairaq.png').metadata()
const stampMeta = await sharp('public/company-stamp-albairaq.png').metadata()
console.log('logo', logoMeta.width, logoMeta.height)
console.log('stamp', stampMeta.width, stampMeta.height, 'alpha', stampMeta.hasAlpha)
