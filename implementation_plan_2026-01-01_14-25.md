# VAPT Master Plugin Builder - Development Plan

## Changelog & Implementation Progress
> [!NOTE]
> This section tracks the evolution of the VAPT Master plugin and its features.

### Revision 2026-01-01 14:10:00 [CURRENT]
- [x] Integrate dynamic JSON feature list selection in the Feature List tab.
- [x] Maintain a `CHANGELOG.md` in the client build and update version info with every build.
- [x] Record build history and implementation timestamps.
- **Planned**: Refactor admin menu to: Main "VAPT Master Security", Submenus "VAPT Master" and "VAPT Master Dashboard".
- **Planned**: Save timestamped revisions of `implementation_plan.md` to the plugin root (e.g., `implementation_plan_2026-01-01_14-25.md`).
- **Planned**: Debug and fix "Dashboard Loading..." freeze by verifying React initialization, script localization, and container existence.

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
### Admin Menu Structure (Revised v2)
- **VAPT Master Security**: The top-level menu label.
- **VAPT Master**: A submenu visible to all Site Admins and the Superadmin (renders client status).
- **VAPT Master Dashboard**: A submenu exclusive to the Superadmin (tanmalik786) (renders the React app).

### Persistent Documentation (Revised)
- Every significant plan update will trigger a copy of `implementation_plan.md` to be saved in the plugin root with the format `implementation_plan_YYYY-MM-DD_HH-MM.md`.

### UI/UX & Bug Fixes
- **Dashboard Freeze**: Investigate `admin.js` initialization. Potential causes: missing container ID in PHP template, script handle mismatch, or JS runtime errors before mount.
- **OTP UX**: Finalize refinements and ensure smooth transition to dashboard post-verification.

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
