# Recruitment — hiring for the four remote roles

Naija Virtual Notary recruits for four fully remote roles: Customer Service,
Marketing Executive, Digital Marketing and Human Resources Manager. This
document covers the module that runs that: what it does, what it needs
configured before anybody applies, how to work it, and how to turn it off.

Two things to read first, because the rest assumes them:

- **Nothing is live yet.** The feature switch is off, the four vacancies are
  drafts, mail goes to the log and no candidate email has ever left this
  server. That is the shipped state and it is deliberate.
- **Missing configuration is tracked separately from broken functionality.**
  `Recruitment → Readiness` is the one list, and it is the only place that
  decides whether you are ready. Section *Launch checklist* below explains it.

---

## 1. What was built

### The candidate's side

A public careers section, an application form, and a passwordless area where a
candidate can see their own application and nothing else.

| Route name | Method | Path | What it is |
| --- | --- | --- | --- |
| `careers.index` | GET | `/careers` | The openings. Published and still accepting only. |
| `careers.show` | GET | `/careers/{vacancy}` | One opening, by slug. |
| `careers.privacy` | GET | `/careers-privacy` | The recruitment privacy notice, versioned. |
| `careers.apply` | GET | `/careers/{vacancy}/apply` | The application form. |
| `careers.apply.store` | POST | `/careers/{vacancy}/apply` | Throttled. Creates the application. |
| `careers.applied` | GET | `/careers/{vacancy}/applied/{reference}` | The receipt, with the reference. |
| `candidate.login` | GET | `/candidate/sign-in` | Ask for a sign-in link by email. |
| `candidate.login.request` | POST | `/candidate/sign-in` | Sends it. Answers the same way whether or not the address is known. |
| `candidate.login.confirm` | GET | `/candidate/sign-in/{token}` | A page with a button. **Consumes nothing.** |
| `candidate.login.redeem` | POST | `/candidate/sign-in/{token}` | Consumes the token. One use. |
| `candidate.home` | GET | `/candidate` | Their applications. |
| `candidate.application` | GET | `/candidate/applications/{application}` | One application, candidate-safe statuses only. |
| `candidate.withdraw` | POST | `…/withdraw` | Withdraws it, after confirmation. |
| `candidate.assessment` | GET | `/candidate/assessments/{assignment}` | The exercise, as snapshotted for them. |
| `candidate.assessment.draft` | POST | `…/draft` | Save and come back. Nothing validated. |
| `candidate.assessment.submit` | POST | `…` | Final. Deadline enforced here. |
| `candidate.interview.acknowledge` | POST | `/candidate/interviews/{interview}/acknowledge` | "I will be there." |
| `candidate.interview.reschedule` | POST | `…/reschedule` | Asks for a different time. A person answers. |
| `candidate.offer` | GET | `/candidate/offers/{offer}` | The offer as approved, nothing invented. |
| `candidate.offer.respond` | POST | `…/respond` | Accept or decline. |
| `candidate.checks.answer` | POST | `/candidate/checks/{item}/answer` | A pre-employment checklist answer. |
| `candidate.checks.upload` | POST | `…/upload` | An identity or reference document. Late stage only. |
| `candidate.document` | GET | `/candidate/documents/{document}` | Their own file, streamed, authorization checked. |
| `candidate.logout` | POST | `/candidate/sign-out` | |

The sign-in link is split into a GET that shows a button and a POST that
redeems it, so a mail scanner prefetching the URL cannot burn the token. Tokens
are high-entropy, stored hashed, short-lived (30 minutes by default), one-use
and revocable. A candidate session runs on its own `candidate` guard and reaches
no client page and no admin page; there is a test for each direction.

### The staff side

Inside the existing Filament panel at `/admin-panel`, under a **Recruitment**
navigation group:

- **Vacancies** — the four drafts, their descriptions, their assessment, their
  reviewers, and a publication panel that lists exactly what is still missing.
- **Applications** — the pipeline. One detail page per candidate carrying the
  answers, the CV, the assessment, reviewer scores, interviews, private notes,
  the stage history and the message log.
- **Assessments** — the exercise templates and their rubrics.
- **Recruitment settings** — the eleven email templates, the privacy notice and
  the retention policy.
- **Readiness** — the launch checklist, also available as
  `php artisan nvn:recruitment-readiness`.
