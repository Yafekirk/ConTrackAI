/**
 * Profile & Settings page, shared by the Admin, CEO, Manager, and Vendor portals.
 *
 * Everything shown comes from the signed-in user's row (GET /api/account.php), so each
 * account sees its own data. One "Save Changes" button sends the changed profile fields,
 * notification toggles, and appearance in a single PATCH, then changes the password when
 * the password fields are filled in. "Cancel" restores the last saved values.
 *
 * Expected ids: ps-profile-card, ps-edit-btn, ps-display-name, disp-email, disp-contact,
 * lbl-name, inp-name, inp-contact, inp-position, inp-department, pw-current, pw-new,
 * pw-confirm, set-alerts, set-announcements, ps-cancel, ps-save, profile-status,
 * acct-photo-btn, acct-photo-input; theme buttons carry data-theme-choice.
 */
import {
  setApiContext,
  getMyProfile,
  updateMyProfile,
  changePassword,
} from "/assets/js/api-client.js";

const PROFILE_FIELDS = {
  name: "inp-name",
  contact_number: "inp-contact",
  position_title: "inp-position",
  department: "inp-department",
};
const PASSWORD_IDS = ["pw-current", "pw-new", "pw-confirm"];

let saved = null;
let role = "vendor";
let pendingTheme = "light";

const $ = (id) => document.getElementById(id);

function isVendor(r = role) {
  return r === "vendor" || r === "client" || r === "vendor_client";
}

function setStatus(message, kind = "info") {
  const el = $("profile-status");
  if (!el) return;
  el.textContent = message;
  el.dataset.kind = kind;
}

function fieldValue(id) {
  return $(id)?.value?.trim() || "";
}

function setText(id, text) {
  const el = $(id);
  if (el) el.textContent = text;
}

function snapshotFrom(me) {
  const company = String(me.company_name || "").trim();
  return {
    name: (isVendor(me.role) && company) || String(me.name || "").trim() || company,
    contact_number: String(me.contact_number || ""),
    position_title: String(me.position_title || ""),
    department: String(me.department || ""),
    notify_alerts: me.notify_alerts !== false,
    notify_announcements: me.notify_announcements !== false,
    theme_preference: me.theme_preference === "dark" ? "dark" : "light",
  };
}

function roleLabel(r) {
  const v = String(r || "").toLowerCase();
  if (v === "ceo") return "Chief Executive";
  if (v === "manager") return "Contract Manager";
  if (v === "admin" || v === "system_admin") return "System Administrator";
  return "Vendor / Supplier";
}

function configureForRole(me) {
  const vendor = isVendor(me.role);
  setText("lbl-name", vendor ? "Company Name" : "Full Name");
  const name = $("inp-name");
  if (name) {
    name.maxLength = vendor ? 120 : 80;
    name.autocomplete = vendor ? "organization" : "name";
    name.placeholder = vendor ? "Your company name" : "Your full name";
  }
  const position = $("inp-position");
  if (position) position.placeholder = vendor ? "e.g. Account Manager" : `e.g. ${roleLabel(me.role)}`;
  const department = $("inp-department");
  if (department) department.placeholder = vendor ? `e.g. ${me.supplier_type || "Supply"}` : "e.g. Procurement";
  setText("set-alerts-copy", alertCopy(me.role));
}

function alertCopy(r) {
  const v = String(r || "").toLowerCase();
  if (v === "ceo") return "Contracts reviewed by a manager and waiting for your decision.";
  if (v === "manager") return "New submissions, resubmissions, and CEO decisions.";
  if (v === "admin" || v === "system_admin") return "New contracts submitted by vendors.";
  return "Submission receipts, review results, and CEO decisions.";
}

function paintIdentity() {
  if (!saved) return;
  setText("ps-display-name", saved.name || "—");
  setText("disp-contact", saved.contact_number || "No contact number yet");
  document.querySelectorAll("[data-shell-name], .sb-name").forEach((el) => {
    el.textContent = saved.name || el.textContent;
  });
}

