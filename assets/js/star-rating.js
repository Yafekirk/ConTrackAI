/**
 * Vendor star rating from a 0–100 score.
 * Stars = 1 + 4 × (score ÷ 100), shown to one decimal (85 → 4.4).
 * The same formula is vendor_star_rating() in api/lib_vendor_score.php.
 */

const STAR_PATH = "M12 2.2 14.7 8.3 21.3 8.9 16.4 13.3 17.9 19.8 12 16.4 6.1 19.8 7.6 13.3 2.7 8.9 9.3 8.3Z";
const STAR_SHINE = "M12 3.4 14.1 8 9.9 8Z";

function ensureStarStyles() {
  if (!document.getElementById("ct-star-defs")) {
    const holder = document.createElement("div");
    holder.innerHTML = `<svg id="ct-star-defs" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden"><defs>
        <linearGradient id="ct-star-face" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#fff6e4"/><stop offset="22%" stop-color="#F4C396"/><stop offset="58%" stop-color="#991B1B"/><stop offset="100%" stop-color="#5a1218"/></linearGradient>
        <linearGradient id="ct-star-empty" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#fff8f8"/><stop offset="100%" stop-color="#e4bcc1"/></linearGradient>
        <linearGradient id="ct-star-side" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#7A0C0C"/><stop offset="100%" stop-color="#5a1218"/></linearGradient>
      </defs></svg>`;
    document.body.appendChild(holder.firstElementChild);
  }
  if (document.getElementById("star-rating-style")) return;
  const style = document.createElement("style");
  style.id = "star-rating-style";
  style.textContent = `
.star-rating{display:inline-flex;align-items:center;gap:8px;line-height:1;vertical-align:middle;white-space:nowrap;}
.star-rating-icons{display:inline-flex;align-items:center;gap:4px;}
.star-rating-num{font-family:"Playfair Display",serif;font-weight:700;color:#7A0C0C;letter-spacing:0.02em;}
.star-rating--pill{padding:6px 12px 6px 8px;border-radius:999px;background:linear-gradient(180deg,#fff 0%,#FFF1F3 100%);border:1px solid #f0c4c8;box-shadow:inset 0 1px 0 #fff,0 6px 14px rgba(122,12,12,.08);}
.star-rating--empty{color:#8a8082;font-size:13px;font-weight:600;}
.star-gem{position:relative;display:inline-block;flex:0 0 auto;filter:drop-shadow(0 1px 0 rgba(74,10,16,.35)) drop-shadow(0 4px 4px rgba(122,12,12,.18));}
.star-gem-svg{display:block;width:100%;height:100%;overflow:visible;}
.star-gem-lit{position:absolute;left:0;top:0;height:100%;width:0;overflow:hidden;pointer-events:none;}
.star-gem-lit .star-gem-svg{position:absolute;left:0;top:0;width:var(--gem,36px);height:var(--gem,36px);}
.star-pick{display:flex;align-items:center;gap:16px;margin-top:10px;padding:10px 14px;border-radius:16px;background:linear-gradient(180deg,#fff 0%,#fbf3f3 100%);border:1px solid #f0d4d7;box-shadow:inset 0 1px 0 #fff,0 10px 22px rgba(122,12,12,.07);}
.star-pick-row{display:inline-flex;align-items:center;gap:8px;perspective:520px;}
.star-pick-star{position:relative;display:inline-block;transform-style:preserve-3d;}
.star-pick-star .star-gem{transition:transform .16s ease,filter .16s ease;}
.star-pick-star:hover .star-gem,.star-pick-star:focus-within .star-gem{transform:translateY(-5px) rotateX(16deg) scale(1.1);filter:drop-shadow(0 2px 0 rgba(74,10,16,.4)) drop-shadow(0 10px 8px rgba(122,12,12,.28));}
.star-pick-hit{position:absolute;inset:0;z-index:2;width:100%;height:100%;padding:0;border:0;background:transparent;cursor:pointer;}
.star-pick-hit:focus-visible{outline:2px solid #F4C396;outline-offset:3px;border-radius:6px;}
.star-pick-value{margin-left:auto;min-width:58px;text-align:center;padding:8px 10px;border-radius:12px;background:linear-gradient(180deg,#fff,#FFF1F3);border:1px solid #f0c4c8;box-shadow:inset 0 1px 0 #fff,0 3px 0 #e7c5c8;font-family:"Playfair Display",serif;font-size:18px;font-weight:700;color:#7A0C0C;}
.criterion-star-list{display:flex;flex-direction:column;gap:4px;}
.criterion-star-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;background:linear-gradient(180deg,#fff,#fbf4f4);border:1px solid #f3e0e2;}
.criterion-star-name{font-size:13px;font-weight:600;color:#3d3336;}
`;
  document.head.appendChild(style);
}

function gemStarHtml(size) {
  const px = Math.max(12, Number(size) || 16);
  return `<span class="star-gem" style="width:${px}px;height:${px}px;--gem:${px}px">
    <svg class="star-gem-svg" viewBox="0 0 24 24" aria-hidden="true">
      <path transform="translate(0 1.35)" fill="url(#ct-star-side)" d="${STAR_PATH}"/>
      <path fill="url(#ct-star-empty)" stroke="#d7a3a8" stroke-width="0.55" stroke-linejoin="round" d="${STAR_PATH}"/>
    </svg>
    <span class="star-gem-lit">
      <svg class="star-gem-svg" viewBox="0 0 24 24" aria-hidden="true">
        <path transform="translate(0 1.35)" fill="url(#ct-star-side)" d="${STAR_PATH}"/>
        <path fill="url(#ct-star-face)" d="${STAR_PATH}"/>
        <path fill="#fff" opacity="0.7" d="${STAR_SHINE}"/>
      </svg>
    </span>
  </span>`;
}

