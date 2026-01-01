# VAPT Master Plugin Builder

## Goal Description
Create a WordPress plugin called **VAPT Master** that provides a dashboard for a Superadmin to manage security features (VAPT, OWASP) per domain. Features are loaded from a JSON file `features-with-test-methods.json`, selectable per domain, with a **Wildcard** domain that grants access to all implemented features for testing and verification before generating a domain‑specific build. The dashboard will include four tabs: **Feature List**, **License Management**, **Domain Features**, and **Build Generator**. It will generate domain‑locked configuration files (including a build version) and a mandatory zip package containing the plugin, the generated config, and a user guide.

## User Review Required
> [!IMPORTANT]
> Confirm the updated tab naming (Feature List), the JSON filename, the added toggles for test methods and verification steps, the mandatory zip with user guide, and the removal of any "Superadmin Dashboard" wording. Let me know if any additional fields or behaviours are needed.

## Proposed Changes
---
### Dashboard UI (Admin Area)
- Add a top‑level admin menu **VAPT Master** (accessible only to the Superadmin user `tanmalik786` identified by email `tanmalik786@gmail.com`). Authentication uses an email OTP before granting access, and this user receives Wildcard access to all features.
- Implement four sub‑pages (tabs) using the WordPress Settings API and custom admin pages:
  1. **Feature List** – upload `features-with-test-methods.json`, display a table of all features with columns: `key`, `label`, `category`, `risk_level`, `status` (Available, In‑Progress, Implemented), toggles for including `test_method` and `verification_steps`, and a button to mark a feature as Implemented.
  2. **License Management** – upload/validate license keys, view usage statistics.
  3. **Domain Features** – list domains, assign features per domain, include a **Wildcard** domain option that automatically applies all features with status **Implemented**.
  4. **Build Generator** – select a target domain (or Wildcard), choose which **Implemented** features to include, configure white‑label header fields (plugin name, author, description), specify a **Build Version** (client‑specific), generate a PHP config file, and produce a zip package.
- Load feature definitions from `data/features-with-test-methods.json`.
- Use React (via `@wordpress/scripts` and `wp.element`) for a modern, responsive UI.

---
### Data Model
- Custom tables:
  - `wp_vaptm_domains` (id, domain, is_wildcard, license_id).
  - `wp_vaptm_domain_features` (id, domain_id, feature_key, enabled).
  - `wp_vaptm_feature_status` (feature_key, status ENUM('available','in_progress','implemented')).
  - `wp_vaptm_feature_meta` (feature_key, category, test_method, verification_steps).
- Feature JSON entries now include:
  ```json
  {
    "key": "csrf_protection",
    "label": "CSRF Protection",
    "category": "Input Validation",
    "description": "Adds nonce checks to forms.",
    "risk_level": "high",
    "test_method": "unit",
    "verification_steps": "manual",
    "status": "available"
  }
  ```
- Helper functions to retrieve feature meta, enabled features per domain, and to toggle inclusion of test methods/verification steps.

---
### Feature List Logic
- The **Feature List** tab shows the full feature table with status and toggle columns.
- Superadmin can mark a feature as **Implemented**; this updates `wp_vaptm_feature_status` and makes the feature selectable in the Domain Features and Build Generator tabs.
- Toggles for `test_method` and `verification_steps` are stored in `wp_vaptm_feature_meta` and displayed on the feature card.

---
### Domain Features Logic
- Domains are listed in a table; clicking a domain opens a modal with a checklist of **Implemented** features (filtered by status).
- The **Wildcard** domain checkbox automatically applies *all* features with status **Implemented** to every domain unless a domain explicitly overrides a feature.

---
### Build Generation
- In **Build Generator**, the Superadmin selects a target domain (or Wildcard) and chooses which **Implemented** features to bundle.
- The generated config file `config-{domain}.php` contains constants for each enabled feature **and** a `BUILD_VERSION` constant reflecting the client‑specific version.
- The zip package is **mandatory** and includes:
  - The full plugin folder.
  - The generated `config-{domain}.php`.
  - A `readme.txt`/header populated with the white‑label inputs.
  - A **User Guide** PDF (generated from a template) describing installation and usage.
  - Optionally, a **Folder Structure** document.
- No other documentation or archive files (like `.git`, [.zip](file:///t:/~/Local925%20Sites/wptest/app/public/wp-content/plugins/VAPTSecurity.zip) etc.) are included in the client builds.
- The client build will also include a **VAPT Master** menu item in the WordPress admin for the specific domain.

---
### Security & Permissions
- All admin pages are restricted to users with the custom capability `vaptm_superadmin` (assigned to `tanmalik786`).
- Email OTP flow is implemented via a transient stored OTP and a verification form.
- All inputs are sanitized/validated; nonces are used for every form submission.
- Follow WordPress.org coding standards and best practices throughout.

---
### Internationalisation & Extensibility
- Load a text domain `vaptmaster` for translations.
- Provide action hooks `vaptm_before_build` and `vaptm_after_build` for third‑party extensions.

## Verification Plan
### Automated Tests
- Unit tests for data access functions using WP_Mock.
- Integration tests for feature status workflow, domain feature persistence, and OTP authentication.
- CI script to run PHP_CodeSniffer with WordPress standards, PHP lint, and PHPUnit.

### Manual Verification
- Install the plugin on a fresh WP site.
- Verify the admin menu appears only for `tanmalik786` after OTP verification.
- Upload `features-with-test-methods.json`, mark features as Implemented, toggle test methods/verification steps, and confirm they appear correctly.
- Assign features to domains, test Wildcard behavior.
- Generate a build, download the zip, and confirm it contains the plugin, config file with `BUILD_VERSION`, and the User Guide.
- Test license key upload/validation flow.
- Ensure all forms use nonces and inputs are sanitized.
