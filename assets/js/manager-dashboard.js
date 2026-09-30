import {

  setApiContext,

  getDashboardStats,

  getManagerPending,

  getManagerMine,

  getVendorSummary,

} from "/assets/js/api-client.js";
import { contractRef } from "/assets/js/contract-intel.js";
import { renderStarRating, starRatingHtml } from "/assets/js/star-rating.js";



function formatPeso(v) {

  return new Intl.NumberFormat("en-PH", {

    style: "currency",

    currency: "PHP",

    maximumFractionDigits: 0,

  }).format(Number(v) || 0);

}



function esc(s) {

  return String(s ?? "")

    .replace(/&/g, "&amp;")

    .replace(/</g, "&lt;")

    .replace(/>/g, "&gt;");

}



document.addEventListener("DOMContentLoaded", async () => {

  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");

  setApiContext({ role: saved.role || "manager", userId: saved.id || null });



  try {

    const settled = await Promise.allSettled([

      getDashboardStats("manager"),

      getManagerPending(),

      getManagerMine(),

      getVendorSummary(),

    ]);

    const stats = settled[0].status === "fulfilled" ? settled[0].value : {};

    const pending = settled[1].status === "fulfilled" ? settled[1].value : [];

    const mine = settled[2].status === "fulfilled" ? settled[2].value : [];

    const vendorSummary =
      settled[3].status === "fulfilled" && Array.isArray(settled[3].value) ? settled[3].value : [];

    // "AVG VENDOR SCORE" is the mean of latest stored evaluations.
    const vendorScores = vendorSummary
      .map((v) => (v?.has_manual_score ? Number(v.vendor_score ?? v.ai_score || 0) : 0))
      .filter((n) => n > 0);
    const avgVendorScore = vendorScores.length
      ? Math.round(vendorScores.reduce((a, b) => a + b, 0) / vendorScores.length)
      : null;



    settled.forEach((item, idx) => {

      if (item.status === "rejected") {

        console.error(`Manager dashboard load #${idx} failed`, item.reason);

      }

    });



    const pend = Array.isArray(pending) ? pending : [];

    const reviewed = Array.isArray(mine) ? mine : [];

    const awaitingCeo = reviewed.filter((r) => String(r.status).toLowerCase() === "pending");



    const values = document.querySelectorAll(".stat-icon-value");

    if (values[0]) values[0].textContent = String(pend.length);

    if (values[1]) values[1].textContent = String(awaitingCeo.length);

    if (values[2]) renderStarRating(values[2], avgVendorScore, { size: 18, pill: true });

    if (values[3]) values[3].textContent = String(reviewed.length);



    const table = document.getElementById("review-table-body");

    const meta = document.querySelector("#review-table-body")?.closest(".table-card")?.querySelector(".t-showing");

    if (meta) meta.textContent = `${pend.length} in queue`;

    if (table) {

      if (pend.length === 0) {

        table.innerHTML = `<div style="padding:48px 20px;text-align:center;">

          <div style="font-size:13px;font-weight:600;color:var(--gray-500);">No contracts pending</div>

          <div style="font-size:12px;color:var(--gray-400);">Vendor submissions appear here after Submit Contract.</div>

        </div>`;

      } else {

        table.innerHTML = pend

          .slice(0, 8)

          .map(

            (r) => `

          <div class="t-row" style="grid-template-columns:2.2fr 1.4fr 1fr 1fr 1.2fr 1fr;">

            <div><div class="t-cell-primary">${esc(r.contract_title)}</div><div class="t-cell-meta">${esc(contractRef(r))}</div></div>

            <div>${formatPeso(r.contract_value)}</div>

            <div>${(() => {
              const scored = vendorSummary.find((v) => String(v.vendor_id) === String(r.vendor_id) && v.has_manual_score);
              return scored ? starRatingHtml(scored.vendor_score ?? scored.ai_score, { size: 14 }) : "—";
            })()}</div>

            <div>${esc(r.vendor_name || "—")}</div>

            <div>${r.uploaded_at ? new Date(r.uploaded_at).toLocaleDateString() : "—"}</div>

            <div><a class="btn btn-primary btn-sm" href="/pages/Manager/ManagerSubmissions.html">Open</a></div>

          </div>`,

          )

          .join("");

      }

    }



    const ceoBody = document.getElementById("submissions-table-body");

    const ceoMeta = document.querySelector("#submissions-table-body")?.closest(".table-card")?.querySelector(".t-showing");

    if (ceoMeta) ceoMeta.textContent = `${awaitingCeo.length} awaiting CEO`;

    if (ceoBody) {

      if (awaitingCeo.length === 0) {

        ceoBody.innerHTML = `<div style="padding:48px 20px;text-align:center;">

          <div style="font-size:13px;font-weight:600;color:var(--gray-500);margin-bottom:4px;">No submissions yet</div>

          <div style="font-size:12px;color:var(--gray-400);">Contracts you submit to CEO will appear here</div>

        </div>`;

      } else {

        ceoBody.innerHTML = awaitingCeo

          .slice(0, 8)

          .map(

            (r) => `

          <div class="t-row" style="grid-template-columns:2.2fr 1.4fr 1.2fr 1.2fr 1fr;">

            <div><div class="t-cell-primary">${esc(r.contract_title)}</div><div class="t-cell-meta">${esc(contractRef(r))}</div></div>

            <div>${formatPeso(r.contract_value)}</div>

            <div>${r.manager_reviewed_at ? new Date(r.manager_reviewed_at).toLocaleDateString() : "—"}</div>

            <div><span class="pill pill-pending">PENDING CEO</span></div>

            <div><a class="btn btn-ghost btn-sm" href="/pages/Manager/ManagerSubmissions.html">View</a></div>

          </div>`,

          )

          .join("");

      }

    }



    const approved = stats?.approved ?? 0;

    const pendCount = stats?.pending ?? pend.length;

    const rej = stats?.rejected ?? 0;



    if (window.Chart) {

      const rt = document.getElementById("review-trend-chart");

      if (rt) {

        new window.Chart(rt, {

          type: "bar",

          data: {

            labels: ["Approved", "Pending", "Rejected"],

            datasets: [

              {

                label: "Contracts",

                data: [approved, pendCount, rej],

                backgroundColor: "rgba(113,26,32,.7)",

                borderRadius: 4,

              },

            ],

          },

          options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: { legend: { display: false } },

            scales: {

              y: {

                grid: { color: "rgba(0,0,0,.04)" },

                ticks: { color: "#9E9E9E", font: { size: 11 } },

              },

              x: {

                grid: { display: false },

                ticks: { color: "#9E9E9E", font: { size: 11 } },

              },

            },

          },

        });

      }



      const sd = document.getElementById("score-dist-chart");

      if (sd) {

        const hi = pend.filter((r) => Number(r.contract_value) >= 5000000).length;

        const med = pend.filter(

          (r) => Number(r.contract_value) >= 1000000 && Number(r.contract_value) < 5000000,

        ).length;

        const low = pend.filter((r) => Number(r.contract_value) < 1000000).length;

        new window.Chart(sd, {

          type: "doughnut",

          data: {

            labels: ["High value", "Medium", "Lower"],

            datasets: [

              {

                data: [hi || 0, med || 0, low || 0],

                backgroundColor: ["#2D7135", "#E67E22", "#C53336"],

                borderWidth: 0,

              },

            ],

          },

          options: {

            responsive: true,

            maintainAspectRatio: false,

            cutout: "68%",

            plugins: {

              legend: {

                position: "bottom",

                labels: { font: { size: 11 }, padding: 14, color: "#555" },

              },

            },

          },

        });

      }

    }

  } catch (e) {

    console.error(e);

  }

});

