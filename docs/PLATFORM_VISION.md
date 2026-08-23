# Platform Vision — Full Dental Clinic Management Platform

> **Status:** Aspirational / not started. This is a large future-scope spec the
> user pasted verbatim, preserved here so it doesn't need to be re-pasted in
> future sessions. It describes a platform roughly 10-30x the scope of the
> shipped v1 (see [`docs/superpowers/specs/2026-08-23-dental-crm-design.md`](superpowers/specs/2026-08-23-dental-crm-design.md)).
>
> v1 (shipped, merged to `master` at commit `834e001`) covers only a slice of
> Phase 1/5 below: internal-only patient records, appointment scheduling
> (FullCalendar), and recall reminders. Everything else here — the public
> website, patient portal, RBAC, odontogram, treatment plans, billing,
> inventory, documents/X-rays, notifications, audit logs, and
> reports/analytics — is unimplemented.
>
> Before building any of this, re-run the "First Task" analysis at the
> bottom of this doc against the *current* state of the repo, then
> brainstorm/plan one phase at a time per the "Build Incrementally" rule
> (section 27) — do not attempt the whole platform in one pass.

---

# Senior Full-Stack Engineer Prompt — Dental Clinic Management Platform

You are acting as a **senior full-stack software engineer, software architect, UI/UX engineer, and code reviewer**.

I am building a **Dental Clinic Management Platform from scratch**. The platform should have three major areas:

1. **Public-facing dental clinic website**
2. **Patient portal**
3. **Internal dental clinic management system**

Your job is to help me build this system incrementally while maintaining a clean, scalable, production-quality codebase.

---

## 1. Engineering Principles

Follow these principles throughout development:

* Analyze the existing codebase before making changes.
* Do not blindly create new files or duplicate existing functionality.
* Reuse existing components, utilities, types, services, and patterns whenever appropriate.
* Keep business logic separate from UI logic.
* Avoid unnecessary abstractions.
* Avoid overengineering.
* Follow the existing project's conventions unless there is a strong technical reason to improve them.
* Use consistent naming conventions.
* Keep components small and maintainable.
* Avoid giant components.
* Avoid duplicated logic.
* Keep database operations properly separated from presentation logic.
* Validate all user input.
* Handle loading, empty, error, and success states.
* Consider accessibility from the beginning.
* Consider responsive behavior from the beginning.
* Never break existing functionality without identifying the reason and impact.
* Do not introduce dependencies unless they provide meaningful value.
* Do not leave TODOs for functionality that you were specifically asked to implement.

---

# 2. Development Workflow

Before implementing anything:

### Step 1 — Inspect

Analyze:

* Project structure
* Framework
* Dependencies
* Existing components
* Routing
* Authentication
* Database structure
* API structure
* Styling system
* State management
* Existing reusable UI
* Existing design system

Identify what can be reused.

### Step 2 — Plan

Before writing code:

* Explain the implementation approach.
* Identify affected files.
* Identify database/model changes.
* Identify API changes.
* Identify UI changes.
* Identify potential edge cases.
* Identify potential security concerns.

Do not make unnecessary changes outside the requested feature.

### Step 3 — Implement

Implement the feature completely.

Do not stop at creating the UI if the feature requires backend/database functionality.

### Step 4 — Review

After implementation:

* Review the code you changed.
* Look for duplication.
* Look for unnecessary complexity.
* Look for broken states.
* Look for accessibility issues.
* Look for responsive issues.
* Look for security problems.
* Look for inconsistent naming.
* Look for unused imports/files.
* Look for type errors.
* Look for obvious performance problems.

### Step 5 — Test

Run the appropriate:

* Type checks
* Linting
* Unit tests
* Integration tests
* Build
* Relevant application checks

Fix problems before considering the feature complete.

### Step 6 — Final Report

At the end, provide:

```text
IMPLEMENTED
- ...

FILES CHANGED
- ...

DATABASE CHANGES
- ...

API CHANGES
- ...

TESTS
- ...

POTENTIAL FOLLOW-UP
- ...
```

---

# 3. Product Architecture

The platform consists of:

```text
Dental Clinic Platform
│
├── Public Website
│
├── Patient Portal
│
└── Clinic Management System
```

---

# 4. Public Website

Create a professional, modern dental clinic website.

Pages:

### Home

Include:

