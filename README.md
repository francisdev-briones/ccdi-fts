# CCDI Legazpi · Student Financial Transaction System

PHP + MySQL web system with four roles: Student, Cashier, Student Assistant, and Dean's Secretary.
Requires **PHP 8.1 or newer** (current XAMPP) and MySQL 8 or MariaDB 10.4+.

## Project structure
```
ccdi-fts/
├── index.php             Sign in (with attempt throttling)
├── register.php          Student self-registration
├── dashboard.php         Role-based dashboards and activity log
├── actions.php           Every form submission (server-side rules and role checks)
├── receipt.php           Printable payment acknowledgment
├── assessments.php       Cashier: encode or import COR-based assessments
├── permit.php            Printable exam permit with QR code
├── proctor.php           Proctor: scan or type a permit code
├── reports.php           Collection, pending, permit and scan reports (CSV export)
├── proof.php             Access-controlled viewer for payment screenshots
├── uploads/              Payment screenshots (blocked from direct web access)
├── setup.php             One-time account setup (local only, delete after use)
├── config/bootstrap.php  Settings, database, session, business rules
├── config/ui.php         Shared HTML helpers (layout, tables, status pills)
├── database/schema.sql   Tables, constraints, exam periods and three sample students
├── database/upgrade_v2.sql  Only for databases imported from the first release
└── assets/style.css      The single stylesheet
```

## Setup (XAMPP + VS Code)

1. **Install XAMPP** from https://www.apachefriends.org and open the XAMPP Control Panel.
2. **Start Apache and MySQL.** Both must show green.
3. **Copy the project** into `C:\xampp\htdocs\ccdi-fts` (Windows) or `/Applications/XAMPP/htdocs/ccdi-fts` (macOS).
4. **Create the database:** open http://localhost/phpmyadmin → *Import* → choose `database/schema.sql` → *Go*.
   Upgrading? Either run `DROP DATABASE ccdi_fts;` and import again (loses data), or keep your data: run `database/upgrade_v2.sql` in the SQL tab, then import `schema.sql` to add the new tables.
5. **Create the accounts:** open http://localhost/ccdi-fts/setup.php, click **Create accounts**, then click **Delete setup.php**.
6. **Sign in:** open http://localhost/ccdi-fts/

| Username | Role | Password |
|---|---|---|
| student1 | Student (regular, Maria Santos) | password123 |
| student2 | Student (scholar, Juan Dela Cruz) | password123 |
| student3 | Student (promissory, Ana Reyes) | password123 |
| cashier | Cashier | password123 |
| assistant | Student Assistant | password123 |
| dean | Dean's Secretary | password123 |
| proctor | Exam Proctor | password123 |

New students can also register at `register.php`. Their student number (for example `2026-0004`) is assigned automatically.

If MySQL has a root password, set `DB_PASS` in `config/bootstrap.php`. Each setting can also come from the environment variables `CCDI_DB_HOST`, `CCDI_DB_NAME`, `CCDI_DB_USER` and `CCDI_DB_PASS`.

## Demo walkthrough

1. **Scholar flow:** sign in as `student2` and submit a scholarship application.
2. Sign in as `assistant` and click **Mark documents complete**.
3. Sign in as `dean`, set the coverage (for example 50) and click **Approve**.
4. Sign in as `student2`. The payable amount now reflects the coverage. Submit a payment with a reference number.
5. Sign in as `cashier` and click **Verify and issue receipt**. The student can open and print the receipt.
6. Students book appointments, staff confirm them, then mark them completed or no-show. The Dean's Secretary sees the full audit log; every other role sees its own recent activity.

## Payment, permit and scan workflow