- A **Recruitment overview** widget on the dashboard: counts by stage, overdue
  assessments, upcoming interviews, failed emails.

Two routes sit outside Filament: `admin.recruitment.document` streams a
candidate's file to authorized staff, and that is the only way a CV or an
identity document can be read.

---

## 2. Who can do what

Recruitment permissions are their own narrow set. They are granted by setting
`users.recruitment_role`, which is **not** in `User::$fillable` — it is set
deliberately, in the panel, by an administrator.

| | Reviewer | Coordinator | HR admin | Lead |
| --- | :-: | :-: | :-: | :-: |
| Read the applications assigned to them | ● | ● | ● | ● |
| Read every application | | ● | ● | ● |
| Move a candidate between stages | | ● | ● | ● |
| Schedule an interview | | ● | ● | ● |
| Approve and send an offer | | | ● | ● |
| Open an identity or guarantor document | | | ● | ● |
| Export to CSV | | | ● | ● |
| Create or publish a vacancy | | | | ● |
| Edit the retention policy and the notice | | | | ● |

Four facts about this that are enforced on the server and covered by tests:

- A recruitment role is **not** an administrator. A lead can reach the
  applications and the vacancies and gets `false` from `PaymentResource`,
  `PayoutResource`, `UserResource` and `NotarizationRequestResource`.
- A client or a notary with no recruitment role holds **nothing** — not one of
  the nine abilities.
- Identity and guarantor paperwork needs `sensitive.view` **on top of** review
  access, so the coordinator running the pipeline cannot open a passport. The
  document is marked sensitive by the database, not by the uploader.
- The feature switch closes the module for **everybody, administrators
  included**. A rollback switch that every admin walks through is not a
  rollback switch.

An administrator seeing a control is never the authorization. Every check above
is asked of the ability map, the query scope or the document's own
`readableBy()`; the tests type the URL a hidden button would have pointed at.

---

## 3. What a candidate is and is not asked

The initial application asks for: name, email, phone, country, city, time zone,
a CV (PDF or DOCX, 5 MB by default), motivation, experience, optional
LinkedIn/portfolio/work-sample links, expected pay with its currency and
period, notice period, whether they can work the stated hours, their
computer/internet/backup arrangements, the role's own screening questions, an
acknowledgment of the privacy notice — whose **version and timestamp are
recorded** — a separate, unticked consent to be contacted about future
openings, and optionally how they heard about us.

It does **not** ask for government ID, bank details, guarantor paperwork, date
of birth, marital status, religion or a photograph. Those belong at the offer
stage, if at all, and the form has no field for them.

Duplicates are prevented by a unique index on the normalized email plus the
vacancy, handled race-safely. Normalization lower-cases and trims; it does
**not** strip dots or `+` aliases, because at most providers those are different
people. The form never reveals whether an address has already applied.

A reference like `NVN-CS-7Q4K2` is generated and shown immediately. Saving the
application does not depend on mail working: the acknowledgment is queued, and a
mail outage loses the email, not the application.

Source tracking keeps an allowlisted, length-limited set of UTM values. No
personal information goes in a public tracking URL.

---

## 4. The stages

```
Applied → Initial Review → Assessment Invited → Assessment Submitted
        → Interview → Offer / Checks → Hired
            Rejected ⟂     Withdrawn ⟂
```

Two things are tracked as substates rather than stages, because they are facts
about the work and not verdicts about the person: an assessment being
**overdue**, and an offer being **accepted or declined**.

The state machine is enforced in `ApplicationStageService::move()`, inside a
transaction with the row locked. Every transition writes who moved it, when,
from what, to what, and a reason where one is required. Prerequisites are real:
you cannot reach *Assessment Invited* without an open assignment, *Assessment
Submitted* without a submission, or *Hired* without an accepted offer and no
outstanding required checks. An authorized skip or a reopen is possible and
records its reason.

A missed deadline shuts the form and does nothing else. The stage does not move
and nobody is rejected; a reviewer extending the deadline is what admits late
work, and that leaves a row saying who allowed it.

**No AI ranking, automated rejection, personality inference, facial or voice
analysis, or demographic scoring exists anywhere in this module.** Reviewer
scores are visible, and incomplete scoring is visible as incomplete, but a score
informs a person — it never decides.