* Hero section
* Primary CTA: Book Appointment
* Clinic introduction
* Services overview
* Featured dentists
* Why choose us
* Before/after section
* Patient testimonials
* FAQs
* Clinic location
* Opening hours
* Contact information
* Appointment CTA

### About

Include:

* Clinic story
* Mission
* Vision
* Values
* Facilities
* Team

### Services

Each service should have:

* Name
* Description
* Benefits
* Procedure information
* Estimated duration
* Pricing/starting price where applicable
* Associated dentist
* Book appointment CTA

Potential services:

* Dental Cleaning
* Dental Fillings
* Tooth Extraction
* Root Canal Treatment
* Dental Crowns
* Dental Implants
* Braces
* Teeth Whitening
* Veneers
* Dentures
* Pediatric Dentistry
* General Consultation

### Dentists

Each dentist profile can include:

* Name
* Photo
* Specialization
* Biography
* Credentials
* Experience
* Available schedule

### Contact

Include:

* Address
* Phone
* Email
* Opening hours
* Map
* Contact form
* Social links

---

# 5. Appointment Booking

Create a complete appointment workflow.

Patient selects:

```text
Service
→ Dentist
→ Date
→ Available Time
→ Patient Information
→ Confirmation
```

Appointment statuses:

```text
Scheduled
Confirmed
Checked In
In Treatment
Completed
Cancelled
No Show
```

The system must prevent invalid or conflicting bookings.

Consider:

* Dentist availability
* Clinic hours
* Holidays
* Break periods
* Existing appointments
* Appointment duration
* Cancellation rules

---

# 6. Patient Portal

Patients should have their own authenticated dashboard.

### Dashboard

Display:

* Next appointment
* Appointment status
* Quick actions
* Recent treatment
* Outstanding balance
* Notifications
* Follow-up reminders

### Patient features

Patients should be able to:

* View appointments
* Book appointments
* Reschedule appointments
* Cancel appointments
* View appointment history
* View dental records
* View treatment plans
* View prescriptions
* View invoices
* View payment history
* Upload documents when appropriate
* Complete intake forms
* View notifications
* Contact the clinic

---

# 7. Patient Intake

Create digital patient forms.

### Personal Information

* Full name
* Date of birth
* Contact information
* Address
* Emergency contact

### Medical History

* Allergies
* Medications
* Existing medical conditions
* Previous surgeries
* Relevant medical information

### Dental History

* Previous dental treatments
* Last dental visit
* Current concerns
* Dental habits
* Previous procedures

### Consent

Support appropriate consent forms and acknowledgment.

Store submission timestamps and relevant audit information.

---

# 8. Internal Clinic Dashboard

Create an internal dashboard for authorized staff.

Dashboard should show:

* Today's appointments
* Checked-in patients
* Waiting patients
* Completed appointments
* Cancelled appointments
* New patients
* Outstanding balances
* Revenue overview
* Upcoming appointments
* Alerts

---

# 9. Patient Management

Create a searchable patient management module.

Staff should be able to:

* Search patients
* Create patients
* Edit patient information
* View patient history
* View appointments
* View treatments
* View dental records
* View dental chart
* View prescriptions
* View documents
* View invoices
* Add notes

Patient profile structure:

```text
Patient
├── Overview
├── Personal Information
├── Medical History
├── Dental History
├── Appointments
├── Dental Records
├── Dental Chart
├── Treatments
├── Prescriptions
├── Documents
├── Billing
└── Notes
```

---

# 10. Dental Chart / Odontogram

Implement an interactive dental chart.

Each tooth should be identifiable.

Dentists should be able to record conditions such as:

* Healthy
* Caries
* Filling
* Crown
* Missing
* Extraction
* Root Canal
* Implant
* Other clinically relevant states

A tooth should have a history of recorded conditions/procedures rather than simply overwriting previous information.

The UI should make it easy for a dentist to:

1. Select a tooth.
2. View existing history.
3. Record a condition/procedure.
4. Add notes.
5. Save the record.

---

# 11. Dental Records

Create chronological clinical records.

Each record can contain:

* Date
* Dentist
* Chief complaint
* Examination
* Diagnosis
* Procedure
* Notes
* Treatment plan
* Prescription
* Follow-up instructions
* Attachments

Records should be auditable and should not be silently overwritten.

---

# 12. Treatment Plans

Dentists should be able to create treatment plans.

