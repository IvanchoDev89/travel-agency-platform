# Contributing to Travel Agency Platform

Thank you for your interest in contributing! This document provides guidelines for contributing to the project.

## Code of Conduct

This project adheres to the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code.

## How to Contribute

### Reporting Bugs

1. Check if the issue already exists in [GitHub Issues](https://github.com/IvanchoDev89/travel-agency-platform/issues).
2. If not, open a new issue using the **Bug Report** template.
3. Include:
   - Steps to reproduce
   - Expected vs actual behavior
   - WordPress/PHP version
   - Screenshots if applicable

### Suggesting Features

1. Open an issue using the **Feature Request** template.
2. Describe the use case and why it benefits the platform.
3. Discuss before implementing — large features should be discussed first.

### Submitting Code

1. **Fork** the repository and create a feature branch:
   ```bash
   git checkout -b feat/my-feature
   ```
2. **Follow coding standards:**
   - PHP: WordPress Coding Standards (via PHPCS, config in `.phpcs.xml.dist`)
   - PHPStan level 3 must pass
   - i18n: all user-facing strings must use `__()` / `_e()` / `esc_html__()` with the `travel-agency-platform` text domain
   - All AJAX handlers must call `check_ajax_referer()`
3. **Write tests** if applicable (E2E in `tests/`).
4. **Run checks locally:**
   ```bash
   vendor/bin/phpstan analyse --no-progress
   php tools/i18n-check.php --quiet
   php tools/ajax-nonce-check.php
   php tools/includes-check.php
   vendor/bin/phpcs --warning-severity=0
   ```
5. **Commit** using conventional commits:
   ```
   feat: add new feature
   fix: fix a bug
   docs: update documentation
   chore: maintenance task
   ```
6. **Push** and open a Pull Request against `hardening/phase-1`.
7. **Wait for review** — PRs require 1 approval + green CI.

### Development Setup

```bash
# Clone
git clone https://github.com/IvanchoDev89/travel-agency-platform.git
cd travel-agency-platform

# Install dependencies
composer install

# Run static checks
vendor/bin/phpstan analyse --no-progress
php tools/i18n-check.php --quiet
php tools/ajax-nonce-check.php
php tools/includes-check.php
vendor/bin/phpcs --warning-severity=0
```

### Project Structure

```
app/public/wp-content/
├── plugins/travel-agency-platform/   # Core plugin
│   ├── includes/                     # PHP classes
│   ├── assets/                       # CSS, JS
│   └── languages/                    # i18n catalogs
├── themes/travel-agency-theme/       # Theme
│   ├── assets/                       # Theme assets (fonts, JS, SW)
│   ├── templates/                    # Page templates
│   └── style.css                     # Main stylesheet
tests/                                # E2E test suites
tools/                                # Quality tools
docs/                                 # Documentation
.github/workflows/                    # CI/CD
```

### Important Notes

- **Secrets:** Never commit API keys, passwords, or credentials. Use `wp-config.php` or environment variables.
- **Database:** The plugin creates custom tables. Run `TAP_Installer::create_tables()` after schema changes.
- **Version bump:** Bump `TAP_VERSION` (plugin) or `TAT_VERSION` (theme) when changing assets.
- **i18n:** Run `tools/i18n.sh` after adding new translatable strings, then fill translations.

### License

By contributing, you agree that your contributions will be licensed under the [GPL-2.0 License](LICENSE).