---

## 5. Migrations

Four additive migrations. Nothing existing is dropped, renamed or re-typed.

| Migration | What it adds |
| --- | --- |
| `…_000001_create_recruitment_vacancy_tables` | `assessment_templates`, `vacancies`, `vacancy_reviewers` |
| `…_000002_create_recruitment_application_tables` | `candidates`, `candidate_login_tokens`, `job_applications`, `application_answers`, `application_documents`, `application_stage_events`, `application_notes` |
| `…_000003_create_recruitment_assessment_tables` | `assessment_assignments`, `assessment_responses`, `reviewer_scores`, `reviewer_reviews`, `interviews`, `offers`, `onboarding_items`, `reference_contacts`, `recruitment_messages` |
| `…_000004_add_recruitment_role_to_users` | `users.recruitment_role`, nullable and indexed |

To deploy:

```bash
cd ~/nvn/nvn-app
php artisan migrate --force
php artisan db:seed --class=RecruitmentSeeder --force   # optional, see below
php artisan config:clear
php artisan view:cache
```

`RecruitmentSeeder` writes the four **draft** vacancies and the four assessment
exercises. It is idempotent — every row goes in with `firstOrCreate` on a
natural key and it never updates or deletes anything — so running it twice is
safe and running it on a database that already has your edits will not overwrite
them. It is not a destructive seeder and it touches nothing outside the
recruitment tables.

The drafts deliberately leave employment type, eligible countries, working hours
and time zone, closing date, pay disclosure and headcount **blank**, so each one
fails its own publication check until you fill them in. Nothing here invents a
salary, a headcount, an employment contract or a reporting line.

Status columns are `string(20)` with class constants rather than MySQL `enum`s,
because a value outside an `enum` truncates with warning 1265 on MySQL and saves
cleanly on SQLite — a difference that hides bugs. Token and reference hashes are
`char(64)`, because MySQL 8 refuses a unique index on `TEXT` (error 1170).

---

## 6. Setup

### Environment

Every key has a working default; nothing below is required to make the module
run, and none of it holds a secret. Add only what you want to change.

| Key | Default | What it does |
| --- | --- | --- |
| `NVN_RECRUITMENT_ENABLED` | `false` | **The feature switch.** Off, every careers route 404s and the panel group is gone. |
| `NVN_RECRUITMENT_TZ` | `Africa/Lagos` | The working clock. Everything is stored in UTC and displayed local. |
| `NVN_ASSESSMENT_DUE_DAYS` | `3` | Working days from the invitation going out to the deadline. |
| `NVN_ASSESSMENT_DUE_TIME` | `17:00` | The hour the deadline falls on. |
| `NVN_CV_MAX_KB` | `5120` | CV upload limit. |
| `NVN_ONBOARDING_MAX_KB` | `8192` | Identity/reference upload limit. |
| `NVN_RECRUITMENT_SCANNER` | `false` | Turn on malware scanning. |
| `NVN_RECRUITMENT_SCANNER_CMD` | `clamscan --no-summary` | The scanner to shell out to. |
| `NVN_CANDIDATE_TOKEN_MINUTES` | `30` | Sign-in link lifetime. |
| `NVN_CANDIDATE_SESSION_MINUTES` | `120` | Candidate session idle lifetime. |
| `NVN_RECRUITMENT_RETENTION_MONTHS` | *unset* | How long records are kept. Unset means nothing is ever deleted automatically. |
| `NVN_RECRUITMENT_AUTO_PURGE` | `false` | Whether the nightly sweep actually deletes. |
| `NVN_RECRUITMENT_HIRED_RETENTION_MONTHS` | *unset* | A longer period for people who were hired. |
| `NVN_RECRUITMENT_PRIVACY_VERSION` | `2026-10-13.1` | Stamped onto every consent record. |
| `NVN_RECRUITMENT_REPLY_TO` | *unset* | Where a candidate's reply goes. Also settable in the panel. |

Working days (Mon–Fri), working hours (09:00–17:00), public holidays, the
invitation delay options (0, 1, 2 or 3 working days) and the number of
professional references live in `config/recruitment.php` and are edited there.

### Storage

Every applicant file goes to the **`private`** disk — `storage/app/private`,
which has no symlink into the document root. There is nothing to add: the disk
already exists in `config/filesystems.php`. Do not create a public symlink to
it.