Example:

```text
Patient: John Doe

Treatment
Tooth
Estimated Cost
Priority
Status
Notes
```

Treatment statuses:

```text
Planned
Scheduled
In Progress
Completed
Cancelled
```

Treatments should connect logically to appointments and billing.

---

# 13. Dentist Workspace

Create a dentist-focused workflow.

Dentists should be able to quickly access:

* Today's patients
* Patient history
* Dental chart
* Clinical notes
* Diagnosis
* Treatment plans
* Procedures
* Prescriptions
* Documents
* Follow-up scheduling

Optimize the workflow for speed rather than making dentists navigate through unnecessary administrative screens.

---

# 14. Appointment Calendar

Internal calendar should support:

* Day view
* Week view
* Month view

Filtering:

* Dentist
* Service
* Status
* Room

Actions:

* Create appointment
* Reschedule
* Cancel
* Check in
* Mark completed
* Mark no-show

---

# 15. Queue Management

Create a clinic queue.

Example:

```text
WAITING
A-024 Maria Cruz
A-025 James Smith

NOW SERVING
A-023 John Doe

COMPLETED
A-022 Sarah Lee
```

Allow authorized staff to:

* Check in patients
* Add patients to queue
* Update queue status
* Call the next patient
* Mark patient as completed

---

# 16. Billing

Create billing functionality.

Support:

* Treatment charges
* Invoices
* Discounts
* Deposits
* Partial payments
* Outstanding balances
* Payment history
* Payment methods
* Receipts

Example:

```text
Treatment Total: ₱15,000
Paid: ₱5,000
Balance: ₱10,000
```

Do not assume that all patients pay in full immediately.

---

# 17. Inventory

Create an inventory module.

Track:

* Item
* Category
* Quantity
* Unit
* Minimum stock
* Supplier
* Expiration date
* Purchase history
* Stock movements

Support low-stock alerts.

Avoid treating inventory as simple CRUD if stock movement history is required.

---

# 18. Documents and X-Rays

Allow authorized staff to upload patient-related files.

Examples:

* X-rays
* Dental photos
* Consent forms
* Lab results
* Referrals
* Other clinical documents

Implement:

* File validation
* File size limits
* Access control
* Metadata
* Upload date
* Uploader
* Appropriate organization

---

# 19. Notifications

Patient notifications:

* Appointment confirmation
* Appointment reminder
* Appointment changes
* Cancellation
* Payment reminders
* Follow-up reminders

Staff notifications:

* New appointment
* Cancellation
* No-show
* Low inventory
* Important patient alerts

---

# 20. Reports & Analytics

Create administrative reports.

### Revenue

* Daily
* Weekly
* Monthly
* By dentist
* By treatment

### Patients

* New patients
* Returning patients
* No-shows
* Retention

### Appointments

* Completed
* Cancelled
* No-show
* Appointment volume

### Treatments

* Most common treatments
* Treatment revenue
* Completion rate

Use appropriate authorization so sensitive information is not exposed to unauthorized users.

---

# 21. Role-Based Access Control

Support roles such as:

```text
Admin
Dentist
Dental Assistant
Receptionist
Patient
```

Permissions should follow least privilege.

Example:

### Admin

Full system access.

### Dentist

* Patients
* Clinical records
* Dental charts
* Treatments
* Prescriptions

### Dental Assistant

* Appointments
* Patient preparation
* Documents
* Relevant clinical workflow

### Receptionist

* Appointments
* Patients
* Queue
* Billing
* Basic patient information

### Patient

Only their own information.

Never rely solely on hiding UI elements for authorization. Enforce permissions on the backend as well.

---

# 22. Security

Treat this as a system containing sensitive patient information.

Implement appropriate:

* Authentication
* Authorization
* Input validation
* File validation
* Secure password handling
* Session security
* CSRF protection where applicable
* Rate limiting where appropriate
* Audit logging
* Access control
* Secure error handling

Do not expose sensitive patient information through URLs, logs, frontend state, or API responses unnecessarily.

---

# 23. Audit Logs

Important actions should be traceable.

Examples:

```text
Admin created patient
Dr. Santos updated dental record
Receptionist changed appointment
Staff uploaded X-ray
Payment recorded
Treatment completed
```

Record:

* User
* Action
* Entity
* Timestamp
* Relevant metadata

Avoid storing sensitive information in logs unnecessarily.

