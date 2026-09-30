// Attaches a show/hide control to every password input on the page.
// Opt a field out with data-pw-toggle="off".

const EYE_ON = `
<svg class="pw-icon pw-icon-on" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
  <path d="M2.3 12S6.1 5.6 12 5.6 21.7 12 21.7 12 17.9 18.4 12 18.4 2.3 12 2.3 12Z"/>
  <circle cx="12" cy="12" r="3.1"/>
</svg>`;

const EYE_OFF = `
<svg class="pw-icon pw-icon-off" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
  <path d="M9.7 6a10 10 0 0 1 2.3-.4c5.9 0 9.7 6.4 9.7 6.4a17.6 17.6 0 0 1-2.7 3.4"/>
  <path d="M6.4 7.9A17.6 17.6 0 0 0 2.3 12S6.1 18.4 12 18.4a9.7 9.7 0 0 0 3.9-.8"/>
  <path d="M10 10a2.8 2.8 0 0 0 4 4"/>
  <line class="pw-slash" x1="3.6" y1="3.6" x2="20.4" y2="20.4"/>
</svg>`;

function enhance(input) {
    if (!input || input.dataset.pwEnhanced === "1") return;
    if (input.dataset.pwToggle === "off") return;
    input.dataset.pwEnhanced = "1";

    const field = document.createElement("div");
    field.className = "pw-field";

    // Move the input's own top margin onto the wrapper so the button stays
    // optically centred on the input rather than on the wrapper box.
    const marginTop = getComputedStyle(input).marginTop;
    input.parentNode.insertBefore(field, input);
    field.appendChild(input);
    field.style.marginTop = marginTop;
    input.style.marginTop = "0";

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "pw-toggle";
    btn.tabIndex = 0;
    btn.innerHTML = EYE_ON + EYE_OFF;
    setState(btn, false);
    field.appendChild(btn);

    btn.addEventListener("click", () => {
        const reveal = input.type === "password";
        input.type = reveal ? "text" : "password";
        field.classList.toggle("is-revealed", reveal);
        setState(btn, reveal);

        const caret = input.value.length;
        input.focus({ preventScroll: true });
        try {
            input.setSelectionRange(caret, caret);
        } catch (_) {
            /* setSelectionRange is unsupported on some input types */
        }
    });
}

function setState(btn, revealed) {
    const label = revealed ? "Hide password" : "Show password";
    btn.setAttribute("aria-pressed", String(revealed));
    btn.setAttribute("aria-label", label);
    btn.title = label;
}

function init() {
    document.querySelectorAll('input[type="password"]').forEach(enhance);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}

export { enhance as enhancePasswordInput };
