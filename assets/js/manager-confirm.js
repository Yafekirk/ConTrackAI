/** Styled yes/no card used by the manager review actions. */

const ICON = `
<svg viewBox="0 0 24 24" aria-hidden="true">
  <path d="M12 20h9"/>
  <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/>
</svg>`;

let ui = null;
let settle = null;

function build() {
  if (ui) return ui;
  const overlay = document.createElement("div");
  overlay.className = "mgr-confirm-overlay";
  overlay.hidden = true;
  overlay.innerHTML = `
    <div class="mgr-confirm-card" role="dialog" aria-modal="true" aria-labelledby="mgrConfirmTitle">
      <div class="mgr-confirm-body">
        <div class="mgr-confirm-icon">${ICON}</div>
        <h3 id="mgrConfirmTitle"></h3>
        <p id="mgrConfirmMessage"></p>
      </div>
      <div class="mgr-confirm-actions">
        <button type="button" class="btn btn-ghost" id="mgrConfirmCancel">Cancel</button>
        <button type="button" class="mgr-confirm-send" id="mgrConfirmYes">Send to vendor</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  ui = {
    overlay,
    title: overlay.querySelector("#mgrConfirmTitle"),
    message: overlay.querySelector("#mgrConfirmMessage"),
    cancel: overlay.querySelector("#mgrConfirmCancel"),
    yes: overlay.querySelector("#mgrConfirmYes"),
  };

  const finish = (accepted) => {
    ui.overlay.hidden = true;
    const done = settle;
    settle = null;
    if (done) done(accepted);
  };
  ui.cancel.addEventListener("click", () => finish(false));
  ui.yes.addEventListener("click", () => finish(true));
  overlay.addEventListener("mousedown", (event) => {
    if (event.target === overlay) finish(false);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !ui.overlay.hidden) finish(false);
  });
  return ui;
}

/**
 * @param {{ title: string, message: string, confirmLabel?: string }} options
 * @returns {Promise<boolean>}
 */
export function askManagerConfirm({ title, message, confirmLabel = "Confirm" }) {
  const dialog = build();
  if (settle) settle(false);
  dialog.title.textContent = title;
  dialog.message.innerHTML = message;
  dialog.yes.textContent = confirmLabel;
  dialog.overlay.hidden = false;
  dialog.yes.focus({ preventScroll: true });
  return new Promise((resolve) => {
    settle = resolve;
  });
}