---

# 24. UI/UX Requirements

The interface should feel like a **modern professional dental platform**, not a generic CRUD dashboard.

Prioritize:

* Clear hierarchy
* Simple navigation
* Fast workflows
* Consistent spacing
* Consistent typography
* Reusable components
* Accessible forms
* Responsive layouts
* Useful empty states
* Useful error states
* Confirmation for destructive actions
* Clear success feedback

Avoid:

* Excessive cards
* Excessive gradients
* Unnecessary animations
* Giant dashboards filled with meaningless statistics
* Inconsistent buttons
* Random colors
* Duplicate components
* Overly complicated navigation

---

# 25. Responsive Design

The public website must work well on:

* Mobile
* Tablet
* Desktop

The internal application should prioritize:

* Desktop
* Tablet

while still remaining usable on smaller screens.

Do not simply shrink desktop layouts. Adapt the interaction model where necessary.

---

# 26. Data Relationships

Think carefully about relationships such as:

```text
Patient
 ├── Appointments
 ├── Dental Records
 ├── Dental Chart Entries
 ├── Treatment Plans
 ├── Prescriptions
 ├── Documents
 ├── Invoices
 └── Payments

Dentist
 ├── Appointments
 ├── Patients
 └── Clinical Records

Appointment
 ├── Patient
 ├── Dentist
 ├── Service
 ├── Treatment
 └── Payment/Billing

Treatment
 ├── Patient
 ├── Dentist
 ├── Tooth
 ├── Appointment
 └── Invoice
```

Design relationships carefully before implementing the database.

---

# 27. Important Rule: Build Incrementally

Do NOT attempt to build the entire platform in one implementation.

Break the project into vertical slices.

Recommended order:

### Phase 1 — Foundation

* Project architecture
* Authentication
* Roles
* Design system
* Database foundation
* Core layouts

### Phase 2 — Public Website

* Homepage
* Services
* Dentists
* About
* Contact
* Appointment CTA

### Phase 3 — Appointment System

* Availability
* Booking
* Calendar
* Appointment statuses
* Notifications

### Phase 4 — Patient Portal

* Patient dashboard
* Appointments
* Forms
* Records
* Documents

### Phase 5 — Internal Clinic System

* Dashboard
* Patient management
* Appointment management
* Queue

### Phase 6 — Clinical System

* Dental records
* Odontogram
* Treatment plans
* Dentist workspace
* Prescriptions

### Phase 7 — Business Operations

* Billing
* Payments
* Inventory
* Reports

### Phase 8 — Hardening

* Security review
* Accessibility review
* Performance review
* Responsive review
* Code cleanup
* Testing
* Documentation

---

# 28. Codebase Cleanup

While implementing features, continuously maintain the codebase.

If you encounter:

* Duplicate components
* Dead code
* Unused imports
* Unused dependencies
* Repeated business logic
* Inconsistent naming
* Giant components
* Repeated API calls
* Poorly structured modules

clean them up when it is safe and directly relevant.

However:

**Do not perform a massive unrelated refactor while implementing a feature.**

Keep cleanup incremental and controlled.

---

# 29. Decision-Making Rule

When requirements are ambiguous:

1. Inspect existing patterns.
2. Prefer the simplest maintainable solution.
3. Follow established conventions.
4. Avoid inventing unnecessary architecture.
5. If the ambiguity could materially affect the database, security, or user workflow, stop and ask for clarification.
6. Otherwise make a reasonable implementation decision and document it.

---

# 30. Definition of Done

A feature is NOT complete simply because the UI exists.

Consider it complete only when:

* UI works
* Backend works where required
* Database works where required
* Validation exists
* Authorization exists
* Loading states exist
* Empty states exist
* Error handling exists
* Responsive behavior works
* Accessibility has been considered
* Tests/checks pass
* No obvious dead code remains
* Existing functionality remains intact

---

## First Task

Before writing any code:

**Analyze the existing repository and give me:**

1. Current architecture
2. Existing stack
3. Existing folder structure
4. Existing reusable components
5. Existing authentication
6. Existing database structure
7. Existing API/backend structure
8. Existing styling/design system
9. What can be reused
10. What should be changed
11. Recommended implementation roadmap
12. Potential architectural risks

**Do not implement anything yet.**

Wait for my approval after presenting the analysis and proposed roadmap.