Files are stored under a randomized name, with the server deciding the MIME type
rather than trusting the browser. Executables and HTML are rejected. Downloads
go through an authorization-checked route that streams with
`Content-Type: application/octet-stream`, `X-Content-Type-Options: nosniff`,
`Cache-Control: no-store` and a `default-src 'none'; sandbox` CSP. **No email
ever carries a document URL or a document attachment.**

Malware scanning is off by default and is honest about it: an unscanned file is
recorded as `unscanned`, never as clean. If scanning is switched on and then
fails, the file is quarantined and the upload fails closed.

### Mail

`config/mail.php` defaults to the `log` transport, and it has stayed there for
the whole of this build — **no sample notification has been sent to any real
address.** Keep it there until you are deliberately going live.

The eleven templates (acknowledgment, assessment invitation, assessment
reminder, assessment received, interview invitation, interview rescheduled,
offer, pre-employment checks, unsuccessful, withdrawal confirmed, sign-in link)
are editable and previewable in **Recruitment settings**. An email body is
**plain text**: the layout escapes every paragraph and supplies the branding, so
whoever writes the words cannot change what the email is able to do.
Placeholders are substituted by string replacement and never compiled as Blade.

### Queue and scheduler

Mail is never sent inline. Every candidate email is written to
`recruitment_messages` as a **transactional outbox** row with a unique event key,
dispatched only after the database transaction commits, and sent by a worker.
That is what makes a retry safe: the key is what stops a second attempt becoming
a second email.

The queue connection is the existing `database` driver. No Redis, no root
access and no long-running supervisor is assumed, because the host is shared
cPanel.

Three scheduled commands, already registered in `routes/console.php`:

| Command | When | Overlap guard |
| --- | --- | --- |
| `nvn:recruitment-dispatch` | every minute | 10 minutes |
| `nvn:recruitment-sweep` | 07:30 Africa/Lagos | 30 minutes |
| `nvn:recruitment-retention` | 02:00 Africa/Lagos | 60 minutes |

`dispatch` sends what is due and bounded — it processes a limited batch and
exits, so it cannot be the job that never ends. `sweep` flags overdue
assessments and queues reminders. `retention` does nothing at all unless a
retention period has been set and automatic purge turned on.

This host caps cron at one run every five minutes, so the Laravel scheduler
entry is the usual single line (replace the placeholders with your own paths):

