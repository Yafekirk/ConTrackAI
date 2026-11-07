# ConTrack AI — Contract Management System

## User Manual

| | |
|---|---|
| **Document Title** | ConTrack AI User Manual |
| **Organisation** | JB Calderon Events Management |
| **System** | ConTrack AI — Contract Management System |
| **Document Version** | 1.1 |
| **Date of Issue** | November 2026 |
| **Intended Audience** | Client/Vendor users, Managers, the CEO, and System Administrators of JB Calderon Events Management |

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [System Overview](#2-system-overview)
3. [Secure Account Access](#3-secure-account-access)
4. [Common Interface Elements](#4-common-interface-elements)
5. [Client/Vendor Portal](#5-clientvendor-portal)
6. [Manager Portal](#6-manager-portal)
7. [CEO Panel](#7-ceo-panel)
8. [System Administrator Panel](#8-system-administrator-panel)
9. [AI-Assisted Clause Extraction](#9-ai-assisted-clause-extraction)
10. [Payment Terms Display](#10-payment-terms-display)
11. [Contract Monitoring and Alerts](#11-contract-monitoring-and-alerts)
12. [Vendor Performance Evaluation](#12-vendor-performance-evaluation)
13. [Reports and Audit Log Download](#13-reports-and-audit-log-download)
14. [Troubleshooting](#14-troubleshooting)
15. [Glossary](#15-glossary)
16. [Appendix A — Recommended Contract PDF Format](#appendix-a--recommended-contract-pdf-format)

---

## 1. Introduction

### 1.1 Purpose

This manual explains how the users of JB Calderon Events Management use ConTrack AI, a secure web-based contract management system. It gives step-by-step instructions for each user role.

### 1.2 Scope

This manual covers only the following system objectives, and shows where each one is described.

| No. | Objective | Section |
|---|---|---|
| 1 | Secure login and sign-up for the CEO, System Administrator, Manager, and Client/Vendor users, with input validation and confirm-password verification. | 3, 8.2 |
| 2 | Role-based access, in which Managers monitor, review, and evaluate contracts and vendors and make recommendations to the CEO, who gives final approval. | 2, 6, 7 |
| 3 | AI-assisted clause extraction using Natural Language Processing (NLP) to identify supplier names, contract dates, subject-for-renewal clauses, penalties, and financial obligations. | 9 |
| 4 | A Client/Vendor portal for uploading contracts in validated, supported file formats and monitoring their status. | 5 |
| 5 | Automated contract monitoring and alerts, including status tracking, subject-for-renewal notifications, and archiving of inactive contracts. | 11 |
| 6 | Vendor evaluation that produces performance scores and reports based on established criteria. | 12, 13 |
| 7 | Clear display of payment terms, such as "30 Days" for Net 30. | 10 |
| 8 | Audit log download to support documentation, monitoring, and reporting. | 13.3 |
| 9 | Reduced manual workload through automated analysis, monitoring, notifications, and data extraction. | 9, 11 |
| 10 | Fewer human errors through AI-assisted extraction, centralised records, validated uploads, and decision support from monitoring, alerts, reports, and vendor scores. | 5.3, 9, 11, 12, 13 |

### 1.3 Document Conventions

| Convention | Meaning |
|---|---|
| **Bold text** | A button, menu item, field label, or other on-screen element. |
| `Monospace text` | A file name, contract code, or web address. |
| "Quoted text" | A message displayed by the system, reproduced exactly. |
| ₱ | Philippine Peso, the system's default currency. |

> **Note:** Notes give supplementary information.

> **Important:** Important notices describe actions that cannot be undone or that affect security.

---

## 2. System Overview

### 2.1 About ConTrack AI

ConTrack AI centralises the supplier contracts of JB Calderon Events Management. Clients and vendors upload contracts as PDF files. The system reads each contract with NLP and extracts its key details automatically. A Manager reviews each contract, evaluates the vendor, and recommends a decision; the CEO then gives final approval. The system monitors every contract, sends alerts for renewals and status changes, archives inactive contracts, and records all activity in an audit log.

### 2.2 User Roles

| Role | Portal | Responsibilities |
|---|---|---|
| **Client/Vendor** | **VENDOR PORTAL** | Registers an account, uploads contracts, monitors contract status, and views performance scores. |
| **Manager** | **MANAGER PORTAL** | Monitors and reviews contracts, evaluates vendor performance, and makes recommendations to the CEO. |
| **CEO** | **EXECUTIVE PANEL** | Gives the final approval or rejection, monitors the contract portfolio, and reviews reports. |
| **System Administrator** | **ADMIN PANEL** | Creates and manages user accounts and downloads the audit log. |

Each user can open only the pages and perform only the actions permitted for their role. The server checks the signed-in user's role on every page and every request.

### 2.3 Contract Approval Workflow

```
 Client/Vendor           Manager                          CEO
 ─────────────           ───────                          ───
 Upload contract ──►     Review contract
 (PENDING)                 │
                           ├─ Approve ── recommend approval ──►  Approve ──► APPROVED
                           │                                     Reject  ──► REJECTED
                           ├─ Reject  ── recommend rejection ──►  (same choices)
                           │
                           └─ Update  ── NEEDS CHANGES ──► Vendor revises and resubmits
                                                           (returns to PENDING)
```

1. **Upload.** The Client/Vendor uploads a contract PDF. The system extracts its details, gives it a contract code (for example, `CT-1001`), and sets its status to **PENDING**. Managers are notified.
2. **Manager review and recommendation.** The Manager reviews the contract and chooses:
   - **Approve** — recommends approval to the CEO.
   - **Reject** — recommends rejection to the CEO. Manager notes are required.
   - **Update** — returns the contract to the vendor for modification. Manager notes are required.
3. **Modification (if requested).** The vendor revises and resubmits the contract, which returns to the Manager's queue.
4. **CEO final approval.** The CEO reviews the Manager's recommendation and selects **Approve** or **Reject**. The decision is final.
5. **Monitoring.** Approved contracts are monitored until they end. See [Section 11](#11-contract-monitoring-and-alerts).

> **Note:** A Manager's **Approve** and **Reject** are recommendations. Only the CEO can give final approval.

### 2.4 Contract Status Reference

| Status | Meaning |
|---|---|
| **PENDING** | Waiting for Manager review or CEO decision. |
| **NEEDS CHANGES** (shown as **MODIFY** in staff tables) | Returned to the vendor for modification. |
| **ACTIVE** | Approved, with more than 30 days left before the end date. |
| **EXPIRING** (shown as **NET 30** in staff views) | Approved, with 30 days or fewer left before the end date. |
| **EXPIRED** | Approved, but the end date has passed. Archived automatically. |
| **REJECTED** | Rejected by the CEO. Archived automatically. |
| **TERMINATED** | Terminated. Archived automatically. |
| **FOR RENEWAL** | Under renegotiation or renewal. |
| **ARCHIVED** | Inactive contract kept for reference. |

> **Note:** In status views, **NET 30** means a contract **expires within 30 days**. This is different from the payment term "Net 30", which is displayed as **30 Days** (see [Section 10](#10-payment-terms-display)).

---

## 3. Secure Account Access

### 3.1 Accessing the System

Open the ConTrack AI web address provided by JB Calderon Events Management. You need a current web browser (Chrome, Edge, Firefox, or Safari) with pop-ups allowed for the site, so that contract PDFs can open.

| Sign-In Page | Who May Use It |
|---|---|
| Main sign-in page | All users |
| **Private Access Portal** | CEO, Manager, and System Administrator only |

### 3.2 Client/Vendor Sign-Up

Clients and vendors create their own accounts.

1. On the main sign-in page, click **Create account**.
2. Complete the form. Each field is checked as you type, and a message appears under any invalid entry.

   | Field | Validation |
   |---|---|
   | **COMPANY NAME** | Required; 2 to 120 characters on a single line. |
   | **EMAIL ADDRESS** | Required; a valid email address. Must not already be registered. |
   | **CONTACT NUMBER** | Required; a Philippine mobile number (`09XXXXXXXXX`, `639XXXXXXXXX`, or `+639XXXXXXXXX`). Letters are not accepted. |
   | **SUPPLIER TYPE** | Required; **Supply**, **Lease**, **Service**, or **Other**. |
   | **PASSWORD** | Required; must meet the password policy (Section 3.4). |
   | **CONFIRM PASSWORD** | Required; must match the password exactly. Otherwise "Passwords do not match." is shown. |

3. Select **I agree to the Terms & Conditions and Privacy Policy**.
4. Click **SIGN UP →**.

When the account is created, you are signed in and taken to the Vendor Dashboard.

### 3.3 Staff Accounts (CEO, Manager, System Administrator)

For security, CEO, Manager, and System Administrator accounts cannot be self-registered. The System Administrator creates them, and the same validation and confirm-password checks apply (see [Section 8.2](#82-creating-a-user-account)).

### 3.4 Password Policy

Every password must:

- be 8 to 24 characters long;
- contain at least one number;
- contain at least one uppercase letter;
- contain at least one special character (for example, `#`, `@`, `!`).

A new password cannot be one you have used before. Passwords are stored only in encrypted (hashed) form.

### 3.5 Signing In

1. Enter your **EMAIL ADDRESS** and **PASSWORD**. Click the eye icon to show or hide the password.
2. Click **SIGN IN →**.
3. The first time you sign in, read the **Terms & Conditions and Privacy Policy**, select the agreement checkbox, and click **Agree & Continue**.

The system opens the dashboard for your role. If the details are incorrect, the sign-in page reloads; re-enter them carefully.

### 3.6 Protection Against Repeated Failed Sign-Ins

| Condition | System Response |
|---|---|
| 3 failed attempts in a row | A "Forgot your password?" prompt is shown. |
| 10 failed attempts | Sign-in is locked for 60 seconds, with a countdown. |
| 10 failed attempts for one email, or 30 from one network address, within 15 minutes | Sign-in is locked for 60 seconds. |

### 3.7 Resetting a Forgotten Password

1. Click **Forgot Password?** on the sign-in page.
2. Enter your email address and click **SEND VERIFICATION CODE →**.
3. Enter the six-digit code sent to your email and click **VERIFY CODE →**. The code is valid for 10 minutes. Click **Resend** if needed (available after 30 seconds).
4. Enter and confirm your new password, then click **RESET PASSWORD →**.
5. Click **GO TO SIGN IN →** and sign in with the new password.

At most 3 codes can be requested every 15 minutes, and a code stops working after 5 incorrect entries.

### 3.8 Changing Your Password

1. Open **Profile & Settings** from the user menu at the top right.
2. Enter your **Current Password**, **New Password**, and **Confirm New Password**.
3. Click **Save Changes**.

### 3.9 Sessions and Signing Out

- A session lasts up to 8 hours.
- To sign out, click **Sign out** in the user menu or the sign-out icon in the sidebar.
- If the System Administrator disables your account or changes your role, the change takes effect within about 30 seconds.

> **Important:** Always sign out when using a shared computer.

---

## 4. Common Interface Elements

### 4.1 Layout

Every portal has:

- a **sidebar** on the left with the menu for your role and a sign-out icon;
- a **top bar** with the notification bell and the user menu (**Profile & Settings**, **Sign out**).

### 4.2 Notifications

Click the bell to open **NOTIFICATIONS**. The badge on the bell shows the number of unread notifications.

| Element | Function |
|---|---|
| **ALL** tab | All notifications. |
| **UNREAD** tab | Notifications not yet read. |
| **ARCHIVED** tab | Notifications about archived contracts. |
| **Mark All as Read** | Marks every notification as read. |

To choose which notifications you receive, open **Profile & Settings** and use:

- **Contract Updates** — submissions, reviews, and decisions;
- **System Announcements** — vendor performance score notices.

### 4.3 Automatic Updates

Every 15 seconds the system checks for new contracts, notifications, and scores. If you are idle, the page refreshes automatically. If you are working on the page, the bar "New updates available — click to refresh" appears instead.

---

## 5. Client/Vendor Portal

### 5.1 Menu

| Menu Item | Purpose |
|---|---|
| **Dashboard** | Summary of your contracts and performance. |
| **Submit Contract** | Upload a new contract. |
| **My Contracts** | Monitor the status of all your contracts. |
| **Performance** | View your performance scores. |
| **Profile & Settings** | Change your password and notification settings. |

### 5.2 Dashboard

| Card | Description |
|---|---|
| **PERFORMANCE SCORE** | Your latest rating, from 1.0 to 5.0 stars. |
| **ACTIVE CONTRACTS** | Approved contracts that are still running. |
| **TOTAL CONTRACT VALUE** | Combined value of your contracts. |
| **PENDING** | Contracts awaiting action. |

The Dashboard also shows a **PERFORMANCE BREAKDOWN** by criterion, a **CONTRACT VALUE TREND** chart, your latest contracts, and **RECENT ACTIVITY**.

### 5.3 Uploading a Contract

**Supported file format:**

| Requirement | Detail |
|---|---|
| File type | **PDF only.** The system checks that the file is a genuine PDF. Word (`.doc`, `.docx`) and other formats are rejected. |
| Maximum size | 25 MB |

**Steps:**

1. Click **Submit Contract**.
2. Drag the PDF onto "Drop your contract PDF here", or click **Browse Files**.
3. Wait while the system extracts the contract details ("Extracting clauses").
4. Review the extracted details:

   | Section | Fields |
   |---|---|
   | **CONTRACT HEADER** | Contract Title, Contract Type, Effective Date, End Date |
   | **PARTIES** | Client Name, Vendor Name, Vendor Address |
   | **SCOPE OF WORK** | Scope of Work |
   | **FINANCIAL TERMS** | Contract Value, Currency, Payment Terms, Penalty Clause |
   | **RENEWAL AND TERMINATION** | Renewal Terms, Termination Clause |
   | **SIGNATURES** | Client and Vendor Authorized Signatories, Signed Date |

5. Correct any editable field if necessary. The scope of work and financial terms are taken directly from the PDF and cannot be edited.
6. Click **Submit Contract**.

The "Contract Submitted!" message confirms the upload, and the contract receives a code such as `CT-1001`.

**Upload validation:**

- The contract title, contract type, contract value (greater than zero), end date, and PDF are required.
- The start date cannot be later than the end date.
- The same PDF, or a contract with identical key terms, cannot be submitted twice ("DUPLICATE CONTRACT BLOCKED").

### 5.4 Monitoring Contract Status

Open **My Contracts**.

| Control | Function |
|---|---|
| Search box | Find contracts by contract ID or title. |
| Status filter | All Status, Active, Pending, Expired / Archived, Expiring (≤30 days), Subject for renewal, Needs changes. |
| Type filter | Filter by contract type. |
| **Export** | Download your contract list. |

The table shows each contract's **VALUE**, **PERIOD**, and **STATUS**. Click **View** to see the contract details, the AI-extracted clauses, and the original PDF (**View PDF**).

### 5.5 Responding to a Modification Request

When a contract shows **NEEDS CHANGES**:

1. Click **View** and read the Manager's comments.
2. Revise the contract document.
3. Click **Submit another contract** and upload the revised PDF.

### 5.6 Viewing Performance

Open **Performance** to see your **OVERALL PERFORMANCE SCORE**, the score for each criterion, the evaluator's remarks, and your **EVALUATION HISTORY**. Click **View** on an evaluation to see its details and the **EVALUATION TREND** chart.

---

## 6. Manager Portal

### 6.1 Menu

| Menu Item | Purpose |
|---|---|
| **Dashboard** | Overview of the review queue. |
| **My Submissions** | Review contracts and track recommendations sent to the CEO. |
| **Vendor Scoring** | Evaluate vendor performance. |
| **All Contracts** | Monitor every contract in the system. |
| **Profile & Settings** | Change your password and notification settings. |

### 6.2 Dashboard

| Card | Description |
|---|---|
| **PENDING** | Contracts awaiting your review. |
| **SUBMITTED TO CEO** | Contracts recommended and awaiting the CEO's decision. |
| **AVG VENDOR SCORE** | Average score of vendors in the queue. |
| **TOTAL REVIEWED** | Contracts you have reviewed. |

**PENDING CONTRACTS** lists contracts awaiting review. **RECENT SUBMISSIONS TO CEO** lists contracts you have forwarded.

### 6.3 Reviewing a Contract and Making a Recommendation

1. Click **My Submissions**.
2. Click **Review** beside a pending contract.
3. Click **View original PDF** to read the contract.
4. Enter your comments in **MANAGER NOTES**.
5. Select a decision:

   | Decision | Result | Notes |
   |---|---|---|
   | **Approve** | Forwarded to the CEO, recommending approval. | Optional |
   | **Reject** | Forwarded to the CEO, recommending rejection. | Required |
   | **Update** | Returned to the vendor for modification. | Required |

6. Confirm the decision.

Until the CEO decides, you may change your recommendation by clicking **Re-review**.

### 6.4 Monitoring Contracts

Open **All Contracts** to monitor every contract. The summary cards show **TOTAL CONTRACTS**, **ACTIVE**, **NET 30** (expiring within 30 days), and **EXPIRED**. Click **View** to see a contract's details and AI-extracted clauses, or **View PDF** to open the original document. Pending contracts can also be reviewed from this window.

### 6.5 Evaluating a Vendor

1. Click **Vendor Scoring**.
2. Search for and select the vendor.
3. Rate every criterion from 1 to 5 stars.
4. Set the **EVALUATION DATE** and, optionally, enter **REMARKS**.
5. Click **Save evaluation**.

The vendor is notified of the new score. See [Section 12](#12-vendor-performance-evaluation) for the criteria and scoring method.

---

## 7. CEO Panel

### 7.1 Menu

| Menu Item | Purpose |
|---|---|
| **Dashboard** | Portfolio overview. |
| **Approvals** | Give final approval to reviewed contracts. |
| **All Contracts** | Complete contract repository. |
| **Vendor Overview** | Vendor performance rankings. |
| **Reports & Analytics** | Charts and downloadable reports. |
| **Profile & Settings** | Change your password and notification settings. |

### 7.2 Dashboard

| Card | Description |
|---|---|
| **TOTAL CONTRACTS** | All contracts on record. |
| **ACTIVE CONTRACTS** | Contracts currently running. |
| **PENDING FINAL APPROVAL** | Contracts awaiting your decision. |
| **TOTAL PORTFOLIO VALUE** | Combined value of active contracts. |

The Dashboard also shows the **CONTRACT STATUS DISTRIBUTION**, **PENDING APPROVALS**, and **TOP VENDORS BY CONTRACT VALUE**.

### 7.3 Giving Final Approval

**Approvals** lists only contracts that a Manager has already reviewed.

1. Click **Review & decide** on a contract.
2. Read the **CONTRACT DETAILS**, the vendor score, and the **MANAGER RECOMMENDATION**.
3. Click **View original PDF** if needed.
4. Optionally, enter decision notes.
5. Click **✓ Approve** or **✗ Reject**.

The vendor and the reviewing Manager are notified.

> **Important:** The CEO's decision is final and cannot be changed.

### 7.4 All Contracts

Search and filter every contract by status, vendor, or keyword. Click **View** to see contract details and AI-extracted clauses. Click **Export** to download the list.

### 7.5 Vendor Overview

**VENDOR PERFORMANCE RANKINGS** lists each vendor's contracts, total value, score, and rating. Summary cards show **TOTAL VENDORS**, **HIGH PERFORMERS** (score 80 or more), and **AT RISK** (score below 60). Click **View** for a vendor's profile.

### 7.6 Reports & Analytics

This page shows key figures (total contracts, average contract value, renewal rate, average vendor score), charts, and **EXPIRING CONTRACTS — NEXT 90 DAYS**. Reports are downloaded from **AVAILABLE REPORTS** (see [Section 13](#13-reports-and-audit-log-download)).

---

## 8. System Administrator Panel

### 8.1 Menu

| Menu Item | Purpose |
|---|---|
| **Dashboard** | User and contract overview. |
| **System Logs & Audit** | View and download the audit log. |
| **User Management** | Create and manage user accounts. |
| **Profile & Settings** | Change your password and notification settings. |

### 8.2 Creating a User Account

1. Click **User Management**, then **Add User**.
2. Enter **FIRST NAME**, **LAST NAME**, and **EMAIL ADDRESS**.
3. Select the **ROLE**: Admin, Manager, CEO, or Vendor / Client.
4. Enter a temporary **PASSWORD** and the same value in **CONFIRM PASSWORD**. The password must meet the policy in Section 3.4.
5. Click **Create User**.

The system rejects the account if the passwords do not match ("Passwords do not match"), the password is too weak, or the email is already registered. Give the new user their temporary password securely and ask them to change it after signing in.

### 8.3 Editing or Disabling an Account

1. Click the edit (✎) button beside the user.
2. Change the name, **ROLE**, or **STATUS** (**Active** or **Disabled**).
3. Click **Save Changes**.

A disabled user is signed out within about 30 seconds and cannot sign in or reset their password. Accounts are disabled rather than deleted so that records are kept.

---

## 9. AI-Assisted Clause Extraction

### 9.1 How It Works

When a contract PDF is uploaded, the system reads its text and uses a trained Natural Language Processing (NLP) model to classify each part of the contract and extract its key details. This removes the need to type contract details by hand and reduces data-entry errors.

### 9.2 Details Extracted

| Category | Details |
|---|---|
| **Supplier and parties** | Vendor (supplier) name, client name, vendor address, authorised signatories |
| **Contract dates** | Effective (start) date, end date, signed date |
| **Subject-for-renewal clauses** | Renewal terms (for example, automatic or manual renewal) |
| **Penalties** | Penalty clause |
| **Financial obligations** | Contract value, currency, payment terms, and a summary of financial obligations |
| **Other** | Contract title, type and classification, scope of work, termination clause |

### 9.3 Where Results Appear

| User | Location |
|---|---|
| Client/Vendor | The upload form, **EXTRACTED BRIEF**, and **AI-EXTRACTED CLAUSES** in contract details. |
| Manager | **AI-EXTRACTED CLAUSES** in **All Contracts** › contract details. |
| CEO | **AI-EXTRACTED CLAUSES** in **All Contracts** › contract details. |

### 9.4 Getting Accurate Results

- Upload a text-based PDF, not a scanned image.
- Use the labelled format in [Appendix A](#appendix-a--recommended-contract-pdf-format).
- Write dates as `YYYY-MM-DD`.
- Always review the extracted details before submitting.

---

## 10. Payment Terms Display

The system converts payment terms written in a contract into a clear duration label.

| Written in the Contract | Displayed As |
|---|---|
| Net 15 | **15 Days** |
| Net 30 | **30 Days** |
| Net 60 | **60 Days** |
| Net 90 | **90 Days** |
| "payable within *N* days" | ***N* Days** |
| Milestone payments | **Milestone-based** |
| Upfront, advance, or due on receipt | **Upfront** |
| Monthly payments | **Monthly** |
| Quarterly payments | **Quarterly** |

Payment terms appear in the upload form, contract lists, contract details, and exported reports.

---

## 11. Contract Monitoring and Alerts

### 11.1 Automatic Status Tracking

The system checks every contract's status and end date and updates its displayed status automatically:

- more than 30 days left — **ACTIVE**;
- 30 days or fewer left — **EXPIRING / NET 30**;
- end date passed — **EXPIRED**.

### 11.2 Subject-for-Renewal Notifications

A contract is flagged as **subject for renewal** when:

- it is approved and ends within 90 days;
- it is under renegotiation; or
- it renews automatically and ends within 120 days.

The affected users receive a **Renewal: *contract title*** notification showing the number of days left and the renewal terms. Renewal-due contracts also appear in the vendor's **My Contracts** banner and in the CEO's **EXPIRING CONTRACTS — NEXT 90 DAYS** list.

### 11.3 Archiving Inactive Contracts

Expired, rejected, and terminated contracts are archived automatically. Archived contracts:

- remain available for reference;
- appear under the **ARCHIVED** tab of the notifications panel;
- can be shown with the **Expired / Archived** (vendor) or **Archived** (CEO) status filter.

### 11.4 Workflow Alerts

| Alert | Sent To | When |
|---|---|---|
| **New contract submitted** | Managers, System Administrators | A vendor uploads a contract. |
| **Contract submitted** | Vendor | The upload is received. |
| **Manager reviewed your contract** | Vendor | A Manager forwards the contract to the CEO. |
| **Changes requested** | Vendor | A Manager returns the contract for modification. |
| **Contract resubmitted** | Managers | A vendor resubmits a revised contract. |
| **Ready for CEO decision** | CEO | A Manager has made a recommendation. |
| **CEO decision: APPROVED / REJECTED** | Vendor | The CEO makes the final decision. |
| **CEO decided on a contract you reviewed** | Reviewing Manager | The CEO makes the final decision. |
| **Vendor score recorded** | Vendor | A performance evaluation is saved. |
| **Renewal: *contract title*** | Vendor, Manager, CEO | A contract becomes subject for renewal. |

---

## 12. Vendor Performance Evaluation

### 12.1 Evaluation Criteria

| Criterion | Weight | Description |
|---|---|---|
| Quality of goods / services | 20% | Accuracy, workmanship, and whether deliverables meet the contracted specification. |
| Delivery / timeliness | 20% | On-time performance against agreed milestones and delivery dates. |
| Compliance & documentation | 20% | Adherence to contract terms, permits, and complete supporting documents. |
| Pricing competitiveness | 15% | Value for money relative to scope, quality, and market rates. |
| Communication & responsiveness | 15% | Clarity, availability, and how quickly issues are resolved. |
| Innovation / value-add | 10% | Improvements or extras that reduce cost or risk. |

### 12.2 How the Score Is Calculated

1. The evaluator rates each criterion from 1 to 5 stars. All criteria must be rated.
2. Each rating is converted to a score out of 100:

   | Stars | 1 | 2 | 3 | 4 | 5 |
   |---|---|---|---|---|---|
   | Score | 0 | 25 | 50 | 75 | 100 |

3. The overall score is the weighted average: **Σ (score × weight) ÷ Σ weights**.
4. The overall score is shown as stars: **1 + 4 × (score ÷ 100)**. For example, a score of 85 is shown as 4.4 stars.

The score is calculated by the system, so it cannot be miscalculated by hand. The most recent evaluation is the vendor's current score.

### 12.3 Rating Bands

| Overall Score | Stars | Rating |
|---|---|---|
| 90 – 100 | 4.6 – 5.0 | **Excellent** |
| 80 – 89 | 4.2 – 4.5 | **Good** |
| 60 – 79 | 3.4 – 4.1 | **Satisfactory** |
| Below 60 | 1.0 – 3.3 | **Needs improvement** |

### 12.4 Who Can Evaluate

| Role | Create | Edit | Delete |
|---|---|---|---|
| Manager | Yes | Own evaluations | Own evaluations |
| CEO | Yes | Any evaluation | Own evaluations |
| System Administrator | Yes | Any evaluation | Any evaluation |
| Client/Vendor | No | No | No (view only) |

### 12.5 Performance Reports

- Vendors see their scores and history under **Performance**.
- The CEO sees all vendor scores under **Vendor Overview** and can download the **Vendor Performance Report** (Section 13).

---

## 13. Reports and Audit Log Download

### 13.1 Contract Lists

| User | Location | File |
|---|---|---|
| Client/Vendor | My Contracts › **Export** | `my-contracts.csv` |
| Manager | All Contracts › **Export CSV** | `manager-contracts.csv` |
| CEO | All Contracts › **Export** | `ceo-all-contracts.csv` |

These lists include the contract number, title, vendor, value, payment terms, start and end dates, and status.

### 13.2 CEO Reports

On **Reports & Analytics**, click **Download** beside a report.

| Report | Contents |
|---|---|
| **Contract Status Summary** | Every contract with its status, value, payment terms, and dates. |
| **Vendor Performance Report** | Each vendor's star rating, contract count, total value, and recommendations. |
| **Expiration & Renewal Tracker** | Contracts subject for renewal, with days until end and renewal terms. |
| **Executive Summary Report** | Total, active, pending, expiring, and archived contracts, average vendor rating, and renewal rate. |

### 13.3 Downloading the Audit Log (System Administrator)

The audit log records sign-ins, account changes, contract submissions, Manager reviews, CEO decisions, archiving, and vendor evaluations.

1. Click **System Logs & Audit**.
2. Optionally, filter the on-screen list with **ALL**, **CRITICAL**, **WARNING**, or **INFO**.
3. Click **Download audit log**.

The file `contrack-audit-log.csv` contains up to 500 recent entries, each with the event type, details, user, network address, and date and time.

All files are in CSV format and open in Microsoft Excel or Google Sheets.

> **Note:** Reports and logs contain confidential company information. Handle them according to JB Calderon Events Management's data policies.

---

## 14. Troubleshooting

| Problem or Message | What to Do |
|---|---|
| The sign-in page reloads | The email or password is wrong. Try again or reset your password. |
| "Please wait — Try again in *N*s" | Too many failed attempts. Wait until the countdown ends. |
| "Passwords do not match." | Type the same password in both fields. |
| "Email is already registered" | Sign in, or reset the password for that email. |
| No verification code received | Check your spam folder, then click **Resend** after 30 seconds. |
| "That code has expired. Request a new one." | Codes last 10 minutes. Request a new code. |
| "You already used this password before. Choose a new one." | Choose a password you have not used before. |
| "Only PDF files are accepted…" | Save the contract as a PDF and upload it again. |
| "PDF is too large…" | Reduce the file to under 25 MB. |
| "DUPLICATE CONTRACT BLOCKED" | This contract was already submitted. Change it before resubmitting. |
| "No clauses could be read from this PDF." | Use a text-based PDF, not a scanned image. |
| "Add manager notes before rejecting or requesting a modification." | Enter Manager notes first. |
| "A manager must review this contract before a CEO decision." | Wait for the Manager's recommendation. |
| The PDF does not open | Allow pop-ups for the ConTrack AI site. |
| Account disabled | Contact the System Administrator. |

---

## 15. Glossary

| Term | Definition |
|---|---|
| **Archived** | An inactive (expired, rejected, or terminated) contract kept for reference. |
| **Audit log** | A record of important user and system activity. |
| **Contract code** | A unique contract identifier, such as `CT-1001`. |
| **CSV** | A spreadsheet-compatible file format. |
| **Financial obligations** | The payment-related duties stated in a contract, such as value, payment terms, and penalties. |
| **Net 30 (payment)** | Payment due within 30 days; displayed as **30 Days**. |
| **NET 30 (status)** | An approved contract expiring within 30 days. |
| **NLP** | Natural Language Processing; the AI technique used to read contract text. |
| **Recommendation** | A Manager's advice to the CEO to approve or reject a contract. |
| **Risk level** | A LOW, MEDIUM, or HIGH label based mainly on contract value (₱1 million and ₱5 million thresholds). |
| **Subject for renewal** | A contract approaching its end date that may need renewal. |

---

## Appendix A — Recommended Contract PDF Format

Use clearly labelled sections in this order for the most accurate extraction.

| No. | Section | Content |
|---|---|---|
| 1 | **Contract Header** | Contract Title, Contract Type, Effective Date, End Date |
| 2 | **Parties** | Client Name, Vendor Name, Vendor Address |
| 3 | **Scope of Work** | Description of goods, services, or leased property |
| 4 | **Financial Terms** | Contract Value, Currency (PHP, USD, or EUR), Payment Terms, Penalty Clause |
| 5 | **Renewal and Termination** | Renewal Terms, Termination Clause |
| 6 | **Signatures** | Client and Vendor Authorized Signatories, Signed Date |

**Example:**

```
CONTRACT HEADER
Contract Title: Event Catering Services Agreement
Contract Type: Service Agreement
Effective Date: 2026-01-01
End Date: 2026-12-31

PARTIES
Client Name: JB Calderon Events Management
Vendor Name: XYZ Catering Services Inc.
Vendor Address: 123 Rizal Avenue, Makati City

SCOPE OF WORK
The Vendor shall provide catering services for all scheduled events...

FINANCIAL TERMS
Contract Value: 1,500,000.00
Currency: PHP
Payment Terms: Net 30
Penalty Clause: A penalty of 5% of the monthly fee applies for each late delivery.

RENEWAL AND TERMINATION
Renewal Terms: Auto-renew (12 months)
Termination Clause: Either party may terminate with 30 days' written notice.

SIGNATURES
Client Authorized Signatory: Juan Dela Cruz
Vendor Authorized Signatory: Maria Santos
Signed Date: 2025-12-15
```

---

*End of Document — ConTrack AI User Manual, Version 1.1*
