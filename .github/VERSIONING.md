# Versioning Strategy

This project uses automated semantic versioning via GitHub Actions.

## How It Works

Whenever code is pushed to the `main` branch and the `composer.json` file is modified, a GitHub Actions workflow automatically:

1. **Reads the version** from the `version` field in `composer.json`
2. **Checks if a tag already exists** for that version
3. **Creates a new Git tag** (e.g., `v1.0.0`) if it doesn't exist
4. **Pushes the tag** to the repository

## Workflow Rules

- **Trigger**: Push to `main` branch that modifies `composer.json`
- **Automatic tag creation**: New version tags are created automatically
- **Duplicate prevention**: If a tag already exists, it won't be recreated
- **Tag format**: Tags are prefixed with `v` (e.g., `v1.0.0` for version `1.0.0`)

## How to Release a New Version

1. **Update the version** in `composer.json`:
   ```json
   {
     "version": "1.0.1"
   }
   ```

2. **Commit and push** to main:
   ```bash
   git add composer.json
   git commit -m "Release version 1.0.1"
   git push origin main
   ```

3. **GitHub Actions will automatically**:
   - Detect the version change
   - Create the `v1.0.1` tag
   - Push it to the repository
   - Make it available for Composer package installation

## Semantic Versioning

This project follows [Semantic Versioning](https://semver.org/):

- **MAJOR** version for incompatible changes (e.g., `2.0.0`)
- **MINOR** version for backwards-compatible additions (e.g., `1.1.0`)
- **PATCH** version for backwards-compatible fixes (e.g., `1.0.1`)

Format: `MAJOR.MINOR.PATCH`

## Composer Installation

Users can install specific versions or version ranges:

```bash
# Specific version
composer require "pvtl/csv-importer:1.0.0"

# Latest patch version in 1.0.x
composer require "pvtl/csv-importer:~1.0"

# Latest minor version in 1.x
composer require "pvtl/csv-importer:^1.0"
```
