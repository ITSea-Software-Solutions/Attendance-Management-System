# TrueCrew — Formal Sales Process (Local B2B, India)

*The complete path from first approach to paid invoice, with the document
used at every step. Documents referenced here are in this `sales/` folder —
fill the [bracketed] placeholders before use. Confirm GST/TDS specifics
with your CA once; the flow itself is standard Indian SME practice.*

---

## Step 0 — Be ready before the first meeting (once)

- [ ] Business identity: [legal name / proprietorship or Pvt Ltd], PAN,
      current account in the business name, UPI on that account.
- [ ] **GST decision (ask your CA once):** if registered → collect GSTIN,
      bill 18% GST under SAC [997331 — licensing of software / confirm];
      if not yet registered (services turnover under threshold) → bill
      WITHOUT GST and the invoice must say so. Templates handle both.
- [ ] Prices decided (`PRICE_*_INR`) and flyer placeholders filled.
- [ ] Demo sandbox rehearsed: your 5-minute gate demo (thumb → verified
      card → Live Board on screen) — the demo sells; documents close.

## Step 1 — First approach

**Document: `intro-letter.html`** (print on letterhead or send as PDF/WhatsApp)

- Walk in / get introduced to the decision maker (owner / plant head / HR
  head). Hand over the **flyer** + intro letter. One goal only: fix a
  **30-minute demo slot at their gate**. Do not quote prices yet.
- Note their basics: company name, contact person, number of workers,
  number of gates, labour contractors (vendors) they use.

## Step 2 — Demo (at their site if possible)

- Bring: Android phone with TrueCrew + HU20 scanner + the Live Board open
  on a laptop/TV. Register one of THEIR supervisors as a worker live —
  Aadhaar PDF → thumb → verified card with photo. That moment closes deals.
- Leave behind: flyer + the client one-pager (`/docs/client-guide.html`
  printed). Agree the next step: "I'll send a formal proposal by [date]."

## Step 3 — Formal proposal / quotation

**Document: `proposal.html`** — the centrepiece. Contains: cover letter,
what TrueCrew does for THEM (use their numbers: workers, gates, vendors),
scope of supply, hardware they buy (~₹3,500/scanner/gate), pricing table
(plan × months, GST line), onboarding plan (their 200 workers imported
day one), payment terms, validity (15 days), and an **acceptance
signature block**.

- Send as PDF (print → save as PDF) by email AND WhatsApp; follow up by
  phone in 2–3 days.
- Negotiation happens here. Any changed price → reissue the proposal with
  a new number (TC-PROP-2026-002-R1). Never negotiate the invoice.

## Step 4 — Order confirmation (the legal moment)

**Document: `work-order.html`** — one page the client signs and stamps.

Two equally valid routes — take whichever the client is comfortable with:
1. **They issue a Purchase Order** on their letterhead referencing your
   proposal number → you countersign a copy; or
2. **They sign your Work Order** (acceptance of the proposal) — it
   references the proposal number and binds the Terms of Service and
   Privacy Policy by URL.

Either way you now have: WHAT (scope), HOW MUCH (price + GST), WHEN
(start date), and SIGNATURES. For sales of this size that signed page +
your published ToS is the contract — no stamp paper needed (have your
lawyer confirm the ToS once; already drafted in `frontend/public/legal/`).

## Step 5 — Advance payment → Proforma Invoice

**Document: `proforma-invoice.html`**

- Standard terms: **50% advance with the work order** (or first
  quarter/year upfront for subscription-only deals), balance on go-live.
- A **proforma invoice** is the formal "please pay this advance" document —
  it is NOT a tax invoice and creates no GST liability yet. Issue it the
  same day the work order is signed.
- Payment lands by bank transfer/UPI → record it (your own Billing flow
  when they're on the platform, or just the reference for now).

## Step 6 — Onboarding & acceptance

- Deliver per the onboarding playbook (GO_LIVE_PLAN Phase 5): create the
  company, gates, users; **import their existing workers from Excel**
  (Aadhaar-pending is fine — verify later); enroll fingerprints; train the
  guard (10 min) and HR (15 min); Live Board on their TV.
- **Document: acceptance section on the work order** — after 2–3 smooth
  days, get the "system delivered and working" line signed/WhatsApp-
  confirmed. This protects you and triggers the balance payment.

## Step 7 — Tax invoice

**Document: `tax-invoice.html`**

- Issue the **Tax Invoice** at/after go-live for the full contract value
  (advance shown as adjusted). Under GST, a services invoice must be
  issued **within 30 days of supply** — invoice at go-live, always.
- GST-registered: serial-numbered invoice, your GSTIN, client GSTIN (they
  need it for input credit — B2B clients WILL ask), SAC code, 18% GST
  (CGST 9% + SGST 9% same-state; IGST 18% inter-state).
- Not GST-registered: same invoice without the tax lines, with the
  required declaration (in the template).
- **Expect TDS:** companies commonly deduct **10% u/s 194J** on software/
  technical services and pay you 90% — that is legal; the 10% appears in
  your Form 26AS as tax already paid on your behalf. Don't chase it as a
  short payment. (CA confirms applicability.)

## Step 8 — After the sale (recurring)

- Renewals: the platform's licence expiry + reminders drive this; each
  renewal = fresh proforma → payment → tax invoice. Same for upgrades.
- Keep one folder per client: proposal, PO/work order, proforma, payment
  proof, tax invoices, acceptance. That folder is your audit trail.
- Support terms you promised in the proposal are the ones in the ToS —
  don't invent side promises on WhatsApp.

---

## The one-line map

**Flyer + intro letter → gate demo → numbered proposal → signed work
order/PO → proforma → 50% advance → onboard + acceptance → tax invoice →
balance → renewals via the platform.**
