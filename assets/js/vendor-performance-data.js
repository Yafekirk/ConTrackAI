import { setApiContext, getPerformance } from "/assets/js/api-client.js";
import { applyVendorIdentity } from "/assets/js/vendor-session.js";
import { criteriaStarsHtml, renderStarRating, starRatingFromScore, starRatingHtml } from "/assets/js/star-rating.js";

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

let trendChart = null;

function closeEvaluation() {
  document.getElementById("modal-eval")?.classList.remove("open");
}

function renderTrend(history, selected) {
  const canvas = document.getElementById("trend-chart");
  if (!canvas || !window.Chart) return;
  const points = history.slice(0, 12).reverse();
  const selectedPoint = points.length - 1 - selected;
  trendChart?.destroy();
  trendChart = new window.Chart(canvas, {
    type: "line",
    data: {
      labels: points.map((h) => (h.evaluated_at ? new Date(h.evaluated_at).toLocaleDateString() : "—")),
      datasets: [{
        data: points.map((h) => starRatingFromScore(h.overall_score) ?? 1),
        borderColor: "#7A0C0C",
        backgroundColor: "#7A0C0C",
        tension: 0.4,
        fill: false,
        pointRadius: points.map((_, i) => (i === selectedPoint ? 6 : 3)),
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { min: 1, max: 5, ticks: { color: "#9E9E9E", stepSize: 1 } },
        x: { ticks: { color: "#9E9E9E" } },
      },
    },
  });
}

function openEvaluation(history, index) {
  const row = history[index];
  if (!row) return;
  const set = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  };
  set("eval-modal-date", row.evaluated_at ? new Date(row.evaluated_at).toLocaleString() : "—");
  set("eval-modal-rating", row.rating_label || "—");
  set("eval-modal-evaluator", row.evaluator_name || "—");
  set("eval-modal-remarks", row.remarks || "No remarks.");
  renderStarRating(document.getElementById("eval-modal-score"), row.overall_score, { size: 14, plain: true });
  document.getElementById("modal-eval")?.classList.add("open");
  renderTrend(history, index);
}

document.addEventListener("click", (event) => {
  if (event.target.closest("[data-close-eval]") || event.target.id === "modal-eval") closeEvaluation();
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") closeEvaluation();
});

document.addEventListener("DOMContentLoaded", async () => {
  const user = applyVendorIdentity();
  if (!user?.id) return;
  setApiContext({ role: "vendor", userId: user.id });
  try {
    const perf = await getPerformance();
    const overall = Number(perf.overall_score || 0);
    const has = !!perf.has_manual_score;
    const overallEl = document.getElementById("overall-score");
    if (overallEl) {
      if (has) renderStarRating(overallEl, overall, { size: 15, pill: true });
      else overallEl.innerHTML = `<span class="star-rating star-rating--empty" style="font-size:16px;">Not yet rated</span>`;
    }
    const scaleLabel = document.querySelector(".perf-score-label");
    if (scaleLabel) scaleLabel.style.display = has ? "" : "none";

    const badge = document.querySelector(".perf-score-ring .stat-badge");
    if (badge) {
      if (has) badge.textContent = perf.rating_label || "Scored";
      else badge.style.display = "none";
    }

    const criteriaHost = document.getElementById("criteria-stars");
    if (criteriaHost) {
      const items = Array.isArray(perf.criteria) ? perf.criteria : [];
      criteriaHost.innerHTML = criteriaStarsHtml(
        items.map((item) => ({ ...item, score: has ? item.score : null })),
      );
    }

    const intro = document.getElementById("perf-intro");
    if (intro) {
      intro.textContent = has
        ? `Latest evaluation ${perf.evaluated_at ? "on " + new Date(perf.evaluated_at).toLocaleDateString() : ""} by ${perf.evaluator_name || "staff"}. ${perf.remarks ? "Remarks: " + perf.remarks : ""}`
        : "No manual evaluation has been recorded for your account yet.";
    }

    const history = Array.isArray(perf.history) ? perf.history : [];
    const histHost = document.getElementById("perf-history-body");
    const histFoot = document.querySelector(".table-card .t-showing");
    if (histHost) {
      if (!history.length) {
        histHost.innerHTML = `<div class="empty-state" style="padding:36px 20px;">
          <div class="empty-state-title">No evaluations yet</div>
          <div class="empty-state-desc">Saved evaluations will appear here after staff score your account.</div>
        </div>`;
      } else {
        histHost.innerHTML = history
          .slice(0, 12)
          .map((r, i) => `<div class="t-row" style="grid-template-columns:1.4fr 1fr 1.2fr 1.4fr 90px;">
            <div>${esc(r.evaluated_at ? new Date(r.evaluated_at).toLocaleString() : "—")}</div>
            <div>${starRatingHtml(r.overall_score, { size: 14, plain: true })}</div>
            <div>${esc(r.rating_label || "")}</div>
            <div>${esc(r.evaluator_name || "—")}</div>
            <div><button type="button" class="btn btn-ghost btn-sm" data-view-eval="${i}" title="View remarks and trend">View</button></div>
          </div>`)
          .join("");
        histHost.addEventListener("click", (event) => {
          const btn = event.target.closest("[data-view-eval]");
          if (btn) openEvaluation(history, Number(btn.getAttribute("data-view-eval")));
        });
      }
      if (histFoot) histFoot.textContent = `Showing ${Math.min(12, history.length)} records`;
    }

    const scores = history.map((h) => Number(h.overall_score || 0)).filter((n) => Number.isFinite(n));
    renderStarRating(document.getElementById("perf-low"), scores.length ? Math.min(...scores) : null, { size: 14, plain: true });
    renderStarRating(document.getElementById("perf-current"), has ? overall : null, { size: 14, plain: true });
    renderStarRating(document.getElementById("perf-high"), scores.length ? Math.max(...scores) : null, { size: 14, plain: true });
  } catch (error) {
    console.error(error);
  }
});
