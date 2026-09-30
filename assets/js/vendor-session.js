/* ----------------------------------------------------------------
 * vendor-session.js
 * Helper that returns the locally-cached vendor user. UI wiring
 * (sidebar identity + logout) is now centralised in portal-shell.js
 * so this module exposes only the lightweight `getActiveUser` /
 * `applyVendorIdentity` helpers used by the data-loaders.
 * ---------------------------------------------------------------- */

export function getActiveUser() {
  try {
    return JSON.parse(localStorage.getItem("contrack_user") || "null");
  } catch (_) {
    return null;
  }
}

export function applyVendorIdentity() {
  return getActiveUser();
}
