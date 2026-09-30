/**
 * Shared profile wiring for the Manager, CEO, and Vendor profile pages.
 *
 * Only the fields that exist as columns on `users` are persisted: name and contact_number
 * (email is read-only, changed by an admin). Buttons for cards with no backing storage are
 * wired to say so instead of showing a fake success toast.
 *
 * Expects these ids on the page (any may be absent):
 *   inp-name or inp-fname [+ inp-lname]  — name fields
 *   inp-email, inp-contact               — email (read-only) and contact number
 *   btn-save-profile                     — saves name + contact number
 *   pw-current, pw-new, pw-confirm       — password fields
 *   btn-update-password                  — changes the password
 *   btn-not-stored (any number)          — controls with no backing storage
 *   profile-status                       — optional inline status line
 *   set-email, set-role, set-seen        — read-only account lines on Settings
 *   set-alerts                           — in-app alert toggle (users.notify_alerts)
 *   set-signout                          — ends this browser session
 */
import {
  setApiContext,
  getMyProfile,
  updateMyProfile,
  changePassword,
} from "/assets/js/api-client.js";

function setStatus(message, kind = "info") {
  const el = document.getElementById("profile-status");
  if (el) {
    el.textContent = message;
    el.dataset.kind = kind;
    el.style.color = kind === "error" ? "#b42318" : kind === "success" ? "#027a48" : "#475467";
    return;
  }
  alert(message);
}

function value(id) {
  return document.getElementById(id)?.value?.trim() || "";
}

async function prefill() {
  let me = null;
  try {
    me = await getMyProfile();
  } catch (_) {
    return;
  }
  if (!me || typeof me !== "object") return;

  const assign = (id, val) => {
    const el = document.getElementById(id);
    if (el && val != null) el.value = String(val);
  };
  assign("inp-email", me.email);
  assign("inp-contact", me.contact_number);

  const company = String(me.company_name || "").trim();
  const full = company || String(me.name || "").trim();
  if (document.getElementById("inp-name")) {
    assign("inp-name", full);
  } else if (document.getElementById("inp-lname")) {
    // Split pages store one `name` column: first word is the given name, rest the surname.
    const parts = full.split(/\s+/);
    assign("inp-fname", parts.shift() || "");
    assign("inp-lname", parts.join(" "));
  } else {
    assign("inp-fname", full);
  }

  const emailInput = document.getElementById("inp-email");
  if (emailInput) {
    emailInput.readOnly = true;
    emailInput.title = "Email is your sign-in name; an administrator can change it.";
  }

  const text = (id, val) => {
    const el = document.getElementById(id);
    if (el && val) el.textContent = val;
  };
  text("disp-name", company || me.name);
  text("disp-email", me.email);
  text("disp-last-login", me.last_login_at ? new Date(me.last_login_at).toLocaleString() : "—");
  text("disp-since", me.created_at ? new Date(me.created_at).toLocaleDateString() : "—");

  const setEmail = document.getElementById("set-email");
  if (setEmail && me.email) setEmail.textContent = me.email;
  const setRole = document.getElementById("set-role");
  if (setRole) setRole.textContent = roleLabel(me.role);
  const setSeen = document.getElementById("set-seen");
  if (setSeen) {
    setSeen.textContent = me.last_login_at
      ? `Last sign-in ${new Date(me.last_login_at).toLocaleString()}`
      : "Last sign-in —";
  }
  const setCopy = document.getElementById("set-alert-copy");
  if (setCopy) setCopy.textContent = alertCopy(me.role);
  const setAlerts = document.getElementById("set-alerts");
  if (setAlerts) setAlerts.checked = me.notify_alerts !== false;

  const supplier = document.getElementById("disp-supplier");
  if (supplier) {
    if (me.supplier_type) {
      supplier.hidden = false;
      supplier.textContent = ` · ${me.supplier_type}`;
    } else {
      supplier.hidden = true;
    }
  }

  // Keep the cached copy used for sidebar/header labels in step with the server.
  try {
    const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
    localStorage.setItem(
      "contrack_user",
      JSON.stringify({
        ...saved,
        id: me.id,
        name: company || me.name,
        company_name: company,
        email: me.email,
        role: me.role,
      }),
    );
  } catch (_) {
    /* non-fatal */
  }
}

function wireSaveProfile() {
  const btn = document.getElementById("btn-save-profile");
  if (!btn) return;
  btn.addEventListener("click", async () => {
    const payload = {};
    let role = "";
    try {
      role = JSON.parse(localStorage.getItem("contrack_user") || "{}").role || "";
    } catch (_) {
      role = "";
    }
    if (document.getElementById("inp-name")) {
      const entered = value("inp-name");
      if (role === "vendor" || role === "client") payload.company_name = entered;
      else payload.name = entered;
    } else if (document.getElementById("inp-fname")) {
      payload.name = [value("inp-fname"), value("inp-lname")].filter(Boolean).join(" ");
    }
    if (document.getElementById("inp-contact")) payload.contact_number = value("inp-contact");
    if (Object.keys(payload).length === 0) return;

    btn.disabled = true;
    setStatus("Saving…");
    try {
      await updateMyProfile(payload);
      setStatus(role === "vendor" || role === "client" ? "Company name and contact number saved." : "Name and contact number saved.", "success");
      await prefill();
    } catch (e) {
      setStatus(e.message || "Could not save your profile.", "error");
    } finally {
      btn.disabled = false;
    }
  });
}

