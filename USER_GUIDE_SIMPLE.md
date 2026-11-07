# ConTrack AI — Quick User Guide

ConTrack AI is the contract management website of **JB Calderon Events Management**. Clients and vendors upload contracts as PDFs, the system reads them automatically using AI, a Manager reviews them and evaluates the vendor, and the CEO gives the final approval. The system also tracks contract dates, sends renewal alerts, and archives inactive contracts.

---

## 1. Who Uses the System

| Role | What They Do |
|---|---|
| **Client/Vendor** | Uploads contracts and checks their status and performance score. |
| **Manager** | Monitors and reviews contracts, rates vendors, and recommends decisions to the CEO. |
| **CEO** | Gives the final approval or rejection. |
| **Administrator** | Creates user accounts and downloads the audit log. |

---

## 2. How a Contract Gets Approved

1. **Vendor submits** a contract. Its status becomes **PENDING**.
2. **Manager reviews** it and chooses one of three options:
   - **Approve** — sends it to the CEO, recommending approval.
   - **Reject** — sends it to the CEO, recommending rejection.
   - **Update** — sends it back to the Vendor for changes (**NEEDS CHANGES**).
3. **CEO decides** to **Approve** or **Reject**. This decision is final.

Everyone involved is notified at each step through the bell icon.

### What the Statuses Mean

| Status | Meaning |
|---|---|
| **PENDING** | Waiting for review or decision. |
| **NEEDS CHANGES** | The Vendor must revise and resubmit. |
| **ACTIVE** | Approved and running. |
| **EXPIRING / NET 30** | Ends within 30 days. |
| **EXPIRED** | The end date has passed. |
| **REJECTED** | Not approved. |

Expired, rejected, and terminated contracts are **archived automatically**. Contracts ending within 90 days are flagged as **subject for renewal**, and you receive a renewal alert.

---

## 3. Getting Started

### Signing In

1. Open the ConTrack AI website.
2. Enter your email and password.
3. Click **SIGN IN**.
4. The first time, read and accept the **Terms & Conditions**.

### Creating a Vendor Account

1. On the sign-in page, click **Create account**.
2. Enter your company name, email, mobile number (e.g., `09171234567`), and supplier type.
3. Create a password and type it again in **Confirm Password**. Both must match.
4. Tick the agreement box and click **SIGN UP**.

Each field is checked as you type. If something is wrong, a message appears under the field.

Staff accounts (Manager, CEO, Administrator) are created by the Administrator.

### Password Rules

Your password must be **8–24 characters** and include **one number**, **one capital letter**, and **one symbol** (for example, `Contract#2026`). You cannot reuse an old password.

### Forgot Your Password?

1. Click **Forgot Password?** on the sign-in page.
2. Enter your email. A 6-digit code is sent to you (valid for 10 minutes).
3. Enter the code, then set a new password.

> After 10 wrong sign-in attempts, you must wait 60 seconds before trying again.

### Signing Out

Click **Sign out** in the menu at the top right, or the sign-out icon at the bottom of the sidebar.

---

## 4. Vendor Guide

### Submit a Contract

1. Click **Submit Contract**.
2. Drag in your contract **PDF** (max 25 MB), or click **Browse Files**.
3. Wait while the system reads the contract and fills in the form.
4. Check the details and correct anything that is wrong.
5. Click **Submit Contract**.

You will receive a contract code such as `CT-1001`.

**Tips:**
- Only PDF files are accepted, not Word files.
- Use clear headings in your contract (see Section 9).
- The same contract cannot be submitted twice.

### Track Your Contracts

Open **My Contracts** to see all your contracts. Use the search box and filters to find one, and click **View** to see its details or open the PDF. Click **Export** to download the list.

### If Changes Are Requested

When a contract shows **NEEDS CHANGES**:

1. Click **View** to read the Manager's comments.
2. Revise your contract.
3. Click **Submit another contract** and upload the revised PDF.

### Check Your Performance

