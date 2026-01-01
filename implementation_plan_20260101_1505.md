# VAPT Master Plugin Builder - Development Plan

## Changelog & Implementation Progress
> [!NOTE]
> This section tracks the evolution of the VAPT Master plugin and its features.

### Revision 2026-01-01 14:10:00 [CURRENT]
- [x] Integrate dynamic JSON feature list selection in the Feature List tab.
- [x] Maintain a `CHANGELOG.md` in the client build and update version info with every build.
- [x] Record build history and implementation timestamps.
- **Planned**: Fix "Sorry, you are not allowed to access this page" for localhost notice link by allowing menu registration for all admins on local environments.
- **Planned**: Refactor `admin.js` to use `React.createElement` (avoid JSX) to fix dashboard loading.
- **Planned**: Correct menu hierarchy: "VAPT Master Security" -> "VAPT Master" (first child) and "VAPT Master Dashboard" (second child).
- **Planned**: Version all docs with `YYYYMMDD_HHMM` format in the plugin root.

### Revision 2026-01-01 13:30:00 [COMPLETED]
- [x] Implemented core VAPT Master scaffold and directory structure.
- [x] Implemented Superadmin Dashboard with Feature List, Domain Features, and Build Generator tabs.
- [x] Implemented Email OTP Authentication for the Superadmin.
- [x] Implemented Build Engine with ZIP packaging, white-labeling, and config generation.
- [x] Added User Guide and Folder Structure documents to client builds.
- [x] Implemented Client-side Security Status menu.

---

## Goal Description
Create a WordPress plugin called **VAPT Master** that provides a dashboard for a Superadmin to manage security features (VAPT, OWASP) per domain. Features are dynamically loaded from a chosen JSON file. The dashboard tracks implementation status across revisions. Every build is versioned and includes a changelog.

## User Review Required
> [!IMPORTANT]
> Confirm the new "Changelog & Implementation Progress" section format and the dynamic JSON selection logic.

## Proposed Changes
### Admin Menu Structure (Revised v4)
- **VAPT Master Security**: Main Top-Level Menu.
- **VAPT Master**: First submenu (visible to all administrators). Should point to the same functional page as the parent menu.
- **VAPT Master Dashboard**: Second submenu (exclusive to Superadmin).

### Localhost Admin Notice (Bug Fix)
- **Problem**: Standard admins get a permission error because the dashboard menu is hardcoded to the Superadmin's username.
- **Fix**: Register the **VAPT Master Dashboard** submenu for *all* users with `manage_options` capability **IF** the environment is localhost.
- **Security**: Access still requires passing the **OTP Authentication** layer (emails still sent to Superadmin email).

### React Implementation (No Build Step)
- Refactor `admin.js` to use `wp.element.createElement` for all components. This removes the JSX syntax error which is preventing the dashboard from loading in standard browsers.

### Persistent Documentation (Revised v5)
- Revisions of `implementation_plan.md` and `walkthrough.md` will be copied to the plugin root.
- Filename format: `[base]_YYYYMMDD_HHMM.md`.
- Active versions: `implementation_plan.md` and `walkthrough.md` will also be kept at the root.

---
### Feature List Management (Revised)
- **Dynamic JSON Selection**: Add an option in the **Feature List** tab to select/upload a JSON file (e.g., `features-with-test-methods.json`).
- **State Synchronization**: Upon loading the JSON, the system compares keys against the `wp_vaptm_feature_status` table. 
  - Features existing in the DB as "Implemented" are automatically marked as such in the UI list.
  - New features from the JSON are added as "Available".
- **Enhanced Feature Cards**: Display implementation date/time (if available) and status history.

---
### Build Logic & Versioning (Revised)
- **Build Versioning**: Every execution of the Build Generator increments a client-specific build version (or uses a user-provided one) and updates `VAPTM_BUILD_VERSION`.
- **Changelog Support**: 
  - A `CHANGELOG.md` file is automatically generated and included in the client build ZIP.
  - It lists all features added/updated in the current build versus previous builds for that domain.
- **Documentation**: The User Guide is updated to include the build timestamp and version information.

---
### Data Model (Updates)
- `wp_vaptm_feature_status`: Add `implemented_at` DATETIME field.
- `wp_vaptm_domain_builds`: New table to track history of builds for each domain (version, features, timestamp).

---
### UI/UX Refinements
- Improve the Feature List UI to show "Implemented" features with more prominent visual indicators (badges, dates).
- Add a "Revise Plan" button that logs current implementation states into the development plan/changelog history.

## Verification Plan
### Automated Tests
- Test JSON loading and database state merging.
- Verify ZIP package contains `CHANGELOG.md` and correctly formatted `config.php`.

### Manual Verification
- Upload a new JSON and verify "Implemented" features are correctly detected.
- Generate two builds for the same domain and confirm version incrementing and changelog accuracy.