function wirePassword() {
  const btn = document.getElementById("btn-update-password");
  if (!btn) return;
  btn.addEventListener("click", async () => {
    const current = value("pw-current");
    const next = value("pw-new");
    const confirm = value("pw-confirm");

    if (!current || !next) {
      setStatus("Enter your current password and a new password.", "error");
      return;
    }
    if (next !== confirm) {
      setStatus("The new passwords do not match.", "error");
      return;
    }

    btn.disabled = true;
    setStatus("Updating password…");
    try {
      await changePassword({ current_password: current, new_password: next });
      setStatus("Password updated.", "success");
      ["pw-current", "pw-new", "pw-confirm"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = "";
      });
    } catch (e) {
      setStatus(e.message || "Could not update your password.", "error");
    } finally {
      btn.disabled = false;
    }
  });
}

function currentUserId() {
  try {
    return JSON.parse(localStorage.getItem("contrack_user") || "{}").id || null;
  } catch (_) {
    return null;
  }
}

function paintStoredPhoto(src) {
  document.querySelectorAll("[data-shell-initials], .sb-av, .profile-avatar, .profile-big-av, .top-user-avatar").forEach((el) => {
    if (src) {
      el.classList.add("has-photo");
      el.style.backgroundImage = `url("${src}")`;
      el.textContent = "";
    }
  });
}

function wirePhoto() {
  const button = document.getElementById("acct-photo-btn");
  const input = document.getElementById("acct-photo-input");
  if (!button || !input) return;

  button.addEventListener("click", () => input.click());
  input.addEventListener("change", () => {
    const file = input.files && input.files[0];
    input.value = "";
    if (!file) return;
    if (!/^image\/(png|jpeg|webp)$/.test(file.type)) {
      setStatus("Use a PNG, JPG, or WebP image.", "error");
      return;
    }
    if (file.size > 4 * 1024 * 1024) {
      setStatus("Choose an image smaller than 4 MB.", "error");
      return;
    }
    const id = currentUserId();
    if (!id) {
      setStatus("Sign in again before updating your photo.", "error");
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      const image = new Image();
      image.onload = () => {
        const size = 256;
        const canvas = document.createElement("canvas");
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext("2d");
        const scale = Math.max(size / image.width, size / image.height);
        const w = image.width * scale;
        const h = image.height * scale;
        ctx.drawImage(image, (size - w) / 2, (size - h) / 2, w, h);
        const dataUrl = canvas.toDataURL("image/jpeg", 0.82);
        localStorage.setItem(`contrack_avatar_${id}`, dataUrl);
        paintStoredPhoto(dataUrl);
        setStatus("Profile picture updated.", "success");
      };
      image.onerror = () => setStatus("Could not read that image.", "error");
      image.src = String(reader.result || "");
    };
    reader.readAsDataURL(file);
  });
}

function wireFieldFilters() {
  const nameInput = document.getElementById("inp-name");
  const contactInput = document.getElementById("inp-contact");
  let role = "";
  try {
    role = JSON.parse(localStorage.getItem("contrack_user") || "{}").role || "";
  } catch (_) {
    role = "";
  }
  // Vendor company names may include numbers. Staff names stay letters only.
  if (role !== "vendor" && role !== "client") {
    nameInput?.addEventListener("input", () => {
      const cleaned = nameInput.value.replace(/[0-9]/g, "");
      if (cleaned !== nameInput.value) nameInput.value = cleaned;
    });
  }
  contactInput?.addEventListener("input", () => {
    const cleaned = contactInput.value.replace(/[A-Za-z]/g, "");
    if (cleaned !== contactInput.value) contactInput.value = cleaned;
  });
}

function roleLabel(role) {
  const r = String(role || "").toLowerCase();
  if (r === "ceo") return "CEO";
  if (r === "manager") return "Manager";
  if (r === "admin" || r === "system_admin") return "Administrator";
  return "Vendor";
}

function alertCopy(role) {
  const r = String(role || "").toLowerCase();
  if (r === "ceo") return "Contracts waiting for your decision.";
  if (r === "manager") return "New submissions and contracts sent back for review.";
  if (r === "admin" || r === "system_admin") return "Account and system notices.";
  return "Decisions, change requests, and renewal notices.";
}

function wireSettings() {
  const alerts = document.getElementById("set-alerts");
  if (alerts) {
    alerts.addEventListener("change", async () => {
      const on = alerts.checked;
      alerts.disabled = true;
      try {
        await updateMyProfile({ notify_alerts: on });
        setStatus(on ? "Alerts are on." : "Alerts are off.", "success");
      } catch (err) {
        alerts.checked = !on;
        setStatus(err?.message || "Could not save alert setting.", "error");
      } finally {
        alerts.disabled = false;
      }
    });
  }

  const signout = document.getElementById("set-signout");
  if (signout) {
    signout.addEventListener("click", async () => {
      try {
        await fetch("/api/session.php", { method: "DELETE" });
      } catch (_) {
        /* best effort */
      }
      localStorage.removeItem("contrack_user");
      window.location.href = "/index.html";
    });
  }
}

/** Controls whose fields have no column behind them must not claim to have saved. */
function wireNotStored() {
  document.querySelectorAll("[data-not-stored]").forEach((btn) => {
    btn.addEventListener("click", () => {
      setStatus(
        btn.getAttribute("data-not-stored") ||
          "This section is not stored yet. Name, contact number, and password are saved.",
        "info",
      );
    });
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  try {
    const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
    setApiContext({ role: saved.role || "vendor", userId: saved.id || null });
  } catch (_) {
    /* the PHP session is authoritative; headers are only a dev convenience */
  }
  wireSaveProfile();
  wirePassword();
  wirePhoto();
  wireFieldFilters();
  wireSettings();
  wireNotStored();
  await prefill();
});