1. The **cashier** encodes each student's COR figures (tuition, laboratory, miscellaneous, other fees, discount such as CHED Gabay Aral, and any amount paid upon registration) on **Assessments**, one by one or by CSV import.
2. The **student** signs in and sees the assessed fees, payments and remaining balance.
3. The student pays by GCash, Maya or bank deposit outside the system, then submits the amount, payment date, reference number and a receipt screenshot.
4. The system checks the amount against the open balance and **flags a reference number that is already recorded**. The submission is saved as **Pending**.
5. The **cashier** compares it with the GCash or bank record, then approves it, or rejects it with a required reason that the student can read. Approving a flagged reference is blocked when that reference was already approved elsewhere.
6. Approval updates the balance and issues a payment acknowledgment (printable).
7. When approved payments reach the percentage set for an exam period, the system issues an **exam permit** with a unique QR code. The Dean's Secretary sets the percentages (starting at Prelim 30%, Midterm 55%, Pre-Final 80%, Final 100%) on the dashboard.
8. At the exam room the **proctor** picks the exam, then scans the QR (camera) or types the code. The system answers Valid, Invalid, Revoked, Wrong period or Not eligible. Eligibility is re-checked at scan time.
9. Every scan is logged. The cashier and Dean's Secretary can open **Reports** (collection, pending payments, permits, scan log) and download them as CSV.

CSV import columns: `student_id, school_year, term, course, units, tuition_fee` and optionally `lab_fee, misc_fee, other_fee, discount, discount_label, upon_reg`. When an assessment exists it replaces the scholarship and promissory request flow for that student.

The QR image is drawn by a small library loaded from cdnjs, so permit pages need internet. Offline, the permit still shows its code, and the proctor can type it.

## Rules enforced by the system

- Scholars cannot pay until the Dean's Secretary approves the application, and the payable amount reflects the coverage. A 100% scholarship has nothing to pay.
- Promissory students cannot pay until their request is approved. The approval sets a payment deadline that cannot be in the past.
- A rejected request can be resubmitted by the student.
- Payment screenshots must be JPG, PNG or WebP up to 3 MB, are stored outside public access, and are visible only to the student and staff.
- Requests cannot be approved until the Student Assistant marks documents complete.
- Payments cannot exceed the open balance. Duplicate reference numbers and unknown payment methods are rejected.
- Only the cashier issues receipts, and each payment gets exactly one receipt number.
- Appointments are limited per office, date and slot. A student may hold one active appointment per date, and only upcoming weekdays can be booked.
- Staff act only on their own office's appointments (the assistant can act on all).
- Money actions run inside database transactions, so two clicks at once cannot double-issue or overbook.

## Security notes

- Every form carries a CSRF token, including sign out. Passwords use `password_hash`.
- Sign-in locks for 60 seconds after 5 failed attempts. Sessions expire after 30 idle minutes.
- Database errors are written to the PHP error log and never shown to users.
- `setup.php` only runs from the local computer and never overwrites an existing account unless you press the reset button.

## Troubleshooting

- **"The system cannot reach its database"**: start MySQL in XAMPP, and check `DB_USER` and `DB_PASS` in `config/bootstrap.php`.
- **"Unknown database ccdi_fts"**: import `database/schema.sql` again.
- **Blank page**: set `DEBUG` to `true` in `config/bootstrap.php` to see errors, then set it back. Logs are in `C:\xampp\php\logs\php_error_log`.
- **Port 80 or 3306 in use**: stop the conflicting service (often Skype or another MySQL) or change the port in XAMPP.
- **Uploads fail**: raise `upload_max_filesize` and `post_max_size` to at least 4M in `php.ini`, enable the `fileinfo` extension, and restart Apache.
- **Cannot sign in as a demo or staff account**: open `setup.php` and press **Reset demo and staff passwords**.

## Note on scope

This is a working capstone prototype for local use and demonstration. For real deployment, add HTTPS, a school-approved receipt numbering process, and compliance with the Data Privacy Act of 2012 (RA 10173). New students receive the tuition set by `DEFAULT_TUITION` in `config/bootstrap.php`; a registrar screen for per-student tuition is the natural next step.
"# ccdi-fts" 
"# ccdi-fts" 
"# ccdi-fts" 