function fillForm() {
  if (!saved) return;
  Object.entries(PROFILE_FIELDS).forEach(([key, id]) => {
    const el = $(id);
    if (el) el.value = saved[key];
  });
  if ($("set-alerts")) $("set-alerts").checked = saved.notify_alerts;
  if ($("set-announcements")) $("set-announcements").checked = saved.notify_announcements;
  PASSWORD_IDS.forEach((id) => {
    const el = $(id);
    if (el) el.value = "";
  });
  selectTheme(saved.theme_preference);
  paintIdentity();
}

function setEditing(on) {
  Object.values(PROFILE_FIELDS).forEach((id) => {
    const el = $(id);
    if (el) el.readOnly = !on;
  });
  $("ps-profile-card")?.classList.toggle("is-editing", on);
  const btn = $("ps-edit-btn");
  if (btn) {
    btn.setAttribute("aria-pressed", String(on));
    const label = btn.querySelector("span");
    if (label) label.textContent = on ? "Editing" : "Edit";
  }
  if (on) $("inp-name")?.focus();
}

function selectTheme(theme) {
  pendingTheme = theme === "dark" ? "dark" : "light";
  document.querySelectorAll("[data-theme-choice]").forEach((btn) => {
    btn.setAttribute("aria-pressed", String(btn.dataset.themeChoice === pendingTheme));
  });
  window.ContrackTheme?.apply(pendingTheme);
}

async function load() {
  let me;
  try {
    me = await getMyProfile();
  } catch (e) {
    setStatus(e?.message || "Could not load your profile.", "error");
    return;
  }
  if (!me || typeof me !== "object") return;

  role = String(me.role || "vendor").toLowerCase();
  saved = snapshotFrom(me);
  configureForRole(me);
  setText("disp-email", me.email || "—");
  fillForm();
  window.ContrackTheme?.remember(saved.theme_preference);

  try {
    const cached = JSON.parse(localStorage.getItem("contrack_user") || "{}");
    localStorage.setItem(
      "contrack_user",
      JSON.stringify({
        ...cached,
        id: me.id,
        name: saved.name,
        company_name: String(me.company_name || "").trim(),
        email: me.email,
        role: me.role,
      }),
    );
  } catch (_) {
    /* non-fatal */
  }
}

function collectProfilePatch() {
  const patch = {};
  const name = fieldValue(PROFILE_FIELDS.name);
  if (name !== saved.name) patch[isVendor() ? "company_name" : "name"] = name;
  ["contact_number", "position_title", "department"].forEach((key) => {
    const val = fieldValue(PROFILE_FIELDS[key]);
    if (val !== saved[key]) patch[key] = val;
  });
  const alerts = $("set-alerts")?.checked ?? saved.notify_alerts;
  if (alerts !== saved.notify_alerts) patch.notify_alerts = alerts;
  const announcements = $("set-announcements")?.checked ?? saved.notify_announcements;
  if (announcements !== saved.notify_announcements) patch.notify_announcements = announcements;
  if (pendingTheme !== saved.theme_preference) patch.theme_preference = pendingTheme;
  return patch;
}

function validateProfile(patch) {
  const name = patch.company_name ?? patch.name;
  if (name !== undefined && (name.length < 2 || name.length > 120)) {
    return `${isVendor() ? "Company name" : "Full name"} must be 2–120 characters.`;
  }
  if (patch.contact_number && !/^(09\d{9}|\+639\d{9}|639\d{9})$/.test(patch.contact_number.replace(/\s+/g, ""))) {
    return "Contact number must be a PH mobile number such as 09171234567.";
  }
  return null;
}

/** Returns null (no password change), a payload, or an error string. */
function collectPassword() {
  const current = fieldValue("pw-current");
  const next = fieldValue("pw-new");
  const confirm = fieldValue("pw-confirm");
  if (!current && !next && !confirm) return null;
  if (!current || !next) return "Enter your current password and a new password.";
  if (next.length < 8 || next.length > 24) return "New password must be 8–24 characters.";
  if (!/[0-9]/.test(next) || !/[A-Z]/.test(next) || !/[^A-Za-z0-9]/.test(next)) {
    return "New password needs at least 1 number, 1 uppercase letter, and 1 special character.";
  }
  if (next !== confirm) return "The new passwords do not match.";
  return { current_password: current, new_password: next };
}

