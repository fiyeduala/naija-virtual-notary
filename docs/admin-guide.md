# Naija Virtual Notary — Administrator's Guide

*How to run the platform: approving notaries, watching jobs through, handling money, and stepping in when something stalls.*

---

## 1. Two hats

As an administrator you have two distinct roles, and it helps to keep them separate in your head.

**Running the platform.** The admin panel at **/admin-panel**. Approving notaries, recording payments, sending payouts, publishing articles, changing settings.

**Being a notary yourself.** The platform has its own notary profile — the *system notary*. It appears in the marketplace like any other, takes bookings at its own prices, and is also the safety net when a partner does not respond. You work those jobs from the ordinary notary desk at **/notary**, not from the admin panel.

The rest of this guide is organised the way the panel is.

---

## 2. Users & notaries

### Users

Every account on the platform — clients, notaries and administrators alike. Use it to find someone, check their role and status, and see their history.

### Notaries

The heart of your day-to-day work.

**Reviewing an application.** A new partner appears here once they have confirmed their email and paid the onboarding fee. Open their profile to see what they submitted, view their uploaded credentials, then **approve** or **reject**. Rejecting with a clear reason is far more useful than rejecting silently — most rejections are a missing document rather than an unsuitable applicant.

**On an approved notary's profile you can:**

| Action | What it does |
|---|---|
| **Edit details** | Name, email, phone, entity type, organisation, licence reference, SCN, year of oath |
| **Manage notarial assets** | Upload or replace their signature, stamp and seal |
| **Payout account** | View, set or re-verify their bank details |
| **Edit commission** | Override the platform default for this notary alone |
| **Edit service pricing** | Change what they charge, and for what |
| **List / unlist** | Whether they appear in the marketplace |

A notary can only be listed once they hold **all three** sealing assets — signature, stamp *and* seal, each with an actual file behind it. Two out of three is not enough, and the seal is the one most often missing.

> **For partners migrated from the old website:** they will generally not complete their own profile. Collect their details, then use **Edit details** and **Manage notarial assets** to fill everything in on their behalf, add their bank account under **Payout account**, set their pricing, and switch on their listing. From their point of view they simply sign in one day and find themselves live.

---

## 3. Requests & sessions

Every notarisation request on the platform, at whatever stage.

A request moves through: **Draft → Awaiting payment → Awaiting notary → Accepted → Scheduled → In verification → Being notarised → Completed.** It can also end as **Cancelled** or **Refunded**.

Nothing reaches a notary before **Awaiting notary**, because that is the status a request reaches the moment payment clears. This is the platform's central rule: no work is ever requested on an unpaid job.

From here you can inspect any request, see its documents, read its message thread, and view the finished notarised document.

**Watch for requests sitting in "Awaiting notary".** That is the queue where a client is waiting. The response window in Platform settings decides when one is flagged as overdue.

### Taking over a stalled request

When a partner has not answered, open the request from your **notary desk** at /notary and choose **Take over**. Three things follow, and they are deliberate:

1. The document is sealed with **the assigned notary's** signature, stamp and seal — not yours. They remain the notary of record.
2. **Their** price applies, and **their** share of the fee is still theirs.
3. Your take on that job is the platform's commission, and nothing more.

Covering a partner's job moves the work, not the money and not the attribution. Nothing is reassigned automatically when the window elapses — the clock only tells you where to look.

A request booked with a partner cannot be *declined* from the admin desk, only taken over. There is nowhere further to send it; you are the last resort.

---

## 4. Payments & payouts

### Payments

Every payment on the platform: onboarding fees and notarisation fees, successful and failed.

**Recording an offline payment.** When a client pays by bank transfer into the company account, or in cash, record it here. This is not merely bookkeeping — recording a payment triggers exactly what a card payment triggers: the notary is notified, the response clock starts, the audit entry is written. A client who paid into your bank account gets identical treatment to one who paid by card.

### Payouts

What each notary is owed, and what has been sent.

Two ways to settle, controlled by a switch in Platform settings:

**Offline (the default).** You transfer the money yourself and record how you paid it. Safe, and the right setting while you are still finding your feet.

**Through Paystack.** The **Send** button debits your Paystack balance directly. This requires Transfers to be enabled on your Paystack account and an actual balance to pay from — if your settlement schedule sweeps everything to your bank each day, there will be nothing there to pay out with.

Either way, what each notary is owed is tracked identically. The switch only decides whether the platform moves the money for you.

If a transfer fails, the amount is released back into *owed*. It is never silently written off.

---

## 5. Operations — hard-copy fulfilment

Where a client asked for a physical copy posted to them. Track it from printing through to dispatch so nobody is left waiting on a document they believe is in the post.

---

