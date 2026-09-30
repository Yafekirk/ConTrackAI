/** Days remaining until contract end_date. Null if missing/invalid. */
export function daysUntilEnd(endDate) {
  if (!endDate) return null;
  const raw = String(endDate);
  const iso = raw.includes("T") ? raw : `${raw}T12:00:00`;
  const end = new Date(iso);
  if (Number.isNaN(end.getTime())) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  end.setHours(0, 0, 0, 0);
  return Math.round((end.getTime() - today.getTime()) / 86400000);
}

/** Net 30 = 30 days or fewer left before expiration (not yet expired). */
export function isNet30Expiring(endDate) {
  const days = daysUntilEnd(endDate);
  return days !== null && days >= 0 && days <= 30;
}

export function net30BadgeHtml() {
  return `<span class="pill pill-expiring" title="30 days or fewer until the contract end date">Net 30</span>`;
}

export function pendingStatusLabel() {
  return "PENDING";
}