export function starRatingFromScore(score) {
  const n = Number(score);
  if (!Number.isFinite(n)) return null;
  const clamped = Math.min(100, Math.max(0, n));
  return Math.round((1 + 4 * (clamped / 100)) * 10) / 10;
}

export function starRatingHtml(score, { size = 16, pill = false } = {}) {
  ensureStarStyles();
  const stars = starRatingFromScore(score);
  if (stars == null) return `<span class="star-rating star-rating--empty">—</span>`;
  const label = stars.toFixed(1);
  const px = Math.max(12, Number(size) || 16);
  const icons = [0, 1, 2, 3, 4]
    .map((i) => {
      const fill = Math.min(1, Math.max(0, stars - i));
      const pct = Math.round(fill * 1000) / 10;
      return gemStarHtml(px).replace('class="star-gem-lit"', `class="star-gem-lit" style="width:${pct}%"`);
    })
    .join("");
  const tone = pill ? " star-rating--pill" : "";
  return `<span class="star-rating${tone}" role="img" aria-label="${label} out of 5 stars"><span class="star-rating-icons">${icons}</span><span class="star-rating-num" style="font-size:${Math.round(px * 0.95)}px">${label}</span></span>`;
}

export function renderStarRating(el, score, options) {
  if (!el) return;
  el.innerHTML = starRatingHtml(score, options);
}

/** Inverse of the star formula. 1 star → 0, 5 stars → 100. */
export function scoreFromStars(stars) {
  const s = Math.min(5, Math.max(1, Number(stars)));
  if (!Number.isFinite(s)) return null;
  return Math.round(((s - 1) / 4) * 10000) / 100;
}

function paintStarPick(pick, stars) {
  pick.querySelectorAll(".star-pick-star").forEach((star) => {
    const index = Number(star.getAttribute("data-star"));
    const fill = stars == null ? 0 : Math.min(1, Math.max(0, stars - (index - 1)));
    const layer = star.querySelector(".star-gem-lit");
    if (layer) layer.style.width = `${Math.round(fill * 1000) / 10}%`;
  });
  const readout = pick.querySelector(".star-pick-value");
  if (readout) readout.textContent = stars == null ? "—" : Number(stars).toFixed(1);
}

export function starPickerHtml(id) {
  ensureStarStyles();
  const stars = [1, 2, 3, 4, 5]
    .map(
      (n) => `<span class="star-pick-star" data-star="${n}">
        ${gemStarHtml(40)}
        <button type="button" class="star-pick-hit" data-value="${n}" aria-label="${n} stars"></button>
      </span>`,
    )
    .join("");
  return `<div class="star-pick" data-criterion="${id}">
    <input type="hidden" class="score-crit-input" id="crit-${id}" value="">
    <div class="star-pick-row" role="group" aria-label="Star rating">${stars}</div>
    <span class="star-pick-value">—</span>
  </div>`;
}

export function bindStarPicker(pick, onChange) {
  if (!pick || pick.dataset.bound === "1") return;
  pick.dataset.bound = "1";
  const input = pick.querySelector("input");
  const apply = (stars, commit) => {
    paintStarPick(pick, stars);
    if (commit && input) {
      const score = scoreFromStars(stars);
      input.value = score == null ? "" : String(score);
      onChange?.();
    }
  };
  pick.querySelectorAll(".star-pick-hit").forEach((btn) => {
    btn.addEventListener("mouseenter", () => apply(Number(btn.getAttribute("data-value")), false));
    btn.addEventListener("focus", () => apply(Number(btn.getAttribute("data-value")), false));
    btn.addEventListener("click", () => apply(Number(btn.getAttribute("data-value")), true));
  });
  pick.addEventListener("mouseleave", () => syncStarPicker(pick));
  pick.addEventListener("focusout", (event) => {
    if (!pick.contains(event.relatedTarget)) syncStarPicker(pick);
  });
  syncStarPicker(pick);
}

export function criteriaStarsHtml(items) {
  ensureStarStyles();
  const rows = Array.isArray(items) ? items : [];
  if (!rows.length) {
    return `<p class="star-rating--empty">No criteria have been scored yet.</p>`;
  }
  return `<div class="criterion-star-list">${rows
    .map((item) => {
      const name = String(item?.name || item?.code || "Criterion")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
      const score = item?.score;
      const stars = score == null || score === "" ? `<span class="star-rating--empty">—</span>` : starRatingHtml(score, { size: 15 });
      return `<div class="criterion-star-row"><span class="criterion-star-name">${name}</span>${stars}</div>`;
    })
    .join("")}</div>`;
}

export function syncStarPicker(pick) {
  if (!pick) return;
  const input = pick.querySelector("input");
  const raw = input?.value;
  paintStarPick(pick, raw === "" || raw == null ? null : starRatingFromScore(raw));
}