## 6. Disputes & audit

### Audit log

The complete record of who did what: sign-ins, approvals, payments, acceptances, take-overs, identity verifications, every finalised document, every settings change — each with a time, a user and the network address it came from.

This is the first place to look when a client or a notary disputes something. Do not reason from memory when the log is right there.

**One note now that the site runs behind Cloudflare:** the addresses recorded are the visitor's real address, because the application is configured to trust Cloudflare's forwarded headers. If the log ever fills with addresses that all look alike, that configuration has drifted and should be looked at.

---

## 7. Content & settings

### Blog

Write, schedule and publish articles.

Formatting is preserved when you save; scripts, embeds and form fields are stripped. That is a security measure and not a bug — the article body is the only place on the site where raw HTML is rendered, so it is deliberately fenced in.

Changing the web address of an article **after** publication breaks every existing link to it. The address updates itself freely while a piece is still a draft, and stops doing so once it is live.

### Email

Compose an announcement and send it to a chosen group.

Sends are **resumable**. The platform keeps a per-recipient ledger, so a send interrupted halfway can be picked up where it stopped, and nobody receives the same message twice.

Every announcement carries an unsubscribe link, which works without the recipient signing in. This is intentional: requiring a login in order to stop receiving email is the fastest way to be reported as spam.

Pace your sending. Shared hosting typically caps outgoing mail at a few hundred an hour and starts rejecting past that — 30 a minute is a safe default.

### Quote requests

Enquiries from the public site that have not yet become bookings.

### Platform settings

| Setting | What it controls |
|---|---|
| **Onboarding fee** | The one-time fee new partners pay, in naira |
| **Default commission rate** | The platform's percentage, applied to new notaries; can be overridden per notary |
| **Response window** | How many minutes before a request is flagged overdue on your desk. A prompt, not a lock — nothing is reassigned automatically |
| **Paystack transfers** | Off: you settle payouts yourself. On: the Send button moves real money and it cannot be recalled |
| **Logo & icon** | Used across the public site, both dashboards and this panel. Blank falls back to the site name in text |
| **Emails per minute** | Bulk sending pace. 0 means as fast as possible, which is right for a dedicated mail service and wrong for shared hosting |
| **Live chat** | Paste your whole Tawk.to embed code and both IDs are extracted for you. Blank switches chat off completely — nothing is loaded and no request is made to Tawk. It never appears in this panel or on a notarisation screen |
| **System notary pricing** | The services and prices the platform itself offers. Clients see these in the marketplace alongside every partner |

---

## 8. Your dashboard

The panel's front page carries: an overview of the platform, the notary desk queue, requests over time, revenue over time, the mix of request statuses, your busiest days, and your most active notaries.

The **notary desk queue** is the one to check first each morning. It shows what is waiting on a human.

---

## 9. A working routine

**Each morning**

1. Notary desk queue — anything waiting on a decision?
2. Requests in *Awaiting notary* — anything overdue? Take it over rather than chasing.
3. New notary applications — approve, reject with a reason, or ask for what is missing.

**Each week**

4. Payouts — settle what is owed and record how you paid it.
5. Payments — reconcile anything that came in by bank transfer.
6. Hard-copy fulfilment — anything printed but not posted?

**Each month**

7. Revenue and requests trends — where is the work actually coming from?
8. Audit log — a quick scan for anything that does not look like ordinary use.
9. Notary details — are prices, names and bank accounts still current?

---

## 10. When something goes wrong

**A client paid but nothing happened.** Check Payments for the transaction. If Paystack shows it and the platform does not, the webhook did not arrive — Paystack's own dashboard has a delivery log, and a non-200 response there is the answer. You can record the payment manually in the meantime; the client is not held up by it.

**A notary cannot go live.** Almost always a missing seal. Open their profile and check that all three assets have files behind them, not just rows.

**A payout failed.** The amount returns to *owed*. Check the bank details on the profile, re-verify the account, and try again.

**A client cannot sign in.** Accounts brought over from the old website keep their old password. If they never had an account there, they need to register. Password reset works normally either way.

**A document sealed wrongly.** The original upload is still held. Do not have the client start over before speaking to an administrator with access to it.

---

## 11. Things worth knowing before someone asks

- **A request only reaches a notary after payment.** There is no path around this, and it is the reason no partner is ever asked to work on trust.
- **The response window is a prompt, not a transfer.** Nothing moves on its own when it elapses.
- **A covered job is still the assigned notary's job** — their seal, their price, their share.
- **Verification calls are not recorded.** What is kept is the record that verification happened: who, when, by which method, from where.
- **Signatures, stamps and seals are private files.** They are never at a public web address and never attached to an email.
- **The audit log is not editable.** That is the point of it.