Open **Performance** to see your score (1 to 5 stars), the evaluator's remarks, and your past evaluations.

---

## 5. Manager Guide

### Review a Contract

1. Click **My Submissions**.
2. Find a contract with a **Review** button and click it.
3. Click **View original PDF** to read the contract.
4. Write your comments in **Manager Notes**.
5. Choose **Approve**, **Reject**, or **Update**.

Notes are **required** for **Reject** and **Update**. You can change your recommendation with **Re-review** until the CEO decides.

### Rate a Vendor

1. Click **Vendor Scoring**.
2. Search for and select the vendor.
3. Give 1–5 stars for each criterion.
4. Add remarks if needed, then click **Save evaluation**.

### View All Contracts

Click **All Contracts** to see every contract in the system. Click **Export CSV** to download the list.

---

## 6. CEO Guide

### Approve or Reject a Contract

1. Click **Approvals**. Only contracts already reviewed by a Manager appear here.
2. Click **Review & decide**.
3. Read the Manager's recommendation and, if needed, open the PDF.
4. Add notes (optional).
5. Click **Approve** or **Reject**.

> CEO decisions are final and cannot be changed.

### Other Pages

| Page | What You Can Do |
|---|---|
| **Dashboard** | See totals, pending approvals, and top vendors. |
| **All Contracts** | Search and filter every contract; export the list. |
| **Vendor Overview** | Compare vendors by value, score, and risk. |
| **Reports & Analytics** | View charts and download reports. |

---

## 7. Administrator Guide

### Add a User

1. Click **User Management**, then **Add User**.
2. Enter the name, email, role, and a temporary password.
3. Click **Create User**, then give the user their password securely.

### Disable a User

Click the edit (✎) button, set **Status** to **Disabled**, and click **Save Changes**. The user is signed out within 30 seconds. Users cannot be deleted, only disabled.

### Download the Audit Log

1. Click **System Logs & Audit**.
2. Click **Download audit log**.

The file lists recent sign-ins, account changes, contract submissions, reviews, decisions, and evaluations.

---

## 8. Your Profile & Settings

Open **Profile & Settings** from the top-right menu to:

- Change your password.
- Turn contract and score notifications on or off.

Click **Save Changes** when done.

---

## 9. What the AI Reads From Your Contract

When a contract PDF is uploaded, the AI automatically finds:

- the supplier and client names;
- the contract dates (start, end, and signed date);
- the renewal terms;
- the penalties;
- the financial obligations (value, currency, and payment terms).

Payment terms are shown clearly. For example, **Net 30** is shown as **30 Days**, and **Net 60** as **60 Days**.

> In contract status lists, **NET 30** means the contract **ends within 30 days**. This is different from the payment term.

---

## 10. How to Format a Contract PDF

For best results, use these headings in your contract:

1. **Contract Header** — Title, Type, Effective Date, End Date
2. **Parties** — Client Name, Vendor Name, Vendor Address
3. **Scope of Work**
4. **Financial Terms** — Value, Currency, Payment Terms, Penalty
5. **Renewal and Termination**
6. **Signatures** — Both signatories and Signed Date

Write dates as `YYYY-MM-DD` (for example, `2026-12-31`).

---

## 11. Common Problems

| Problem | What to Do |
|---|---|
| Sign-in page just reloads | Your email or password is wrong. Try again or reset your password. |
| "Please wait — Try again in …s" | Too many wrong attempts. Wait for the countdown. |
| Did not receive the reset code | Check your spam folder, then click **Resend** after 30 seconds. |
| "Only PDF files are accepted" | Save your contract as a PDF. |
| "Duplicate contract blocked" | This contract was already submitted. Change it before resubmitting. |
| PDF does not open | Allow pop-ups for the ConTrack AI website in your browser. |
| Account disabled | Contact your Administrator. |

For anything else, contact your System Administrator.

---

*For full details, see the complete ConTrack AI User Manual (`USER_MANUAL.md`).*