```
*/5 * * * * cd /home/<ACCOUNT>/nvn/nvn-app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

A five-minute cron means `schedule:run` fires every five minutes, so the
every-minute dispatch effectively runs every five. That is fine for recruitment
email; nothing here is time-critical to the minute. If you would rather not wait,
`php artisan nvn:recruitment-dispatch` by hand sends the queue immediately.

Invitation timing is deliberate, never randomized: an administrator approves the
send, and the default is to go **immediately after that approval**. A delay of
one, two or three working days can be chosen per invitation. Before any delayed
send actually goes out, the stage, the policy, the withdrawal state and the
deadline are re-checked — so a candidate who withdrew, was rejected, was hired,
or whose interview was rescheduled never receives the superseded message.

---

## 7. Launch checklist

`Recruitment → Readiness`, or `php artisan nvn:recruitment-readiness`. Thirteen
checks, each either **ok**, **todo** (configuration you have not done yet) or
**FAIL** (something that would go wrong). **Do not publish a vacancy while
anything reads FAIL.**

| Check | What it wants |
| --- | --- |
| The recruitment feature switch | On, once you are ready. |
| Mail transport | Not `log`. A candidate cannot receive a sign-in link otherwise. |
| Where candidate replies go | A reply-to address somebody actually reads. |
| Queue driver | `database`. |
| The scheduler | Evidence that something queued has actually been sent. |
| Failed emails | None outstanding. |
| Private document storage | Writable, and outside the document root. |
| Malware scanning | Optional. Says plainly that it is off. |
| The privacy notice | **Reviewed by somebody qualified.** FAIL until then. |
| How long records are kept | A period you have decided on. |
| Assessment exercises | A live exercise for each of the four roles. |
| Vacancies | What each draft is still missing, item by item. |
| Who reads the applications | Every published vacancy has a named reviewer. |

The privacy notice starts as a FAIL on purpose. The shipped wording describes
what this software actually does, which is the part that can be verified in
code. **It does not and cannot assert that it satisfies the NDPA or any other
law.** Have somebody qualified read it, edit it or save it back unchanged, and
bump the version — the version is stamped onto every consent record, so a
record never silently points at text nobody saw.

Retention is the same kind of decision and is handled the same way. The period
starts empty and the automatic purge refuses to run until you set one. This
module will not choose a legal retention period on your behalf, and a helpful
default here would be exactly that. Note also that a purge removes records from
the database but **not from backups already taken** — those age out on your
host's own cycle, and no software here can shorten that.

Publishing a vacancy requires, and the server enforces: a description, the
requirements, the employment type, which countries someone must be able to work
from, the hours and their time zone, a closing date, and **an explicit choice
about whether pay is shown on the advert**. Either choice is fine; leaving it
unanswered is not. Remote does not mean worldwide, and it does not mean flexible
hours — the advert says which countries and which hours, because those are
different questions.

Closing a vacancy does not silently cancel the people already in it. You are
asked what should happen to them.

---

## 8. Working it — the short version

**Opening a vacancy.** `Recruitment → Vacancies`, open a draft, fill in what the
publication panel asks for, assign at least one reviewer, pick the assessment,
then publish. The public page appears at `/careers/<slug>`.

**Reading applications.** `Recruitment → Applications`. Search by name, email or
reference; filter by role, stage, date, source or reviewer. A reviewer sees the
vacancies they were assigned; a coordinator and above see all of them.

**Inviting someone to the assessment.** From the application page. You choose
the delay — immediate, or one to three working days — and the deadline is three
working days after the invitation goes out, at 17:00 Lagos time by default. The
exercise is **snapshotted onto that invitation**, so rewriting the template this
afternoon cannot change the exercise somebody is halfway through this morning.
One reminder goes out a working day before the deadline, and is skipped if they
have already submitted or are no longer active.

Exercises are fictional scenarios, take roughly 30–45 minutes, and ask for no
real client data and no unpaid live campaign work. The optional 60–120 second
introduction has a written or audio alternative that is **not scored lower**,
defaults to a link rather than an upload, and says plainly that an unlisted link
is viewable by anyone who holds it. Nobody is asked to post publicly, tag NVN or
follow an account. A link a candidate supplies is checked for an HTTP(S) scheme,
displayed safely, and **never fetched by this server** — which is both an SSRF
hole and a way of telling a third party that we read it.

**Giving somebody longer.** Extend the deadline from the application page. It
works whether or not the deadline has already passed, it records who allowed it
and why, and extending reopens the form. Extensions keep their history.

**Scoring.** Reviewers score against the rubric's anchors. Incomplete scoring
shows as incomplete rather than as a low score.

**Interviews.** Schedule with a date, a duration, the time zone, the
interviewer, your **own meeting link** and any instructions. Nothing here
provisions a Meet or Zoom room — you paste the link you made. The calendar
attachment keeps a stable UID across reschedules and bumps its sequence, so a
candidate's calendar updates the existing entry instead of growing a second one.
Interview feedback is internal and the candidate never sees it.

**Offers.** Generated only from a template you have approved or a document you
uploaded. Nothing invents employment terms. A candidate clicking Accept is
recorded as their response; it is **not** described as a certified electronic
signature, because it is not one.

**Checks.** Identity documents and references are collected here and nowhere
earlier. A **guarantor requirement is off by default**, has to be switched on
per role with a stated reason, and is not the same thing as a professional
reference. No third party is contacted automatically — a member of staff takes
that action. Verification is recorded as pending, verified or not verified, with
who said so and when; **a file having been uploaded is not proof of anything.**

**Hiring.** Marked by hand. It grants no account and no privileges of any kind.
An applicant does not become a client, a notary, an employee or staff by being
hired here; that is a separate, deliberate action elsewhere in the panel.

**Rejection and withdrawal.** Either ends the pipeline, cancels pending
messages, and keeps the internal reasoning internal. A candidate sees that their
application was unsuccessful; they do not see notes, scores, reviewer identities
or why.

**Exports.** CSV, restricted to the export ability, audited, and deliberately
limited to twenty non-sensitive columns — no identity or guarantor data leaves
in an ordinary export. Every cell that starts with `=`, `+`, `-`, `@`, a tab or
a carriage return is prefixed with an apostrophe so a spreadsheet treats it as
text rather than a formula.

**Bulk actions** preview what they will do, ask for confirmation, and validate
each candidate individually rather than assuming the batch is uniform.

---

## 9. Rollout and rollback

### Going live

1. Take a backup and **restore it somewhere else to confirm it restores.** An
   untested backup is not a backup.
2. `php artisan migrate --force`, then `config:clear` and `view:cache`.
3. Leave `NVN_RECRUITMENT_ENABLED=false`. Nothing is public yet.
4. Set an administrator's `recruitment_role` to `lead` in the panel.
5. Work through Readiness until nothing says FAIL. The privacy notice and the
   retention period are the two that need a decision rather than a setting.
6. Switch mail to SMTP and set the reply-to address.
7. Set `NVN_RECRUITMENT_ENABLED=true`, `config:clear`. `/careers` now answers —
   and shows an empty state, because the vacancies are still drafts.
8. Fill in one draft, publish it, and apply to it yourself end to end. That is
   the real test: you will receive the acknowledgment and the sign-in link.
9. Publish the rest.

### Rolling back

`NVN_RECRUITMENT_ENABLED=false` and `php artisan config:clear`. That is the
whole of it, and it takes effect immediately:

- Every careers and candidate route returns 404.
- The Recruitment group disappears from the panel.
- Every recruitment ability returns false, **for administrators too**.

Nothing is deleted and no data is touched, so switching it back on resumes
exactly where you were. If you need to go further, the four migrations are
additive and independently reversible — `php artisan migrate:rollback --step=4`
drops only the recruitment tables and the one nullable `users` column. Nothing
in notarization, payments, payouts, documents or the client and notary areas
depends on any of it.

---

## 10. Tests

Six new feature files, 62 tests, run alongside everything that was already
there. The full suite with OTP verification forced on:

```
NVN_REQUIRE_OTP=true php artisan test
Tests:    244 passed (935 assertions)
Duration: 199.34s
```

| File | Tests | What it pins down |
| --- | :-: | --- |
| `RecruitmentCareersTest` | 11 | Listing and detail pages, empty and closed states, the server refusing a closed vacancy, publication blockers. |
| `RecruitmentApplicationTest` | 10 | The form, validation, the duplicate constraint, the reference, saving without mail. |
| `RecruitmentCandidateAccessTest` | 10 | Sign-in tokens, the scanner-safe GET, one-use redemption, the candidate boundary. |
| `RecruitmentWorkflowTest` | 12 | The state machine, prerequisites, reasons, rejection and withdrawal. |
| `RecruitmentAssessmentTest` | 12 | Snapshotting, deadlines, reminders, overdue as a flag, extend and reopen. |
| `RecruitmentAuthorizationTest` | 7 | The ability matrix, the query scope, the feature switch, sensitive documents, CSV escaping. |

Two deliberate choices in how these are written. Nothing asserts that a button
is hidden — every assertion asks the thing that actually decides, and the HTTP
tests type the URL a hidden button would have pointed at. And all seed and
fixture data is fictional; no real address has ever been mailed.

Four acceptance criteria worth singling out, because they are the ones most
easily claimed and not checked:

- A GET to `/candidate/sign-in/{token}` leaves the token usable; only the POST
  consumes it. Tested both ways round.
- The feature switch returns false for an administrator, not merely for a
  reviewer.
- `=HYPERLINK(...)`, `+1-800-NOT-A-SUM`, `-2+3` and `@SUM(A1:A9)` all come out
  of an export prefixed with an apostrophe, a cell containing a newline does not
  become an extra row, and a harmless cell gets no stray apostrophe.
- The existing login, client, payment, notary and document flows still pass —
  that is the other 182 tests, unchanged.

### What is not covered

- **No browser automation.** The mobile and desktop flows were inspected by
  hand; there is no Dusk suite asserting layout.
- **Mail delivery is not tested end to end**, because the transport is `log` by
  design. What is tested is that the right outbox row is written with the right
  event key, and that a cancelled, superseded or withdrawn message is not sent.
- **Malware scanning is untested against a real scanner.** What is tested is the
  fail-closed path: a configured scanner that errors quarantines the file.
- **The scheduler check in Readiness is weak by nature.** Until something has
  actually been queued it has no evidence either way, and it says so rather than
  reporting ok.
- **One test was flaky and is not any more.** The stage-history assertion passed
  or failed depending on whether all seven of its moves landed inside the same
  second. That was worth more than the fix: the instability was in the model,
  not the test, and it was visible to candidates. If a recruitment test ever
  fails intermittently, suspect an ordering tie before suspecting the test.
- **The retention purge has never run on real data**, because no retention
  period has been set. The preview is what to check first when you set one.

---

## 11. Changes to code that already existed

The module is additive, but seven existing files were corrected while building
it. Six of those were real bugs, found by writing the tests rather than by
reading:

| File | Change |
| --- | --- |
| `app/Support/AuditLogger.php` | The actor fallback now reads the named `web` guard. Laravel's `Authenticate` middleware calls `shouldUse()`, so inside a candidate request the default guard is `candidate` and the bare `auth()->id()` would have attributed a staff action to nobody — or a candidate action to a user id that is not a user. |
| `app/Support/WorkingCalendar.php` | `zoneLabel()`'s argument is now optional. Required, it threw `ArgumentCountError` and returned a 500 on the assessment page — the one page every shortlisted candidate has to use. |
| `app/Models/AssessmentAssignment.php` | `isOpen()` now counts `OVERDUE` as a live status, mirrored in `scopeOpen()`. Without it the state stopped being reported at the exact moment the nightly sweep confirmed it: `isOverdue()` went quiet, the status pill fell back to "Invited", and a fresh invitation would not have cancelled the old row. A new `closedReason()` is the single definition of why the form is shut, because three places had written their own and a missed deadline was announcing itself as "already submitted". |
| `app/Services/Recruitment/AssessmentService.php` | `extend()` and `reopen()` re-read the row under `lockForUpdate()` at the top of their transaction. Both `forceFill(...)->save()`, and `save()` writes only what it believes has changed — handed a stale instance, `submitted_at => null` and the `OVERDUE → INVITED` flip look like no change and were silently dropped, leaving a reopened assignment still marked submitted. It also stops two reviewers moving the same deadline at once. |
| `app/Filament/Resources/.../ViewApplication.php` | The extend-deadline button was gated on `acceptsChanges()`, which is false once a deadline passes — so it was hidden in the only situation it exists for. It is gated on `isOpen()` now. |
| `app/Models/JobApplication.php` | The stage history, the notes and the message log were ordered by `created_at` with no tiebreaker. Several moves can land inside the same second, and the order of a tied pair was then the database's to choose — on a list that is read as a sequence of decisions, and on the candidate's own page, where a tied pair was being shown to them in reverse. The id breaks the tie now. |
| `app/Support/RecruitmentReadiness.php` | Two fixes. The feature-switch check read the config file while the module reads a setting that can override it, so the page could report the feature off while every careers route was answering. And the assessment-coverage check compared enum instances against strings — Eloquent's `pluck()` applies the model's casts — which reported all four exercises missing while all four existed. |

One more, not a bug: the public application form validates email as
`email:rfc` rather than `email:rfc,dns`. A DNS lookup inside a public form is a
request a stranger can make the server perform, it fails on a perfectly valid
address whose nameserver is briefly slow, and it turns a form submission into
something that can time out. The address is confirmed by the fact that a
sign-in link reaches it, which is a better check than a MX record anyway.

The other modified files — `routes/web.php`, `routes/console.php`,
`bootstrap/app.php`, `config/auth.php`, `app/Models/User.php`,
`resources/views/layouts/public.blade.php` — gained registrations only: the
careers routes, the three scheduled commands, the candidate session middleware
alias, the `candidate` guard and provider, the `canAccessPanel()` clause for
recruitment roles, and the "Work With Us" nav and footer links. The remaining
modified resources and widgets each gained one line — the `AdminOnly` trait on a
resource, `AdminOnlyWidget` on a widget — which is what keeps a recruitment role
out of them. Fourteen resources and ten widgets, so that a lead reading
applications sees nothing of the platform's money or its clients.

`users.recruitment_role` is **not** in `User::$fillable`, alongside
`bulk_email_opt_out` and `organization_referred_at`. A mass-assignable
permission column is a privilege escalation waiting for a careless `update()`.