async function save() {
  if (!saved) return;
  const patch = collectProfilePatch();
  const password = collectPassword();
  if (typeof password === "string") {
    setStatus(password, "error");
    return;
  }
  const profileError = validateProfile(patch);
  if (profileError) {
    setStatus(profileError, "error");
    return;
  }
  if (Object.keys(patch).length === 0 && !password) {
    setStatus("No changes to save.");
    setEditing(false);
    return;
  }

  const saveBtn = $("ps-save");
  const cancelBtn = $("ps-cancel");
  saveBtn.disabled = true;
  cancelBtn.disabled = true;
  setStatus("Saving…");

  let profileSaved = false;
  try {
    if (Object.keys(patch).length > 0) {
      await updateMyProfile(patch);
      profileSaved = true;
      saved = {
        ...saved,
        name: fieldValue(PROFILE_FIELDS.name),
        contact_number: patch.contact_number ?? saved.contact_number,
        position_title: patch.position_title ?? saved.position_title,
        department: patch.department ?? saved.department,
        notify_alerts: patch.notify_alerts ?? saved.notify_alerts,
        notify_announcements: patch.notify_announcements ?? saved.notify_announcements,
        theme_preference: pendingTheme,
      };
      window.ContrackTheme?.remember(pendingTheme);
      paintIdentity();
    }
    if (password) await changePassword(password);
    setEditing(false);
    PASSWORD_IDS.forEach((id) => {
      if ($(id)) $(id).value = "";
    });
    setStatus(password ? "Changes saved and password updated." : "Changes saved.", "success");
    await load();
  } catch (e) {
    const reason = e?.message || "Could not save your changes.";
    setStatus(profileSaved ? `Profile saved, but the password was not changed: ${reason}` : reason, "error");
  } finally {
    saveBtn.disabled = false;
    cancelBtn.disabled = false;
  }
}

function cancel() {
  fillForm();
  setEditing(false);
  setStatus("Changes discarded.");
}

function wireEyes() {
  document.querySelectorAll("[data-eye-for]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const input = $(btn.dataset.eyeFor);
      if (!input) return;
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
      btn.setAttribute("aria-pressed", String(show));
    });
  });
}

function wireFieldFilters() {
  const nameInput = $("inp-name");
  nameInput?.addEventListener("input", () => {
    if (isVendor()) return;
    const cleaned = nameInput.value.replace(/[0-9]/g, "");
    if (cleaned !== nameInput.value) nameInput.value = cleaned;
  });
  const contactInput = $("inp-contact");
  contactInput?.addEventListener("input", () => {
    const cleaned = contactInput.value.replace(/[^0-9+]/g, "");
    if (cleaned !== contactInput.value) contactInput.value = cleaned;
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
  const button = $("acct-photo-btn");
  const input = $("acct-photo-input");
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
        setStatus("Profile picture updated on this browser.", "success");
      };
      image.onerror = () => setStatus("Could not read that image.", "error");
      image.src = String(reader.result || "");
    };
    reader.readAsDataURL(file);
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  try {
    const cached = JSON.parse(localStorage.getItem("contrack_user") || "{}");
    role = String(cached.role || "vendor").toLowerCase();
    setApiContext({ role: cached.role || "vendor", userId: cached.id || null });
  } catch (_) {
    /* the PHP session is authoritative; headers are only a dev convenience */
  }

  setEditing(false);
  selectTheme(window.ContrackTheme?.current() || "light");
  $("ps-edit-btn")?.addEventListener("click", () => {
    setEditing($("ps-edit-btn").getAttribute("aria-pressed") !== "true");
  });
  document.querySelectorAll("[data-theme-choice]").forEach((btn) => {
    btn.addEventListener("click", () => selectTheme(btn.dataset.themeChoice));
  });
  $("ps-save")?.addEventListener("click", save);
  $("ps-cancel")?.addEventListener("click", cancel);
  wireEyes();
  wireFieldFilters();
  wirePhoto();
  await load();
});
