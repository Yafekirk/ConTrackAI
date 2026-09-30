// Terms & Conditions / Privacy Policy modal.
// Agreement is recorded once per account on the backend (users.terms_accepted_at),
// so it is asked for after signing up and at sign in until it is on record.

import { acceptTerms, getTermsStatus } from "/assets/js/api-client.js";

const TERMS = [
  "You must provide accurate and complete information.",
  "You are responsible for maintaining the confidentiality of your account.",
  "You agree not to use the platform for any unlawful or unauthorized purpose.",
  "ConTrack AI reserves the right to suspend or terminate access if these terms are violated.",
  "We may update these terms from time to time without prior notice.",
];

const PRIVACY = [
  "We collect information to provide and improve our services.",
  "We do not sell or share your personal information with third parties except as required by law.",
  "We implement security measures to protect your data from unauthorized access.",
  "You have the right to access, correct, or delete your personal data.",
  "We may update this policy from time to time.",
];

const bullets = (items) => items.map((item) => `<li>${item}</li>`).join("");

let refs = null;
let settle = null;
let lastFocused = null;

function build() {
  if (refs) return refs;

  const overlay = document.createElement("div");
  overlay.className = "terms-overlay";
  overlay.id = "termsOverlay";
  overlay.hidden = true;
  overlay.innerHTML = `
    <div class="terms-modal" role="dialog" aria-modal="true" aria-labelledby="termsTitle">
      <div class="terms-head">
        <h2 id="termsTitle">Terms &amp; Conditions and Privacy Policy</h2>
        <button type="button" class="terms-close" data-terms-close aria-label="Close">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
      </div>

      <div class="terms-body" tabindex="0">
        <p class="terms-intro">
          By accessing and using ConTrack AI, you agree to the following terms and conditions and Privacy Policy.
        </p>

        <div class="terms-section">
          <h3>Terms &amp; Conditions</h3>
          <ul>${bullets(TERMS)}</ul>
        </div>

        <div class="terms-section">
          <h3>Privacy Policy</h3>
          <ul>${bullets(PRIVACY)}</ul>
        </div>
      </div>

      <div class="terms-foot">
        <label class="terms-agree">
          <input type="checkbox" id="termsAgreeCheck">
          <span>I have read, understood, and agree to the <em>Terms &amp; Conditions</em> and <em>Privacy Policy</em>.</span>
        </label>
        <button type="button" class="terms-submit" id="termsAgreeBtn" disabled>Agree &amp; Continue</button>
        <p class="terms-error" id="termsError" hidden></p>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  refs = {
    overlay,
    modal: overlay.querySelector(".terms-modal"),
    check: overlay.querySelector("#termsAgreeCheck"),
    submit: overlay.querySelector("#termsAgreeBtn"),
    error: overlay.querySelector("#termsError"),
  };

  refs.check.addEventListener("change", () => {
    refs.submit.disabled = !refs.check.checked;
    setError("");
  });

  refs.submit.addEventListener("click", submitAgreement);

  overlay.querySelector("[data-terms-close]").addEventListener("click", () => close(false));

  overlay.addEventListener("mousedown", (event) => {
    // Only a read-only preview can be dismissed by clicking the backdrop.
    if (event.target === overlay && refs.modal.classList.contains("is-readonly")) close(false);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !overlay.hidden) close(false);
  });

  return refs;
}

function setError(message) {
  if (!refs) return;
  refs.error.textContent = message;
  refs.error.hidden = !message;
}

async function submitAgreement() {
  // In read-only mode the same button just dismisses the dialog.
  if (refs.modal.classList.contains("is-readonly")) {
    close(false);
    return;
  }
  if (!refs.check.checked) return;

  refs.submit.disabled = true;
  refs.submit.textContent = "Saving…";
  setError("");

  try {
    await acceptTerms();
    close(true);
  } catch (error) {
    setError(error.message || "Could not save your agreement. Please try again.");
    refs.submit.disabled = false;
  } finally {
    refs.submit.textContent = "Agree & Continue";
  }
}

function close(agreed) {
  if (!refs) return;
  refs.overlay.hidden = true;
  document.body.style.overflow = "";
  if (lastFocused && document.contains(lastFocused)) lastFocused.focus({ preventScroll: true });
  lastFocused = null;

  const done = settle;
  settle = null;
  if (done) done(Boolean(agreed));
}

function open({ readonly = false } = {}) {
  const ui = build();
  lastFocused = document.activeElement;

  ui.modal.classList.toggle("is-readonly", readonly);
  ui.check.checked = false;
  ui.submit.disabled = !readonly;
  ui.submit.textContent = readonly ? "Close" : "Agree & Continue";
  setError("");
  ui.overlay.hidden = false;
  document.body.style.overflow = "hidden";
  ui.overlay.querySelector(".terms-body").scrollTop = 0;
  (readonly ? ui.submit : ui.check).focus({ preventScroll: true });

  return new Promise((resolve) => {
    settle = resolve;
  });
}

/** Asks for agreement and records it. Resolves false if the user closed the dialog instead. */
export function requestTermsAgreement() {
  return open({ readonly: false });
}

/** Read-only preview, e.g. from the "Terms & Conditions" link on the signup form. */
export function showTerms() {
  return open({ readonly: true });
}

/**
 * Resolves true when the signed-in account has already agreed, otherwise asks.
 * A failed status read resolves true so a backend hiccup cannot lock anyone out.
 */
export async function ensureTermsAccepted() {
  let status = null;
  try {
    status = await getTermsStatus();
  } catch (error) {
    return true;
  }
  if (status?.accepted) return true;
  return requestTermsAgreement();
}

function findTermsGate(el) {
  const gateId = el.getAttribute("data-terms-gated-by");
  if (gateId) {
    return document.getElementById(gateId);
  }

  const group = el.closest("label, .terms, .terms-consent-text");
  return group?.querySelector("input[type='checkbox']") || document.getElementById("signupTerms");
}

/** Wires every [data-terms-open] element on the page to the read-only preview. */
export function initTermsLinks(root = document) {
  root.querySelectorAll("[data-terms-open]").forEach((el) => {
    el.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();

      // Signup consent: if the box is already checked, skip the popup.
      const gate = findTermsGate(el);
      if (gate && gate.checked) return;

      showTerms();
    });
  });
}
