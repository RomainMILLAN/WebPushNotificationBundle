# Design brief: Web Push Notification

Brief for **Claude Design** (claude.ai/design). The design project is created as a design system
named "Web Push Notification" and kept in sync with this repository through the **`/design-sync`**
skill, one component at a time. Exports land in `art/` (excluded from the Composer archive by
`.gitattributes`).

## The product

`romainmillan/web-push-notification`: an open-source (MIT) PHP package for Symfony and Laravel,
plus an npm package, that lets web applications send push notifications to browsers and
installed PWAs, safely. Audience: PHP developers reading a GitHub README, Packagist and npm pages.

Tone: precise, trustworthy, quietly technical. Security-minded without looking like a security
product (no shields, no padlocks). Neutral: it serves every browser and push service.

## Concept

A **bell** combined with a **push wave**: two or three concentric arcs radiating from the bell
(or from a dot at its top-right), suggesting a message arriving. Geometric, built on a grid,
rounded terminals, a single stroke weight.

Constraints:

- **No third-party brand marks**: no Apple, Google, Mozilla, Microsoft, Symfony or Laravel logos,
  shapes or colours evoking them; no phone mock-ups of a specific OS.
- Readable at **16 px** (favicon) and in **monochrome** (notification badge, which browsers tint).
- Works on light and dark backgrounds; no gradient required for recognition.
- No text inside the symbol. The wordmark, when present, is "Web Push Notification" or
  "web-push-notification".

## Palette (proposal, as tokens)

| Token | Light | Dark | Use |
|---|---|---|---|
| `color.bg` | `#FFFFFF` | `#0B1220` | Background |
| `color.surface` | `#F4F6FA` | `#131C2E` | Cards, banner panels |
| `color.text` | `#0F172A` | `#E6EAF2` | Wordmark, headings |
| `color.muted` | `#526079` | `#94A3B8` | Secondary text |
| `color.primary` | `#2F4BD8` | `#7C93FF` | Bell |
| `color.accent` | `#14B8A6` | `#2DD4BF` | Push wave |
| `color.signal` | `#F59E0B` | `#FBBF24` | Optional dot (the unread indicator), used sparingly |
| `color.border` | `#DCE2EC` | `#24304A` | Hairlines |

Contrast: `text` on `bg` and `primary` on `bg` at least WCAG AA (4.5:1) in both themes.

## Typography

- Wordmark and headings: **Inter** (SemiBold 600, slightly tight tracking).
- Code snippets in banners: **JetBrains Mono** (Regular 400).

Both are open-licensed (SIL OFL); outline the wordmark in exported SVGs.

## Deliverables and expected file names (`art/`)

| File | Size / format | Notes |
|---|---|---|
| `logo.svg` | Vector, colour | Symbol + wordmark, horizontal |
| `logo-mono.svg` | Vector, one colour (`currentColor`) | Same layout, single fill |
| `icon.svg` | Vector, square | Symbol only, centred, safe margin 12 % |
| `icon-512.png` | 512 × 512 PNG | Packagist / npm avatar, from `icon.svg` on `color.surface` |
| `banner-light.png` | 1280 × 320 PNG | README banner for light mode: logo + one-line pitch ("Web Push for Symfony & Laravel") |
| `banner-dark.png` | 1280 × 320 PNG | Same, dark tokens (the README switches with `prefers-color-scheme`) |
| `social-preview.png` | 1280 × 640 PNG | GitHub social preview; keep content inside the central 1200 × 600 |
| `favicon.svg` | Vector | Documentation favicon, simplified symbol for 16 px |
| `notification-icon-192.png` | 192 × 192 PNG | Demo app notification icon (`tests/Fixtures` apps) |
| `notification-icon-512.png` | 512 × 512 PNG | Same, large |
| `notification-badge-96.png` | 96 × 96 PNG | Monochrome badge: white symbol on transparent background, no detail thinner than 4 px |

## Checks before export

- The symbol is recognisable at 16 px and as a white-on-transparent 96 px badge.
- No resemblance to a vendor's push or notification icon.
- Light and dark banners share the exact same layout.
- PNGs are optimised (lossless), SVGs have no embedded raster and no external fonts.
