const STORAGE_KEY = "contrack_login_guard";
const FORGOT_PATH = "/ForgotPassword.html";

function readClientFlag() {
  try {
    return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || "{}");
  } catch (error) {
    return {};
  }
}

function writeClientFlag(patch) {
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ ...readClientFlag(), ...patch }));
}

function clearClientFlag() {
  sessionStorage.removeItem(STORAGE_KEY);
}

export async function fetchLoginGuard() {
  try {
    const response = await fetch("/api/auth.php", { method: "GET" });
    return await response.json();
  } catch (error) {
    return { fails: 0, locked: false, retry_after: 0, forgot: false };
  }
}

function ensureOverlays() {
  if (document.getElementById("loginLockOverlay")) return;

  const lock = document.createElement("div");
  lock.id = "loginLockOverlay";
  lock.className = "login-guard-overlay";
  lock.hidden = true;
  lock.innerHTML = `
    <div class="login-guard-dialog" role="dialog" aria-modal="true" aria-labelledby="loginLockTitle">
      <h3 id="loginLockTitle">Please wait</h3>
      <p>Try again in <span id="loginLockSeconds">60</span>s</p>
    </div>
  `;

  const forgot = document.createElement("div");
  forgot.id = "forgotPrompt";
  forgot.className = "login-guard-overlay";
  forgot.hidden = true;
  forgot.innerHTML = `
    <div class="login-guard-dialog" role="dialog" aria-modal="true" aria-labelledby="forgotPromptTitle">
      <h3 id="forgotPromptTitle">Forgot your password?</h3>
      <div class="login-guard-actions">
        <button type="button" id="forgotPromptYes" class="login-guard-btn login-guard-btn-primary">Yes</button>
        <button type="button" id="forgotPromptNo" class="login-guard-btn">No</button>
      </div>
    </div>
  `;

  document.body.append(lock, forgot);
}

function setFormEnabled(form, enabled) {
  if (!form) return;
  form.querySelectorAll("input, button, select").forEach((el) => {
    el.disabled = !enabled;
  });
}

function hideForgotPrompt() {
  const overlay = document.getElementById("forgotPrompt");
  if (overlay) overlay.hidden = true;
}

function showForgotPrompt() {
  const overlay = document.getElementById("forgotPrompt");
  const yesBtn = document.getElementById("forgotPromptYes");
  const noBtn = document.getElementById("forgotPromptNo");
  if (!overlay || !yesBtn || !noBtn) return;

  overlay.hidden = false;
  yesBtn.onclick = () => {
    writeClientFlag({ showForgot: false, forgotDismissed: true });
    window.location.href = FORGOT_PATH;
  };
  noBtn.onclick = () => {
    writeClientFlag({ showForgot: false, forgotDismissed: true });
    hideForgotPrompt();
  };
}

function showLockOverlay(retryAfter, form) {
  const overlay = document.getElementById("loginLockOverlay");
  const secondsEl = document.getElementById("loginLockSeconds");
  if (!overlay || !secondsEl) return;

  hideForgotPrompt();
  overlay.hidden = false;
  setFormEnabled(form, false);

  let remaining = Math.max(1, Math.ceil(retryAfter));
  secondsEl.textContent = String(remaining);

  const tick = setInterval(() => {
    remaining -= 1;
    if (remaining <= 0) {
      clearInterval(tick);
      overlay.hidden = true;
      setFormEnabled(form, true);
      return;
    }
    secondsEl.textContent = String(remaining);
  }, 1000);
}

export function restartLoginPage() {
  window.location.reload();
}

export function handleAuthFailure(payload) {
  if (payload?.locked) {
    writeClientFlag({ showForgot: false });
  } else if (payload?.forgot) {
    writeClientFlag({ showForgot: true, forgotDismissed: false });
  }
  restartLoginPage();
}

export async function initLoginGuard(form) {
  ensureOverlays();
  const status = await fetchLoginGuard();

  if (status.locked && status.retry_after > 0) {
    showLockOverlay(status.retry_after, form);
    return status;
  }

  const flags = readClientFlag();
  if ((flags.showForgot || status.forgot) && !flags.forgotDismissed) {
    showForgotPrompt();
  }
  return status;
}

export function resetLoginGuard() {
  clearClientFlag();
}
