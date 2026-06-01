# GitHub-to-WordPress Plugin Handoff

This handoff explains the workflow used for the IBBI Staff Dashboard plugin so the same pattern can be reused for another WordPress app/plugin.

## Goal

Build a normal WordPress plugin locally, keep the source code in GitHub, and install/update that plugin on the WordPress site through a GitHub deployment plugin such as WP Pusher.

This lets development happen safely outside the live site. After changes are tested and pushed to GitHub, WordPress can pull the new plugin version from the repository.

## Example From This Project

Local folder:

```txt
/Users/michaelschmidt/Documents/ibbi_dashboard/
```

GitHub repository:

```txt
https://github.com/preachingalways0710/ibbi-staff-dashboard.git
```

WordPress plugin name:

```txt
IBBI Staff Dashboard
```

Main plugin file:

```txt
student-database-dashboard.php
```

Shortcode used on the WordPress page:

```txt
[ibbi_staff_dashboard]
```

Current public/staff page:

```txt
https://meuibbi.com/hub-codex/
```

## Plugin Structure

The plugin folder is installed in WordPress like a normal plugin:

```txt
wp-content/plugins/ibbi-staff-dashboard/
```

The repository contains:

```txt
ibbi-staff-dashboard/
├── student-database-dashboard.php
├── assets/
│   ├── css/
│   │   └── dashboard.css
│   └── js/
│       └── dashboard.js
└── tabs/
    ├── tab-personal.php
    ├── tab-progress.php
    └── tab-status.php
```

The main PHP file contains the WordPress plugin header:

```php
/**
 * Plugin Name: IBBI Staff Dashboard
 * Description: Staff-facing Bible Institute dashboard for Tutor LMS student progress and academic follow-up.
 * Version: 1.0.22
 * Author: Mike Schmidt / OpenAI
 */
```

The version number matters because WordPress/WP Pusher uses it to detect updates, and the dashboard also displays it visually so we can confirm the live site updated.

## WordPress Setup

1. Install a GitHub deployment plugin in WordPress.

   We used WP Pusher for this workflow.

2. Connect the WordPress deployment plugin to GitHub.

3. Add the GitHub repository as a WordPress plugin.

   Repository:

   ```txt
   preachingalways0710/ibbi-staff-dashboard
   ```

4. Install and activate the plugin.

5. Add the shortcode to the desired WordPress page:

   ```txt
   [ibbi_staff_dashboard]
   ```

6. Visit the page and confirm the dashboard loads.

7. Confirm the visible version number on the dashboard matches the latest GitHub version.

## Development Workflow

Make changes locally first.

Recommended cycle:

```bash
cd /Users/michaelschmidt/Documents/ibbi_dashboard
```

Edit the plugin files.

Run syntax checks:

```bash
php -l student-database-dashboard.php
node --check assets/js/dashboard.js
git diff --check
```

Bump the plugin version in both places:

```php
* Version: 1.0.23
define('SDD_VERSION', '1.0.23');
```

Commit and push:

```bash
git status
git add student-database-dashboard.php assets/css/dashboard.css assets/js/dashboard.js
git commit -m "Describe the change"
git push origin main
```

Then update the plugin in WordPress through WP Pusher.

If WP Pusher auto-pull is enabled and working, the update may appear automatically. In practice, manual update may still be needed.

## Important Lessons Learned

- Use a new plugin name/repository when replacing an older plugin to avoid folder/plugin conflicts.
- Do not delete the old plugin immediately. Deactivate it first and keep it as a backup until the new one is stable.
- If WordPress says the destination folder already exists, the plugin folder already exists on the server. Rename the new plugin or remove/deactivate the conflicting old install carefully.
- Always bump the plugin version before pushing an update that WordPress needs to detect.
- Show the plugin version inside the UI so it is obvious whether the live page updated.
- Keep the shortcode stable. Pages should not need to be rebuilt every time the plugin changes.
- Do not test first on the production site if a staging/local option exists.

## Security Pattern Used

The dashboard protects staff-only data by:

- Requiring login before rendering the dashboard.
- Checking user permissions before showing data.
- Checking permissions again on AJAX requests.
- Using WordPress nonces for AJAX requests.
- Escaping output with functions like `esc_html()`, `esc_attr()`, and `esc_url()`.
- Sanitizing saved values with functions like `sanitize_text_field()` and `sanitize_textarea_field()`.

## Integration Pattern

For Tutor LMS-style integrations:

- Prefer official WordPress/Tutor LMS functions where possible.
- Read existing WordPress user data and user meta.
- Store custom staff/admin fields in user meta.
- Avoid writing directly to third-party plugin database tables unless truly necessary.

For this dashboard, the plugin reads Tutor LMS progress/course data and adds staff-facing metadata such as:

```txt
_bi_student_status
_bi_level
_bi_payment_status
_bi_supervisor
_bi_covalidation_status
_bi_admin_notes
_bi_last_contacted_at
_bi_last_contacted_by
_bi_staff_updated_at
_bi_staff_updated_by
```

## Reusing This Pattern For A New App

For the next app:

1. Create a new GitHub repository.
2. Create a new local plugin folder.
3. Give the plugin a unique name and main PHP file.
4. Add a shortcode or block that renders the app inside WordPress.
5. Push the code to GitHub.
6. Install the repository through WP Pusher or the GitHub WordPress plugin.
7. Add the shortcode to the target WordPress page.
8. Use version numbers visibly in the UI for update verification.

Recommended starting structure:

```txt
new-plugin-name/
├── new-plugin-name.php
├── assets/
│   ├── css/
│   │   └── app.css
│   └── js/
│       └── app.js
└── includes/
    ├── helpers.php
    └── data-functions.php
```

## Minimal Starter Plugin Pattern

```php
<?php
/**
 * Plugin Name: My New WordPress App
 * Description: Short description of what this app does.
 * Version: 1.0.0
 * Author: Mike Schmidt / OpenAI
 */

defined('ABSPATH') || exit;

define('MY_APP_VERSION', '1.0.0');
define('MY_APP_URL', plugin_dir_url(__FILE__));

add_action('wp_enqueue_scripts', function () {
    wp_register_style('my-app', MY_APP_URL . 'assets/css/app.css', [], MY_APP_VERSION);
    wp_register_script('my-app', MY_APP_URL . 'assets/js/app.js', [], MY_APP_VERSION, true);
});

add_shortcode('my_app', function () {
    wp_enqueue_style('my-app');
    wp_enqueue_script('my-app');

    ob_start();
    ?>
    <section class="my-app">
        <div class="my-app__version">Version <?php echo esc_html(MY_APP_VERSION); ?></div>
        <div id="my-app-root"></div>
    </section>
    <?php
    return ob_get_clean();
});
```

Then place this shortcode on a WordPress page:

```txt
[my_app]
```

## Operational Rule

The WordPress site should be the deployment target, not the place where development happens.

The source of truth is GitHub. WordPress receives the plugin from GitHub.

