# FAOS BOS — AI Poster Prompts

Ready‑to‑paste prompts for AI image generators (Midjourney, DALL·E 3,
Imagen, SDXL, Recraft, etc.) to produce a marketing poster for FAOS BOS.

> **Workflow tip:** generate the image with these prompts, then overlay the
> *real* headline, your logo, and the live‑demo QR (from
> `public/poster.html`) in Figma / Canva / Keynote — that way the typography
> and brand details stay crisp regardless of how the model renders text.

---

## 🅰️ Hero marketing poster (main pick)

```
Modern minimalist tech poster for an F&B Business Operating System called
"FAOS BOS — AI Central Kitchen + QR Kiosk Sales", vertical A3 portrait,
headline "Run F&B kiosks without owning the POS" set in bold sans-serif,
clean editorial layout, white background with a subtle deep-blue → violet
gradient (#2563eb → #7c3aed) at top and bottom, isometric vignette: a
Malaysian kiosk worker scanning a QR code on a coffee cup with a phone,
floating UI cards behind showing a dashboard, a QR label, an invoice with
SST 6% and a green checkmark, a stylised central-kitchen icon, soft drop
shadows, ample whitespace, geometric accent shapes, premium product
launch aesthetic, sharp vector look, 2:3 aspect ratio, ultra high
resolution, no photographic noise, no extra text artefacts.
```

**Negative prompt:** `blurry text, gibberish letters, watermark, low contrast, busy background, cluttered, photorealistic skin pores`

---

## 🅱️ Tech / architecture style

```
Editorial system-architecture poster for "FAOS BOS", vertical 2:3, dark
navy background (#0f172a) with neon blue and violet accents, isometric
diagram of a central kitchen feeding multiple hypermarket kiosks and
restaurants via stylised arrows, layered nodes for QR Sales → Stock →
Reconciliation → Finance → e-Invoice, glowing connection lines, monospaced
labels, clean grid layout, ultra-modern infographic style à la Stripe /
Linear / Vercel marketing, crisp typography, subtle scanlines, futuristic
but professional, 1123×1587 px.
```

**Negative prompt:** `cartoonish, childish, low resolution, illegible text, oversaturated`

---

## 🅲️ Minimal flat design

```
Flat-design poster, vertical A3, large headline "Run F&B kiosks without
owning the POS" in heavy sans-serif, two-tone palette (deep blue #2563eb
+ off-white #f8fafc), single bold illustration of a stylised QR code
morphing into a coffee cup, small icon row at the bottom (cart, kitchen
pot, chart, invoice, padlock), generous whitespace, Swiss-design influence,
gallery quality.
```

**Negative prompt:** `3D, photorealism, gradients, drop shadows, low contrast`

---

## 🅳️ Storytelling — daily flow

```
Editorial poster, A3 vertical, depicts the day in a Malaysian F&B kiosk:
top half = morning, supplier truck delivering crates to a central kitchen
where chefs prepare drinks; middle = QR-coded cartons distributed to
kiosks inside a hypermarket; bottom = worker scanning QR on phone at a
brightly lit kiosk, customer with coffee, small overlay of a clean
dashboard chart showing growth; warm-cool palette (sunrise gold to deep
blue), soft cinematic light, illustration style (not photo), brand mark
"FAOS BOS" small in the top-left corner, tagline at the bottom "Real-time
QR sales. Delayed reports, handled."
```

**Negative prompt:** `extra fingers, distorted faces, illegible signs, low res`

---

## Per‑tool tweaks

| Tool | Add to the prompt |
|---|---|
| **Midjourney v6+** | `--ar 2:3 --style raw --v 6` |
| **DALL·E 3 (ChatGPT)** | Append `"do not generate any text — I will add it later in a design tool"` to avoid garbled headlines |
| **Stable Diffusion / SDXL** | Use the **negative prompts** above; SDXL needs them more than MJ |
| **Imagen 3 / Recraft** | These render headline text reliably — keep the headline IN the prompt |
| **Adobe Firefly** | Set Content Type = `Graphic`, Style = `Vector look`, Color = `Cool tones` |

## Brand palette (paste into Figma / Canva)

| Role | HEX |
|---|---|
| Primary blue | `#2563eb` |
| Primary deep | `#1e3a8a` |
| Accent violet | `#7c3aed` |
| Dark ink | `#0f172a` |
| Off‑white | `#f8fafc` |
| Soft border | `#cbd5e1` |
| Success | `#16a34a` |
| Warning | `#f59e0b` |
| Danger | `#dc2626` |

## Fonts (free + brand‑safe)

- **Headlines:** Inter Black, Manrope ExtraBold, Geist Bold
- **Body:** Inter Regular, Geist, system‑ui
- **Mono / labels:** JetBrains Mono, Geist Mono

## The story to keep in your head while picking an image

> *"A Malaysian F&B operator runs kiosks inside hypermarkets and a central
> kitchen. They don't own the hypermarket POS — but with FAOS BOS, every
> sale is captured by QR scan in real time, the delayed report just
> reconciles, and SST + LHDN MyInvois are handled. All self‑hosted."*

If the picture matches that sentence, you've picked the right one.

---

*See also: `public/poster.html` (HTML A3 poster, real layout), `public/exec-summary.html` (one‑pager), `docs/DEMO_SCRIPT.md`, `docs/ARCHITECTURE.md`.*
