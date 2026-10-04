# BizyDash

An internal operations plugin for WordPress, built for agencies. It keeps clients, team, work, billing, payroll and
contracts in one clean app that runs inside WordPress, or on its own page of your site.

## What it does

BizyDash is a small **core** with switchable **modules** around it. Turn off what you don't use — data is never deleted.

| Area | What's in it |
|---|---|
| **Core** (always on) | Home, Clients, Team, Departments, Activity, Settings |
| **Work** | Projects with phases and tasks, who is doing what, phase budgets and team pay split |
| **Finance** | One Billing list (retainers, subscriptions, one-off bills) → invoices, payments, expenses, payroll and payouts, catalog, profit |
| **Contracts** | Contracts and templates with e-signatures (email one-time code, tamper-evident audit log, sealed PDF, public verification page), Word / Google Docs import |
| **Analytics** | A page per area (Finance, Work, Contracts, Clients, Team) with period, department and — for Finance — multi-currency views |

Other things worth knowing:

- **Departments** can be attached to clients, team members, projects/phases/tasks, bills, invoices, contracts, payments
  and payouts, and each department has an overview page.
- **Front-end app** at `/app/` (the word is configurable), with a branded sign-in and password reset page, full-screen
  mode, and an option to use it as the site's home page.
- **Roles:** `manage_bizydash` for the team, `bizydash_finance` for money screens, and a "BizyDash team member" role that
  keeps people out of wp-admin.
- **Backup & restore** (Settings → Backup & restore): manual and automatic (daily/weekly) backups kept on the site,
  download with an optional password, and restore of any chosen modules. A restore is verified before it replaces
  anything, takes a safety copy first, and can be undone. A fresh install offers to restore a backup.
- **Sample data**: a new, empty install can load example clients, invoices and projects to explore, and remove them
  in one click (Home banner or Settings → General).
- **Invoice import**: Finance → Invoices → *Import invoices* brings in an Invoice.ng export (.xlsx or .csv) or a Sprout Invoices
  export (.csv); the platform is detected automatically. A review step for matching or creating clients, and an undo for each import.
- **Roles & access** (Settings → Roles & access): BD Client, BD Client Manager, BD Work Team, BD Finance Team,
  BD Contract Team, BD Team Manager (with per-person extra modules) and BD Admin (all of BizyDash, none of WordPress).
  Give a team member a role from their profile. Every client contact with an email gets a BD Client login linked to
  their organisation, ready for the client portal; existing clients are linked automatically on update.
- **Client portal** (`/portal/`, configurable): clients sign in (branded sign-in, password reset, optional emailed
  invitation) and see only their own organisation: projects with phases and task progress, invoices and payments,
  bills coming up that are not invoiced yet, and contracts with signing links and signed PDFs. People linked to several
  clients switch between them. Staff can preview any client's portal. Costs, team pay, notes, drafts and other clients'
  records are never exposed. What clients see is set in Settings → Client portal.
- Light, dark and system themes. Money is always shown per currency, with an optional combined estimate.

## Requirements

- WordPress 6.0+ and PHP 7.4+
- PHP extensions `dom`, `mbstring` and `zip` (document import and PDF)

## Install

1. Get the plugin folder named exactly `bizydash` (this repository's contents).
2. Zip it and use **Plugins → Add New → Upload Plugin**, or copy it to `wp-content/plugins/bizydash`.
3. Activate **BizyDash**, then open **BizyDash** in the admin menu (or `/app/` on your site).

> If you use GitHub's **Download ZIP**, the folder is named `bizydash-main`. Rename it to `bizydash` before zipping.

The compiled app is committed in `build/`, so no build step is needed to install.

## Development

The interface is Vue 3 + Element Plus, built with Vite. The server side is plain PHP in `includes/`.

```bash
npm install        # install dependencies
npm run build      # compile the app into build/
npm run dev        # rebuild on change
npm test           # run the UI tests (Vitest)
```

Layout:

```
bizydash.php        plugin entry
includes/           PHP: resources, REST routes, services, install and migrations
ui/                 Vue app (views, components, styles, tests)
build/              compiled app (committed)
assets/             fonts and small scripts
lib/                bundled libraries (dompdf, QR code)
```

Data lives in custom tables created and upgraded automatically on activation and update.

## Licence

The plugin header declares **GPL-2.0-or-later**. Bundled libraries keep their own licences (see `lib/` and
`assets/fonts/`).
