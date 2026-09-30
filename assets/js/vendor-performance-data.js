import { setApiContext, getPerformance } from "/assets/js/api-client.js";
import { applyVendorIdentity } from "/assets/js/vendor-session.js";
import { criteriaStarsHtml, renderStarRating, starRatingFromScore, starRatingHtml } from "/assets/js/star-rating.js";

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

document.addEventListener("DOMContentLoaded", async () => {
  const user = applyVendorIdentity();
  if (!user?.id) return;
  setApiContext({ role: "vendor", userId: user.id });
  try {
    const perf = await getPerformance();
    const overall = Number(perf.overall_score || 0);
    const has = !!perf.has_manual_score;
    const overallEl = document.getElementById("overall-score");
    renderStarRating(overallEl, has ? overall : null, { size: 15, pill: true });
    const scaleLabel = document.querySelector(".perf-score-label");
    if (scaleLabel) scaleLabel.textContent = has ? "OUT OF 5" : "";

    const badge = document.querySelector(".perf-score-ring .stat-badge");
    if (badge) badge.textContent = has ? perf.rating_label || "Scored" : "Not scored yet";

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

    const recWrap = document.getElementById("perf-notes");
    if (recWrap) {
      recWrap.innerHTML = has
        ? `<div style="font-size:13px;line-height:1.6;">Rating: <strong>${esc(perf.rating_label || "")}</strong><br>${esc(perf.remarks || "No remarks.")}</div>`
        : `<div class="empty-state-desc">Evaluations recorded by managers, the CEO, or an admin appear here.</div>`;
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
          .map((r) => `<div class="t-row" style="grid-template-columns:1.4fr 1fr 1.2fr 1.4fr 2fr;">
            <div>${esc(r.evaluated_at ? new Date(r.evaluated_at).toLocaleString() : "—")}</div>
            <div>${starRatingHtml(r.overall_score, { size: 14, plain: true })}</div>
            <div>${esc(r.rating_label || "")}</div>
            <div>${esc(r.evaluator_name || "—")}</div>
            <div class="t-cell-meta">${esc(r.remarks || "")}</div>
          </div>`)
          .join("");
      }
      if (histFoot) histFoot.textContent = `Showing ${Math.min(12, history.length)} records`;
    }

    const scores = history.map((h) => Number(h.overall_score || 0)).filter((n) => Number.isFinite(n));
    renderStarRating(document.getElementById("perf-low"), scores.length ? Math.min(...scores) : null, { size: 14, plain: true });
    renderStarRating(document.getElementById("perf-current"), has ? overall : null, { size: 14, plain: true });
    renderStarRating(document.getElementById("perf-high"), scores.length ? Math.max(...scores) : null, { size: 14, plain: true });

    const ringCtx = document.getElementById("score-ring-chart");
    if (ringCtx && window.Chart) {
      new window.Chart(ringCtx, {
        type: "doughnut",
        data: {
          datasets: [
            {
              data: [has ? starRatingFromScore(overall) : 0, has ? Math.max(0, 5 - starRatingFromScore(overall)) : 5],
              backgroundColor: ["#7A0C0C", "#F0F0F0"],
              borderWidth: 0,
            },
          ],
        },
        options: {
          cutout: "78%",
          responsive: false,
          plugins: { legend: { display: false }, tooltip: { enabled: false } },
        },
      });
    }

    const trendCtx = document.getElementById("trend-chart");
    if (trendCtx && window.Chart) {
      const labels = history
        .slice()
        .reverse()
        .slice(-6)
        .map((h) => (h.evaluated_at ? new Date(h.evaluated_at).toLocaleDateString() : "—"));
      const data = history.slice().reverse().slice(-6).map((h) => starRatingFromScore(h.overall_score) ?? 1);
      if (!labels.length) {
        trendCtx.parentElement?.insertAdjacentHTML(
          "beforeend",
          `<div style="padding:40px 10px;text-align:center;font-size:12px;color:var(--gray-400);">No evaluation history yet.</div>`,
        );
        trendCtx.style.display = "none";
      } else {
        new window.Chart(trendCtx, {
          type: "line",
          data: {
            labels,
            datasets: [{ data, borderColor: "#7A0C0C", tension: 0.4, fill: false }],
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
    }
  } catch (error) {
    console.error(error);
  }
});
