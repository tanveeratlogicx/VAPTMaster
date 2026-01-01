# VAPT Master Plugin Builder - Development Plan

## Changelog & Implementation Progress
> [!NOTE]
> This section tracks the evolution of the VAPT Master plugin and its features.

### Revision 2026-01-01 14:10:00 [COMPLETED]
- [x] Integrate dynamic JSON feature list selection in the Feature List tab.
- [x] Maintain a `CHANGELOG.md` in the client build and update version info with every build.
- [x] Fix OTP & Menu Logic: Strictly restrict menu to Superadmin. Show Admin Notice for other admins *only* on localhost.
- [x] Fix JSON Mapping: Map `name` to the Feature name correctly. Generate stable `key` from `name`.
- [x] Add Admin Notice Hook: Register `vaptm_localhost_admin_notice` to `admin_notices`.
- [x] Add JSON Upload UI: Allow Superadmin to upload new feature lists.
- [x] Ensure OTP is mandatory on localhost and always sent to `tanmalik786@gmail.com`.
- [x] Refactor `admin.js` to use `React.createElement` (avoid JSX) to fix dashboard loading.
- [x] Correct menu hierarchy: "VAPT Master Security" -> "VAPT Master" (first child) and "VAPT Master Dashboard" (second child).
- [x] Version all docs with `YYYYMMDD_HHMM` format in the plugin root.
- [x] Refactor UI to use Optimistic State (Enable smooth AJAX updates without reloading).
- [x] Add "Saving..."/"Saved" visual indicators for background sync actions.
- [x] Feature List UX: Add Status Counts (Total/Implemented/In Progress), Sorting, Filtering, and Search controls.

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

### Strict Superadmin & Localhost Access (Revised v7)
- **Menu Registration**:
    - **Superadmin** (`tanmalik786`): Sees the full menu hierarchy.
    - **Other Admins (Localhost)**: Menu is hidden (parent `null`), but access to the slug `vapt-master` is granted.
    - **Other Admins (Public)**: No access, no menu.
- **Admin Notice**:
    - Shown **ONLY** to non-Superadmin users **ON** Localhost.
    - Link points to `admin.php?page=vapt-master`.
- **OTP Logic**:
    - **Mandatory** for everyone accessing the Superadmin Dashboard.
    - **Recipient**: ALWAYS `tanmalik786@gmail.com`.
    - **Verification**: Grants session-based access (user-specific transient).

### Feature List Mapping & Upload (Bug Fix)
- **Mapping**: Update `admin.js` to use `f.name` as the primary label. Currently, it incorrectly uses `description`.
- **Key Generation**: Since the JSON lacks a `key` field, `get_features` in `class-vaptm-rest.php` will slugify the `name` to create a permanent `key` for DB synchronization.
- **JSON Upload**: Add a "Upload New Features (JSON)" button in the `FeatureList` component that sends a file to the `/upload-json` REST endpoint.

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
