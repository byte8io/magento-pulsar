# Releasing

## Overview

Releases are triggered by pushing a git tag. GitHub Actions validates the code, then creates a GitHub Release with an auto-generated changelog.

## Creating a Release

### 1. Update the version

Before tagging, ensure the version is updated in these files:

| File | Field |
|------|-------|
| `Model/HealthCheck.php` | `MODULE_VERSION` constant |
| `composer.json` | `extra.branch-alias.dev-main` |

### 2. Commit and push

```bash
git add -A
git commit -m "chore: bump version to 1.5.0"
git push origin main
```

### 3. Create and push the tag

```bash
git tag v1.5.0
git push origin v1.5.0
```

This triggers the release workflow which:
1. Validates PHP syntax (8.1, 8.2, 8.3)
2. Validates `composer.json`
3. Generates a changelog from commits since the last tag
4. Creates a GitHub Release at `github.com/byte8io/magento-pulsar/releases`

### 4. Verify

Check the [Actions tab](https://github.com/byte8io/magento-pulsar/actions) to confirm the workflow succeeded.

## Versioning

We follow [Semantic Versioning](https://semver.org/):

- **Major** (`v2.0.0`) — breaking changes to the health endpoint response format
- **Minor** (`v1.5.0`) — new collectors or features (backward compatible)
- **Patch** (`v1.4.1`) — bug fixes, threshold adjustments, documentation

## Tag Conventions

- Tags must start with `v` (e.g., `v1.4.0`, not `1.4.0`)
- Use three-part version numbers: `vMAJOR.MINOR.PATCH`

## CI

The CI workflow runs automatically on every push to `main` and on pull requests:

- PHP syntax check across PHP 8.1, 8.2, 8.3
- `composer.json` validation
- PSR-12 coding standards check

## Deleting a Tag (if needed)

If you tagged the wrong commit:

```bash
# Delete local tag
git tag -d v1.5.0

# Delete remote tag
git push origin --delete v1.5.0
```

Then delete the corresponding GitHub Release manually from the releases page.

## Quick Reference

```bash
# List all tags
git tag --sort=-v:refname

# Show what a tag points to
git show v1.4.0

# Create an annotated tag (optional, for extra metadata)
git tag -a v1.5.0 -m "Add new collector for X"
git push origin v1.5.0
```
